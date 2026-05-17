<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/email_helper.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* GET USER DATA */
$user_query = mbus_db_query($conn,"
    SELECT user_id, full_name, email, phone_number, user_type
    FROM users
    WHERE user_id = '$user_id'
");

$user_data = mbus_db_fetch_assoc($user_query);

/* HANDLE PROFILE UPDATE */
if(isset($_POST['update_profile'])){
    $full_name = mbus_db_escape($conn, $_POST['full_name']);
    $email = mbus_db_escape($conn, $_POST['email']);
    $phone_number = mbus_db_escape($conn, $_POST['phone_number']);
    
    // Validate email
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        $error = "Invalid email format";
    } else {
        // Check if email already exists for another user
        $email_check = mbus_db_query($conn,"
            SELECT user_id FROM users
            WHERE email = '$email' AND user_id != '$user_id'
        ");
        
        if(mbus_db_num_rows($email_check) > 0){
            $error = "Email already in use by another account";
        } else {
            // Update profile
            mbus_db_query($conn,"
                UPDATE users
                SET full_name = '$full_name', email = '$email', phone_number = '$phone_number'
                WHERE user_id = '$user_id'
            ");
            
            // Log profile update
            log_activity($conn, $user_id, ACTION_PROFILE_UPDATE, "Profile information updated");
            
            $_SESSION['full_name'] = $full_name;
            $success = "Profile updated successfully";
            
            // Refresh user data
            $user_query = mbus_db_query($conn,"
                SELECT user_id, full_name, email, phone_number, user_type
                FROM users
                WHERE user_id = '$user_id'
            ");
            $user_data = mbus_db_fetch_assoc($user_query);
        }
    }
}

/* HANDLE PASSWORD CHANGE */
if(isset($_POST['change_password'])){
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verify current password
    $password_check = mbus_db_query($conn,"
        SELECT password FROM users WHERE user_id = '$user_id'
    ");
    $password_data = mbus_db_fetch_assoc($password_check);
    
    if(!password_verify($current_password, $password_data['password'])){
        $password_error = "Current password is incorrect";
    } elseif(strlen($new_password) < 8){
        $password_error = "New password must be at least 8 characters";
    } elseif($new_password !== $confirm_password){
        $password_error = "New passwords do not match";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        mbus_db_query($conn,"
            UPDATE users
            SET password = '$hashed_password'
            WHERE user_id = '$user_id'
        ");
        
        // Log password change
        log_activity($conn, $user_id, ACTION_PASSWORD_CHANGE, "Password changed successfully");
        
        // Send email notification
        $user_email_query = mbus_db_query($conn, "SELECT email FROM users WHERE user_id = '$user_id'");
        $user_email_data = mbus_db_fetch_assoc($user_email_query);
        $user_email = $user_email_data['email'];
        
        $email_subject = "Password Changed Successfully";
        $email_message = "
            <h2>Password Changed</h2>
            <p>Your password has been changed successfully.</p>
            <p>If you did not make this change, please contact our support team immediately.</p>
            <p>For your security, we recommend changing your password regularly.</p>
        ";
        send_email_notification($user_email, $email_subject, $email_message);
        
        $password_success = "Password changed successfully";
    }
}

/* HANDLE PROFILE PICTURE UPLOAD */
if(isset($_POST['upload_picture'])){
    if(isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0){
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_picture']['name'];
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if(in_array($file_ext, $allowed)){
            $new_filename = "profile_" . $user_id . "_" . time() . "." . $file_ext;
            $upload_path = "../Assets/profile_pictures/" . $new_filename;
            
            // Create directory if it doesn't exist
            if(!file_exists("../Assets/profile_pictures/")){
                mkdir("../Assets/profile_pictures/", 0777, true);
            }
            
            if(move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)){
                mbus_db_query($conn,"
                    UPDATE users
                    SET profile_picture = '$new_filename'
                    WHERE user_id = '$user_id'
                ");
                $picture_success = "Profile picture updated successfully";
            } else {
                $picture_error = "Failed to upload profile picture";
            }
        } else {
            $picture_error = "Invalid file type. Only JPG, JPEG, PNG, and GIF allowed";
        }
    } else {
        $picture_error = "No file uploaded or upload error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - MBUS</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/modern_theme.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/profile.css">
</head>
<body>

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle" onclick="toggleSidebar()">
    <i class="fa fa-bars"></i>
</button>

<!-- Sidebar Overlay -->
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
        <a href="profile.php" class="active"><i class="fa fa-user"></i> My Profile</a>
        
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
    <h1>My Profile</h1>
    
    <div class="profile-container">
        <!-- Profile Picture Section -->
        <div class="profile-picture-section glass-card">
            <div class="profile-picture-container">
                <?php 
                $profile_pic = $user_data['profile_picture'] ?? 'default.png';
                if(!file_exists("../Assets/profile_pictures/" . $profile_pic)){
                    $profile_pic = 'default.png';
                }
                ?>
                <img src="../Assets/profile_pictures/<?php echo $profile_pic; ?>" alt="Profile Picture" class="profile-picture">
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="upload-form">
                <input type="file" name="profile_picture" accept="image/*" id="profilePictureInput">
                <button type="submit" name="upload_picture" class="btn-modern btn-primary">
                    <i class="fa fa-upload"></i> Upload New Picture
                </button>
            </form>
            
            <?php if(isset($picture_error)): ?>
                <div class="notice error"><?php echo $picture_error; ?></div>
            <?php endif; ?>
            
            <?php if(isset($picture_success)): ?>
                <div class="notice success"><?php echo $picture_success; ?></div>
            <?php endif; ?>
        </div>
        
        <!-- Profile Information Section -->
        <div class="profile-info-section glass-card">
            <h2>Profile Information</h2>
            
            <?php if(isset($success)): ?>
                <div class="notice success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if(isset($error)): ?>
                <div class="notice error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST" class="profile-form">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($user_data['full_name']); ?>" class="input-modern" required>
                </div>
                
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" class="input-modern" required>
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone_number" value="<?php echo htmlspecialchars($user_data['phone_number'] ?? ''); ?>" class="input-modern">
                </div>
                
                <div class="form-group">
                    <label>User Type</label>
                    <input type="text" value="<?php echo htmlspecialchars($user_data['user_type']); ?>" class="input-modern" disabled style="background: #f5f5f5;">
                </div>
                
                <button type="submit" name="update_profile" class="btn-modern btn-primary">
                    <i class="fa fa-save"></i> Update Profile
                </button>
            </form>
        </div>
        
        <!-- Change Password Section -->
        <div class="password-section glass-card">
            <h2>Change Password</h2>
            
            <?php if(isset($password_error)): ?>
                <div class="notice error"><?php echo $password_error; ?></div>
            <?php endif; ?>
            
            <?php if(isset($password_success)): ?>
                <div class="notice success"><?php echo $password_success; ?></div>
            <?php endif; ?>
            
            <form method="POST" class="password-form">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="input-modern" required>
                </div>
                
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="input-modern" required minlength="8">
                    <small>Minimum 8 characters</small>
                </div>
                
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="input-modern" required minlength="8">
                </div>
                
                <button type="submit" name="change_password" class="btn-modern btn-secondary">
                    <i class="fa fa-key"></i> Change Password
                </button>
            </form>
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
