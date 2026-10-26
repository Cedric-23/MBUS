<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/email_helper.php";
include "../Includes/csrf.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* HANDLE VERIFICATION SUBMISSION */
if(isset($_POST['submit_verification'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $verification_type = mbus_db_escape($conn, $_POST['verification_type']);
        $document_number = mbus_db_escape($conn, $_POST['document_number']);
        $expiry_date = mbus_db_escape($conn, $_POST['expiry_date']);
        
        // Handle file upload
        if(isset($_FILES['verification_document']) && $_FILES['verification_document']['error'] == 0){
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $filename = $_FILES['verification_document']['name'];
            $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if(in_array($file_ext, $allowed)){
                $new_filename = "verification_" . $user_id . "_" . time() . "." . $file_ext;
                $upload_path = "../Assets/verification_documents/" . $new_filename;
                
                // Create directory if it doesn't exist
                if(!file_exists("../Assets/verification_documents/")){
                    mkdir("../Assets/verification_documents/", 0777, true);
                }
                
                if(move_uploaded_file($_FILES['verification_document']['tmp_name'], $upload_path)){
                    // Check if user already has a pending verification using prepared statement
                    $existing_check = mbus_db_prepare($conn, "
                        SELECT verification_id FROM commuter_verification
                        WHERE user_id = ? AND verification_status = 'Pending'
                    ", [$user_id]);
                    
                    if(mbus_db_num_rows($existing_check) > 0){
                        $error = "You already have a pending verification. Please wait for it to be reviewed.";
                    } else {
                        // Insert verification record using prepared statement
                        mbus_db_prepare($conn, "
                            INSERT INTO commuter_verification (user_id, verification_type, document_path, document_number, expiry_date)
                            VALUES (?, ?, ?, ?, ?)
                        ", [$user_id, $verification_type, $new_filename, $document_number, $expiry_date]);
                        
                        // Log verification submission
                        log_activity($conn, $user_id, ACTION_VERIFICATION_SUBMIT, "Submitted $verification_type verification document");
                        
                        // Send email notification
                        $user_email_query = mbus_db_query($conn, "SELECT email FROM users WHERE user_id = '$user_id'");
                        $user_email_data = mbus_db_fetch_assoc($user_email_query);
                        $user_email = $user_email_data['email'];
                        
                        $email_subject = "Verification Submitted - " . $verification_type;
                        $email_message = "Dear Commuter,

Your $verification_type verification document has been submitted successfully and is pending admin review.

Thank you for using MBUS!";
                        send_email_notification($user_email, $email_subject, $email_message);
                        
                        $success = "Verification submitted successfully. Please wait for admin approval.";
                    }
                } else {
                    $error = "Failed to upload document";
                }
            } else {
                $error = "Invalid file type. Only JPG, JPEG, PNG, and PDF allowed";
            }
        } else {
            $error = "Please upload a document";
        }
    }
}

/* GET EXISTING VERIFICATION STATUS - using prepared statement */
$verification_query = mbus_db_prepare($conn, "
    SELECT verification_type, verification_status, submitted_at, reviewed_at, rejection_reason
    FROM commuter_verification
    WHERE user_id = ?
    ORDER BY submitted_at DESC
    LIMIT 1
", [$user_id]);

$verification_data = mbus_db_fetch_assoc($verification_query);
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
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/verification.css">
</head>
<body class="has-sidebar">

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle btn btn-icon" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
    <i class="fa fa-bars" aria-hidden="true"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()" aria-hidden="true"></div>

<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="logo-container">
        <img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo">
    </div>
    
    <h2>Commuter</h2>
    
    <nav class="menu-top" aria-label="Commuter menu">
            <a href="commuter_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
            <a href="my_reservations.php"><i class="fa fa-ticket" aria-hidden="true"></i> My Reservations</a>
            <a href="history.php"><i class="fa fa-history" aria-hidden="true"></i> History</a>
            <a href="profile.php"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
            <a href="verification.php" aria-current="page"><i class="fa fa-id-card" aria-hidden="true"></i> Verification</a>
        </nav>
    
    <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
        <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
    </a>
</aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Commuter Verification</h1>
</header>

<div class="verification-container">
    <!-- Current Status -->
    <section class="card status-section">
        <h2 class="section-title">
            <i class="fa fa-shield-alt" aria-hidden="true"></i> Current Verification Status
        </h2>
        
        <?php if($verification_data): ?>
            <div class="status-card status-<?php echo strtolower($verification_data['verification_status']); ?>">
                <div class="status-icon">
                    <?php if($verification_data['verification_status'] == 'Pending'): ?>
                        <i class="fa fa-clock" aria-hidden="true"></i>
                    <?php elseif($verification_data['verification_status'] == 'Approved'): ?>
                        <i class="fa fa-check-circle" aria-hidden="true"></i>
                    <?php else: ?>
                        <i class="fa fa-times-circle" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>
                <div class="status-details">
                    <h3><?php echo htmlspecialchars($verification_data['verification_status']); ?></h3>
                    <p>Type: <?php echo htmlspecialchars($verification_data['verification_type']); ?></p>
                    <p>Submitted: <?php echo date('F d, Y g:i A', strtotime($verification_data['submitted_at'])); ?></p>
                    <?php if($verification_data['reviewed_at']): ?>
                        <p>Reviewed: <?php echo date('F d, Y g:i A', strtotime($verification_data['reviewed_at'])); ?></p>
                    <?php endif; ?>
                    <?php if($verification_data['rejection_reason']): ?>
                        <p class="rejection-reason">Reason: <?php echo htmlspecialchars($verification_data['rejection_reason']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="no-verification">
                <i class="fa fa-id-card" aria-hidden="true"></i>
                <p>No verification submitted yet</p>
            </div>
        <?php endif; ?>
    </section>
    
    <!-- Submit Verification -->
    <?php if(!$verification_data || $verification_data['verification_status'] == 'Rejected'): ?>
    <section class="card submit-section">
        <h2 class="section-title">
            <i class="fa fa-file-upload" aria-hidden="true"></i> Submit Verification Document
        </h2>
        
        <p class="info-text">Submit your verification document to avail of special discounts (Student, Senior Citizen, PWD).</p>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-error" role="alert"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if(isset($success)): ?>
            <div class="alert alert-success" role="alert"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" class="verification-form">
            <?php csrf_field(); ?>
            <div class="form-group">
                <label for="verification_type">Verification Type</label>
                <select name="verification_type" id="verification_type" class="form-control" required>
                    <option value="">Select Type</option>
                    <option value="Student">Student</option>
                    <option value="Senior">Senior Citizen</option>
                    <option value="PWD">PWD (Person with Disability)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="document_number">Document Number</label>
                <input type="text" name="document_number" id="document_number" class="form-control" placeholder="Enter document number" required>
            </div>
            
            <div class="form-group">
                <label for="expiry_date">Expiry Date (if applicable)</label>
                <input type="date" name="expiry_date" id="expiry_date" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="verification_document">Upload Document</label>
                <input type="file" name="verification_document" id="verification_document" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                <small class="form-hint">Accepted formats: JPG, JPEG, PNG, PDF (Max 5MB)</small>
            </div>
            
            <button type="submit" name="submit_verification" class="btn btn-primary">
                <i class="fa fa-upload" aria-hidden="true"></i> Submit Verification
            </button>
        </form>
    </section>
    <?php endif; ?>
    
    <!-- Discount Information -->
    <section class="card discount-info">
        <h2 class="section-title">
            <i class="fa fa-percentage" aria-hidden="true"></i> Discount Information
        </h2>
        
        <div class="discount-grid">
            <div class="discount-item">
                <div class="discount-icon student">
                    <i class="fa fa-graduation-cap" aria-hidden="true"></i>
                </div>
                <div class="discount-details">
                    <h3>Student Discount</h3>
                    <p>20% discount on regular fare</p>
                    <small>Valid school ID required</small>
                </div>
            </div>
            
            <div class="discount-item">
                <div class="discount-icon senior">
                    <i class="fa fa-user" aria-hidden="true"></i>
                </div>
                <div class="discount-details">
                    <h3>Senior Citizen Discount</h3>
                    <p>20% discount on regular fare</p>
                    <small>Valid senior citizen ID required</small>
                </div>
            </div>
            
            <div class="discount-item">
                <div class="discount-icon pwd">
                    <i class="fa fa-wheelchair" aria-hidden="true"></i>
                </div>
                <div class="discount-details">
                    <h3>PWD Discount</h3>
                    <p>20% discount on regular fare</p>
                    <small>Valid PWD ID required</small>
                </div>
            </div>
        </div>
    </section>
</div>

</main>

<script>
// Toggle sidebar
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.sidebar-toggle');
    
    if (sidebar && overlay && toggle) {
        const isOpen = sidebar.classList.toggle('open');
        overlay.classList.toggle('active', isOpen);
        toggle.setAttribute('aria-expanded', isOpen);
        
        // Add/remove body class for CSS to hide hamburger
        document.body.classList.toggle('sidebar-open', isOpen);
    }
}

// Close sidebar when clicking a nav link on mobile
document.querySelectorAll('.menu-top a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});

// Handle keyboard navigation for sidebar
document.addEventListener('keydown', function(e) {
    const sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open') && e.key === 'Escape') {
        toggleSidebar();
    }
});
</script>

</body>
</html>