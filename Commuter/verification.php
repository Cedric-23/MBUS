<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* HANDLE VERIFICATION SUBMISSION */
if(isset($_POST['submit_verification'])){
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
                // Check if user already has a pending verification
                $existing_check = mbus_db_query($conn,"
                    SELECT verification_id FROM commuter_verification
                    WHERE user_id = '$user_id' AND verification_status = 'Pending'
                ");
                
                if(mbus_db_num_rows($existing_check) > 0){
                    $error = "You already have a pending verification. Please wait for it to be reviewed.";
                } else {
                    // Insert verification record
                    mbus_db_query($conn,"
                        INSERT INTO commuter_verification (user_id, verification_type, document_path, document_number, expiry_date)
                        VALUES ('$user_id', '$verification_type', '$new_filename', '$document_number', '$expiry_date')
                    ");
                    
                    // Log verification submission
                    log_activity($conn, $user_id, ACTION_VERIFICATION_SUBMIT, "Submitted $verification_type verification document");
                    
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

/* GET EXISTING VERIFICATION STATUS */
$verification_query = mbus_db_query($conn,"
    SELECT verification_type, verification_status, submitted_at, reviewed_at, rejection_reason
    FROM commuter_verification
    WHERE user_id = '$user_id'
    ORDER BY submitted_at DESC
    LIMIT 1
");

$verification_data = mbus_db_fetch_assoc($verification_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification - MBUS</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/modern_theme.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/verification.css">
</head>
<body>
<button class="sidebar-toggle" onclick="toggleSidebar()"><i class="fa fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>
<div class="sidebar">
    <div class="logo-container">
        <img src="../Assets/images/mbus_logo.png" class="logo">
    </div>
    
    <h2>Commuter</h2>
    
    <div class="menu-top">
        <a href="commuter_dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="my_reservations.php"><i class="fa fa-ticket"></i> My Reservations</a>
        <a href="history.php"><i class="fa fa-history"></i> History</a>
        <a href="profile.php"><i class="fa fa-user"></i> My Profile</a>
        <a href="verification.php" class="active"><i class="fa fa-id-card"></i> Verification</a>
        
        <!-- Notification Bell -->
        <div class="notification-container">
            <div class="notification-bell" onclick="toggleNotifications()">
                <i class="fa fa-bell"></i>
                <span class="notification-badge" id="notificationBadge">0</span>
            </div>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-dropdown-header">
                    Notifications
                </div>
                <div id="notificationList">
                    <div class="notification-empty">Loading notifications...</div>
                </div>
            </div>
        </div>
    </div>
    
    <a href="../logout.php" class="logout-btn">
        <i class="fa fa-sign-out"></i> Logout
    </a>
</div>

<div class="main">
    <h1>Commuter Verification</h1>
    
    <div class="verification-container">
        <!-- Current Status -->
        <div class="status-section glass-card">
            <h2>Current Verification Status</h2>
            
            <?php if($verification_data): ?>
                <div class="status-card <?php echo strtolower($verification_data['verification_status']); ?>">
                    <div class="status-icon">
                        <?php if($verification_data['verification_status'] == 'Pending'): ?>
                            <i class="fa fa-clock"></i>
                        <?php elseif($verification_data['verification_status'] == 'Approved'): ?>
                            <i class="fa fa-check-circle"></i>
                        <?php else: ?>
                            <i class="fa fa-times-circle"></i>
                        <?php endif; ?>
                    </div>
                    <div class="status-details">
                        <h3><?php echo $verification_data['verification_status']; ?></h3>
                        <p>Type: <?php echo $verification_data['verification_type']; ?></p>
                        <p>Submitted: <?php echo date('F d, Y g:i A', strtotime($verification_data['submitted_at'])); ?></p>
                        <?php if($verification_data['reviewed_at']): ?>
                            <p>Reviewed: <?php echo date('F d, Y g:i A', strtotime($verification_data['reviewed_at'])); ?></p>
                        <?php endif; ?>
                        <?php if($verification_data['rejection_reason']): ?>
                            <p class="rejection-reason">Reason: <?php echo $verification_data['rejection_reason']; ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-verification">
                    <i class="fa fa-id-card"></i>
                    <p>No verification submitted yet</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Submit Verification -->
        <?php if(!$verification_data || $verification_data['verification_status'] == 'Rejected'): ?>
        <div class="submit-section glass-card">
            <h2>Submit Verification Document</h2>
            
            <p class="info-text">Submit your verification document to avail of special discounts (Student, Senior Citizen, PWD).</p>
            
            <?php if(isset($error)): ?>
                <div class="notice error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if(isset($success)): ?>
                <div class="notice success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" class="verification-form">
                <div class="form-group">
                    <label>Verification Type</label>
                    <select name="verification_type" class="input-modern" required>
                        <option value="">Select Type</option>
                        <option value="Student">Student</option>
                        <option value="Senior">Senior Citizen</option>
                        <option value="PWD">PWD (Person with Disability)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Document Number</label>
                    <input type="text" name="document_number" class="input-modern" placeholder="Enter document number" required>
                </div>
                
                <div class="form-group">
                    <label>Expiry Date (if applicable)</label>
                    <input type="date" name="expiry_date" class="input-modern">
                </div>
                
                <div class="form-group">
                    <label>Upload Document</label>
                    <input type="file" name="verification_document" class="input-modern" accept=".jpg,.jpeg,.png,.pdf" required>
                    <small>Accepted formats: JPG, JPEG, PNG, PDF (Max 5MB)</small>
                </div>
                
                <button type="submit" name="submit_verification" class="btn-modern btn-primary">
                    <i class="fa fa-upload"></i> Submit Verification
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Discount Information -->
        <div class="discount-info glass-card">
            <h2>Discount Information</h2>
            
            <div class="discount-item">
                <div class="discount-icon student">
                    <i class="fa fa-graduation-cap"></i>
                </div>
                <div class="discount-details">
                    <h3>Student Discount</h3>
                    <p>20% discount on regular fare</p>
                    <small>Valid school ID required</small>
                </div>
            </div>
            
            <div class="discount-item">
                <div class="discount-icon senior">
                    <i class="fa fa-user"></i>
                </div>
                <div class="discount-details">
                    <h3>Senior Citizen Discount</h3>
                    <p>20% discount on regular fare</p>
                    <small>Valid senior citizen ID required</small>
                </div>
            </div>
            
            <div class="discount-item">
                <div class="discount-icon pwd">
                    <i class="fa fa-wheelchair"></i>
                </div>
                <div class="discount-details">
                    <h3>PWD Discount</h3>
                    <p>20% discount on regular fare</p>
                    <small>Valid PWD ID required</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let notificationDropdownOpen = false;

function toggleSidebar() {
    document.querySelector('.sidebar').classList.toggle('open');
    document.querySelector('.sidebar-overlay').classList.toggle('active');
}

// Toggle notification dropdown
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    notificationDropdownOpen = !notificationDropdownOpen;
    
    if (notificationDropdownOpen) {
        dropdown.classList.add('active');
        loadNotifications();
    } else {
        dropdown.classList.remove('active');
    }
}

// Load notifications
function loadNotifications() {
    fetch('../api/get_notifications.php')
        .then(response => response.json())
        .then(data => {
            updateNotificationBadge(data.unread_count);
            renderNotifications(data.notifications);
        })
        .catch(error => {
            console.error('Error loading notifications:', error);
        });
}

// Update notification badge
function updateNotificationBadge(count) {
    const badge = document.getElementById('notificationBadge');
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
}

// Render notifications
function renderNotifications(notifications) {
    const list = document.getElementById('notificationList');
    
    if (notifications.length === 0) {
        list.innerHTML = '<div class="notification-empty">No notifications</div>';
        return;
    }
    
    list.innerHTML = notifications.map(notif => `
        <div class="notification-item ${notif.status === 'Unread' ? 'unread' : ''}" 
             onclick="markAsRead(${notif.id})">
            <div class="notification-message">${notif.message}</div>
            <div class="notification-time">${notif.time_ago}</div>
        </div>
    `).join('');
}

// Mark notification as read
function markAsRead(notificationId) {
    const formData = new FormData();
    formData.append('notification_id', notificationId);
    
    fetch('../api/mark_notification_read.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const container = document.querySelector('.notification-container');
    if (!container.contains(event.target) && notificationDropdownOpen) {
        toggleNotifications();
    }
});

// Auto-refresh notifications every 30 seconds
setInterval(loadNotifications, 30000);

// Initial load
loadNotifications();
</script>

</body>
</html>
