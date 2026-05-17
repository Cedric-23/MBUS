<?php
session_start();
include "config/db_connect.php";
include "Includes/activity_log.php";

/* HANDLE OPERATOR APPLICATION SUBMISSION */
if(isset($_POST['submit_application'])){
    $full_name = mbus_db_escape($conn, $_POST['full_name']);
    $email = mbus_db_escape($conn, $_POST['email']);
    $phone_number = mbus_db_escape($conn, $_POST['phone_number']);
    $address = mbus_db_escape($conn, $_POST['address']);
    $license_number = mbus_db_escape($conn, $_POST['license_number']);
    $license_expiry = mbus_db_escape($conn, $_POST['license_expiry']);
    $vehicle_type = mbus_db_escape($conn, $_POST['vehicle_type']);
    $vehicle_plate_number = mbus_db_escape($conn, $_POST['vehicle_plate_number']);
    
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply as Operator - MBUS</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="Assets/css/modern_theme.css">
    <link rel="stylesheet" href="Assets/css/operator_application.css">
</head>
<body>

<div class="application-container">
    <div class="application-form glass-card">
        <div class="form-header">
            <img src="Assets/images/mbus_logo.png" alt="MBUS Logo" class="logo">
            <h1>Apply as Driver/Operator</h1>
            <p>Join our team and help provide safe and reliable transportation services.</p>
        </div>
        
        <?php if(isset($success)): ?>
            <div class="notice success">
                <i class="fa fa-check-circle"></i>
                <?php echo $success; ?>
                <br><br>
                <a href="login.php" class="btn-modern btn-primary">Go to Login</a>
            </div>
        <?php else: ?>
        
        <?php if(isset($error)): ?>
            <div class="notice error">
                <i class="fa fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" class="operator-form">
            <h2><i class="fa fa-user"></i> Personal Information</h2>
            
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" class="input-modern" required placeholder="Enter your full name">
            </div>
            
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="input-modern" required placeholder="Enter your email">
            </div>
            
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone_number" class="input-modern" required placeholder="Enter your phone number">
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" class="input-modern" required placeholder="Enter your complete address" rows="3"></textarea>
            </div>
            
            <h2><i class="fa fa-id-card"></i> License Information</h2>
            
            <div class="form-group">
                <label>License Number</label>
                <input type="text" name="license_number" class="input-modern" required placeholder="Enter your license number">
            </div>
            
            <div class="form-group">
                <label>License Expiry Date</label>
                <input type="date" name="license_expiry" class="input-modern" required>
            </div>
            
            <div class="form-group">
                <label>Upload License Document</label>
                <input type="file" name="license_document" class="input-modern" accept=".jpg,.jpeg,.png,.pdf" required>
                <small>Accepted formats: JPG, JPEG, PNG, PDF</small>
            </div>
            
            <h2><i class="fa fa-bus"></i> Vehicle Information</h2>
            
            <div class="form-group">
                <label>Vehicle Type</label>
                <select name="vehicle_type" class="input-modern" required>
                    <option value="">Select Vehicle Type</option>
                    <option value="Bus">Bus</option>
                    <option value="Van">Van</option>
                    <option value="Minibus">Minibus</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Vehicle Plate Number</label>
                <input type="text" name="vehicle_plate_number" class="input-modern" required placeholder="Enter vehicle plate number">
            </div>
            
            <div class="form-group">
                <label>Upload Vehicle Registration Document</label>
                <input type="file" name="vehicle_document" class="input-modern" accept=".jpg,.jpeg,.png,.pdf" required>
                <small>Accepted formats: JPG, JPEG, PNG, PDF</small>
            </div>
            
            <div class="terms-agreement">
                <label class="checkbox-label">
                    <input type="checkbox" required>
                    <span>I agree to the terms and conditions and certify that all information provided is accurate.</span>
                </label>
            </div>
            
            <button type="submit" name="submit_application" class="btn-modern btn-primary">
                <i class="fa fa-paper-plane"></i> Submit Application
            </button>
        </form>
        
        <div class="form-footer">
            <p>Already have an account? <a href="login.php">Login here</a></p>
            <p>Want to book a ride? <a href="register.php">Register as Commuter</a></p>
        </div>
        
        <?php endif; ?>
    </div>
</div>

</body>
</html>
