<?php
require_once __DIR__ . '/config/app.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$status = "Sending...";
$status_detail = '';
$reset_email = (string)($_SESSION['reset_email'] ?? '');
$reset_code = (string)($_SESSION['reset_code'] ?? '');
if ($reset_email === '' || !preg_match('/^\d{6}$/', $reset_code)) {
    header('Location: forgot_password.php');
    exit();
}

try{
    $smtp_username = trim((string)getenv('SMTP_USERNAME'));
    $smtp_password = trim((string)getenv('SMTP_PASSWORD'));
    $smtp_from = trim((string)(getenv('SMTP_FROM') ?: $smtp_username));
    if ($smtp_username === '' || $smtp_password === '' || $smtp_from === '') {
        throw new RuntimeException('SMTP credentials are not configured in Render.');
    }

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $smtp_username;
    $mail->Password = $smtp_password;
    $mail->SMTPSecure = getenv('SMTP_SECURE') ?: 'ssl';
    $mail->Port = getenv('SMTP_PORT') ?: 465;

    $mail->setFrom($smtp_from, 'MBUS System');
    $mail->addAddress($reset_email);

    $mail->Subject = 'MBUS Password Reset Code';
    $mail->Body = "Your reset code is: " . $reset_code;

    $mail->send();

    $status = "Code Sent Successfully";

}catch(Exception $e){
    $status = "Failed to send email";
    $status_detail = 'Check the Render SMTP_USERNAME, SMTP_PASSWORD, and SMTP_FROM values, then try again.';
    error_log('Password reset email failed: ' . $e->getMessage());
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
    .status-error{color:#b42318}
    .status-detail,.status-back{text-align:center;font-size:.9rem;line-height:1.5}
    .status-detail{color:#667085;margin:12px 0 6px}
    .status-back{margin:0}.status-back a{color:var(--color-primary);font-weight:600}
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

        <div class="status <?php echo $status === 'Failed to send email' ? 'status-error' : ''; ?>">
            <?php echo htmlspecialchars($status); ?>
        </div>
        <?php if ($status_detail): ?>
            <p class="status-detail"><?php echo htmlspecialchars($status_detail); ?></p>
            <p class="status-back"><a href="forgot_password.php">Return to forgot password</a></p>
        <?php endif; ?>

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
