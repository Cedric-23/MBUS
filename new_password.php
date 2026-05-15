<?php
session_start();
include "config/db_connect.php";

if(!isset($_SESSION['verified'])){
header("Location: forgot_password.php");
exit();
}

if(isset($_POST['reset'])){

$password = $_POST['password'];
$confirm = $_POST['confirm_password'];

if($password != $confirm){

echo "<script>alert('Passwords do not match');</script>";

}else{

$hashed = password_hash($password, PASSWORD_DEFAULT);
$email = $_SESSION['reset_email'];

mbus_db_query($conn, "
UPDATE users 
SET password='$hashed',
reset_code=NULL,
reset_expiry=NULL
WHERE email='$email'
");

session_destroy();

echo "<script>
alert('Password updated successfully');
window.location='login.php';
</script>";
}
}
?>

<!DOCTYPE html>
<html>
<head>
<title>New Password</title>

<link rel="stylesheet" href="auth.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<div class="container">

<div class="auth-box">

<h2>New Password</h2>

<form method="POST">

<div class="input-group">
<i class="fa-solid fa-lock"></i>
<input type="password" name="password" placeholder="New Password" required>
</div>

<div class="input-group">
<i class="fa-solid fa-lock"></i>
<input type="password" name="confirm_password" placeholder="Confirm Password" required>
</div>

<button type="submit" name="reset">Update Password</button>

</form>

</div>

</div>

</body>
</html>