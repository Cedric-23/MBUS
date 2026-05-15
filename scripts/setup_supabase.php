<?php
/**
 * Apply MBus schema and seed data to Supabase (no local MySQL required).
 * Requires SUPABASE_DB_PASSWORD in .env
 *
 * Run: php scripts/setup_supabase.php
 */

require_once dirname(__DIR__) . '/config/load_env.php';

$host = getenv('SUPABASE_DB_HOST') ?: 'db.xeczvnheaixpfattbwsk.supabase.co';
$port = getenv('SUPABASE_DB_PORT') ?: '5432';
$database = getenv('SUPABASE_DB_NAME') ?: 'postgres';
$user = getenv('SUPABASE_DB_USER') ?: 'postgres';
$password = getenv('SUPABASE_DB_PASSWORD') ?: '';

if ($password === '') {
    fwrite(STDERR, "Set SUPABASE_DB_PASSWORD in .env\n");
    exit(1);
}

$dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=require";

try {
    $pg = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "PostgreSQL: {$e->getMessage()}\n");
    exit(1);
}

$base = dirname(__DIR__) . '/supabase';
$files = ['00_reset.sql', '01_schema.sql', '02_data.sql'];

foreach ($files as $file) {
    $path = "$base/$file";
    if (!is_readable($path)) {
        fwrite(STDERR, "Missing: $path\n");
        exit(1);
    }

    echo "Running $file...\n";
    $pg->exec(file_get_contents($path));
}

echo "Supabase setup complete.\n";
