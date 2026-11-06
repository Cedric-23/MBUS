<?php
require_once __DIR__ . '/../config/app.php';
session_start();
if (!isset($_SESSION['user_id']) || !is_string($_SESSION['user_type'] ?? null) || strtolower($_SESSION['user_type']) !== 'admin') {
    header('Location: ../login.php');
    exit;
}
$pickupStaffRole = 'admin';
require __DIR__ . '/../Includes/pickup_staff_view.php';
