<?php
require_once dirname(__DIR__) . '/config/load_env.php';

$host = getenv('SUPABASE_DB_HOST') ?: 'aws-1-ap-south-1.pooler.supabase.com';
$user = getenv('SUPABASE_DB_USER') ?: 'postgres.xeczvnheaixpfattbwsk';
$password = getenv('SUPABASE_DB_PASSWORD') ?: '';

$pdo = new PDO(
    "pgsql:host=$host;port=5432;dbname=postgres;sslmode=require",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== Tables in public schema ===\n";
$tables = $pdo->query("
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
    ORDER BY table_name
")->fetchAll(PDO::FETCH_COLUMN);

if (count($tables) === 0) {
    echo "(none — schema may not be applied yet)\n";
} else {
    foreach ($tables as $t) {
        $count = $pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
        echo "- $t ($count rows)\n";
    }
}
