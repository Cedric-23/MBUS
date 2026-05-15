<?php
session_start();

if(isset($_POST['verify'])){

$code = $_POST['code'];

if($code == $_SESSION['reset_code']){

$_SESSION['verified'] = true;

header("Location: new_password.php");

}else{
echo "<script>alert('Invalid code');</script>";
}
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Verify Code</title>

<link rel="stylesheet" href="auth.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<div class="container">

<div class="auth-box">

<h2>Enter Code</h2>

<form method="POST">

<div class="input-group">
<i class="fa-solid fa-key"></i>
<input type="text" name="code" placeholder="Enter 6-digit code" required>
</div>

<button type="submit" name="verify">Verify Code</button>

</form>

</div>

</div>

</body>
</html>