<?php
/* Keeps PHP sessions in the database instead of container files, so a Render restart,
 * redeploy or wake-up from sleep no longer signs everybody out. */
final class MbusDbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private PDO $pdo;
    private int $lifetime;
    private array $seen = [];   // id => [data, updated_at epoch]

    public function __construct(PDO $pdo, int $lifetime) { $this->pdo = $pdo; $this->lifetime = $lifetime; }

    public static function connect(): ?PDO
    {
        require_once dirname(__DIR__) . '/config/load_env.php';
        foreach ([dirname(__DIR__) . '/.env', dirname(__DIR__) . '/config/.env'] as $f) load_env($f);
        $password = (string)getenv('SUPABASE_DB_PASSWORD');
        if ($password === '') return null;
        $host = getenv('SUPABASE_DB_HOST') ?: 'aws-1-ap-south-1.pooler.supabase.com';
        $port = getenv('SUPABASE_DB_PORT') ?: '5432';
        $db = getenv('SUPABASE_DB_NAME') ?: 'postgres';
        $user = getenv('SUPABASE_DB_USER') ?: 'postgres.xeczvnheaixpfattbwsk';
        try {
            return new PDO("pgsql:host=$host;port=$port;dbname=$db;sslmode=require;connect_timeout=5", $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => true,
            ]);
        } catch (Throwable $e) { error_log('Session DB unavailable, using files: ' . $e->getMessage()); return null; }
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS mbus_sessions (
            id VARCHAR(128) PRIMARY KEY, data TEXT NOT NULL DEFAULT '', updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS mbus_sessions_updated_idx ON mbus_sessions (updated_at)');
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        for ($try = 0; $try < 2; $try++) {
            try {
                $q = $this->pdo->prepare('SELECT data, EXTRACT(EPOCH FROM updated_at)::bigint AS t FROM mbus_sessions WHERE id = ? AND updated_at > NOW() - (? * INTERVAL \'1 second\')');
                $q->execute([$id, $this->lifetime]);
                $row = $q->fetch();
                $data = $row ? (string)$row['data'] : '';
                $this->seen[$id] = [$data, $row ? (int)$row['t'] : 0];
                return $data;
            } catch (PDOException $e) {
                if ($try === 0 && $e->getCode() === '42P01') { $this->ensureTable(); continue; }
                error_log('Session read failed: ' . $e->getMessage());
                return '';
            }
        }
        return '';
    }

    public function write(string $id, string $data): bool
    {
        try {
            [$old, $t] = $this->seen[$id] ?? [null, 0];
            if ($old === $data && $t > 0) {
                /* unchanged (e.g. a polling request): never overwrite, just keep the session alive now and then */
                if (time() - $t > 300) $this->touch($id, $data);
                return true;
            }
            $this->pdo->prepare('INSERT INTO mbus_sessions (id, data, updated_at) VALUES (?, ?, NOW())
                ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = NOW()')->execute([$id, $data]);
            return true;
        } catch (PDOException $e) { error_log('Session write failed: ' . $e->getMessage()); return false; }
    }

    public function destroy(string $id): bool
    {
        try { $this->pdo->prepare('DELETE FROM mbus_sessions WHERE id = ?')->execute([$id]); } catch (PDOException $e) {}
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try { return $this->pdo->prepare('DELETE FROM mbus_sessions WHERE updated_at < NOW() - (? * INTERVAL \'1 second\')')->execute([$this->lifetime]) ? 1 : 0; }
        catch (PDOException $e) { return 0; }
    }

    public function validateId(string $id): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9,-]{22,128}$/', $id);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    private function touch(string $id, string $data): void
    {
        $this->pdo->prepare('UPDATE mbus_sessions SET updated_at = NOW() WHERE id = ?')->execute([$id]);
    }
}
