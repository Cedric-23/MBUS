<?php
session_start();
include "config/db_connect.php";
include "Includes/activity_log.php";

require_once "Includes/csrf.php";

/** Read an uploaded photo/PDF into base64 after checking its real type and size. */
function mbus_application_file(string $field, &$error): ?array {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) { $error = 'Please attach all required documents.'; return null; }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $error = 'A document could not be uploaded. Try a smaller file.'; return null; }
    if ($f['size'] > 3 * 1024 * 1024) { $error = 'Each document must be 3 MB or smaller.'; return null; }
    $data = file_get_contents($f['tmp_name']);
    $mime = str_starts_with($data, "\xFF\xD8\xFF") ? 'image/jpeg' : (str_starts_with($data, "\x89PNG\r\n\x1a\n") ? 'image/png' : (str_starts_with($data, '%PDF-') ? 'application/pdf' : ''));
    if ($mime === '') { $error = 'Documents must be a JPG, PNG or PDF file.'; return null; }
    return ['b64' => base64_encode($data), 'mime' => $mime];
}

/* HANDLE OPERATOR APPLICATION SUBMISSION */
if (isset($_POST['submit_application'])) {
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone_number = trim((string)($_POST['phone_number'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $license_number = trim((string)($_POST['license_number'] ?? ''));
    $license_expiry = trim((string)($_POST['license_expiry'] ?? ''));
    $vehicle_type = trim((string)($_POST['vehicle_type'] ?? ''));
    $plate = strtoupper(trim((string)($_POST['vehicle_plate_number'] ?? '')));
    $expiry_ts = strtotime($license_expiry);

    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please reload the page and try again.';
    } elseif ($full_name === '' || strlen($full_name) > 255 || $address === '' || strlen($address) > 500 || $license_number === '' || strlen($license_number) > 100 || $plate === '' || strlen($plate) > 50) {
        $error = 'Please fill in every field with valid details.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $error = 'Enter a valid email address.';
    } elseif (!preg_match('/^09\d{9}$/', $phone_number)) {
        $error = 'Phone number must be 11 digits starting with 09 (e.g., 09123456789).';
    } elseif (!in_array($vehicle_type, ['Bus', 'Van', 'Minibus'], true)) {
        $error = 'Choose a vehicle type.';
    } elseif (!$expiry_ts || $expiry_ts < strtotime('today')) {
        $error = 'Your license has expired. Apply again with a valid license.';
    } else {
        $dup = mbus_db_prepare($conn, "SELECT 1 FROM users WHERE LOWER(email) = ? UNION SELECT 1 FROM operator_application WHERE LOWER(email) = ? AND application_status::text = 'Pending'", [$email, $email]);
        if ($dup !== false && mbus_db_num_rows($dup) > 0) {
            $error = 'This email already has an account or a pending application. Log in, or wait for the admin to review it.';
        } else {
            $lic = mbus_application_file('license_document', $error);
            $veh = $lic ? mbus_application_file('vehicle_document', $error) : null;
            if ($lic && $veh) {
                $ok = mbus_db_prepare($conn, "INSERT INTO operator_application
                    (full_name, email, phone_number, address, license_number, license_expiry, vehicle_type, vehicle_plate_number,
                     license_document_b64, license_document_mime, vehicle_document_b64, vehicle_document_mime, application_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')",
                    [$full_name, $email, $phone_number, $address, $license_number, $license_expiry, $vehicle_type, $plate,
                     $lic['b64'], $lic['mime'], $veh['b64'], $veh['mime']]);
                if ($ok === false) { $error = 'We could not save your application. Please try again.'; }
                else { $success = 'Application submitted. An admin will review it and email you at ' . htmlspecialchars($email) . '.'; }
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
            <?php csrf_field(); ?>
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
                <input type="date" name="license_expiry" id="licExp" required min="<?php echo date('Y-m-d'); ?>"></div>
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
