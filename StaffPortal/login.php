<?php
// Separate staff-facing entry point. The shared handler keeps passwords,
// rate limiting, role routing, and account rules identical to MBUS.
define('MBUS_STAFF_PORTAL', true);
require __DIR__ . '/../login.php';
