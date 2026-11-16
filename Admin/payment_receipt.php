<?php
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Receipt review is paused with the Admin manual GCash console.
// Keep the old route as a safe redirect and preserve stored receipts for future decisions.
header('Location: admin_dashboard.php');
exit();
