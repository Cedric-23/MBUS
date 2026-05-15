<?php

session_start();

include "../config/db_connect.php";

/* AUTH */

if(!isset($_SESSION['user_id'])){
exit();
}

$user_id=$_SESSION['user_id'];

/* CHECK */

if(!isset($_POST['schedule_id'])){
exit();
}

$schedule_id=mbus_db_escape(
$conn,
$_POST['schedule_id']
);

/* CANCEL ALL USER PENDING RESERVATIONS
FOR THIS SCHEDULE */

mbus_db_query($conn,"
UPDATE reservation
SET status='Cancelled'
WHERE user_id='$user_id'
AND schedule_id='$schedule_id'
AND status='Pending'
");

/* RESPONSE */

echo "ok";

?>