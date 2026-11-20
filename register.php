<?php

session_start();
include "config/db_connect.php";
include_once "Includes/validation.php";
include "Includes/activity_log.php";
require_once "Includes/csrf.php";
require_once __DIR__ . '/Includes/reset_mailer.php';
require_once __DIR__ . '/Includes/registration_security.php';
require_once __DIR__ . '/Includes/phone_unique.php';

/*
 * Conductor accounts:
 *   true  -> the person applies; an admin approves the application before a login exists
 *            (needs the conductor_application table, see conductor_application.sql)
 *   false -> a Conductor account is created immediately in `users`
 */
$conductor_requires_approval = true;

/* ---------- helpers ---------- */

function reg_e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Sticky value for re-displaying the form after an error
function reg_old(string $key): string {
    return reg_e($_POST[$key] ?? '');
}

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

/** Read a required JPEG/PNG vehicle photo, with a small request-safe limit. */
function mbus_application_photo(string $field, &$error): ?array {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) { $error = 'Please attach both bus photos.'; return null; }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $error = 'A bus photo could not be uploaded. Try a smaller image.'; return null; }
    if ($f['size'] > 786432) { $error = 'Each bus photo must be 768 KB or smaller.'; return null; }
    $data = file_get_contents($f['tmp_name']);
    $mime = str_starts_with($data, "\xFF\xD8\xFF") ? 'image/jpeg' : (str_starts_with($data, "\x89PNG\r\n\x1a\n") ? 'image/png' : '');
    if ($mime === '') { $error = 'Bus photos must be JPG or PNG images.'; return null; }
    return ['b64' => base64_encode($data), 'mime' => $mime];
}

/* ---------- user type ---------- */

$allowed_types  = ["passenger", "conductor", "driver"];
$requested_type = $_GET['type'] ?? 'passenger';
$user_type      = in_array($requested_type, $allowed_types, true) ? $requested_type : 'passenger';

$error   = '';
$success = '';

// One-time message after a successful application (post/redirect/get, so a refresh can't resubmit)
if (!empty($_SESSION['register_flash'])) {
    $success = $_SESSION['register_flash'];
    unset($_SESSION['register_flash']);
}

/* ---------- form handling ---------- */

$errors = [];
$step_with_error = 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $posted_type = $_POST["user_type"] ?? "passenger";
    $user_type   = in_array($posted_type, $allowed_types, true) ? $posted_type : "passenger";

    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please reload the page and try again.';
    }

    /* ===== Passenger (Commuter) / Conductor ===== */
    elseif ($user_type === "passenger" || $user_type === "conductor") {
        $firstName = trim((string)($_POST["firstName"] ?? ""));
        $lastName  = trim((string)($_POST["lastName"] ?? ""));
        $email     = (string)($_POST["emailAddress"] ?? "");
        $phone     = (string)($_POST["mobileNumber"] ?? "");
        $password  = (string)($_POST["password"] ?? "");
        $confirm   = (string)($_POST["confirmationPassword"] ?? "");
        $is_conductor_application = ($user_type === "conductor" && $conductor_requires_approval);

        if ($firstName === '') $errors['firstName'] = 'Enter your first name.';
        if ($lastName === '')  $errors['lastName']  = 'Enter your last name.';
        $full_name = trim($firstName . ' ' . $lastName);
        if ($firstName !== '' && $lastName !== '' && ($e = mbus_check_name($full_name))) $errors[isset($errors['firstName']) ? 'lastName' : 'firstName'] = $e;
        if ($e = mbus_check_email($email)) $errors['emailAddress'] = $e;
        if ($e = mbus_check_phone($phone)) $errors['mobileNumber'] = $e;
        if ($e = mbus_check_password($password)) $errors['password'] = $e;
        elseif ($password !== $confirm) $errors['confirmationPassword'] = 'Passwords do not match.';
        $_POST['emailAddress'] = $email; $_POST['mobileNumber'] = $phone;

        if (!$errors && !$is_conductor_application && !mbus_registration_mail_is_configured()) {
            $error = 'Email verification is temporarily unavailable. Please try again later.';
        }

        if (!$errors && $error === '') {
            $phone_used = mbus_phone_in_use($conn, $phone);
            if ($phone_used === null) $error = 'Registration is temporarily unavailable. Please try again in a moment.';
            elseif ($phone_used) $errors['mobileNumber'] = 'That mobile number is already used by another account. Use a different number or log in.';
        }
        if (!$errors && $error === '') {
            $dup = mbus_db_prepare($conn,
                "SELECT 1 FROM users WHERE LOWER(email) = ?
                 UNION SELECT 1 FROM operator_application WHERE LOWER(email) = ? AND application_status::text = 'Pending'
                 UNION SELECT 1 FROM conductor_application WHERE LOWER(email) = ? AND application_status = 'Pending'",
                [$email, $email, $email]);
            if ($dup === false) {
                $error = 'Registration is temporarily unavailable. Please try again in a moment.';
            } elseif (mbus_db_num_rows($dup) > 0) {
                $errors['emailAddress'] = 'This email already has an account or a pending application. Log in, or wait for the admin to review it.';
            } else {
                $limit_reason = $is_conductor_application
                    ? mbus_registration_allow_application($conn, $email)
                    : mbus_registration_allow_code_send($conn, $email);
                if ($limit_reason !== null) {
                    $error = mbus_registration_limit_message($limit_reason, !$is_conductor_application);
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($is_conductor_application) {
                        $ok = mbus_db_prepare($conn, "INSERT INTO conductor_application (full_name, email, phone_number, password_hash, application_status) VALUES (?, ?, ?, ?, 'Pending')",
                            [$full_name, $email, $phone, $hash]);
                        if ($ok === false) { $error = 'We could not save your application. Please try again.'; }
                        else {
                            $_SESSION['register_flash'] = 'Application submitted. An admin will review it. Once approved, log in with ' . $email . ' and the password you chose.';
                            header('Location: register.php?type=conductor'); exit();
                        }
                    } else {
                        $registration_code = (string)random_int(100000, 999999);
                        $registration_expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                        $ok = mbus_db_prepare($conn, "INSERT INTO users (full_name, email, phone_number, password, user_type, registration_code, registration_code_expiry, registration_code_attempts) VALUES (?, ?, ?, ?, 'Commuter', ?, ?, 0)",
                            [$full_name, $email, $phone, $hash, $registration_code, $registration_expiry]);
                        if ($ok === false) { $error = 'Could not create your account. Please try again.'; }
                        else {
                            $mail_error = mbus_send_registration_code($email, $registration_code, $full_name);
                            $mail_sent = $mail_error === null;
                            if (!$mail_sent) error_log('Initial registration email failed: ' . (string)$mail_error);
                            $_SESSION['registration_email'] = $email;
                            $_SESSION['registration_mail_sent'] = $mail_sent;
                            $_SESSION['registration_sent_at'] = time();
                            header('Location: verify_registration.php'); exit();
                        }
                    }
                }
            }
        }
    }

    /* ===== Driver / Operator application ===== */
    elseif ($user_type === "driver") {
        $full_name      = (string)($_POST['full-name'] ?? '');
        $email          = (string)($_POST['email-address'] ?? '');
        $phone_number   = (string)($_POST['phone-number'] ?? '');
        $address        = trim(preg_replace('/\s+/', ' ', (string)($_POST['home-address'] ?? '')));
        $password       = (string)($_POST['driver-password'] ?? '');
        $confirm        = (string)($_POST['driver-confirm'] ?? '');
        $license_number = (string)($_POST['license-number'] ?? '');
        $license_expiry = trim((string)($_POST['license-expiry'] ?? ''));
        $vehicle_type   = trim((string)($_POST['vehicle-type'] ?? ''));
        $plate          = (string)($_POST['vehicle-plate'] ?? '');
        $seat_layout_raw = (string)($_POST['seat-layout'] ?? '');
        $seat_layout = json_decode($seat_layout_raw, true);

        if ($e = mbus_check_full_name($full_name)) $errors['full-name'] = $e;
        if ($e = mbus_check_email($email)) $errors['email-address'] = $e;
        if ($e = mbus_check_phone($phone_number)) $errors['phone-number'] = $e;
        if (mb_strlen($address) < 8) $errors['home-address'] = 'Enter your complete address (at least 8 characters).';
        elseif (mb_strlen($address) > 300) $errors['home-address'] = 'Address is too long (300 characters at most).';
        if ($e = mbus_check_password($password)) $errors['driver-password'] = $e;
        elseif ($password !== $confirm) $errors['driver-confirm'] = 'Passwords do not match.';
        if ($e = mbus_check_license_number($license_number)) $errors['license-number'] = $e;
        $d = DateTime::createFromFormat('Y-m-d', $license_expiry);
        if (!$d || $d->format('Y-m-d') !== $license_expiry) $errors['license-expiry'] = 'Enter the license expiry date.';
        elseif ($license_expiry < date('Y-m-d')) $errors['license-expiry'] = 'This license has expired. Apply again with a valid license.';
        elseif ($license_expiry > date('Y-m-d', strtotime('+15 years'))) $errors['license-expiry'] = 'That expiry date is too far ahead. Check the year.';
        if (!in_array($vehicle_type, ['Ordinary', 'Aircon'], true)) $errors['vehicle-type'] = 'Choose a bus type (Ordinary or Aircon).';
        if ($e = mbus_check_plate($plate)) $errors['vehicle-plate'] = $e;
        $seat_count = 0;
        if (!is_array($seat_layout) || count($seat_layout) < 1 || count($seat_layout) > 25) {
            $errors['seat-layout'] = 'Choose a seat layout with 1 to 100 seats.';
        } else {
            foreach ($seat_layout as $row) {
                if (!is_array($row) || count($row) !== 4) { $errors['seat-layout'] = 'The seat layout is invalid. Please rebuild it.'; break; }
                foreach ($row as $seat) {
                    if (!is_bool($seat)) { $errors['seat-layout'] = 'The seat layout is invalid. Please rebuild it.'; break 2; }
                    if ($seat) $seat_count++;
                }
            }
            if (!isset($errors['seat-layout']) && ($seat_count < 1 || $seat_count > 100)) $errors['seat-layout'] = 'Choose between 1 and 100 seats.';
        }
        $_POST['email-address'] = $email; $_POST['phone-number'] = $phone_number; $_POST['full-name'] = $full_name;
        $_POST['license-number'] = $license_number; $_POST['vehicle-plate'] = $plate; $_POST['home-address'] = $address;

        if (!isset($errors['email-address'])) {
            $dup = mbus_db_prepare($conn,
                "SELECT 1 FROM users WHERE LOWER(email) = ?
                 UNION SELECT 1 FROM operator_application WHERE LOWER(email) = ? AND application_status::text = 'Pending'
                 UNION SELECT 1 FROM conductor_application WHERE LOWER(email) = ? AND application_status = 'Pending'",
                [$email, $email, $email]);
            if ($dup !== false && mbus_db_num_rows($dup) > 0) $errors['email-address'] = 'This email already has an account or a pending application. Log in, or wait for the admin to review it.';
        }
        if (!isset($errors['phone-number'])) {
            $phone_used = mbus_phone_in_use($conn, $phone_number);
            if ($phone_used === null) $error = 'Registration is temporarily unavailable. Please try again in a moment.';
            elseif ($phone_used) $errors['phone-number'] = 'That mobile number is already used by another account. Use a different number or log in.';
        }
        if (!$errors && $error === '') {
            $application_limit = mbus_registration_allow_application($conn, $email);
            if ($application_limit !== null) $error = mbus_registration_limit_message($application_limit);
        }
        $lic = $veh = $busPhoto = $interiorPhoto = null;
        if (!$errors && $error === '') {
            $fe = ''; $lic = mbus_application_file('license_document', $fe);
            if (!$lic) $errors['license_document'] = 'License file: ' . $fe;
            $fe = ''; $veh = mbus_application_file('vehicle_document', $fe);
            if (!$veh) $errors['vehicle_document'] = 'Vehicle registration file: ' . $fe;
            $fe = ''; $busPhoto = mbus_application_photo('bus_photo', $fe);
            if (!$busPhoto) $errors['bus_photo'] = 'Bus exterior photo: ' . $fe;
            $fe = ''; $interiorPhoto = mbus_application_photo('interior_photo', $fe);
            if (!$interiorPhoto) $errors['interior_photo'] = 'Bus interior photo: ' . $fe;
        }
        if (!$errors && $error === '') {
            $ok = mbus_db_prepare($conn,
                "INSERT INTO operator_application
                    (full_name, email, phone_number, address, license_number, license_expiry, vehicle_type, vehicle_plate_number,
                     license_document_b64, license_document_mime, vehicle_document_b64, vehicle_document_mime, password_hash,
                     seat_layout, bus_photo_b64, bus_photo_mime, interior_photo_b64, interior_photo_mime, application_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')",
                [$full_name, $email, $phone_number, $address, $license_number, $license_expiry, $vehicle_type, $plate,
                 $lic['b64'], $lic['mime'], $veh['b64'], $veh['mime'], password_hash($password, PASSWORD_DEFAULT),
                 json_encode($seat_layout), $busPhoto['b64'], $busPhoto['mime'], $interiorPhoto['b64'], $interiorPhoto['mime']]);
            if ($ok === false) { $error = 'We could not save your application. Please try again.'; }
            else {
                $_SESSION['register_flash'] = 'Application submitted. An admin will review it. Once approved, log in with ' . $email . ' and the password you chose.';
                header('Location: register.php?type=driver'); exit();
            }
        }
    }

    if ($errors && $error === '') $error = 'Please fix the highlighted fields.';
    // Driver form is a 3-step wizard: reopen on the first step that has an error
    $stepOf = ['full-name'=>0,'email-address'=>0,'phone-number'=>0,'home-address'=>0,'driver-password'=>0,'driver-confirm'=>0,
               'license-number'=>1,'license-expiry'=>1,'license_document'=>1,'vehicle-type'=>2,'vehicle-plate'=>2,'vehicle_document'=>2,
               'bus_photo'=>2,'interior_photo'=>2,'seat-layout'=>2];
    foreach (array_keys($errors) as $k) { if (isset($stepOf[$k])) { $step_with_error = $stepOf[$k]; break; } }
}

/* ---------- UI state (computed AFTER POST so it matches the submitted type) ---------- */

$form_class       = $user_type !== "driver" ? "active" : "";
$passenger_class  = $user_type === "passenger" ? "active" : "";
$conductor_class  = $user_type === "conductor" ? "active" : "";
$driver_class     = $user_type === "driver" ? "active" : "";
$option_bar_class = $user_type === "driver" ? "active" : ($user_type === "conductor" ? "conductor" : "disabled");

$picker_messages = [
    "passenger" => "Book seats and keep all your trips in one place",
    "conductor" => $conductor_requires_approval
        ? "Apply to validate tickets and assist passengers on board. An admin reviews each application."
        : "Register to validate tickets and assist passengers on board.",
    "driver"    => "Register to manage assigned routes and passenger trips."
];
$picker_text = $picker_messages[$user_type];

if ($user_type === "conductor") {
    $submit_text = $conductor_requires_approval ? "Submit conductor application" : "Create conductor account";
} else {
    $submit_text = "Create passenger account";
}

// Hidden sections are disabled so their `required` fields don't block form submission
$passenger_disabled = $user_type === "driver" ? "disabled" : "";
$driver_disabled    = $user_type !== "driver" ? "disabled" : "";
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>MBUS</title>
        <link rel="stylesheet" href="Assets/css/global/font.css"/>
        <link rel="stylesheet" href="Assets/css/main/register.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/register.css') ?>"/>
        <link rel="stylesheet" href="Assets/css/legal_modal.css?v=<?= @filemtime(__DIR__ . '/Assets/css/legal_modal.css') ?>"/>
    <link rel="stylesheet" href="Assets/css/main/auth_modern.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/auth_modern.css') ?>"/>
        <link rel="stylesheet" href="Assets/css/main/driver_bus_setup.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/driver_bus_setup.css') ?>"/>
        <script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.min.js"></script>
        <style>
            .form-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
            .form-alert.error   { background: #fdecea; color: #b42318; border: 1px solid #f5c2be; }
            .form-alert.success { background: #e7f6ec; color: #1a7f37; border: 1px solid #b7e4c7; }
            .form-fieldset { display: contents; border: 0; padding: 0; margin: 0; min-width: 0; }
        </style>
    </head>
    <body class="auth-register">
        <div class="auth-hero-m">
            <a class="auth-back" href="index.php" aria-label="Back to home"><i data-lucide="arrow-left"></i></a>
            <h1>Create your account</h1>
            <p>Plan your next bus trip in just a few steps.</p>
        </div>
        <div class="left-container">
            <div class="left-container-child-1">
                <div class="logo-wordmark">
                    <img src="Assets/images/mbus_logo.png" alt="logo">
                </div>
            </div>
            <div class="left-container-child-2">
                <div class="lc-heading">
                    <h1>Travel smarter.</h1>
                    <h1>Travel on time.</h1>
                </div>
                <div class="lc-sub-heading">
                    <p>Your dependable way to reserve seats, manage trips, and travel with confidence.</p>
                </div>
            </div>
            <img class="auth-art" src="Assets/images/auth_bus.svg" alt="" aria-hidden="true">
            <div class="lc-circle-1"></div>
            <div class="lc-circle-2"></div>
        </div>
        <div class="right-container">
            <div class="form-container">
                <div class="auth-tabs" role="tablist">
                    <?php if ($user_type === 'passenger'): ?>
                    <a href="login.php" role="tab">Login</a>
                    <a href="register.php" class="on" role="tab">Register</a>
                    <?php else: ?>
                    <a href="register.php?type=conductor" class="<?= $user_type === 'conductor' ? 'on' : '' ?>" role="tab">Conductor</a>
                    <a href="register.php?type=driver" class="<?= $user_type === 'driver' ? 'on' : '' ?>" role="tab">Driver/Operator</a>
                    <?php endif; ?>
                </div>
                <div class="form-header">
                    <div class="form-header-text">
                        <h1>Create your Account</h1>
                        <p>Plan your next bus trip in just a few steps.</p>
                    </div>
                    <div class="form-header-picker">
                        <div class="picker-container-text">
                            <span id="picker-text"><?= reg_e($picker_text) ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="form-alert error" role="alert"><?= reg_e($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="form-alert success"><?= reg_e($success) ?></div>
                <?php endif; ?>

                <!-- enctype is REQUIRED for file uploads -->
                <form action="register.php" class="form-body" method="POST" enctype="multipart/form-data">
                    <input type="hidden" id="user-type" name="user_type" value="<?= reg_e($user_type) ?>"/>
                    <?php csrf_field(); ?>

                    <div class="passenger <?= $form_class ?>" id="passenger">
                        <fieldset class="form-fieldset" id="passenger-fields" <?= $passenger_disabled ?>>
                        <div class="form-name-input">
                            <div class="fn-input">
                                <label>First Name</label>
                                <input name="firstName" class="fn-input-i" type="text" placeholder="Juan" maxlength="50" autocomplete="given-name" value="<?= reg_old('firstName') ?>" required/>
                                <i class="input-icon" data-lucide="user"></i>
                            <?= mbus_field_error($errors, 'firstName') ?></div>
                            <div class="ln-input">
                                <label>Last Name</label>
                                <input name="lastName" type="text" placeholder="Dela Cruz" maxlength="50" autocomplete="family-name" value="<?= reg_old('lastName') ?>" required/>
                                <i class="input-icon" data-lucide="user"></i>
                            <?= mbus_field_error($errors, 'lastName') ?></div>
                        </div>
                        <div class="form-contact-input">
                            <div class="form-email-input">
                                <label>Email Address</label>
                                <input name="emailAddress" type="email" placeholder="juan@example.com" maxlength="254" autocapitalize="none" spellcheck="false" autocomplete="email" aria-describedby="email-verification-hint" value="<?= reg_old('emailAddress') ?>" required/>
                                <i class="input-icon" data-lucide="mail"></i>
                            <?= mbus_field_error($errors, 'emailAddress') ?>
                                <small class="field-hint" id="email-verification-hint">We’ll send a 6-digit code to this address. Your account becomes active after verification; the code expires in 15 minutes.</small>
                            </div>
                            <div class="form-phone-input">
                                <label>Mobile Number</label>
                                <div class="phone-input"><span class="phone-prefix" aria-hidden="true">+63</span><input name="mobileNumber" type="text" placeholder="9XXXXXXXXX" maxlength="10" minlength="10" inputmode="numeric" pattern="9[0-9]{9}" autocomplete="tel" value="<?= preg_replace('/^0/', '', reg_old('mobileNumber')) ?>" required/></div>
                            <?= mbus_field_error($errors, 'mobileNumber') ?></div>
                        </div>
                        <div class="form-password-input">
                            <div class="password-input">
                                <label>Password</label>
                                <input name="password" type="password" placeholder="At least 8 characters" minlength="8" maxlength="72" autocomplete="new-password" required/>
                                <i class="input-icon" data-lucide="lock"></i>
                            <?= mbus_field_error($errors, 'password') ?></div>
                            <div class="confirm-password-input">
                                <label>Confirm Password</label>
                                <input type="password" name="confirmationPassword" placeholder="Repeat Password" minlength="8" maxlength="72" autocomplete="new-password" required/>
                                <i class="input-icon" data-lucide="lock"></i>
                            <?= mbus_field_error($errors, 'confirmationPassword') ?></div>
                        </div>
                        <div class="form-tos-check">
                            <input type="checkbox" required/>
                            <label class="tos">I agree to the<a href="terms.php" data-legal="terms" style="color:#2f6fe4;font-weight:600;"> Terms of Service </a> and <a href="privacy.php" data-legal="privacy" style="color:#2f6fe4;font-weight:600;"> Privacy Policy. </a> </label>
                        </div>
                        <button class="submit-button" id="passenger-submit" type="submit"><?= reg_e($submit_text) ?></button>
                        </fieldset>

                        
                        <div class="footer">
                            <p>Already have an account? <a style="color: #086FCF; text-decoration: none;" href="login.php">Sign In</a></p>
                            <?php if ($user_type === 'conductor'): ?>
                            <p>Not a conductor? <a style="color: #086FCF; text-decoration: none;" href="register.php?type=passenger">Register as a passenger</a></p>
                            <?php else: ?>
                            <p>Want to drive with us? <a style="color: #086FCF; text-decoration: none;" href="register.php?type=driver">Apply as Driver/Operator</a></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="driver <?= $driver_class ?>" id="driver">
                        <fieldset class="form-fieldset" id="driver-fields" <?= $driver_disabled ?>>
                        <div class="step-progress">
                            <div class="step-progress-bar active"></div>
                            <div class="step-progress-bar"></div>
                            <div class="step-progress-bar"></div>
                        </div>
                        <div class="driver-input-group active">
                            <div class="group-header">
                                <div class="group-count">1</div>
                                <p class="group-name">Personal Information</p>
                            </div>
                            <div class="form-inputs">
                                <div class="input-group">
                                    <div class="full-name">
                                        <label class="input-label">Full Name (LN, FN, MI)</label>
                                        <input type="text" name="full-name" placeholder="Enter your full name" maxlength="100" value="<?= reg_old('full-name') ?>" required/>
                                        <i class="input-icon" data-lucide="user"></i>
                                    <?= mbus_field_error($errors, 'full-name') ?></div>
                                    <div class="email-address">
                                        <label class="input-label">Email Address</label>
                                        <input type="email" name="email-address" placeholder="Enter your email" maxlength="254" autocapitalize="none" spellcheck="false" value="<?= reg_old('email-address') ?>" required/>
                                        <i class="input-icon" data-lucide="mail"></i>
                                    <?= mbus_field_error($errors, 'email-address') ?></div>
                                </div>
                                <div class="input-group">
                                    <div class="phone-number">
                                        <label>Phone Number</label>
                                <div class="phone-input"><span class="phone-prefix" aria-hidden="true">+63</span><input type="text" name="phone-number" placeholder="9XXXXXXXXX" maxlength="10" minlength="10" inputmode="numeric" pattern="9[0-9]{9}" value="<?= preg_replace('/^0/', '', reg_old('phone-number')) ?>" required/></div>
                                    <?= mbus_field_error($errors, 'phone-number') ?></div>
                                    <div class="home-address">
                                        <label>Address</label>
                                        <input type="text" name="home-address" placeholder="Enter your home address" maxlength="300" value="<?= reg_old('home-address') ?>" required/>
                                        <i class="input-icon" data-lucide="map-pin-house"></i>
                                    <?= mbus_field_error($errors, 'home-address') ?></div>
                                </div>
                                <div class="input-group">
                                    <div class="password-input">
                                        <label>Password</label>
                                        <input type="password" name="driver-password" placeholder="At least 8 characters" minlength="8" maxlength="72" autocomplete="new-password" required/>
                                        <i class="input-icon" data-lucide="lock"></i>
                                        <?= mbus_field_error($errors, 'driver-password') ?>
                                    </div>
                                    <div class="confirm-password-input">
                                        <label>Confirm Password</label>
                                        <input type="password" name="driver-confirm" placeholder="Repeat password" minlength="8" maxlength="72" autocomplete="new-password" required/>
                                        <i class="input-icon" data-lucide="lock"></i>
                                        <?= mbus_field_error($errors, 'driver-confirm') ?>
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
                                        <input type="text" name="license-number" placeholder="Enter your license number" maxlength="20" value="<?= reg_old('license-number') ?>" required/>
                                        <i class="input-icon" data-lucide="user"></i>
                                    <?= mbus_field_error($errors, 'license-number') ?></div>
                                    <div class="license-expiry">
                                        <label class="input-label">License Expiry Date</label>
                                        <input type="date" name="license-expiry" min="<?= date('Y-m-d') ?>" value="<?= reg_old('license-expiry') ?>" required/>
                                        <i class="input-icon" data-lucide="calendar"></i>
                                    <?= mbus_field_error($errors, 'license-expiry') ?></div>
                                </div>
                                <div class="upload-group">
                                    <label class="input-label">Upload License Document</label>
                                    <div class="upload-document-group">
                                        <div class="upload-icon">
                                            <i data-lucide="upload"></i>
                                        </div>
                                        <div class="document-desc">
                                            <span class="document-name">Click to upload document</span>
                                            <span>JPG, JPEG, PNG, or PDF &middot; Max 3 MB</span>
                                        </div>
                                        <div class="document-input-button">
                                            <button type="button">Browse</button>
                                            <input type="file" name="license_document" accept=".jpg,.jpeg,.png,.pdf" required/>
                                        </div>
                                    </div>
                                    <?= mbus_field_error($errors, 'license_document') ?>
                                    <div class="document-preview">
                                        <img class="document-preview-image" alt="License document preview"/>
                                        <iframe class="document-preview-frame" title="License document preview"></iframe>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="driver-input-group">
                            <div class="group-header">
                                <div class="group-count">3</div>
                                <h3 class="group-name">Vehicle &amp; Bus Setup</h3>
                            </div>
                            <div class="form-inputs">
                                <div class="input-group">
                                    <div class="vehicle-type">
                                        <label class="input-label">Vehicle Type</label>
                                        <!-- name fixed: was "vehile-type" -->
                                        <select name="vehicle-type" class="vehicle-selection" required>
                                            <?php $vt = $_POST['vehicle-type'] ?? ''; ?>
                                            <option value="" disabled <?= $vt === '' ? 'selected' : '' ?>>Select Vehicle Type</option>
                                            <option value="Ordinary" <?= $vt === 'Ordinary' ? 'selected' : '' ?>>Ordinary</option>
                                            <option value="Aircon"   <?= $vt === 'Aircon' ? 'selected' : '' ?>>Aircon</option>
                                        </select>
                                        <?= mbus_field_error($errors, 'vehicle-type') ?>
                                    </div>
                                    <div class="vehicle-plate-number">
                                        <label class="input-label">Vehicle Plate Number</label>
                                        <input type="text" name="vehicle-plate" placeholder="Enter vehicle plate number" maxlength="12" value="<?= reg_old('vehicle-plate') ?>" required/>
                                        <i class="input-icon" data-lucide="user"></i>
                                    <?= mbus_field_error($errors, 'vehicle-plate') ?></div>
                                </div>
                                <div class="upload-group">
                                    <label class="input-label">Upload Vehicle Registration Document</label>
                                    <div class="upload-document-group">
                                        <div class="upload-icon">
                                            <i data-lucide="upload"></i>
                                        </div>
                                        <div class="document-desc">
                                            <span class="document-name">Click to upload document</span>
                                            <span>JPG, JPEG, PNG, or PDF &middot; Max 3 MB</span>
                                        </div>
                                        <div class="document-input-button">
                                            <button type="button">Browse</button>
                                            <input type="file" name="vehicle_document" accept=".jpg,.jpeg,.png,.pdf" required/>
                                        </div>
                                    </div>
                                    <?= mbus_field_error($errors, 'vehicle_document') ?>
                                    <div class="document-preview">
                                        <img class="document-preview-image" alt="Vehicle registration document preview"/>
                                        <iframe class="document-preview-frame" title="Vehicle registration document preview"></iframe>
                                    </div>
                                </div>
                                <div class="bus-photo-grid">
                                    <div class="upload-group">
                                        <label class="input-label" for="bus-photo-input">Bus Exterior Photo</label>
                                        <div class="upload-document-group">
                                            <div class="upload-icon"><i data-lucide="camera"></i></div>
                                            <div class="document-desc"><span class="document-name">Upload a clear exterior photo</span><span>JPG or PNG · Max 768 KB</span></div>
                                            <div class="document-input-button"><button type="button">Browse</button><input id="bus-photo-input" type="file" name="bus_photo" accept="image/jpeg,image/png" data-max-bytes="786432" required/></div>
                                        </div>
                                        <?= mbus_field_error($errors, 'bus_photo') ?>
                                        <div class="document-preview"><img class="document-preview-image" alt="Bus exterior photo preview"/><iframe class="document-preview-frame" title="Bus exterior photo preview"></iframe></div>
                                    </div>
                                    <div class="upload-group">
                                        <label class="input-label" for="interior-photo-input">Bus Interior Photo</label>
                                        <div class="upload-document-group">
                                            <div class="upload-icon"><i data-lucide="camera"></i></div>
                                            <div class="document-desc"><span class="document-name">Show the passenger seating area</span><span>JPG or PNG · Max 768 KB</span></div>
                                            <div class="document-input-button"><button type="button">Browse</button><input id="interior-photo-input" type="file" name="interior_photo" accept="image/jpeg,image/png" data-max-bytes="786432" required/></div>
                                        </div>
                                        <?= mbus_field_error($errors, 'interior_photo') ?>
                                        <div class="document-preview"><img class="document-preview-image" alt="Bus interior photo preview"/><iframe class="document-preview-frame" title="Bus interior photo preview"></iframe></div>
                                    </div>
                                </div>
                                <section class="seat-builder" data-seat-builder aria-labelledby="seat-builder-title">
                                    <div class="seat-builder-head">
                                        <div><label id="seat-builder-title" class="input-label">Customize Seat Layout</label><p>Toggle seats to match the vehicle. The aisle is shown between the two seat pairs.</p></div>
                                        <div class="seat-builder-actions"><button type="button" class="seat-layout-button" data-seat-add-row>Add row</button><button type="button" class="seat-layout-button" data-seat-reset>Reset 2 + 2</button></div>
                                    </div>
                                    <div class="seat-builder-meta"><span data-seat-count aria-live="polite">40 seats selected</span><span>1–100 seats</span></div>
                                    <div class="seat-layout-preview" data-seat-grid role="grid" aria-label="Custom bus seat layout"></div>
                                    <input type="hidden" name="seat-layout" data-seat-layout value="<?= reg_e($_POST['seat-layout'] ?? json_encode(array_fill(0, 10, [true, true, true, true]))) ?>"/>
                                    <?= mbus_field_error($errors, 'seat-layout') ?>
                                </section>
                                <div class="terms-conditions">
                                    <input type="checkbox" required>
                                    <p>I agree to the <a href="terms.php" data-legal="terms">Terms of Service</a> and <a href="privacy.php" data-legal="privacy">Privacy Policy</a> and certify that all information provided is accurate.</p>
                                </div>
                            </div>
                        </div>
                        <footer class="driver-footer">
                            <div class="button-container">
                                <button class="step-button-back" id="step-back" type="button">Back</button>
                                <button class="submit-button step-button-next active" id="step-next" type="button">Next</button>
                                <button class="submit-button step-button-submit" id="step-submit" type="submit">Submit Application</button>
                            </div>
                            <div class="redirect-footer">
                                <div class="accounts-redirect">
                                    <p>Already have an account? <a style="color: #0877DF;" href="login.php">Login here</a></p>
                                    <p>Want to book a ride? <a style="color: #0877DF;" href="register.php?type=passenger"> Register as Commuter </a> </p>
                                </div>
                                <div class="contacts">
                                    
                                </div>
                            </div>
                        </footer>
                        </fieldset>
                    </div>
                </form>
            </div>
        </div>
        <style>
            .phone-input { display:flex; align-items:stretch; width:100%; }
            .phone-prefix { display:grid; place-items:center; min-width:54px; padding:0 10px; border:1px solid #d5dce6; border-right:0; border-radius:12px 0 0 12px; background:#f4f6f9; color:#3b4a5f; font-weight:600; }
            .phone-input input { flex:1; min-width:0; border-radius:0 12px 12px 0; }
        </style>
        <script>window.MBUS_START_STEP = <?= (int)$step_with_error ?>;</script>
        <script src="register.js"></script>
        <script src="Assets/js/driver_bus_setup.js?v=<?= @filemtime(__DIR__ . '/Assets/js/driver_bus_setup.js') ?>"></script>
        <script> if (window.lucide) lucide.createIcons(); </script>
        <script src="Assets/js/password-toggle.js"></script>

<div class="legal-modal" id="legalModal" hidden>
    <div class="legal-modal-backdrop" data-legal-close></div>
    <div class="legal-modal-card" role="dialog" aria-modal="true" aria-labelledby="legalTitle">
        <div class="legal-modal-head">
            <h2 id="legalTitle">Terms of Service</h2>
            <button type="button" class="legal-modal-x" data-legal-close aria-label="Close">&times;</button>
        </div>
        <div class="legal-modal-tabs">
            <button type="button" data-legal-tab="terms" class="active">Terms of Service</button>
            <button type="button" data-legal-tab="privacy">Privacy Policy</button>
        </div>
        <div class="legal-modal-body" id="legalBody" tabindex="0"></div>
        <button type="button" class="legal-modal-ok" data-legal-close>Got it</button>
    </div>
</div>
<script>
(function(){
    var modal=document.getElementById('legalModal'),body=document.getElementById('legalBody'),title=document.getElementById('legalTitle');
    var names={terms:'Terms of Service',privacy:'Privacy Policy'},cache={},last=null;
    function show(kind){
        title.textContent=names[kind];
        modal.querySelectorAll('[data-legal-tab]').forEach(function(b){b.classList.toggle('active',b.dataset.legalTab===kind);});
        function put(h){body.innerHTML=h;body.scrollTop=0;}
        if(cache[kind]){put(cache[kind]);return;}
        body.textContent='Loading…';
        fetch(kind+'.php?embed=1',{credentials:'same-origin'}).then(function(r){if(!r.ok)throw 0;return r.text();})
            .then(function(h){cache[kind]=h;put(h);})
            .catch(function(){body.innerHTML='<p>Could not load this right now. <a href="'+kind+'.php" target="_blank" rel="noopener">Open it in a new page</a>.</p>';});
    }
    function open(kind,from){last=from;modal.hidden=false;document.body.style.overflow='hidden';show(kind);modal.querySelector('.legal-modal-ok').focus();}
    function close(){modal.hidden=true;document.body.style.overflow='';if(last)last.focus();}
    document.addEventListener('click',function(e){
        var a=e.target.closest('[data-legal]');
        if(a){e.preventDefault();open(a.dataset.legal,a);return;}
        var t=e.target.closest('[data-legal-tab]');if(t){show(t.dataset.legalTab);return;}
        if(e.target.closest('[data-legal-close]'))close();
    });
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)close();});
})();
</script>
<script src="Assets/js/submit_feedback.js"></script>
</body>
</html>
