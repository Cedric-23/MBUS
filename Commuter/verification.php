<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/csrf.php";
include "../Includes/fare.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}
$user_id = (int)$_SESSION['user_id'];
$error = ""; $success = "";
$types = ['Student' => 'Student', 'Senior' => 'Senior citizen', 'PWD' => 'Person with disability'];
$max_bytes = 5 * 1024 * 1024;

function mbus_latest_verification($conn, int $user_id) {
    $q = mbus_db_prepare($conn, "
        SELECT verification_id, verification_type, verification_status, rejection_reason, expiry_date, submitted_at
        FROM commuter_verification WHERE user_id = ?
        ORDER BY verification_id DESC LIMIT 1", [$user_id]);
    return $q === false ? false : mbus_db_fetch_assoc($q);
}

if(isset($_POST['submit_verification'])){
    $latest = mbus_latest_verification($conn, $user_id);
    $type = $_POST['verification_type'] ?? '';
    $number = trim((string)($_POST['document_number'] ?? ''));
    $expiry = trim((string)($_POST['expiry_date'] ?? ''));
    $file = $_FILES['verification_document'] ?? null;

    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } elseif($latest && $latest['verification_status'] === 'Pending'){
        $error = "You already have a request under review.";
    } elseif(!isset($types[$type])){
        $error = "Choose your passenger type.";
    } elseif($number === '' || strlen($number) > 50){
        $error = "Enter the ID number shown on your ID.";
    } elseif($expiry !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || $expiry < date('Y-m-d'))){
        $error = "Your ID is expired. Use a valid ID.";
    } elseif(!$file || $file['error'] !== UPLOAD_ERR_OK){
        $error = "Upload a photo of your ID.";
    } elseif($file['size'] > $max_bytes){
        $error = "File is too large. Maximum is 5 MB.";
    } else {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if(!in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)){
            $error = "Only JPG, PNG or PDF files are allowed.";
        } else {
            $b64 = base64_encode(file_get_contents($file['tmp_name']));
            $ok = mbus_db_prepare($conn, "
                INSERT INTO commuter_verification (user_id, verification_type, document_number, expiry_date, document_b64, document_mime)
                VALUES (?, ?, ?, ?, ?, ?)",
                [$user_id, $type, $number, $expiry === '' ? null : $expiry, $b64, $mime]);
            if($ok === false){
                $error = "Could not submit your request. Please try again.";
            } else {
                log_activity($conn, $user_id, ACTION_VERIFICATION_SUBMIT, "Submitted $type verification");
                mbus_db_prepare($conn, "UPDATE users SET verification_status = 'Pending' WHERE user_id = ?", [$user_id]);
                $success = "Submitted. We'll review your ID shortly.";
            }
        }
    }
}

$latest = mbus_latest_verification($conn, $user_id);
$current = mbus_passenger_type($conn, $user_id);
$status = $latest['verification_status'] ?? null;
$can_submit = !$latest || $status === 'Rejected' || ($status === 'Approved' && $current === 'Regular');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification - MBUS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_mobile.css">
    <link rel="stylesheet" href="../Assets/css/commuter_verification.css">
</head>
<body class="verify-page has-bottom-nav">
<main class="main" role="main">

<header class="page-header">
    <div class="page-header-left">
        <a href="<?php echo ($_GET['from'] ?? '') === 'profile' ? 'profile.php' : 'commuter_dashboard.php'; ?>" class="back-btn" aria-label="Go back"><i class="fa fa-arrow-left" aria-hidden="true"></i></a>
        <h1>Get 20% off</h1>
    </div>
</header>

<?php if($success){ ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php } ?>
<?php if($error){ ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php } ?>

<section class="card v-status">
    <span class="v-label">Your passenger type</span>
    <p class="v-type v-<?php echo strtolower($current); ?>"><?php echo htmlspecialchars($current); ?></p>
    <?php if($current !== 'Regular'){ ?>
        <p class="v-note">20% off the fare on your own seat. Reservation fee stays the same.<?php if(!empty($latest['expiry_date'])){ echo ' ID valid until ' . htmlspecialchars(date('M j, Y', strtotime($latest['expiry_date']))) . '.'; } ?></p>
    <?php } elseif($status === 'Pending'){ ?>
        <p class="v-note">Your <?php echo htmlspecialchars($latest['verification_type']); ?> request is under review. You'll pay the regular fare until it's approved.</p>
    <?php } elseif($status === 'Rejected'){ ?>
        <p class="v-note v-reject">Your last request was rejected<?php echo !empty($latest['rejection_reason']) ? ': ' . htmlspecialchars($latest['rejection_reason']) : '.'; ?> Submit a clearer photo below.</p>
    <?php } else { ?>
        <p class="v-note">Students, senior citizens and PWD get 20% off. Everyone else is Regular, no action needed.</p>
    <?php } ?>
</section>

<?php if($can_submit){ ?>
<form method="POST" enctype="multipart/form-data" class="card v-form" novalidate>
    <?php echo csrf_field(); ?>
    <fieldset>
        <legend>I am a</legend>
        <div class="v-types">
            <?php foreach($types as $key => $label){ ?>
            <label class="v-choice">
                <input type="radio" name="verification_type" value="<?php echo $key; ?>" required>
                <span><strong><?php echo $key; ?></strong><small><?php echo $label; ?></small></span>
            </label>
            <?php } ?>
        </div>
    </fieldset>

    <div class="form-group">
        <label for="document_number">ID number</label>
        <input class="form-control" id="document_number" name="document_number" maxlength="50" autocomplete="off" required>
    </div>
    <div class="form-group">
        <label for="expiry_date">ID expiry date <small>(if it has one)</small></label>
        <input class="form-control" type="date" id="expiry_date" name="expiry_date" min="<?php echo date('Y-m-d'); ?>">
    </div>
    <div class="form-group">
        <label for="verification_document">Photo of your ID</label>
        <label class="v-file" for="verification_document"><i class="fa fa-camera" aria-hidden="true"></i> <span id="fileName">Take or choose a photo</span></label>
        <input type="file" id="verification_document" name="verification_document" accept="image/jpeg,image/png,application/pdf" required>
        <small class="form-hint">JPG, PNG or PDF, up to 5 MB. Student ID, senior citizen ID or PWD ID.</small>
    </div>
    <button class="btn btn-primary btn-block" type="submit" name="submit_verification">Submit for review</button>
</form>
<script>
document.getElementById('verification_document').addEventListener('change', function(){
    document.getElementById('fileName').textContent = this.files[0] ? this.files[0].name : 'Take or choose a photo';
});
</script>
<?php } ?>

</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="schedule.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span></a>
    <a href="live.php" class="bottom-nav-item"><i class="fa fa-location-dot" aria-hidden="true"></i><span>Live</span></a>
    <a href="my_reservations.php" class="bottom-nav-item"><i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>
</body>
</html>
