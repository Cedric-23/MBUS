<?php
require_once __DIR__ . '/../Includes/registration_security.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class FakeRatePdo
{
    public array $events = [];
    public int $now = 1_800_000_000;
    private bool $transaction = false;
    private array $snapshot = [];

    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool
    {
        $this->snapshot = $this->events;
        $this->transaction = true;
        return true;
    }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool
    {
        $this->events = $this->snapshot;
        $this->transaction = false;
        return true;
    }
    public function prepare(string $sql): FakeRateStatement { return new FakeRateStatement($this, $sql); }
    public function exec(string $sql): int
    {
        if (str_contains($sql, 'DELETE FROM registration_rate_limit_events')) {
            $before = count($this->events);
            $this->events = array_values(array_filter(
                $this->events,
                fn(array $event): bool => $event['created_at'] >= $this->now - 172800
            ));
            return $before - count($this->events);
        }
        return 0;
    }
}

final class FakeRateStatement
{
    private array|false $result = false;
    public function __construct(private FakeRatePdo $pdo, private string $sql) {}

    public function execute(array $params = []): bool
    {
        if (str_contains($this->sql, 'pg_advisory_xact_lock')) {
            $this->result = false;
        } elseif (str_contains($this->sql, 'SELECT COUNT(*) AS event_count')) {
            [$cooldown, $bucketHash, $window] = $params;
            $recent = array_values(array_filter(
                $this->pdo->events,
                fn(array $event): bool => $event['bucket_hash'] === $bucketHash
                    && $event['created_at'] > $this->pdo->now - (int)$window
            ));
            $last = $recent ? max(array_column($recent, 'created_at')) : null;
            $this->result = [
                'event_count' => count($recent),
                'cooldown_active' => $last !== null && $this->pdo->now - $last < (int)$cooldown ? 1 : 0,
            ];
        } elseif (str_contains($this->sql, 'INSERT INTO registration_rate_limit_events')) {
            $this->pdo->events[] = ['bucket_hash' => $params[0], 'created_at' => $this->pdo->now];
            $this->result = false;
        } else {
            throw new RuntimeException('Unexpected SQL in rate-limit test: ' . $this->sql);
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT): array|false { return $this->result; }
}

putenv('SUPABASE_DB_PASSWORD=unit-test-only-pepper');
$pdo = new FakeRatePdo();
$conn = (object)['pdo' => $pdo];
$checks = [
    ['test:email:one', 2, 3600, 60, 'email_cooldown', 'email_limit'],
    ['test:global', 3, 3600, 0, '', 'limit'],
];

check(mbus_registration_consume_limits($conn, $checks) === null, 'first request should pass');
check(count($pdo->events) === 2, 'an allowed request should add one event to each bucket');
check(mbus_registration_consume_limits($conn, $checks) === 'email_cooldown', 'immediate resend should hit the cooldown');
check(count($pdo->events) === 2, 'a rejected request must not consume the global quota');

$pdo->now += 60;
check(mbus_registration_consume_limits($conn, $checks) === null, 'request after the cooldown should pass');
check(count($pdo->events) === 4, 'second allowed request should be recorded');

$pdo->now += 60;
check(mbus_registration_consume_limits($conn, $checks) === 'email_limit', 'email-hour limit should reject a third request');
check(count($pdo->events) === 4, 'email-limit rejection must roll back every bucket');

$pdo->now = 1_800_003_601;
check(mbus_registration_consume_limits($conn, $checks) === null, 'expired events must fall out of the rolling hour');
check(count($pdo->events) === 6, 'request after the sliding hour should be recorded');

$pdo->now += 60;
$globalOnly = [['test:global-cap', 1, 3600, 0, '', 'limit']];
check(mbus_registration_consume_limits($conn, $globalOnly) === null, 'first global-only request should pass');
check(mbus_registration_consume_limits($conn, $globalOnly) === 'limit', 'global cap should reject the next request');

check(!$pdo->inTransaction(), 'all request paths must close their transaction');
echo "PASS sliding-hour quotas, resend cooldown, all-or-nothing rejected requests, and transaction closure\n";

require_once __DIR__ . '/../Includes/reset_mailer.php';

$providerVars = ['SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM', 'MAIL_FROM_EMAIL', 'BREVO_API_KEY', 'MAIL_RELAY_URL', 'MAIL_RELAY_SECRET'];
foreach ($providerVars as $name) putenv($name . '=');
check(!mbus_registration_mail_is_configured(), 'empty mail configuration must be rejected');
putenv('SMTP_USERNAME=sender@example.com');
putenv('SMTP_PASSWORD=test-password');
check(mbus_registration_mail_is_configured(), 'valid SMTP configuration should be accepted');
putenv('SMTP_USERNAME=');
putenv('SMTP_PASSWORD=');
putenv('BREVO_API_KEY=test-key');
putenv('MAIL_FROM_EMAIL=sender@example.com');
check(mbus_registration_mail_is_configured() === function_exists('curl_init'), 'Brevo should require its sender and the cURL transport');
putenv('BREVO_API_KEY=');
putenv('MAIL_FROM_EMAIL=');
putenv('MAIL_RELAY_URL=http://relay.example.test/send');
putenv('MAIL_RELAY_SECRET=test-secret');
check(!mbus_registration_mail_is_configured(), 'plain HTTP relays must be rejected');
putenv('MAIL_RELAY_URL=https://relay.example.test/send');
check(mbus_registration_mail_is_configured() === function_exists('curl_init'), 'HTTPS relay should require cURL and its shared secret');

check(mbus_mail_redirect_is_allowed('https://relay.example.test/send', 'https://relay.example.test/result', 'relay'), 'same-host HTTPS relay redirect should be allowed');
check(mbus_mail_redirect_is_allowed('https://script.google.com/macros/s/id/exec', 'https://script.googleusercontent.com/macros/echo?key=test', 'relay'), 'the Apps Script response redirect should be allowed');
check(!mbus_mail_redirect_is_allowed('https://relay.example.test/send', 'http://relay.example.test/result', 'relay'), 'HTTP redirect must be rejected');
check(!mbus_mail_redirect_is_allowed('https://relay.example.test/send', 'https://attacker.example.test/result', 'relay'), 'cross-host redirect must be rejected');
echo "PASS email-provider readiness and redirect safety (no email sent)\n";
