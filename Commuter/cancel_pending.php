<?php
session_start();
include "../config/db_connect.php";
include "../Includes/csrf.php";

if(!isset($_SESSION['user_id'])){
    exit();
}

if(!csrf_validate_token()){
    echo "csrf_error";
    exit();
}

$user_id = $_SESSION['user_id'];

if(!isset($_POST['reservation_id'])){
    exit();
}

$reservation_id = mbus_db_escape($conn, $_POST['reservation_id']);

mbus_db_prepare($conn, "
    UPDATE reservation
    SET status='Cancelled'
    WHERE reservation_id=?
    AND user_id=?
    AND status='Pending'
", [$reservation_id, $user_id]);

echo "ok";
