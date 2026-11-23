<?php
require_once __DIR__ . '/../config/app.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array(strtolower((string)($_SESSION['user_type'] ?? '')), ['driver', 'conductor'], true)) {
    header('Location: ../login.php');
    exit;
}

$pickupStaffRole = strtolower((string)$_SESSION['user_type']);
require __DIR__ . '/../Includes/pickup_staff_view.php';
