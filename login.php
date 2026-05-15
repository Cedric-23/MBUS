<?php

session_start();
include "config/db_connect.php";

if(isset($_POST['login'])){

    $email = mbus_db_escape($conn, trim($_POST['email']));
    $password = trim($_POST['password']);

    $sql = "SELECT * FROM users WHERE email='$email'";
    $result = mbus_db_query($conn, $sql);

    if(mbus_db_num_rows($result) > 0){

        $user = mbus_db_fetch_assoc($result);

        if(password_verify($password, $user['password'])){

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_type'] = strtolower($user['user_type']);

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
                echo "<script>alert('Invalid user type');</script>";
            }

        } else {
            echo "<script>alert('Incorrect password');</script>";
        }

    } else {
        echo "<script>alert('User not found');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>MBUS Login</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link rel="stylesheet" href="login.css">
</head>

<body>

<div class="container">

    <!-- LEFT -->
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

    <!-- RIGHT -->
    <div class="right">

        <div class="register-box">

            <h2>Login</h2>

            <form method="POST">

                <div class="input-group">
                    <i class="fa-solid fa-envelope"></i>
                    <input type="email" name="email" placeholder="Email" required>
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

                <button type="submit" name="login">
                    Login
                </button>

            </form>

            <div class="login-link">
                <a href="register.php">Don't have an account? Register</a>
                <br>
                <a href="forgot_password.php">Forgot Password?</a>
            </div>

        </div>

    </div>

</div>

<script src="register.js"></script>

</body>
</html>