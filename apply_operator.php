<?php
session_start();
include "config/db_connect.php";
include "Includes/activity_log.php";

/* HANDLE OPERATOR APPLICATION SUBMISSION */
if(isset($_POST['submit_application'])){
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone_number = trim($_POST['phone_number']);
    $address = trim($_POST['address']);
    $license_number = trim($_POST['license_number']);
    $license_expiry = trim($_POST['license_expiry']);
    $vehicle_type = trim($_POST['vehicle_type']);
    $vehicle_plate_number = trim($_POST['vehicle_plate_number']);
    
    // Validate phone number
    if(!preg_match('/^09\d{9}$/', $phone_number)){
        $error = "Phone number must be 11 digits starting with 09 (e.g., 09123456789).";
    } else {
        $full_name = mbus_db_escape($conn, $full_name);
        $email = mbus_db_escape($conn, $email);
        $phone_number = mbus_db_escape($conn, $phone_number);
        $address = mbus_db_escape($conn, $address);
        $license_number = mbus_db_escape($conn, $license_number);
        $license_expiry = mbus_db_escape($conn, $license_expiry);
        $vehicle_type = mbus_db_escape($conn, $vehicle_type);
        $vehicle_plate_number = mbus_db_escape($conn, $vehicle_plate_number);
    
    // Handle license document upload
    $license_document = '';
    if(isset($_FILES['license_document']) && $_FILES['license_document']['error'] == 0){
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $filename = $_FILES['license_document']['name'];
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if(in_array($file_ext, $allowed)){
            $new_filename = "license_" . time() . "_" . rand(1000,9999) . "." . $file_ext;
            $upload_path = "Assets/operator_documents/" . $new_filename;
            
            if(!file_exists("Assets/operator_documents/")){
                mkdir("Assets/operator_documents/", 0777, true);
            }
            
            if(move_uploaded_file($_FILES['license_document']['tmp_name'], $upload_path)){
                $license_document = $new_filename;
            }
        }
    }
    
    // Handle vehicle document upload
    $vehicle_document = '';
    if(isset($_FILES['vehicle_document']) && $_FILES['vehicle_document']['error'] == 0){
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $filename = $_FILES['vehicle_document']['name'];
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if(in_array($file_ext, $allowed)){
            $new_filename = "vehicle_" . time() . "_" . rand(1000,9999) . "." . $file_ext;
            $upload_path = "Assets/operator_documents/" . $new_filename;
            
            if(!file_exists("Assets/operator_documents/")){
                mkdir("Assets/operator_documents/", 0777, true);
            }
            
            if(move_uploaded_file($_FILES['vehicle_document']['tmp_name'], $upload_path)){
                $vehicle_document = $new_filename;
            }
        }
    }
    
    // Insert application
    mbus_db_query($conn,"
        INSERT INTO operator_application (full_name, email, phone_number, address, license_number, license_expiry, vehicle_type, vehicle_plate_number, license_document_path, vehicle_document_path)
        VALUES ('$full_name', '$email', '$phone_number', '$address', '$license_number', '$license_expiry', '$vehicle_type', '$vehicle_plate_number', '$license_document', '$vehicle_document')
    ");
    
    $success = "Application submitted successfully. Please wait for admin approval.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply as Operator - MBUS</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="Assets/css/responsive.css">
    <link rel="stylesheet" href="login.css">
    <link rel="stylesheet" href="Assets/css/auth.css">
</head>
<body class="login-page">
<div class="login-container">

    <div class="login-brand">
        <img src="Assets/images/mbus_icon.png" alt="MBUS Logo" class="login-logo">
        <div class="login-brand-text"><h2>Morong - SBMA Bus Reservation</h2></div>
    </div>

    <div class="login-card">
        <div class="login-header">
            <h3>Apply as Operator</h3>
            <p>Tell us about you and your vehicle</p>
        </div>

        <?php if(isset($success)): ?>
            <div class="login-alert login-alert-success" role="status"><i class="fa fa-check-circle" aria-hidden="true"></i><span><?php echo $success; ?></span></div>
            <a href="login.php" class="btn-block" style="display:grid;place-items:center;text-decoration:none;">Go to Login</a>
        <?php else: ?>

        <?php if(isset($error)): ?>
            <div class="login-alert login-alert-error" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i><span><?php echo $error; ?></span></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="login-form">
            <div class="auth-section">Personal information</div>
            <div class="form-field"><i class="fa fa-user form-field-icon" aria-hidden="true"></i>
                <input type="text" name="full_name" required placeholder="Full name" autocomplete="name"></div>
            <div class="form-field"><i class="fa fa-envelope form-field-icon" aria-hidden="true"></i>
                <input type="email" name="email" required placeholder="Email" autocomplete="email" autocapitalize="none"></div>
            <div class="form-field"><i class="fa fa-phone form-field-icon" aria-hidden="true"></i>
                <input type="tel" name="phone_number" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX" required inputmode="tel" autocomplete="tel"></div>
            <div class="form-field plain"><textarea name="address" required placeholder="Complete address" rows="3" aria-label="Complete address"></textarea></div>

            <div class="auth-section">License</div>
            <div class="form-field"><i class="fa fa-id-card form-field-icon" aria-hidden="true"></i>
                <input type="text" name="license_number" required placeholder="License number"></div>
            <div class="form-field plain"><label class="auth-label" for="licExp">License expiry date</label>
                <input type="date" name="license_expiry" id="licExp" required></div>
            <div class="form-field plain"><label class="auth-label" for="licDoc">License photo or PDF</label>
                <input type="file" name="license_document" id="licDoc" accept=".jpg,.jpeg,.png,.pdf" required></div>

            <div class="auth-section">Vehicle</div>
            <div class="form-field plain"><select name="vehicle_type" required aria-label="Vehicle type">
                    <option value="">Select Vehicle Type</option>
                    <option value="Bus">Bus</option>
                    <option value="Van">Van</option>
                    <option value="Minibus">Minibus</option>
                
            </select></div>
            <div class="form-field"><i class="fa fa-bus form-field-icon" aria-hidden="true"></i>
                <input type="text" name="vehicle_plate_number" required placeholder="Plate number"></div>
            <div class="form-field plain"><label class="auth-label" for="vehDoc">Vehicle registration (photo or PDF)</label>
                <input type="file" name="vehicle_document" id="vehDoc" accept=".jpg,.jpeg,.png,.pdf" required></div>

            <label class="auth-check"><input type="checkbox" required><span>I agree to the terms and certify that the information is accurate.</span></label>

            <button type="submit" name="submit_application" class="btn-block">Submit application</button>
        </form>

        <div class="login-footer">
            <p>Already have an account? <a href="login.php">Login</a></p>
            <p>Want to book a ride? <a href="register.php">Register as commuter</a></p>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
