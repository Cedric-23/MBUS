<?php
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Manual GCash administration is paused while MBUS selects a payment approach.
// Keep the route as a safe redirect for old bookmarks; do not alter historical records.
header('Location: admin_dashboard.php');
exit();
