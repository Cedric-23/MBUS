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
    $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = getenv('SMTP_USERNAME') ?: 'YOUR_EMAIL@gmail.com';
    $mail->Password = getenv('SMTP_PASSWORD') ?: 'APP_PASSWORD';
    $mail->SMTPSecure = getenv('SMTP_SECURE') ?: 'ssl';
    $mail->Port = getenv('SMTP_PORT') ?: 465;

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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sending Code</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="Assets/css/responsive.css">
    <link rel="stylesheet" href="login.css">

    <style>
    /* LOADING SPINNER */
    .loader{
        border:5px solid #eee;
        border-top:5px solid var(--color-primary);
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
        color:var(--color-primary);
        font-weight:500;
    }
    </style>
</head>

<body class="login-page">
<div class="login-container">
    
    <div class="login-brand">
        <img src="Assets/images/mbus_logo.png" alt="MBUS Logo" class="login-logo">
        <div class="login-brand-text">
            <h2>Morong - SBMA Bus Reservation</h2>
            <p>Fast. Reliable. Connected.</p>
        </div>
    </div>

    <div class="login-card">
        <div class="login-header">
            <h3>Processing</h3>
        </div>

        <div class="loader"></div>

        <div class="status">
            <?php echo htmlspecialchars($status); ?>
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