<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$status = "Sending...";

try{

$mail = new PHPMailer(true);

$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';
$mail->SMTPAuth = true;
$mail->Username = 'YOUR_EMAIL@gmail.com';
$mail->Password = 'APP_PASSWORD';
$mail->SMTPSecure = 'ssl';
$mail->Port = 465;

$mail->setFrom('YOUR_EMAIL@gmail.com', 'MBUS System');
$mail->addAddress($_SESSION['reset_email']);

$mail->Subject = 'MBUS Password Reset Code';
$mail->Body = "Your reset code is: ".$_SESSION['reset_code'];

$mail->send();

$status = "Code Sent Successfully";

}catch(Exception $e){
$status = "Failed to send email";
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Sending Code</title>

<link rel="stylesheet" href="auth.css">

<style>

/* LOADING SPINNER */
.loader{
border:5px solid #eee;
border-top:5px solid #1e3a5f;
border-radius:50%;
width:40px;
height:40px;
animation:spin 1s linear infinite;
margin:20px auto;
}

@keyframes spin{
0%{transform:rotate(0deg);}
100%{transform:rotate(360deg);}
}

.status{
text-align:center;
margin-top:10px;
color:#1e3a5f;
font-weight:500;
}

</style>

</head>

<body>

<div class="container">

<div class="auth-box">

<h2>Processing</h2>

<div class="loader"></div>

<div class="status">
<?php echo $status; ?>
</div>

</div>

</div>

<?php if($status == "Code Sent Successfully"){ ?>

<script>
setTimeout(()=>{
window.location = "verify_code.php";
},2000);
</script>

<?php } ?>

</body>
</html>