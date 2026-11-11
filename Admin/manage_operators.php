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

$operator_query = mbus_db_query($conn, "
    SELECT * FROM users
    WHERE LOWER(user_type)='operator'
    ORDER BY user_id DESC
");
$operator_rows = [];
if($operator_query !== false){
    while($operator = mbus_db_fetch_assoc($operator_query)){
        $operator_rows[] = $operator;
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
    <link rel="stylesheet" href="../Assets/css/admin/manage_resources.css">
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

<section class="resource-page" aria-labelledby="operator-page-heading">
    <div class="resource-intro">
        <div>
            <p class="resource-kicker">People</p>
            <h2 class="resource-heading" id="operator-page-heading">Keep operator access tidy</h2>
            <p class="resource-description">Create and remove the accounts used to run active trips.</p>
        </div>
        <span class="resource-count"><?php echo count($operator_rows); ?> <?php echo count($operator_rows) === 1 ? 'operator' : 'operators'; ?></span>
    </div>

    <details class="resource-add" <?php echo isset($error) && isset($_POST['add_operator']) ? 'open' : ''; ?>>
        <summary class="btn btn-primary btn-sm"><i class="fa fa-plus" aria-hidden="true"></i> Add operator</summary>
        <form method="POST" class="resource-add-form">
            <?php csrf_field(); ?>
            <div class="resource-field">
                <label for="full_name">Full name</label>
                <input type="text" name="full_name" id="full_name" class="form-control" placeholder="Operator name" required>
            </div>
            <div class="resource-field">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" class="form-control" placeholder="name@example.com" required>
            </div>
            <div class="resource-field">
                <label for="phone_number">Phone number</label>
                <input type="text" name="phone_number" id="phone_number" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX" required>
            </div>
            <div class="resource-field">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="Temporary password" required>
            </div>
            <div class="resource-add-actions">
                <button type="submit" name="add_operator" class="btn btn-primary btn-sm"><i class="fa fa-check" aria-hidden="true"></i> Save operator</button>
            </div>
        </form>
    </details>

    <section class="resource-list" aria-labelledby="operator-list-heading">
        <div class="resource-list-heading">
            <h2 id="operator-list-heading">Operator directory</h2>
            <span class="resource-list-note">Newest accounts first</span>
        </div>
        <?php if(!$operator_rows): ?>
            <p class="resource-empty">No operator accounts yet. Add one to assign trip access.</p>
        <?php endif; ?>
        <?php foreach($operator_rows as $row):
            $name = trim((string)$row['full_name']);
            $name_parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
            $initials = strtoupper(substr($name_parts[0] ?? 'O', 0, 1) . (count($name_parts) > 1 ? substr($name_parts[count($name_parts) - 1], 0, 1) : ''));
        ?>
            <article class="resource-row">
                <span class="resource-avatar" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                <div class="resource-main">
                    <p class="resource-name"><?php echo htmlspecialchars($name); ?></p>
                    <div class="resource-meta">
                        <span><i class="fa fa-envelope" aria-hidden="true"></i><?php echo htmlspecialchars((string)$row['email']); ?></span>
                        <span><i class="fa fa-phone" aria-hidden="true"></i><?php echo htmlspecialchars((string)$row['phone_number']); ?></span>
                    </div>
                </div>
                <span class="resource-tag">Operator</span>
                <form method="POST" class="resource-actions">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                    <button type="submit" name="delete_operator" class="resource-icon-btn resource-icon-btn--danger" title="Delete operator" aria-label="Delete operator <?php echo htmlspecialchars($name); ?>" onclick="return confirm('Delete this operator?')">
                        <i class="fa fa-trash" aria-hidden="true"></i>
                    </button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
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
