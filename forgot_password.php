<?php
session_start();
include "config/db_connect.php";

if(isset($_POST['send_code'])){

$email = $_POST['email'];

$result = mysqli_query($conn, "SELECT * FROM users WHERE email='$email'");

if(mysqli_num_rows($result) > 0){

$code = rand(100000,999999);
$expiry = date("Y-m-d H:i:s", strtotime("+15 minutes"));

mysqli_query($conn, "UPDATE users 
SET reset_code='$code', reset_expiry='$expiry'
WHERE email='$email'");

$_SESSION['reset_email'] = $email;
$_SESSION['reset_code'] = $code;

header("Location: send_reset_code.php");
exit();

}else{
echo "<script>alert('Email not found');</script>";
}
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Forgot Password</title>

<link rel="stylesheet" href="auth.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<div class="container">

<div class="auth-box">

<h2>Forgot Password</h2>

<form method="POST">

<div class="input-group">
<i class="fa-solid fa-envelope"></i>
<input type="email" name="email" placeholder="Enter your email" required>
</div>

<button type="submit" name="send_code">Send Reset Code</button>

</form>

<div class="link">
<a href="login.php">Back to Login</a>
</div>

</div>

</div>

</body>
</html>