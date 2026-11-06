<?php
require_once __DIR__ . '/../config/app.php';
session_start();

if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_type'] ?? '')) !== 'operator') {
    header('Location: ../login.php');
    exit;
}

$pickupStaffRole = 'operator';
require __DIR__ . '/../Includes/pickup_staff_view.php';
