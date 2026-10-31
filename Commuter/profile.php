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

/* GET USER DATA */
$user_query = mbus_db_prepare($conn,"
    SELECT user_id, full_name, email, phone_number, user_type, profile_picture
    FROM users
    WHERE user_id = ?
", [$user_id]);

$user_data = mbus_db_fetch_assoc($user_query);

// Handle case where user data might be false/null
if (!$user_data) {
    $user_data = [
        'user_id' => $user_id,
        'full_name' => '',
        'email' => '',
        'phone_number' => '',
        'user_type' => 'Regular',
        'profile_picture' => 'default.png'
    ];
} elseif (empty($user_data['user_type'])) {
    $user_data['user_type'] = 'Regular';
}

/* HANDLE PROFILE UPDATE */
if(isset($_POST['update_profile'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone_number = trim($_POST['phone_number']);

        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            $error = "Invalid email format";
        } elseif (!preg_match('/^09\d{9}$/', $phone_number) && !empty($phone_number)) {
            $error = "Phone number must be 11 digits starting with 09 (e.g., 09123456789).";
        } else {
            $email_check = mbus_db_prepare($conn,"
                SELECT user_id FROM users
                WHERE email = ? AND user_id != ?
            ", [$email, $user_id]);

            if(mbus_db_num_rows($email_check) > 0){
                $error = "Email already in use by another account";
            } else {
                mbus_db_prepare($conn,"
                    UPDATE users
                    SET full_name = ?, email = ?, phone_number = ?
                    WHERE user_id = ?
                ", [$full_name, $email, $phone_number, $user_id]);

                log_activity($conn, $user_id, ACTION_PROFILE_UPDATE, "Profile information updated");

                $_SESSION['full_name'] = $full_name;
                $success = "Profile updated successfully";

                $user_query = mbus_db_prepare($conn,"
                    SELECT user_id, full_name, email, phone_number, user_type, profile_picture
                    FROM users
                    WHERE user_id = ?
                ", [$user_id]);
                $user_data = mbus_db_fetch_assoc($user_query);
            }
        }
    }
}

/* HANDLE PROFILE PICTURE UPLOAD */
if(isset($_POST['upload_picture'])){
    if(!csrf_validate_token()){
        $picture_error = "Security validation failed. Please try again.";
    } elseif(isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0){
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_picture']['name'];
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $max_size = 5 * 1024 * 1024;

        if($_FILES['profile_picture']['size'] > $max_size){
            $picture_error = "File too large. Maximum size is 5MB.";
        } elseif(!in_array($file_ext, $allowed)){
            $picture_error = "Invalid file type. Only JPG, JPEG, PNG, and GIF allowed";
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $_FILES['profile_picture']['tmp_name']);
            finfo_close($finfo);

            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
            if(!in_array($mime_type, $allowed_mimes)){
                $picture_error = "Invalid file content. Only images allowed.";
            } else {
                $image_info = getimagesize($_FILES['profile_picture']['tmp_name']);
                if($image_info === false){
                    $picture_error = "Invalid image file.";
                } else {
                    $max_width = 2000;
                    $max_height = 2000;
                    if($image_info[0] > $max_width || $image_info[1] > $max_height){
                        $picture_error = "Image too large. Maximum dimensions: {$max_width}x{$max_height}px";
                    } else {
                        $new_filename = "profile_" . $user_id . "_" . time() . "." . $file_ext;
                        $upload_path = "../Assets/profile_pictures/" . $new_filename;

                        if(!file_exists("../Assets/profile_pictures/")){
                            mkdir("../Assets/profile_pictures/", 0755, true);
                        }

                        if(move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)){
                            mbus_db_prepare($conn,"
                                UPDATE users
                                SET profile_picture = ?
                                WHERE user_id = ?
                            ", [$new_filename, $user_id]);
                            $picture_success = "Profile picture updated successfully";
                        } else {
                            $picture_error = "Failed to upload profile picture";
                        }
                    }
                }
            }
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/profile.css">
</head>
<body class="has-sidebar">

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
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
            <a href="profile.php" aria-current="page"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
            <a href="verification.php"><i class="fa fa-id-card" aria-hidden="true"></i> Verification</a>
        </nav>

        <div class="menu-bottom">
            <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
                <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
            </a>
        </div>
</aside>

<main class="main" role="main">

<header class="page-header">
    <div class="page-header-left">
        <h1>My Profile</h1>
    </div>
</header>

<div class="profile-container">
    <section class="card profile-picture-section">
        <h2 class="section-title">
            <i class="fa fa-user-circle" aria-hidden="true"></i> Profile Picture
        </h2>
        <div class="profile-picture-container">
            <?php 
            $profile_pic = $user_data['profile_picture'] ?? 'default.png';
            $profile_pic_path = "../Assets/profile_pictures/" . $profile_pic;
            if(!file_exists($profile_pic_path) || $profile_pic === 'default.png'){
                $profile_pic = 'default.png';
                $profile_pic_path = "../Assets/images/mbus_icon.png";
            }
            ?>
            <img src="<?php echo $profile_pic_path; ?>" alt="Profile Picture" class="profile-picture" id="profilePreview">
        </div>
        
        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <?php csrf_field(); ?>
            <div class="file-input-wrapper">
                <input type="file" name="profile_picture" accept="image/*" id="profilePictureInput" class="file-input" onchange="previewProfilePicture(this)">
                <label for="profilePictureInput" class="btn btn-secondary">
                    <i class="fa fa-upload" aria-hidden="true"></i> Choose New Picture
                </label>
            </div>
            <button type="submit" name="upload_picture" class="btn btn-primary" disabled id="uploadPictureBtn">
                            <i class="fa fa-paper-plane" aria-hidden="true"></i> Submit
                        </button>
        </form>
        
        <?php if(isset($picture_error)): ?>
            <div class="alert alert-error" role="alert"><?php echo $picture_error; ?></div>
        <?php endif; ?>
        
        <?php if(isset($picture_success)): ?>
            <div class="alert alert-success" role="alert"><?php echo $picture_success; ?></div>
        <?php endif; ?>
    </section>
    
    <section class="card profile-info-section">
        <h2 class="section-title">
            <i class="fa fa-id-card" aria-hidden="true"></i> Profile Information
        </h2>
        
        <?php if(isset($success)): ?>
            <div class="alert alert-success" role="alert"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-error" role="alert"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" class="profile-form">
            <?php csrf_field(); ?>
            <div class="form-grid">
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" name="full_name" id="full_name" value="<?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?>" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="phone_number">Phone Number</label>
                    <input type="tel" name="phone_number" id="phone_number" value="<?php echo htmlspecialchars($user_data['phone_number'] ?? ''); ?>" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX">
                    <small class="form-hint">Format: 11 digits starting with 09 (e.g., 09123456789)</small>
                </div>
                
                <div class="form-group">
                    <label for="user_type">User Type</label>
                    <input type="text" id="user_type" value="<?php echo htmlspecialchars($user_data['user_type'] ?? 'Regular'); ?>" class="form-control" disabled aria-disabled="true">
                </div>
            </div>
            
            <button type="submit" name="update_profile" class="btn btn-primary">
                <i class="fa fa-save" aria-hidden="true"></i> Update Profile
            </button>
        </form>
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
        document.body.classList.toggle('sidebar-open', isOpen);
    }
}

// Profile picture preview
function previewProfilePicture(input) {
    const preview = document.getElementById('profilePreview');
    const uploadBtn = document.getElementById('uploadPictureBtn');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            uploadBtn.disabled = false;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

</body>
</html>