<?php
require_once __DIR__ . '/../Includes/registration_security.php';

function integration_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function integration_pdo(): PDO
{
    $dsn = (string)getenv('MBUS_TEST_POSTGRES_DSN');
    return new PDO($dsn, (string)getenv('MBUS_TEST_POSTGRES_USER'), (string)getenv('MBUS_TEST_POSTGRES_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

if (($argv[1] ?? '') === 'worker') {
    putenv('SUPABASE_DB_PASSWORD=' . (string)getenv('MBUS_TEST_POSTGRES_PEPPER'));
    $pdo = integration_pdo();
    $bucket = (string)($argv[2] ?? '');
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    fgets(STDIN); // All workers start only after the parent has connected every child.
    $result = mbus_registration_consume_limits((object)['pdo' => $pdo], [
        [$bucket, 1, 3600, 0, '', 'concurrent_limit'],
    ]);
    fwrite(STDOUT, ($result === null ? 'ALLOWED' : $result) . "\n");
    exit(0);
}

$dsn = trim((string)getenv('MBUS_TEST_POSTGRES_DSN'));
if ($dsn === '') {
    echo "SKIP PostgreSQL integration test; set MBUS_TEST_POSTGRES_DSN to a local disposable test database.\n";
    exit(0);
}

preg_match('/(?:^|[;:])host=([^;]+)/i', $dsn, $hostMatch);
preg_match('/(?:^|;)dbname=([^;]+)/i', $dsn, $databaseMatch);
$host = strtolower(trim((string)($hostMatch[1] ?? '')));
$database = (string)($databaseMatch[1] ?? '');
integration_check(in_array($host, ['localhost', '127.0.0.1', '::1'], true), 'Refusing to run against a non-local PostgreSQL host.');
integration_check(str_starts_with($database, 'mbus_test_'), 'Refusing to run unless the database name begins with mbus_test_.');
integration_check(function_exists('proc_open'), 'proc_open is required for the concurrency check.');

$pdo = integration_pdo();
$pdo->exec("CREATE TABLE IF NOT EXISTS registration_rate_limit_events (
    event_id BIGSERIAL PRIMARY KEY,
    bucket_hash CHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS registration_rate_limit_events_bucket_idx ON registration_rate_limit_events (bucket_hash, created_at)');
$pdo->exec('TRUNCATE TABLE registration_rate_limit_events RESTART IDENTITY');

$pepper = 'local-postgres-integration-test-pepper';
putenv('SUPABASE_DB_PASSWORD=' . $pepper);
putenv('MBUS_TEST_POSTGRES_PEPPER=' . $pepper);
$pdo = null;

$bucket = 'registration-test:concurrent:' . bin2hex(random_bytes(12));
$children = [];
$workerCount = 12;
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
for ($i = 0; $i < $workerCount; $i++) {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $bucket], $descriptors, $pipes);
    integration_check(is_resource($process), 'Could not start a PostgreSQL concurrency worker.');
    $ready = fgets($pipes[1]);
    integration_check(trim((string)$ready) === 'READY', 'A PostgreSQL concurrency worker failed to connect.');
    $children[] = [$process, $pipes];
}
foreach ($children as [, $pipes]) {
    fwrite($pipes[0], "go\n");
    fclose($pipes[0]);
}
$results = [];
foreach ($children as [$process, $pipes]) {
    $results[] = trim((string)stream_get_contents($pipes[1]));
    $error = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    integration_check($exitCode === 0, 'A worker failed: ' . $error);
}
$allowedCount = count(array_filter($results, fn(string $value): bool => $value === 'ALLOWED'));
$deniedCount = count(array_filter($results, fn(string $value): bool => $value === 'concurrent_limit'));
integration_check($allowedCount === 1 && $deniedCount === $workerCount - 1, 'Concurrent requests must not race past a limit of one.');

$pdo = integration_pdo();
$globalBucket = 'registration-test:global:' . bin2hex(random_bytes(12));
$emailBucketOne = 'registration-test:email-one:' . bin2hex(random_bytes(12));
$emailBucketTwo = 'registration-test:email-two:' . bin2hex(random_bytes(12));
$conn = (object)['pdo' => $pdo];
$first = mbus_registration_consume_limits($conn, [
    [$emailBucketOne, 1, 3600, 0, '', 'email_limit'],
    [$globalBucket, 1, 3600, 0, '', 'global_limit'],
]);
integration_check($first === null, 'The first multi-bucket request should pass.');
$second = mbus_registration_consume_limits($conn, [
    [$emailBucketTwo, 1, 3600, 0, '', 'email_limit'],
    [$globalBucket, 1, 3600, 0, '', 'global_limit'],
]);
integration_check($second === 'global_limit', 'The service-wide bucket should reject the second request.');

$emailHash = hash_hmac('sha256', $emailBucketTwo, $pepper);
$count = $pdo->prepare('SELECT COUNT(*) FROM registration_rate_limit_events WHERE bucket_hash = ?');
$count->execute([$emailHash]);
integration_check((int)$count->fetchColumn() === 0, 'A global rejection must roll back its otherwise-available email bucket.');

$hashes = array_map(fn(string $value): string => hash_hmac('sha256', $value, $pepper), [$bucket, $globalBucket, $emailBucketOne, $emailBucketTwo]);
$delete = $pdo->prepare('DELETE FROM registration_rate_limit_events WHERE bucket_hash = ANY(CAST(? AS CHAR(64)[]))');
$delete->execute(['{' . implode(',', $hashes) . '}']);
echo "PASS real PostgreSQL advisory-lock concurrency and atomic multi-bucket rollback\n";
