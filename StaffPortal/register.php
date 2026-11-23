<?php
// Staff registration uses the same validated application handler and shared
// operator/conductor application tables as the main MBUS site.
define('MBUS_STAFF_PORTAL', true);
if (!isset($_GET['type']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_GET['type'] = 'driver';
}
require __DIR__ . '/../register.php';
