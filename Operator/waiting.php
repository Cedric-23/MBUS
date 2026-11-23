<?php
require_once __DIR__ . '/../config/app.php';
session_start();

if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_type'] ?? '')) !== 'conductor') {
    header('Location: ../login.php');
    exit;
}

$pickupStaffRole = 'conductor';
require __DIR__ . '/../Includes/pickup_staff_view.php';
