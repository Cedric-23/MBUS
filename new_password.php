<?php
session_start();
include "config/db_connect.php";
include "Includes/csrf.php";

if(!isset($_SESSION['verified'])){
    header("Location: forgot_password.php");
    exit();
}

$error = '';
$success = '';

if(isset($_POST['reset'])){
    if(!csrf_validate_token()){
        $error = 'Security validation failed. Please try again.';
    } else {
        $password = $_POST['password'];
        $confirm = $_POST['confirm_password'];

        if($password != $confirm){
            $error = 'Passwords do not match';
        } elseif(strlen($password) < 8){
            $error = 'Password must be at least 8 characters';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $email = $_SESSION['reset_email'];

            // Use prepared statement
            mbus_db_prepare($conn, "
                UPDATE users
                SET password = ?,
                reset_code = NULL,
                reset_expiry = NULL
                WHERE email = ?", [$hashed, $email]);

            session_destroy();
            $success = 'Password updated successfully';
            
            // Redirect after short delay via meta refresh
            header("refresh:2;url=login.php");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Password</title>

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
            <h3>New Password</h3>
            <p>Enter your new password</p>
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
                <label for="password">New Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="New Password (min 8 chars)"
                    required
                    autocomplete="new-password"
                    minlength="8"
                    aria-describedby="password-hint"
                >
                <i class="fa-solid fa-lock form-field-icon" aria-hidden="true"></i>
            </div>

            <div class="form-field">
                <label for="confirm_password">Confirm Password</label>
                <input 
                    type="password" 
                    id="confirm_password" 
                    name="confirm_password" 
                    placeholder="Confirm Password"
                    required
                    autocomplete="new-password"
                    aria-describedby="confirm-hint"
                >
                <i class="fa-solid fa-lock form-field-icon" aria-hidden="true"></i>
            </div>

            <button 
                type="submit" 
                name="reset" 
                class="btn btn-primary btn-block login-submit"
            >
                <i class="fa-solid fa-key" aria-hidden="true"></i>
                Update Password
            </button>
        </form>

        <div class="login-footer">
            <p><a href="login.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Back to Login</a></p>
        </div>
    </div>

</div>
</body>
</html>