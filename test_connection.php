<?php
require_once __DIR__ . '/config/app.php';
if (mbus_is_production()) {
    http_response_code(404);
    exit;
}
include "db_connect.php";

echo "Database connected successfully!";
?>