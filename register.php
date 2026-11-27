<?php

session_start();
include __DIR__ . "/config/db_connect.php";
include_once __DIR__ . "/Includes/validation.php";
require_once __DIR__ . "/Includes/seat_layout.php";
require_once __DIR__ . "/Includes/notify.php";
include __DIR__ . "/Includes/activity_log.php";
require_once __DIR__ . "/Includes/csrf.php";
require_once __DIR__ . '/Includes/reset_mailer.php';
require_once __DIR__ . '/Includes/registration_security.php';
require_once __DIR__ . '/Includes/totp.php';
require_once __DIR__ . '/Includes/phone_unique.php';

$staff_portal = defined('MBUS_STAFF_PORTAL') && MBUS_STAFF_PORTAL === true;

/*
 * Conductor accounts:
 *   true  -> the person applies; they verify their email first, then an admin approves the application before a login exists
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
    /* The main license/vehicle controls allow multiple files. Most browsers split them into the
       numbered extra fields in JS, but mobile browsers can submit the original field as an array.
       Normalize that shape before validating the actual temporary file. */
    if (is_array($f['error'] ?? null)) {
        $index = null;
        foreach ($f['error'] as $i => $uploadError) {
            if ((int)$uploadError !== UPLOAD_ERR_NO_FILE) { $index = $i; break; }
        }
        if ($index === null) { $error = 'Please attach all required documents.'; return null; }
        foreach (['name', 'type', 'tmp_name', 'error', 'size', 'full_path'] as $key) {
            if (isset($f[$key]) && is_array($f[$key])) $f[$key] = $f[$key][$index] ?? null;
        }
    }
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Please attach all required documents.';
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $error = 'A document could not be uploaded. Try a smaller file.'; return null; }
    if ($f['size'] > 3 * 1024 * 1024) { $error = 'Each document must be 3 MB or smaller.'; return null; }
    $data = file_get_contents($f['tmp_name']);
    $mime = str_starts_with($data, "\xFF\xD8\xFF") ? 'image/jpeg' : (str_starts_with($data, "\x89PNG\r\n\x1a\n") ? 'image/png' : (str_starts_with($data, '%PDF-') ? 'application/pdf' : ''));
    if ($mime === '') { $error = 'Documents must be a JPG, PNG or PDF file.'; return null; }
    return ['b64' => base64_encode($data), 'mime' => $mime];
}

/** Optional extra page for a document: null when nothing was attached, error text only for a bad file. */
function mbus_application_extra_file(string $field, &$error): ?array {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    return mbus_application_file($field, $error);
}

/** Markup for an optional extra upload box (same look and behavior as the required ones). */
function mbus_extra_upload_html(string $name, string $label, ?string $err): string {
    return '<div class="upload-group extra-upload"><label class="input-label">' . $label . ' <span class="optional-tag">(optional)</span></label>'
        . '<div class="upload-document-group"><div class="upload-icon"><i data-lucide="plus"></i></div>'
        . '<div class="document-desc"><span class="document-name">Click to add another page</span></div>'
        . '<div class="document-input-button"><button type="button">Browse</button>'
        . '<input type="file" name="' . $name . '" accept=".jpg,.jpeg,.png,.pdf"/></div></div>'
        . ($err ? '<small class="field-error" role="alert">' . htmlspecialchars($err) . '</small>' : '')
        . '<div class="document-preview"><img class="document-preview-image" alt="' . $label . ' preview"/>'
        . '<iframe class="document-preview-frame" title="' . $label . ' preview"></iframe></div></div>';
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

/** Read the required 2x2 picture: a JPEG/PNG image only, up to 1 MB. */
function mbus_application_id_photo(string $field, &$error): ?array {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) { $error = 'Please attach your 2x2 picture.'; return null; }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $error = 'The picture could not be uploaded. Try a smaller image.'; return null; }
    if ($f['size'] > 1048576) { $error = 'The 2x2 picture must be 1 MB or smaller.'; return null; }
    $data = file_get_contents($f['tmp_name']);
    $mime = str_starts_with($data, "\xFF\xD8\xFF") ? 'image/jpeg' : (str_starts_with($data, "\x89PNG\r\n\x1a\n") ? 'image/png' : '');
    if ($mime === '') { $error = 'The 2x2 picture must be a JPG or PNG image.'; return null; }
    return ['b64' => base64_encode($data), 'mime' => $mime];
}

/**
 * Email the 6-digit code for a driver/conductor application and send the applicant to the verification page.
 * The application stays hidden from the admin until the code is entered (see verify_application.php).
 */
function mbus_staff_send_verification($conn, string $type, string $email, string $full_name, string $code): void {
    if ($type === 'driver') {   // drivers confirm with an authenticator app instead of an email code
        $_SESSION['staff_verify_email'] = $email;
        $_SESSION['staff_verify_type'] = 'driver';
        header('Location: verify_driver_authenticator.php');
        exit();
    }
    $mail_error = mbus_send_registration_code($email, $code, $full_name);
    if ($mail_error !== null) error_log('Staff application verification email failed: ' . (string)$mail_error);
    $_SESSION['staff_verify_email'] = $email;
    $_SESSION['staff_verify_type'] = $type;
    $_SESSION['staff_verify_mail_sent'] = $mail_error === null;
    $_SESSION['staff_verify_sent_at'] = time();
    header('Location: verify_application.php');
    exit();
}

/* ---------- user type ---------- */

$allowed_types  = $staff_portal ? ["conductor", "driver"] : ["passenger"];
$requested_type = $_GET['type'] ?? ($staff_portal ? 'driver' : 'passenger');

if (!$staff_portal && $_SERVER['REQUEST_METHOD'] !== 'POST'
    && in_array($requested_type, ['conductor', 'driver'], true)) {
    header('Location: https://mbus-registrations.onrender.com/register.php?type=' . rawurlencode($requested_type), true, 302);
    exit;
}

$user_type      = in_array($requested_type, $allowed_types, true) ? $requested_type : ($staff_portal ? 'driver' : 'passenger');

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

    $posted_type = $_POST["user_type"] ?? ($staff_portal ? 'driver' : 'passenger');
    $invalid_type = !in_array($posted_type, $allowed_types, true);
    $user_type = $invalid_type ? ($staff_portal ? 'driver' : 'passenger') : $posted_type;

    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please reload the page and try again.';
    } elseif ($invalid_type) {
        $error = 'This registration type is not available on this site.';
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

        /* every route (commuter, conductor) now needs a working verification email */
        if (!$errors && !mbus_registration_mail_is_configured()) {
            $error = 'Email verification is temporarily unavailable. Please try again later.';
        }

        if (!$errors && $error === '') {
            mbus_purge_unverified_signups($conn, $email, $phone);
            $phone_used = mbus_phone_in_use($conn, $phone);
            if ($phone_used === null) $error = 'Registration is temporarily unavailable. Please try again in a moment.';
            elseif ($phone_used) $errors['mobileNumber'] = 'That mobile number is already used by another account. Use a different number or log in.';
        }
        if (!$errors && $error === '') {
            /* only verified applications count as pending: an unverified one never reached the admin and is replaced below */
            $dup = mbus_db_prepare($conn,
                "SELECT 1 FROM users WHERE LOWER(email) = ?
                 UNION SELECT 1 FROM operator_application WHERE LOWER(email) = ? AND application_status::text = 'Pending' AND email_verified = TRUE
                 UNION SELECT 1 FROM conductor_application WHERE LOWER(email) = ? AND application_status = 'Pending' AND email_verified = TRUE",
                [$email, $email, $email]);
            if ($dup === false) {
                $error = 'Registration is temporarily unavailable. Please try again in a moment.';
            } elseif (mbus_db_num_rows($dup) > 0) {
                $errors['emailAddress'] = 'This email already has an account or a pending application. Log in, or wait for the admin to review it.';
            } else {
                $limit_reason = $is_conductor_application
                    ? (mbus_registration_allow_application($conn, $email) ?? mbus_registration_allow_code_send($conn, $email))
                    : mbus_registration_allow_code_send($conn, $email);
                if ($limit_reason !== null) {
                    $error = mbus_registration_limit_message($limit_reason, true);
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($is_conductor_application) {
                        $verify_code = (string)random_int(100000, 999999);
                        $verify_expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                        /* replace an earlier, never-verified attempt with the same email */
                        mbus_db_prepare($conn, "DELETE FROM conductor_application WHERE LOWER(email) = LOWER(?) AND email_verified = FALSE", [$email]);
                        $ok = mbus_db_prepare($conn, "INSERT INTO conductor_application (full_name, email, phone_number, password_hash, application_status, email_verified, verification_code, verification_code_expiry, verification_code_attempts) VALUES (?, ?, ?, ?, 'Pending', FALSE, ?, ?, 0)",
                            [$full_name, $email, $phone, $hash, $verify_code, $verify_expiry]);
                        if ($ok === false) { $error = 'We could not save your application. Please try again.'; }
                        else {
                            /* the admin is notified only after the email is verified (verify_application.php) */
                            mbus_staff_send_verification($conn, 'conductor', $email, $full_name, $verify_code);
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
        $first_name     = trim((string)($_POST['driver-first-name'] ?? ''));
        $last_name      = trim((string)($_POST['driver-last-name'] ?? ''));
        $email          = (string)($_POST['email-address'] ?? '');
        $phone_number   = (string)($_POST['phone-number'] ?? '');
        $address        = trim(preg_replace('/\s+/', ' ', (string)($_POST['home-address'] ?? '')));
        $password       = (string)($_POST['driver-password'] ?? '');
        $confirm        = (string)($_POST['driver-confirm'] ?? '');
        $license_number = (string)($_POST['license-number'] ?? '');
        $license_expiry = trim((string)($_POST['license-expiry'] ?? ''));
        $vehicle_type   = trim((string)($_POST['vehicle-type'] ?? ''));
        $plate          = (string)($_POST['vehicle-plate'] ?? '');
        $seatsRaw       = trim((string)($_POST['bus-seats'] ?? ''));
        $vehicle_expiry = trim((string)($_POST['vehicle-expiry'] ?? ''));

        /* first and last name are separate fields, saved together as "First Last" like every other account */
        if ($first_name === '') $errors['driver-first-name'] = 'Enter your first name.';
        if ($last_name === '')  $errors['driver-last-name']  = 'Enter your last name.';
        $full_name = trim($first_name . ' ' . $last_name);
        if ($first_name !== '' && $last_name !== '' && ($e = mbus_check_name($full_name))) $errors['driver-first-name'] = $e;
        if ($e = mbus_check_email($email)) $errors['email-address'] = $e;
        if ($e = mbus_check_phone($phone_number)) $errors['phone-number'] = $e;
        if (mb_strlen($address) < 8) $errors['home-address'] = 'Enter your complete address (at least 8 characters).';
        elseif (mb_strlen($address) > 300) $errors['home-address'] = 'Address is too long (300 characters at most).';
        if ($e = mbus_check_password($password)) $errors['driver-password'] = $e;
        elseif ($password !== $confirm) $errors['driver-confirm'] = 'Passwords do not match.';
        if ($e = mbus_check_license_number($license_number)) $errors['license-number'] = $e;
        /* ID expiry is optional: some IDs have none. When given it must be a real, unexpired date. */
        $d = DateTime::createFromFormat('Y-m-d', $license_expiry);
        if ($license_expiry === '') { /* nothing to check */ }
        elseif (!$d || $d->format('Y-m-d') !== $license_expiry) $errors['license-expiry'] = 'Enter a valid expiry date or leave it blank.';
        elseif ($license_expiry < date('Y-m-d')) $errors['license-expiry'] = 'This ID has expired. Apply again with a valid ID.';
        elseif ($license_expiry > date('Y-m-d', strtotime('+15 years'))) $errors['license-expiry'] = 'That expiry date is too far ahead. Check the year.';
        if (!in_array($vehicle_type, ['Ordinary', 'Aircon'], true)) $errors['vehicle-type'] = 'Choose a bus type (Ordinary or Aircon).';
        if ($e = mbus_check_plate($plate)) $errors['vehicle-plate'] = $e;
        $seats = ctype_digit($seatsRaw) ? (int)$seatsRaw : 0;
        /* The applicant enters passenger seats. Two additional layout positions are provisioned so one
           two-seat pair can later be converted into the single supported door without losing the intended count. */
        $maxPassengerSeats = MBUS_MAX_SEATS - 2;
        if ($seats < 10 || $seats > $maxPassengerSeats) $errors['bus-seats'] = 'Enter how many passenger seats your bus has (10 to ' . $maxPassengerSeats . ').';
        /* registration expiry is optional: only documents that show one */
        if ($vehicle_expiry !== '') {
            $vd = DateTime::createFromFormat('Y-m-d', $vehicle_expiry);
            if (!$vd || $vd->format('Y-m-d') !== $vehicle_expiry) $errors['vehicle-expiry'] = 'Enter a valid registration expiry date, or leave it empty.';
            elseif ($vehicle_expiry < date('Y-m-d')) $errors['vehicle-expiry'] = 'This vehicle registration has expired. Renew it before applying.';
            elseif ($vehicle_expiry > date('Y-m-d', strtotime('+15 years'))) $errors['vehicle-expiry'] = 'That expiry date is too far ahead. Check the year.';
        }
        $_POST['email-address'] = $email; $_POST['phone-number'] = $phone_number;
        $_POST['driver-first-name'] = $first_name; $_POST['driver-last-name'] = $last_name;
        $_POST['license-number'] = $license_number; $_POST['vehicle-plate'] = $plate; $_POST['home-address'] = $address;

        if (!isset($errors['email-address'])) {
            /* only verified applications count as pending: an unverified one never reached the admin and is replaced below */
            $dup = mbus_db_prepare($conn,
                "SELECT 1 FROM users WHERE LOWER(email) = ?
                 UNION SELECT 1 FROM operator_application WHERE LOWER(email) = ? AND application_status::text = 'Pending' AND email_verified = TRUE
                 UNION SELECT 1 FROM conductor_application WHERE LOWER(email) = ? AND application_status = 'Pending' AND email_verified = TRUE",
                [$email, $email, $email]);
            if ($dup !== false && mbus_db_num_rows($dup) > 0) $errors['email-address'] = 'This email already has an account or a pending application. Log in, or wait for the admin to review it.';
        }
        if (!isset($errors['phone-number'])) {
            $phone_used = mbus_phone_in_use($conn, $phone_number);
            if ($phone_used === null) $error = 'Registration is temporarily unavailable. Please try again in a moment.';
            elseif ($phone_used) $errors['phone-number'] = 'That mobile number is already used by another account. Use a different number or log in.';
        }
        /* the application is sent to the admin only after the applicant verifies their email, so mail must work */
        if (!$errors && $error === '' && !mbus_registration_mail_is_configured()) {
            $error = 'Email verification is temporarily unavailable. Please try again later.';
        }
        if (!$errors && $error === '') {
            $application_limit = mbus_registration_allow_application($conn, $email) ?? mbus_registration_allow_code_send($conn, $email);
            if ($application_limit !== null) $error = mbus_registration_limit_message($application_limit, true);
        }
        $lic = $veh = $busPhoto = $interiorPhoto = $idPhoto = $medical = $nbi = null;
        if (!$errors && $error === '') {
            $fe = ''; $lic = mbus_application_file('license_document', $fe);
            if (!$lic) $errors['license_document'] = 'License file: ' . $fe;
            $fe = ''; $idPhoto = mbus_application_id_photo('id_photo', $fe);
            if (!$idPhoto) $errors['id_photo'] = '2x2 picture: ' . $fe;
            $fe = ''; $medical = mbus_application_file('medical_document', $fe);
            if (!$medical) $errors['medical_document'] = 'Medical certificate with drug test: ' . $fe;
            $fe = ''; $nbi = mbus_application_file('nbi_document', $fe);
            if (!$nbi) $errors['nbi_document'] = 'NBI clearance: ' . $fe;
            $fe = ''; $veh = mbus_application_file('vehicle_document', $fe);
            if (!$veh) $errors['vehicle_document'] = 'Vehicle registration file: ' . $fe;
            $extras = [];
            foreach (['license_document_2' => "Driver's license (extra page)", 'license_document_3' => "Driver's license (extra page)",
                      'vehicle_document_2' => 'Vehicle registration (extra page)', 'vehicle_document_3' => 'Vehicle registration (extra page)'] as $f => $lbl) {
                $fe = ''; $extras[$f] = mbus_application_extra_file($f, $fe);
                if ($fe !== '') $errors[$f] = $lbl . ': ' . $fe;
            }
            $fe = ''; $busPhoto = mbus_application_photo('bus_photo', $fe);
            if (!$busPhoto) $errors['bus_photo'] = 'Bus exterior photo: ' . $fe;
            $fe = ''; $interiorPhoto = mbus_application_photo('interior_photo', $fe);
            if (!$interiorPhoto) $errors['interior_photo'] = 'Bus interior photo: ' . $fe;
        }
        if (!$errors && $error === '') {
            $verify_code = '';
            $totp_secret = mbus_totp_new_secret();
            /* replace an earlier, never-verified attempt with the same email */
            mbus_db_prepare($conn, "DELETE FROM operator_application WHERE LOWER(email) = LOWER(?) AND email_verified = FALSE", [$email]);
            $ok = mbus_db_prepare($conn,
                "INSERT INTO operator_application
                    (full_name, email, phone_number, address, license_number, license_expiry, vehicle_type, vehicle_plate_number,
                     license_document_b64, license_document_mime, vehicle_document_b64, vehicle_document_mime, password_hash,
                     bus_photo_b64, bus_photo_mime, interior_photo_b64, interior_photo_mime, last_name, vehicle_registration_expiry, seat_layout,
                     id_photo_b64, id_photo_mime, medical_document_b64, medical_document_mime, nbi_document_b64, nbi_document_mime,
                     license_document_2_b64, license_document_2_mime, license_document_3_b64, license_document_3_mime,
                     vehicle_document_2_b64, vehicle_document_2_mime, vehicle_document_3_b64, vehicle_document_3_mime,
                     email_verified, verification_code_attempts, application_status, totp_secret)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE, 0, 'Pending', ?)",
                [$full_name, $email, $phone_number, $address, $license_number, ($license_expiry === "" ? null : $license_expiry), $vehicle_type, $plate,
                 $lic['b64'], $lic['mime'], $veh['b64'], $veh['mime'], password_hash($password, PASSWORD_DEFAULT),
                 $busPhoto['b64'], $busPhoto['mime'], $interiorPhoto['b64'], $interiorPhoto['mime'],
                 $last_name, $vehicle_expiry !== '' ? $vehicle_expiry : null, json_encode(mbus_seat_layout_with_door_room($seats)),
                 $idPhoto['b64'], $idPhoto['mime'], $medical['b64'], $medical['mime'], $nbi['b64'], $nbi['mime'],
                 $extras['license_document_2']['b64'] ?? null, $extras['license_document_2']['mime'] ?? null,
                 $extras['license_document_3']['b64'] ?? null, $extras['license_document_3']['mime'] ?? null,
                 $extras['vehicle_document_2']['b64'] ?? null, $extras['vehicle_document_2']['mime'] ?? null,
                 $extras['vehicle_document_3']['b64'] ?? null, $extras['vehicle_document_3']['mime'] ?? null,
                 $totp_secret]);
            if ($ok === false) { $error = 'We could not save your application. Please try again.'; }
            else {
                /* the admin is notified only after the email is verified (verify_application.php) */
                mbus_staff_send_verification($conn, 'driver', $email, $full_name, $verify_code);
            }
        }
    }

    if ($errors && $error === '') $error = 'Please fix the highlighted fields.';
    // Driver form is a 3-step wizard: reopen on the first step that has an error
    $stepOf = ['driver-first-name'=>0,'driver-last-name'=>0,'email-address'=>0,'phone-number'=>0,'home-address'=>0,'driver-password'=>0,'driver-confirm'=>0,
               'license-number'=>1,'license-expiry'=>1,'license_document'=>1,'license_document_2'=>1,'license_document_3'=>1,'id_photo'=>1,'medical_document'=>1,'nbi_document'=>1,
               'vehicle-type'=>2,'vehicle-plate'=>2,'bus-seats'=>2,'vehicle-expiry'=>2,'vehicle_document'=>2,'vehicle_document_2'=>2,'vehicle_document_3'=>2,
               'bus_photo'=>2,'interior_photo'=>2];
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
        ? "Apply to validate tickets and assist passengers on board. Verify your email first; an admin then reviews your application."
        : "Register to validate tickets and assist passengers on board.",
    "driver"    => "Register to manage assigned routes and passenger trips. Verify your email first; an admin then reviews your application."
];
$picker_text = $picker_messages[$user_type];

if ($user_type === "conductor") {
    $submit_text = $conductor_requires_approval ? "Submit & verify email" : "Create conductor account";
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
    <body class="auth-register<?= $staff_portal ? ' staff-registration' : '' ?>">
        <div class="auth-hero-m">
            <h1><?= $staff_portal ? 'Apply for an MBUS staff role' : 'Create your account' ?></h1>
            <p><?= $staff_portal ? 'Choose Conductor or Driver to start an application.' : 'Plan your next bus trip in just a few steps.' ?></p>
        </div>
        <div class="left-container">
            <div class="left-container-child-1">
                <div class="logo-wordmark">
                    <img src="Assets/images/mbus_logo_light.png" alt="logo">
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
                    <?php if (!$staff_portal): ?>
                    <a href="login.php" role="tab">Login</a>
                    <a href="register.php" class="on" role="tab">Register</a>
                    <?php else: ?>
                    <a href="register.php?type=conductor" class="<?= $user_type === 'conductor' ? 'on' : '' ?>" role="tab">Conductor</a>
                    <a href="register.php?type=driver" class="<?= $user_type === 'driver' ? 'on' : '' ?>" role="tab">Driver</a>
                    <?php endif; ?>
                </div>
                <div class="form-header">
                    <div class="form-header-text">
                        <h1><?= $staff_portal ? 'Apply to join MBUS' : 'Create your Account' ?></h1>
                        <p><?= $staff_portal ? 'Verify your email, then an admin reviews your application before staff access is granted.' : 'Plan your next bus trip in just a few steps.' ?></p>
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
                                <small class="field-hint" id="email-verification-hint">We’ll send a 6-digit code to this address. <?= $user_type === 'conductor' ? 'Your application goes to the admin only after you verify it; the code expires in 15 minutes.' : 'Your account becomes active after verification; the code expires in 15 minutes.' ?></small>
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
                                    <div class="first-name">
                                        <label class="input-label">First Name</label>
                                        <input type="text" name="driver-first-name" placeholder="Juan" maxlength="50" autocomplete="given-name" value="<?= reg_old('driver-first-name') ?>" required/>
                                        <i class="input-icon" data-lucide="user"></i>
                                    <?= mbus_field_error($errors, 'driver-first-name') ?></div>
                                    <div class="last-name">
                                        <label class="input-label">Last Name</label>
                                        <input type="text" name="driver-last-name" placeholder="Dela Cruz" maxlength="50" autocomplete="family-name" value="<?= reg_old('driver-last-name') ?>" required/>
                                        <i class="input-icon" data-lucide="user"></i>
                                    <?= mbus_field_error($errors, 'driver-last-name') ?></div>
                                </div>
                                <div class="input-group">
                                    <div class="email-address">
                                        <label class="input-label">Email Address</label>
                                        <input type="email" name="email-address" placeholder="Enter your email" maxlength="254" autocapitalize="none" spellcheck="false" value="<?= reg_old('email-address') ?>" required/>
                                        <i class="input-icon" data-lucide="mail"></i>
                                    <?= mbus_field_error($errors, 'email-address') ?>
                                        <small class="field-hint">We’ll send a 6-digit code to verify this email. Your application goes to the admin only after you verify it.</small></div>
                                    <div class="phone-number">
                                        <label>Phone Number</label>
                                <div class="phone-input"><span class="phone-prefix" aria-hidden="true">+63</span><input type="text" name="phone-number" placeholder="9XXXXXXXXX" maxlength="10" minlength="10" inputmode="numeric" pattern="9[0-9]{9}" value="<?= preg_replace('/^0/', '', reg_old('phone-number')) ?>" required/></div>
                                    <?= mbus_field_error($errors, 'phone-number') ?></div>
                                </div>
                                <div class="input-group">
                                    <div class="home-address" style="grid-column:1 / -1">
                                        <label>Address</label>
                                        <div class="addr-picker" id="addressPicker">
                                            <select disabled aria-label="Country"><option>Philippines</option></select>
                                            <select data-addr="region" aria-label="Region" required><option value="">Loading regions…</option></select>
                                            <select data-addr="province" aria-label="Province" disabled required><option value="">Province</option></select>
                                            <select data-addr="city" aria-label="City or municipality" disabled required><option value="">City / Municipality</option></select>
                                            <select data-addr="barangay" aria-label="Barangay" disabled required><option value="">Barangay</option></select>
                                            <input type="text" data-addr="street" placeholder="House no., street, subdivision" maxlength="120" autocomplete="street-address" required/>
                                            <input type="text" data-addr="manual" placeholder="Full address" maxlength="300" hidden/>
                                            <small class="field-hint" data-addr="note" hidden></small>
                                            <input type="hidden" name="home-address" id="home-address" value="<?= reg_old('home-address') ?>"/>
                                        </div>
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
                                <h3 class="group-name">License &amp; Requirements</h3>
                            </div>
                            <div class="form-inputs">
                                <div class="input-group">
                                    <div class="license-number">
                                        <label class="input-label">License Number</label>
                                        <input type="text" name="license-number" placeholder="Enter your license number" maxlength="20" value="<?= reg_old('license-number') ?>" required/>
                                        <i class="input-icon" data-lucide="user"></i>
                                    <?= mbus_field_error($errors, 'license-number') ?></div>
                                    <div class="license-expiry">
                                        <label class="input-label">ID Expiry Date <span class="optional-tag">(if applicable)</span></label>
                                        <input type="date" name="license-expiry" min="<?= date('Y-m-d') ?>" value="<?= reg_old('license-expiry') ?>"/>
                                        <i class="input-icon" data-lucide="calendar"></i>
                                    <?= mbus_field_error($errors, 'license-expiry') ?></div>
                                </div>
                                <div class="upload-group" data-multi data-extra-prefix="license_document">
                                    <label class="input-label">Updated Driver's License <span class="optional-tag">(up to 3 files)</span></label>
                                    <div class="upload-document-group">
                                        <div class="upload-icon">
                                            <i data-lucide="upload"></i>
                                        </div>
                                        <div class="document-desc">
                                            <span class="document-name">Click to upload</span>
                                            <span class="document-hint">Pick up to 3 photos at once</span>
                                        </div>
                                        <div class="document-input-button">
                                            <button type="button">Browse</button>
                                            <input type="file" name="license_document" accept=".jpg,.jpeg,.png,.pdf" multiple required/>
                                        </div>
                                    </div>
                                    <?= mbus_field_error($errors, 'license_document') ?><?= mbus_field_error($errors, 'license_document_2') ?><?= mbus_field_error($errors, 'license_document_3') ?>
                                    <div class="document-preview">
                                        <img class="document-preview-image" alt="License document preview"/>
                                        <iframe class="document-preview-frame" title="License document preview"></iframe>
                                    </div>
                                </div>
                                <div class="extra-uploads" data-extra-for="license_document" hidden>
                                    <?= mbus_extra_upload_html('license_document_2', 'License document, page 2', null) ?>
                                    <?= mbus_extra_upload_html('license_document_3', 'License document, page 3', null) ?>
                                </div>
                                <div class="upload-group">
                                    <label class="input-label" for="id-photo-input">2x2 Picture</label>
                                    <div class="upload-document-group">
                                        <div class="upload-icon"><i data-lucide="camera"></i></div>
                                        <div class="document-desc">
                                            <span class="document-name">Click to upload</span>
                                        </div>
                                        <div class="document-input-button">
                                            <button type="button">Browse</button>
                                            <input id="id-photo-input" type="file" name="id_photo" accept="image/jpeg,image/png" data-max-bytes="1048576" required/>
                                        </div>
                                    </div>
                                    <?= mbus_field_error($errors, 'id_photo') ?>
                                    <div class="document-preview">
                                        <img class="document-preview-image" alt="2x2 picture preview"/>
                                        <iframe class="document-preview-frame" title="2x2 picture preview"></iframe>
                                    </div>
                                </div>
                                <div class="upload-group">
                                    <label class="input-label">Medical Certificate with Drug Test</label>
                                    <div class="upload-document-group">
                                        <div class="upload-icon">
                                            <i data-lucide="upload"></i>
                                        </div>
                                        <div class="document-desc">
                                            <span class="document-name">Click to upload</span>
                                        </div>
                                        <div class="document-input-button">
                                            <button type="button">Browse</button>
                                            <input type="file" name="medical_document" accept=".jpg,.jpeg,.png,.pdf" required/>
                                        </div>
                                    </div>
                                    <?= mbus_field_error($errors, 'medical_document') ?>
                                    <div class="document-preview">
                                        <img class="document-preview-image" alt="Medical certificate preview"/>
                                        <iframe class="document-preview-frame" title="Medical certificate preview"></iframe>
                                    </div>
                                </div>
                                <div class="upload-group">
                                    <label class="input-label">NBI Clearance</label>
                                    <div class="upload-document-group">
                                        <div class="upload-icon">
                                            <i data-lucide="upload"></i>
                                        </div>
                                        <div class="document-desc">
                                            <span class="document-name">Click to upload</span>
                                        </div>
                                        <div class="document-input-button">
                                            <button type="button">Browse</button>
                                            <input type="file" name="nbi_document" accept=".jpg,.jpeg,.png,.pdf" required/>
                                        </div>
                                    </div>
                                    <?= mbus_field_error($errors, 'nbi_document') ?>
                                    <div class="document-preview">
                                        <img class="document-preview-image" alt="NBI clearance preview"/>
                                        <iframe class="document-preview-frame" title="NBI clearance preview"></iframe>
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
                                    <div class="bus-seats">
                                        <label class="input-label">Number of Seats</label>
                                        <input type="text" name="bus-seats" inputmode="numeric" pattern="[0-9]{2,3}" maxlength="3" title="Enter 10 to <?= MBUS_MAX_SEATS - 2 ?> passenger seats" placeholder="e.g. 30" value="<?= reg_old('bus-seats') ?>" required/>
                                        <i class="input-icon" data-lucide="armchair"></i>
                                    <?= mbus_field_error($errors, 'bus-seats') ?></div>
                                    <div class="vehicle-expiry">
                                        <label class="input-label">Registration Expiry Date <small style="font-weight:500;color:#8a98ae">(if your document shows one)</small></label>
                                        <input type="date" name="vehicle-expiry" min="<?= date('Y-m-d') ?>" value="<?= reg_old('vehicle-expiry') ?>"/>
                                    <?= mbus_field_error($errors, 'vehicle-expiry') ?></div>
                                </div>
                                <div class="upload-group" data-multi data-extra-prefix="vehicle_document">
                                    <label class="input-label">Upload Vehicle Registration Document <span class="optional-tag">(up to 3 files)</span></label>
                                    <div class="upload-document-group">
                                        <div class="upload-icon">
                                            <i data-lucide="upload"></i>
                                        </div>
                                        <div class="document-desc">
                                            <span class="document-name">Click to upload</span>
                                            <span class="document-hint">Pick up to 3 photos at once</span>
                                        </div>
                                        <div class="document-input-button">
                                            <button type="button">Browse</button>
                                            <input type="file" name="vehicle_document" accept=".jpg,.jpeg,.png,.pdf" multiple required/>
                                        </div>
                                    </div>
                                    <?= mbus_field_error($errors, 'vehicle_document') ?><?= mbus_field_error($errors, 'vehicle_document_2') ?><?= mbus_field_error($errors, 'vehicle_document_3') ?>
                                    <div class="document-preview">
                                        <img class="document-preview-image" alt="Vehicle registration document preview"/>
                                        <iframe class="document-preview-frame" title="Vehicle registration document preview"></iframe>
                                    </div>
                                </div>
                                <div class="extra-uploads" data-extra-for="vehicle_document" hidden>
                                    <?= mbus_extra_upload_html('vehicle_document_2', 'Vehicle registration document, page 2', null) ?>
                                    <?= mbus_extra_upload_html('vehicle_document_3', 'Vehicle registration document, page 3', null) ?>
                                </div>
                                <div class="bus-photo-grid">
                                    <div class="upload-group">
                                        <label class="input-label" for="bus-photo-input">Bus Exterior Photo</label>
                                        <div class="upload-document-group">
                                            <div class="upload-icon"><i data-lucide="camera"></i></div>
                                            <div class="document-desc"><span class="document-name">Click to upload</span></div>
                                            <div class="document-input-button"><button type="button">Browse</button><input id="bus-photo-input" type="file" name="bus_photo" accept="image/jpeg,image/png" data-max-bytes="786432" required/></div>
                                        </div>
                                        <?= mbus_field_error($errors, 'bus_photo') ?>
                                        <div class="document-preview"><img class="document-preview-image" alt="Bus exterior photo preview"/><iframe class="document-preview-frame" title="Bus exterior photo preview"></iframe></div>
                                    </div>
                                    <div class="upload-group">
                                        <label class="input-label" for="interior-photo-input">Bus Interior Photo</label>
                                        <div class="upload-document-group">
                                            <div class="upload-icon"><i data-lucide="camera"></i></div>
                                            <div class="document-desc"><span class="document-name">Click to upload</span></div>
                                            <div class="document-input-button"><button type="button">Browse</button><input id="interior-photo-input" type="file" name="interior_photo" accept="image/jpeg,image/png" data-max-bytes="786432" required/></div>
                                        </div>
                                        <?= mbus_field_error($errors, 'interior_photo') ?>
                                        <div class="document-preview"><img class="document-preview-image" alt="Bus interior photo preview"/><iframe class="document-preview-frame" title="Bus interior photo preview"></iframe></div>
                                    </div>
                                </div>
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
                                <button class="submit-button step-button-submit" id="step-submit" type="submit">Submit &amp; Verify Email</button>
                            </div>
                            <div class="redirect-footer">
                                <div class="accounts-redirect">
                                    <p>Already have an account? <a style="color: #0877DF;" href="login.php">Login here</a></p>
                                    <?php if (!$staff_portal): ?><p>Want to book a ride? <a style="color: #0877DF;" href="register.php?type=passenger"> Register as Commuter </a> </p><?php endif; ?>
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
        <script src="register.js?v=<?= @filemtime(__DIR__ . "/register.js") ?>"></script>
        <script src="Assets/js/ph_address.js?v=2"></script>
    <script src="Assets/js/driver_bus_setup.js?v=<?= @filemtime(__DIR__ . '/Assets/js/driver_bus_setup.js') ?>"></script>
        <script src="Assets/js/license_ocr.js?v=<?= @filemtime(__DIR__ . "/Assets/js/license_ocr.js") ?>"></script>
        <script> if (window.lucide) lucide.createIcons(); </script>
        <script src="Assets/js/password-toggle.js"></script>
        <?php if (!empty($success)): ?><script>try{localStorage.removeItem("mbus_reg_draft_v1")}catch(e){}</script><?php endif; ?>
        <script src="Assets/js/register_draft.js?v=<?= @filemtime(__DIR__ . '/Assets/js/register_draft.js') ?>"></script>

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
