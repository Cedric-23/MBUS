<?php

session_start();
include "config/db_connect.php";
include "Includes/activity_log.php";
include "Includes/csrf.php";

$csrf_error = '';
$login_error = '';

if(isset($_POST['login'])){

    // Validate CSRF token
    if(!csrf_validate_token()){
        $csrf_error = 'Security validation failed. Please try again.';
    } else {
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);

        // Use prepared statement to prevent SQL injection
        $sql = "SELECT * FROM users WHERE email = ?";
        $result = mbus_db_prepare($conn, $sql, [$email]);

        if(mbus_db_num_rows($result) > 0){

        $user = mbus_db_fetch_assoc($result);

        if(password_verify($password, $user['password'])){

            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_type'] = strtolower($user['user_type']);

            // Log successful login
            log_activity($conn, $user['user_id'], ACTION_LOGIN, "User logged in successfully");

            if($_SESSION['user_type'] == "commuter"){
                header("Location: Commuter/commuter_dashboard.php");
                exit();
            }
            elseif($_SESSION['user_type'] == "operator"){
                header("Location: Operator/operator_dashboard.php");
                exit();
            }
            elseif($_SESSION['user_type'] == "admin"){
                header("Location: Admin/admin_dashboard.php");
                exit();
            }
            else{
                $login_error = 'Invalid user type';
            }

        } else {
            $login_error = 'Incorrect password';
        }

        } else {
            $login_error = 'User not found';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>MBUS Login</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
            <h3>Welcome Back</h3>
            <p>Sign in to your account to continue</p>
        </div>

        <?php if($csrf_error): ?>
            <div class="login-alert login-alert-error">
                <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($csrf_error); ?></span>
            </div>
        <?php endif; ?>

        <?php if($login_error): ?>
            <div class="login-alert login-alert-error">
                <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($login_error); ?></span>
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

            <div class="form-field">
                <label for="password">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="Enter your password"
                    required
                    autocomplete="current-password"
                    aria-describedby="password-hint"
                >
                <i class="fa-solid fa-lock form-field-icon" aria-hidden="true"></i>
                <button 
                    type="button" 
                    class="password-toggle" 
                    aria-label="Toggle password visibility"
                    aria-pressed="false"
                    onmousedown="showPassword('password')"
                    onmouseup="hidePassword('password')"
                    onmouseleave="hidePassword('password')"
                >
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                </button>
            </div>

                        <button 
                            type="submit" 
                            name="login" 
                            class="btn btn-primary btn-block login-submit"
                        >
                            Login
                        </button>
        </form>

                <div class="login-footer">
                    <p><a href="forgot_password.php" class="forgot-link">Forgot Password?</a></p>
                    <p>Don't have an account? <a href="register.php">Register</a></p>
                </div>
    </div>

</div>

<script src="register.js"></script>

<script>
// Password visibility toggle
function showPassword(fieldId) {
    const field = document.getElementById(fieldId);
    const toggle = field.parentElement.querySelector('.password-toggle');
    if (field && toggle) {
        field.type = 'text';
        toggle.innerHTML = '<i class="fa-solid fa-eye-slash" aria-hidden="true"></i>';
        toggle.setAttribute('aria-pressed', 'true');
    }
}

function hidePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const toggle = field.parentElement.querySelector('.password-toggle');
    if (field && toggle) {
        field.type = 'password';
        toggle.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
        toggle.setAttribute('aria-pressed', 'false');
    }
}

// Form validation feedback
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.login-form');
    const submitBtn = document.querySelector('.login-submit');
    
    if (form && submitBtn) {
        form.addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            
            if (!email || !password) {
                e.preventDefault();
                return;
            }
            
            // Show loading state
            submitBtn.classList.add('loading');
            submitBtn.innerHTML = '<span class="visually-hidden">Signing in...</span>';
        });
    }
    
    // Auto-focus email field
    const emailField = document.getElementById('email');
    if (emailField) {
        emailField.focus();
    }
});
</script>

<style>
.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</script>

</body>
</html>