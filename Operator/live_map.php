<?php
session_start();

if (!isset($_SESSION['user_id']) || !in_array(strtolower((string)($_SESSION['user_type'] ?? '')), ['driver', 'conductor'], true)) {
    header('Location: ../login.php');
    exit;
}

header('Location: scan.php', true, 302);
exit;
