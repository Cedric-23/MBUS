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
include "../Includes/auth_validation.php";

/* ADD OPERATOR */
if(isset($_POST['add_operator'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email = mbus_normalize_email($_POST['email'] ?? '');
        $phone_number = mbus_normalize_phone($_POST['phone_number'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $name_error = mbus_validate_full_name($full_name);
        $email_error = mbus_validate_email($email);
        $phone_error = mbus_validate_phone($phone_number);
        $password_error = mbus_validate_password($password, 8);

        if($name_error !== null){
            $error = $name_error;
        } elseif($email_error !== null){
            $error = $email_error;
        } elseif($phone_error !== null){
            $error = $phone_error;
        } elseif($password_error !== null){
            $error = $password_error;
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            /* CHECK EMAIL using prepared statement */
            $check = mbus_db_prepare($conn, "
SELECT * FROM users WHERE LOWER(email) = LOWER(?)
", [$email]);

            if($check === false){
                error_log('Admin operator email lookup failed: ' . mbus_db_error($conn));
                $error = "We could not check this email address right now. Please try again.";
            } elseif(mbus_db_num_rows($check) > 0){
                $error = "This email address is already registered. Use another email.";
            } else {
                $created = mbus_db_prepare($conn, "
INSERT INTO users
(full_name, email, phone_number, password, user_type)
VALUES
(?, ?, ?, ?, 'operator')
", [$full_name, $email, $phone_number, $hashed_password]);

                if($created === false){
                    error_log('Admin operator insert failed: ' . mbus_db_error($conn));
                    $error = "Operator account could not be created. Please check the details and try again.";
                } else {
                    $success = "Operator account created. The operator can now log in with this email and password.";
                }
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
        $deleted = mbus_db_prepare($conn, "DELETE FROM users WHERE user_id = ? AND LOWER(user_type) IN ('operator', 'driver')", [$user_id]);
        if($deleted === false){
            error_log('Operator delete failed: ' . mbus_db_error($conn));
            $error = "Operator could not be deleted. Please try again.";
        } else {
            $success = "Operator deleted successfully";
        }
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
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=1">
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
                        <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
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
<div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php
$oq = mbus_db_query($conn, "
    SELECT u.user_id, u.full_name, u.email, u.phone_number,
      (SELECT string_agg(b.bus_number, ', ') FROM operator_bus_assignments oa JOIN buses b ON b.bus_id=oa.bus_id WHERE oa.operator_id=u.user_id) AS buses
    FROM users u WHERE LOWER(u.user_type) IN ('operator', 'driver') ORDER BY u.full_name");
$op_list = [];
while($oq !== false && ($row = mbus_db_fetch_assoc($oq))){ $op_list[] = $row; }
$assigned = count(array_filter($op_list, fn($x) => !empty($x['buses'])));
?>
<div class="ad-toolbar">
    <button type="button" class="ad-btn primary" data-ad-open="add" data-title="Add operator" data-action="add_operator"><i class="fa fa-plus" aria-hidden="true"></i> Add operator</button>
    <a class="ad-btn" href="operator_applications.php"><i class="fa fa-user-check" aria-hidden="true"></i> Applications</a>
    <a class="ad-btn" href="conductor_applications.php"><i class="fa fa-user-check" aria-hidden="true"></i> Conductor applications</a>
</div>

<?php if(!$op_list): ?><div class="ad-empty"><i class="fa fa-user-gear" aria-hidden="true"></i><p>No operators yet.</p></div><?php endif; ?>
<div class="ad-table ops">
    <div class="ad-th"><span>Operator</span><span>Phone</span><span>Bus</span><span></span></div>
    <?php foreach($op_list as $row):
        $initial = strtoupper(mb_substr(trim((string)$row['full_name']) ?: '?', 0, 1));
    ?>
    <div class="ad-tr" data-q="<?php echo htmlspecialchars(strtolower($row['full_name'].' '.$row['email'].' '.($row['buses'] ?? '')), ENT_QUOTES); ?>">
        <div class="ad-c person"><span class="ad-avatar"><?php echo htmlspecialchars($initial); ?></span><div><b><?php echo htmlspecialchars($row['full_name']); ?></b><small><?php echo htmlspecialchars($row['email']); ?></small></div></div>
        <div class="ad-c phone"><?php echo htmlspecialchars((string)$row['phone_number']); ?></div>
        <div class="ad-c bus"><?php echo !empty($row['buses']) ? '<span class="ad-pill active"><i class="fa fa-bus" aria-hidden="true"></i> Bus '.htmlspecialchars($row['buses']).'</span>' : '<span class="ad-pill muted">No bus</span>'; ?></div>
        <div class="ad-c actions">
            <form method="POST" data-confirm="Delete this operator?"><?php csrf_field(); ?><input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>"><button type="submit" name="delete_operator" class="ad-icon danger" aria-label="Delete operator"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<dialog class="ad-dialog" id="adDialog" aria-labelledby="adTitle">
    <form method="POST" class="ad-form">
        <?php csrf_field(); ?>
        <header class="ad-dialog-head"><h2 id="adTitle">Add operator</h2><button type="button" class="ad-x" data-ad-close aria-label="Close"><i class="fa fa-xmark" aria-hidden="true"></i></button></header>
        <div class="ad-dialog-body">
            <label class="ad-field"><span>Full name</span><input type="text" name="full_name" maxlength="100" required></label>
            <label class="ad-field"><span>Email</span><input type="email" name="email" maxlength="100" required></label>
            <div class="ad-two">
                <label class="ad-field"><span>Phone</span><div class="phone-input"><span class="phone-prefix" aria-hidden="true">+63</span><input type="tel" name="phone_number" inputmode="numeric" pattern="9[0-9]{9}" minlength="10" maxlength="10" placeholder="9XXXXXXXXX" required></div></label>
                <label class="ad-field"><span>Password</span><input type="password" name="password" minlength="8" maxlength="128" required placeholder="8+ characters"></label>
            </div>
        </div>
        <footer class="ad-dialog-foot">
            <button type="button" class="ad-btn" data-ad-close>Cancel</button>
            <button type="submit" class="ad-btn primary" id="adSubmit" name="add_operator" value="1">Save</button>
        </footer>
    </form>
</dialog>
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
<script src="../Assets/js/admin_list.js?v=<?php echo filemtime(__DIR__ . '/../Assets/js/admin_list.js'); ?>"></script>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/password-toggle.js"></script>
</body>
</html>
