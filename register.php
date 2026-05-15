<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>MBUS Register</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link rel="stylesheet" href="register.css">
</head>

<body>

<div class="container">

    <div class="left">
        <div class="left-content">

            <img src="Assets/images/mbus_logo.png">

            <div class="left-text">
                <h3>
                    Morong - SBMA Bus Reservation and Schedule System
                </h3>

                <p>
                    Fast. Reliable. Connected.
                </p>
            </div>

        </div>
    </div>

    <div class="right">

        <div class="register-box">

            <h2>Create Account</h2>

            <form method="POST">

                <div class="input-group">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" name="full_name" placeholder="Full Name" required>
                </div>

                <div class="input-group">
                    <i class="fa-solid fa-envelope"></i>
                    <input type="email" name="email" placeholder="Email" required>
                </div>

                <div class="input-group">
                    <i class="fa-solid fa-phone"></i>
                    <input type="text" name="phone_number" placeholder="Phone Number" required>
                </div>

                <div class="input-group">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="Password" required>

                    <i class="fa-solid fa-eye eye"
                       onmousedown="showPassword('password')"
                       onmouseup="hidePassword('password')"
                       onmouseleave="hidePassword('password')">
                    </i>
                </div>

                <div class="input-group">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" id="confirm" name="confirm_password" placeholder="Confirm Password" required>

                    <i class="fa-solid fa-eye eye"
                       onmousedown="showPassword('confirm')"
                       onmouseup="hidePassword('confirm')"
                       onmouseleave="hidePassword('confirm')">
                    </i>
                </div>

                <button type="submit" name="register">
                    Register
                </button>

            </form>

            <div class="login-link">
                <a href="login.php">Already have an account? Login</a>
            </div>

        </div>

    </div>

</div>

<script src="register.js"></script>

</body>
</html>