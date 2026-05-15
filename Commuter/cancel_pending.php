<?php
session_start();
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
exit();
}

$user_id=$_SESSION['user_id'];

if(!isset($_POST['reservation_id'])){
exit();
}

$reservation_id=mysqli_real_escape_string(
$conn,
$_POST['reservation_id']
);

$update=mysqli_query($conn,"
UPDATE reservation
SET status='Cancelled'
WHERE reservation_id='$reservation_id'
AND user_id='$user_id'
AND status='Pending'
");

if($update){
echo "ok";
}else{
echo "error";
}
?>