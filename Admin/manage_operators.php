<?php

session_start();

/* CHECK ADMIN */
if(
    !isset($_SESSION['user_type'])
    ||
    strtolower($_SESSION['user_type']) != "admin"
){
    header("Location: ../login.php");
    exit();
}

include "../config/db_connect.php";
include "../Includes/csrf.php";

/* ADD OPERATOR */
if(isset($_POST['add_operator'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone_number = trim($_POST['phone_number']);
        $password = trim($_POST['password']);

        // Validate phone number
        if(!preg_match('/^09\d{9}$/', $phone_number)){
            $error = "Phone number must be 11 digits starting with 09 (e.g., 09123456789).";
        } elseif(strlen($password) < 8){
            $error = "Password must be at least 8 characters.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            /* CHECK EMAIL using prepared statement */
            $check = mbus_db_prepare($conn, "
SELECT * FROM users WHERE LOWER(email) = LOWER(?)
", [$email]);

            if(mbus_db_num_rows($check) > 0){
                $error = "Email already exists";
            } else {
                mbus_db_prepare($conn, "
INSERT INTO users
(full_name, email, phone_number, password, user_type)
VALUES
(?, ?, ?, ?, 'operator')
", [$full_name, $email, $phone_number, $hashed_password]);

                $success = "Operator account created";
            }
        }
    }
}

/* DELETE OPERATOR */
if(isset($_POST['delete_operator'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $user_id = (int)$_POST['user_id'];
        mbus_db_prepare($conn, "DELETE FROM users WHERE user_id = ? AND LOWER(user_type) = 'operator'", [$user_id]);
        $success = "Operator deleted successfully";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Operators</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_operators.css">
    <link rel="stylesheet" href="../Assets/css/admin/schedules_compact.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
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

    <h2>Admin</h2>

    <nav class="menu-top" aria-label="Admin menu">
        <a href="admin_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="manage_schedules.php"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
        <a href="manage_operators.php" aria-current="page"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
        <a href="manage_routes.php"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
    </nav>

    <div class="menu-bottom">
<a href="../logout.php" class="logout-btn btn btn-outline btn-block">
            <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
        </a>
    </div>
</aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Manage Operators</h1>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                            <span class="profile-menu-role">Admin account</span>
                        </div>
                        <?php include_once "../Includes/admin_menu.php"; echo mbus_admin_approvals_link($conn); ?>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
</header>

<?php if(isset($success)): ?>
<div class="alert alert-success" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<?php
$oq = mbus_db_query($conn, "
    SELECT u.user_id, u.full_name, u.email, u.phone_number,
      (SELECT string_agg(b.bus_number, ', ') FROM operator_bus_assignments oa JOIN buses b ON b.bus_id=oa.bus_id WHERE oa.operator_id=u.user_id) AS buses
    FROM users u WHERE LOWER(u.user_type)='operator' ORDER BY u.user_id DESC");
$op_list = [];
while($oq !== false && ($row = mbus_db_fetch_assoc($oq))){ $op_list[] = $row; }
$assigned = count(array_filter($op_list, fn($x) => !empty($x['buses'])));
?>
<div class="sc-summary">
    <span><b><?php echo count($op_list); ?></b>operators</span>
    <span><b><?php echo $assigned; ?></b>with a bus</span>
    <span><b><?php echo count($op_list) - $assigned; ?></b>unassigned</span>
</div>

<details class="sc-add" <?php echo isset($error) && isset($_POST['add_operator']) ? 'open' : ''; ?>>
    <summary class="sc-link strong">+ Add operator</summary>
    <form method="POST" class="sc-add-form">
        <?php csrf_field(); ?>
        <input type="text" name="full_name" class="form-control" placeholder="Full name" aria-label="Full name" required>
        <input type="email" name="email" class="form-control" placeholder="Email" aria-label="Email" required>
        <input type="text" name="phone_number" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX" aria-label="Phone number" required>
        <input type="password" name="password" class="form-control" placeholder="Password" aria-label="Password" minlength="8" required>
        <button type="submit" name="add_operator" class="sc-link strong">Create</button>
    </form>
</details>

<section class="sc-list" aria-label="Operators">
<?php if(!$op_list): ?><p class="sc-empty">No operators yet.</p><?php endif; ?>
<?php foreach($op_list as $row):
    $initial = strtoupper(mb_substr(trim((string)$row['full_name']) ?: '?', 0, 1));
?>
<article class="sc-row <?php echo !empty($row['buses']) ? 'dir-sbma' : ''; ?>">
    <div class="sc-main people">
        <span class="sc-avatar"><?php echo htmlspecialchars($initial); ?></span>
        <span class="sc-who">
            <strong><?php echo htmlspecialchars($row['full_name']); ?></strong>
            <small><?php echo htmlspecialchars($row['email']); ?> &middot; <?php echo htmlspecialchars((string)$row['phone_number']); ?></small>
        </span>
        <?php if(!empty($row['buses'])): ?>
            <span class="sc-chip green">Bus <?php echo htmlspecialchars($row['buses']); ?></span>
        <?php else: ?>
            <span class="sc-chip amber">No bus</span>
        <?php endif; ?>
        <span class="sc-actions">
            <form method="POST" onsubmit="return confirm('Delete this operator?')"><?php csrf_field(); ?>
                <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                <button type="submit" name="delete_operator" class="sc-icon sc-del" aria-label="Delete operator"><i class="fa fa-trash" aria-hidden="true"></i></button>
            </form>
        </span>
    </div>
</article>
<?php endforeach; ?>
</section>

</main>

<script>
// Toggle sidebar for mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.sidebar-toggle');
    
    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    
    if (toggle) {
        toggle.setAttribute('aria-expanded', isOpen);
    }
    
    if (isOpen) {
        document.body.style.overflow = 'hidden';
        const firstLink = sidebar.querySelector('a, button');
        if (firstLink) firstLink.focus();
    } else {
        document.body.style.overflow = '';
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
    const sidebar = document.querySelector('.sidebar');
    if (sidebar.classList.contains('open') && e.key === 'Escape') {
        toggleSidebar();
    }
});
</script>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="admin_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="manage_schedules.php" class="bottom-nav-item">
        <i class="fa fa-calendar" aria-hidden="true"></i><span>Schedules</span>
    </a>
    <a href="manage_buses.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span>
    </a>
    <a href="manage_operators.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-users" aria-hidden="true"></i><span>Operators</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/password-toggle.js"></script>
</body>
</html>