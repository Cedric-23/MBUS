<?php
// session_start();
// include "config/db_connect.php";

// if (isset($_POST['register'])) {
//     $full_name = mbus_db_escape($conn, trim($_POST['full_name']));
//     $email = mbus_db_escape($conn, trim($_POST['email']));
//     $phone = mbus_db_escape($conn, trim($_POST['phone_number']));
//     $password = $_POST['password'] ?? '';
//     $confirm = $_POST['confirm_password'] ?? '';

//     if ($password !== $confirm) {
//         $error = 'Passwords do not match.';
//     } elseif (strlen($password) < 8) {
//         $error = 'Password must be at least 8 characters.';
//     } else {
//         $check = mbus_db_query($conn, "SELECT user_id FROM users WHERE email='$email'");
//         if (mbus_db_num_rows($check) > 0) {
//             $error = 'Email is already registered.';
//         } else {
//             $hash = password_hash($password, PASSWORD_DEFAULT);
//             $hash = mbus_db_escape($conn, $hash);
//             mbus_db_query($conn, "
//                 INSERT INTO users (full_name, email, phone_number, password, user_type)
//                 VALUES ('$full_name', '$email', '$phone', '$hash', 'Commuter')
//             ");
//             header('Location: login.php?registered=1');
//             exit();
//         }
//     }
// }
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>MBUS</title>
        <link rel="stylesheet" href="../Assets/css/global/font.css"/>
        <link rel="stylesheet" href="../Assets/css/main/register.css"/>
        <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
        <script src="register.js"></script>
    </head>
    <body>
        <div class="left-container">
            <div class="left-container-child-1">
                <div class="logo-wordmark">
                    <img src="../Assets/images/TRANSPARENT_MBUS.svg" alt="logo">
                </div>
            </div>
            <div class="left-container-child-2">
                <div class="lc-heading">
                    <h1>Travel smarter.</h1>
                    <h1>Travel on time.</h1>
                </div>
                <div class="lc-sub-heading">
                    <p>Your dependable way to reserve seats, manage trips, and <br> travel with confidence.</p>
                </div>
            </div>
            <div class="lc-circle-1"></div>
            <div class="lc-circle-2"></div>
        </div>
        <div class="right-container">
            <div class="form-container">
                <div class="form-header">
                    <div class="form-header-text">
                        <h1>Create your Account</h1>
                        <p>Plan your next bus trip in just a few steps.</p>
                    </div>
                    <div class="form-header-picker">
                        <div class="picker-container">
                            <div class="picker-passenger">
                                <p>Passenger</p>
                            </div>
                            <div class="picker-driver">
                                <p>Driver/Operator</p>
                            </div>
                        </div>
                        <div class="picker-container-text">
                            <span>Book seats and keep all your trips in one place</span>
                        </div>
                    </div>
                </div>
                <form class="form-body">
                    <div class="form-name-input">
                        <div class="fn-input">
                            <label>First Name</label>
                            <input  class="fn-input-i" type="text" placeholder="Juan" required/>
                            <i class="input-icon" data-lucide="user"></i>
                        </div>
                        <div class="ln-input">
                            <label>Last Name</label>
                            <input type="text"  placeholder="Dela Cruz" required/>
                            <i class="input-icon" data-lucide="user"></i>
                        </div>
                    </div>
                    <div class="form-email-input">
                            <label>Email Address</label>
                            <input type="text" placeholder="juan@example.com" requred/>
                            <i class="input-icon" data-lucide="mail"></i>
                    </div>
                    <div class="form-phone-input">
                            <label>Mobile Number</label>
                            <input type="text" placeholder="+63 09xx xxx xxxx" requred/>
                            <i class="input-icon" data-lucide="phone"></i>
                    </div>
                    <div class="form-password-input">
                        <div class="password-input">
                            <label>Password</label>
                            <input type="password" placeholder="Atleast 8 characters" requred/>
                            <i class="input-icon" data-lucide="lock"></i>
                        </div>
                        <div class="confirm-password-input">
                            <label>Confirm Password</label>
                            <input type="password" placeholder="Repeat Password" required/>
                            <i class="input-icon" data-lucide="lock"></i>
                        </div>
                    </div>
                    <div class="form-tos-check">
                        <input type="checkbox"/>
                        <label class="tos">I agree to the<a style="color:#086FCF;"> Terms of Service </a> and <a style="color:#086FCF;"> Private Policy. </a> </label>
                    </div>
                    <button class="submit-button" type="submit">Create passenger account</button>
                </form>
                <div class="footer">
                    <p>Already have an account? <a style="color: #086FCF; text-decoration: none;" href="../login.php">Sign In</a></p>
                    <p >Need Help?<a style="color: #086FCF;"> Contact MBUS Support </a> </p>
                </div>
        </div>
        </div>
        <script>
            lucide.createIcons()
        </script>
    </body>
</html>