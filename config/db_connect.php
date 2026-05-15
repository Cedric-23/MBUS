<?php

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/load_env.php';
require_once __DIR__ . '/mysqli_compat.php';

$host = getenv('SUPABASE_DB_HOST') ?: 'aws-1-ap-south-1.pooler.supabase.com';
$port = getenv('SUPABASE_DB_PORT') ?: '5432';
$database = getenv('SUPABASE_DB_NAME') ?: 'postgres';
$user = getenv('SUPABASE_DB_USER') ?: 'postgres.xeczvnheaixpfattbwsk';
$password = getenv('SUPABASE_DB_PASSWORD') ?: '';

if ($password === '') {
    die(
        'Supabase database password is missing. Copy .env.example to .env and set SUPABASE_DB_PASSWORD ' .
        '(Supabase Dashboard → Project Settings → Database → Database password).'
    );
}

$dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $conn = new MbusConnection($pdo);
} catch (PDOException $e) {
    $GLOBALS['mbus_connect_error'] = $e->getMessage();
    die('Connection failed: ' . $e->getMessage());
}
