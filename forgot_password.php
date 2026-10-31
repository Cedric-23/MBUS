<?php
session_start();
include "config/db_connect.php";
include "Includes/csrf.php";

$error = '';
$success = '';

if(isset($_POST['send_code'])){
    if(!csrf_validate_token()){
        $error = 'Security validation failed. Please try again.';
    } else {
        $email = trim($_POST['email']);

        // Validate email format
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            $error = 'Invalid email format';
        } else {
            // Use prepared statement
            $result = mbus_db_prepare($conn, "SELECT * FROM users WHERE email = ?", [$email]);

            if(mbus_db_num_rows($result) > 0){
                $code = rand(100000,999999);
                $expiry = date("Y-m-d H:i:s", strtotime("+15 minutes"));

                // Use prepared statement for update
                mbus_db_prepare($conn, "UPDATE users
                SET reset_code = ?, reset_expiry = ?
                WHERE email = ?", [$code, $expiry, $email]);

                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_code'] = $code;

                header("Location: send_reset_code.php");
                exit();
            }else{
                $error = 'Email not found';
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
    <title>Forgot Password</title>

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
            <h3>Forgot Password</h3>
            <p>Enter your email to receive a reset code</p>
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
                <label for="email">Email Address</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    placeholder="Enter your email"
                    required
                    autocomplete="email"
                    aria-describedby="email-hint"
                >
                <i class="fa-solid fa-envelope form-field-icon" aria-hidden="true"></i>
            </div>

            <button 
                type="submit" 
                name="send_code" 
                class="btn btn-primary btn-block login-submit"
            >
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                Send Reset Code
            </button>
        </form>

        <div class="login-footer">
            <p><a href="login.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Back to Login</a></p>
        </div>
    </div>

</div>
</body>
</html>