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
                        <div id="option-bar" class="option-bar"></div>
                        <div class="picker-container">
                            <div id="option-passenger" class="picker-passenger">
                                <p>Passenger</p>
                            </div>
                            <div id="option-driver" class="picker-driver">
                                <p>Driver/Operator</p>
                            </div>
                        </div>
                        <div class="picker-container-text">
                            <span>Book seats and keep all your trips in one place</span>
                        </div>
                    </div>
                </div>
                <form class="form-body">
                    <div class="passenger" id="passenger">
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
                        <div class="footer">
                            <p>Already have an account? <a style="color: #086FCF; text-decoration: none;" href="../login.php">Sign In</a></p>
                            <p >Need Help?<a style="color: #086FCF;"> Contact MBUS Support </a> </p>
                        </div>
                    </div>
                    <div class="driver" id="driver">
                        <div class="driver-input-group">
                            <div class="group-header">
                                <div class="group-count">1</div>
                                <p class="group-name">Personal Information</p>
                            </div>
                            <div class="form-inputs">
                                <div class="input-group">
                                    <div class="full-name">
                                        <label class="input-label">Full Name (LN, FN, MI)</label>
                                        <input type="text" placeholder="Enter your full name" required/>
                                        <i class="input-icon" data-lucide="user"></i>
                                    </div>
                                    <div class="email-address">
                                        <label class="input-label">Email Address</label>
                                        <input type="email" placeholder="Enter your email" required/>
                                        <i class="input-icon" data-lucide="mail"></i>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <div class="phone-number">
                                        <label>Phone Number</label>
                                        <input type="text" placeholder="Enter your phone number" required></input>
                                        <i class="input-icon" data-lucide="phone"></i>
                                    </div>
                                    <div class="home-address">
                                        <label>Address</label>
                                        <input type="text" placeholder="Enter your home address" required></input>        
                                        <i class="input-icon" data-lucide="map-pin-house"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="driver-input-group">
                            <div class="group-header">
                                <div class="group-count">2</div>
                                <h3 class="group-name">License Information</h3>
                            </div>
                            <div class="form-inputs">
                                <div class="input-group">
                                    <div class="license-number">
                                        <label class="input-label">License Number</label>
                                        <input type="text" placeholder="Enter your license number" required/>
                                        <i class="input-icon" data-lucide="user"></i>
                                    </div>
                                    <div class="license-expiry">
                                        <label class="input-label">License Expiry Date (DD/MM/YYYY)</label>
                                        <input type="date" placeholder="Enter License Expiry" required/>                                
                                        <i class="input-icon" data-lucide="calendar"></i>
                                    </div>
                                </div>
                                <div class="upload-group">
                                    <label class="input-label">Upload License Document</label>
                                    <div class="upload-document-group">
                                        <div class="upload-icon">
                                            <i data-lucide="upload"></i>
                                        </div>
                                        <div class="document-desc">
                                            <span>Click to upload document</span>
                                            <span>JPG,JPEG, PNG, or PDF &middot; Max 5 MB</span>
                                        </div>
                                        <div class="document-input-button">
                                            <button type="button">Browse</button>
                                            <input type="file" accept=".jpg,.jpeg,.png,.pdf"/>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="driver-input-group">
                            <div class="group-header">
                                <div class="group-count">3</div>
                                <h3 class="group-name">Vehicle Information</h3>
                            </div>
                            <div class="form-inputs">
                                <div class="input-group">
                                    <div class="vehicle-type">
                                        <label class="input-label">Vehicle  Type</label>
                                        <select class="vehicle-selection">
                                            <option disabled selected>Select Vehicle Type</option>
                                            <option value="Bus">Bus</option>
                                            <option value="Van">Van</option>
                                            <option value="MiniBus">Mini Bus</option>
                                        </select>
                                    </div>
                                    <div class="vehicle-plate-number">
                                        <label class="input-label">Vehicle Plate Number</label>
                                        <input placeholder="Enter vehicle plate number"/>
                                        <i class="input-icon" data-lucide="user"></i>
                                    </div>
                                </div>
                                <div class="upload-group">
                                    <label class="input-label">Upload Vehicle Registration Document</label>
                                    <div class="upload-document-group">
                                        <div class="upload-icon">
                                            <i data-lucide="upload"></i>
                                        </div>
                                        <div class="document-desc">
                                            <span>Click to upload document</span>
                                            <span>JPG,JPEG, PNG, or PDF &middot; Max 5 MB</span>
                                        </div>
                                        <div class="document-input-button">
                                            <button type="button">Browse</button>
                                            <input type="file" accept=".jpg,.jpeg,.png,.pdf"/>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <footer class="driver-footer">
                            <div class="terms-conditions">
                                <input type="checkbox">
                                <p>I agree to the terms and conditions and certify that all information provide is accurate.</p>
                            </div>
                            <div class="button-container">
                                <button class="submit-button" type="submit">Submit Application</button>
                            </div>
                            <div class="redirect-footer">
                                <div class="accounts-redirect"">
                                    <p>Already have an account? <a style="color: #0877DF;">Login here</a></p>
                                    <p>Want to book a ride? <a style="color: #0877DF;"> Register as Commuter </a> </p>
                                </div>
                                <div class="contacts">
                                    <p>Need help?<a style="color: #0877DF;"> Contact MBUS support <a/> </p>
                                </div>
                            </div>
                        </footer>
                    </div>
                </form>
            </div>
        </div>
        <script src="register.js"></script>
        <script> lucide.createIcons() </script>
    </body>
</html>