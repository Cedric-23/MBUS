<?php
session_start();

if(isset($_SESSION['user_id'])){
    include "config/db_connect.php";
    include "Includes/activity_log.php";
    
    $user_id = $_SESSION['user_id'];
    
    // Log logout before destroying session
    log_activity($conn, $user_id, ACTION_LOGOUT, "User logged out");
    
    session_destroy();
    
    header("Location: login.php");
    exit();
}

header("Location: login.php");
exit();
?>