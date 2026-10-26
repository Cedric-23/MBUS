<?php
session_start();
include "config/db_connect.php";
include "Includes/csrf.php";

$error = '';
$success = '';

if (isset($_POST['register'])) {
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = 'Security validation failed. Please try again.';
    } else {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone_number']);
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } elseif (!preg_match('/^09\d{9}$/', $phone)) {
            $error = 'Phone number must be 11 digits starting with 09 (e.g., 09123456789).';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            // Use prepared statement to check if email exists
            $check = mbus_db_prepare($conn, "SELECT user_id FROM users WHERE email = ?", [$email]);
            if (mbus_db_num_rows($check) > 0) {
                $error = 'Email is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                // Use prepared statement for insert
                mbus_db_prepare($conn, "
                    INSERT INTO users (full_name, email, phone_number, password, user_type)
                    VALUES (?, ?, ?, ?, 'Commuter')
                ", [$full_name, $email, $phone, $hash]);
                header('Location: login.php?registered=1');
                exit();
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

<title>MBUS Register</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="Assets/css/responsive.css">
<link rel="stylesheet" href="Assets/css/register.css">
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
            <h3>Create Account</h3>
            <p>Join MBUS and start booking your rides</p>
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
                <label for="full_name">Full Name</label>
                <input 
                    type="text" 
                    id="full_name" 
                    name="full_name" 
                    placeholder="Enter your full name"
                    required
                    autocomplete="name"
                    aria-describedby="name-hint"
                >
                <i class="fa-solid fa-user form-field-icon" aria-hidden="true"></i>
            </div>

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
                <label for="phone_number">Phone Number</label>
                <input 
                    type="tel" 
                    id="phone_number" 
                    name="phone_number" 
                    placeholder="09XXXXXXXXX"
                    pattern="09\d{9}"
                    maxlength="11"
                    required
                    autocomplete="tel"
                    aria-describedby="phone-hint"
                >
                <i class="fa-solid fa-phone form-field-icon" aria-hidden="true"></i>
            </div>

            <div class="form-field">
                <label for="password">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="Create a password (min 8 chars)"
                    required
                    autocomplete="new-password"
                    aria-describedby="password-hint"
                    minlength="8"
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

            <div class="form-field">
                <label for="confirm_password">Confirm Password</label>
                <input 
                    type="password" 
                    id="confirm_password" 
                    name="confirm_password" 
                    placeholder="Confirm your password"
                    required
                    autocomplete="new-password"
                    aria-describedby="confirm-hint"
                >
                <i class="fa-solid fa-lock form-field-icon" aria-hidden="true"></i>
                <button 
                    type="button" 
                    class="password-toggle" 
                    aria-label="Toggle password visibility"
                    aria-pressed="false"
                    onmousedown="showPassword('confirm_password')"
                    onmouseup="hidePassword('confirm_password')"
                    onmouseleave="hidePassword('confirm_password')"
                >
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                </button>
            </div>

            <button 
                            type="submit" 
                            name="register" 
                            class="btn btn-primary btn-block login-submit"
                        >
                            Register
                        </button>
        </form>

        <div class="login-footer">
            <p>Already have an account? <a href="login.php">Login</a></p>
            <p class="operator-link"><a href="apply_operator.php"><i class="fa fa-bus" aria-hidden="true"></i> Apply as Driver/Operator</a></p>
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
    const password = document.getElementById('password');
    const confirm = document.getElementById('confirm_password');
    
    if (form && submitBtn) {
        form.addEventListener('submit', function(e) {
            const fullName = document.getElementById('full_name').value.trim();
            const email = document.getElementById('email').value.trim();
            const phone = document.getElementById('phone_number').value.trim();
            const pwd = password.value;
            const conf = confirm.value;
            
            if (!fullName || !email || !phone || !pwd || !conf) {
                e.preventDefault();
                return;
            }
            
            if (pwd !== conf) {
                e.preventDefault();
                confirm.setCustomValidity('Passwords do not match');
                confirm.reportValidity();
                return;
            }
            
            if (pwd.length < 8) {
                e.preventDefault();
                password.setCustomValidity('Password must be at least 8 characters');
                password.reportValidity();
                return;
            }
            
            if (!/^09\d{9}$/.test(phone)) {
                e.preventDefault();
                document.getElementById('phone_number').setCustomValidity('Phone must be 11 digits starting with 09');
                document.getElementById('phone_number').reportValidity();
                return;
            }
            
            // Show loading state
            submitBtn.classList.add('loading');
            submitBtn.innerHTML = '<span class="visually-hidden">Creating account...</span>';
        });
    }
    
    // Real-time password match check
    if (password && confirm) {
        confirm.addEventListener('input', function() {
            if (this.value && this.value !== password.value) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    }
    
    // Auto-focus first field
    const nameField = document.getElementById('full_name');
    if (nameField) {
        nameField.focus();
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

.operator-link {
    margin-top: var(--space-4) !important;
    padding-top: var(--space-4);
    border-top: 1px solid var(--color-border-light);
}

.operator-link a {
    color: var(--color-accent) !important;
    font-weight: var(--fw-medium);
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
}

.operator-link a:hover {
    color: var(--color-primary) !important;
}
</script>

</body>
</html>