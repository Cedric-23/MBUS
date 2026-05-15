<?php
require_once dirname(__DIR__) . '/config/load_env.php';

$password = getenv('SUPABASE_DB_PASSWORD') ?: '';
if ($password === '') {
    die("SUPABASE_DB_PASSWORD is empty in .env\n");
}

$attempts = [];

$regions = [
    'ap-southeast-1', 'ap-southeast-2', 'ap-northeast-1', 'ap-northeast-2',
    'ap-south-1', 'us-east-1', 'us-west-1', 'us-west-2',
    'eu-west-1', 'eu-west-2', 'eu-central-1', 'sa-east-1',
];

foreach (['aws-0', 'aws-1'] as $prefix) {
    foreach ($regions as $region) {
        foreach (['5432', '6543'] as $port) {
            $attempts[] = [
                'host' => "$prefix-$region.pooler.supabase.com",
                'port' => $port,
                'user' => 'postgres.xeczvnheaixpfattbwsk',
            ];
        }
    }
}

$attempts[] = ['host' => '2406:da1a:b00:1301:73fa:af4d:41a6:acdf', 'port' => '5432', 'user' => 'postgres'];
$attempts[] = ['host' => 'db.xeczvnheaixpfattbwsk.supabase.co', 'port' => '5432', 'user' => 'postgres'];

foreach ($attempts as $a) {
    $dsn = "pgsql:host={$a['host']};port={$a['port']};dbname=postgres;sslmode=require";
    try {
        new PDO($dsn, $a['user'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        echo "OK: {$a['user']}@{$a['host']}:{$a['port']}\n";
        file_put_contents(
            dirname(__DIR__) . '/.env.connection',
            "SUPABASE_DB_HOST={$a['host']}\nSUPABASE_DB_PORT={$a['port']}\nSUPABASE_DB_USER={$a['user']}\n"
        );
        exit(0);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'password authentication failed')) {
            echo "WRONG PASSWORD at {$a['host']}:{$a['port']}\n";
            exit(2);
        }
        if (!str_contains($msg, 'ENOTFOUND') && !str_contains($msg, 'Tenant or user not found') && !str_contains($msg, 'could not translate')) {
            echo "HINT {$a['host']}:{$a['port']} — $msg\n";
        }
    }
}

echo "No connection worked. Copy host/port/user from Supabase → Settings → Database → Connection string (Session pooler).\n";
exit(1);
