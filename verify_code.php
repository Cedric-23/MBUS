<?php
session_start();
include "config/db_connect.php";
include "Includes/csrf.php";

$error = '';
$success = '';

if(isset($_POST['verify'])){
    if(!csrf_validate_token()){
        $error = 'Security validation failed. Please try again.';
    } else {
        $code = trim($_POST['code']);

        // Validate code format (6 digits)
        if(!preg_match('/^\d{6}$/', $code)){
            $error = 'Invalid code format. Enter 6 digits.';
        } elseif(!isset($_SESSION['reset_email'])){
            $error = 'Session expired. Please try again.';
        } else {
            // Verify code against database
            $email = $_SESSION['reset_email'];
            $result = mbus_db_prepare($conn, "SELECT reset_code, reset_expiry FROM users WHERE email = ?", [$email]);
            $user = mbus_db_fetch_assoc($result);

            if($user && $user['reset_code'] == $code && strtotime($user['reset_expiry']) > time()){
                $_SESSION['verified'] = true;
                header("Location: new_password.php");
                exit();
            } else {
                $error = 'Invalid or expired code.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="Assets/css/responsive.css">
    <link rel="stylesheet" href="login.css">
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
            <h3>Enter Code</h3>
            <p>We sent a 6-digit code to your email</p>
        </div>

        <?php if($error): ?>
            <div class="login-alert login-alert-error">
                <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="login-alert login-alert-success">
                <i class="fa fa-check-circle" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form" novalidate>
            <?php csrf_field(); ?>

            <div class="form-field">
                <label for="code">6-Digit Code</label>
                <input 
                    type="text" 
                    id="code" 
                    name="code" 
                    placeholder="Enter 6-digit code"
                    required
                    maxlength="6"
                    pattern="\d{6}"
                    autocomplete="one-time-code"
                    aria-describedby="code-hint"
                >
                <i class="fa-solid fa-key form-field-icon" aria-hidden="true"></i>
            </div>

            <button 
                type="submit" 
                name="verify" 
                class="btn btn-primary btn-block login-submit"
            >
                <i class="fa-solid fa-check" aria-hidden="true"></i>
                Verify Code
            </button>
        </form>

        <div class="login-footer">
            <p><a href="forgot_password.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Back</a></p>
        </div>
    </div>

</div>
</body>
</html>