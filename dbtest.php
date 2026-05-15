<?php

require_once __DIR__ . '/config/db_connect.php';

if (!$conn) {
    die('Connection failed: ' . mbus_db_connect_error());
}

echo 'Database connected successfully (Supabase PostgreSQL)';
