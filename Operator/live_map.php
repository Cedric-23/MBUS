<?php
session_start();

if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_type'] ?? '')) !== 'operator') {
    header('Location: ../login.php');
    exit;
}

header('Location: scan.php', true, 302);
exit;
