<?php
session_start();

/* CHECK ADMIN */
if (!isset($_SESSION['user_type']) || strtolower((string)$_SESSION['user_type']) !== 'admin') {
    header('Location: ../login.php');
    exit();
}

include '../config/db_connect.php';
include '../Includes/csrf.php';
require_once __DIR__ . '/../Includes/route_locations.php';

$success = '';
$error = '';
if (isset($_GET['fare_updated'])) {
    $success = 'Fare updated successfully.';
} elseif (isset($_GET['fare_unchanged'])) {
    $success = 'The fare is already set to that amount.';
}

/* Routes are intentionally fixed here; only the fare may be changed. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fare'])) {
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $route_id = filter_var($_POST['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $fare_raw = isset($_POST['fare']) && is_scalar($_POST['fare']) ? trim((string)$_POST['fare']) : '';

        if ($route_id < 1) {
            $error = 'The route was not identified. Please refresh and try again.';
        } elseif (!preg_match('/^\d+(?:\.\d{1,2})?$/', $fare_raw) || !is_finite((float)$fare_raw) || (float)$fare_raw < 0.01 || (float)$fare_raw > 100000) {
            $error = 'Enter a fare from ₱0.01 to ₱100,000.00, with up to two decimal places.';
        } else {
            $fare = number_format((float)$fare_raw, 2, '.', '');
            $existing = mbus_db_prepare($conn, 'SELECT route_id, fare FROM routes WHERE route_id = ?', [$route_id]);
            if ($existing === false) {
                error_log('Route fare lookup failed: ' . mbus_db_error($conn));
                $error = 'The route could not be loaded. Please try again.';
            } else {
                $route = mbus_db_fetch_assoc($existing);
                if (!$route) {
                    $error = 'That route no longer exists. Refresh the page and try again.';
                } elseif ((int)round((float)$route['fare'] * 100) === (int)round((float)$fare * 100)) {
                    header('Location: manage_routes.php?fare_unchanged=1');
                    exit();
                } else {
                    $updated = mbus_db_prepare($conn, 'UPDATE routes SET fare = ? WHERE route_id = ?', [$fare, $route_id]);
                    if ($updated === false) {
                        error_log('Route fare update failed: ' . mbus_db_error($conn));
                        $error = 'The fare could not be saved. Please try again.';
                    } elseif ($conn->affectedRows > 0) {
                        header('Location: manage_routes.php?fare_updated=1');
                        exit();
                    } else {
                        $error = 'The fare was not changed. Refresh the page and try again.';
                    }
                }
            }
        }
    }
}

$routes = [];
$routes_query = mbus_db_query($conn, 'SELECT * FROM routes ORDER BY route_id ASC');
if ($routes_query === false) {
    error_log('Route list query failed: ' . mbus_db_error($conn));
    $error = $error ?: 'Routes could not be loaded. Please refresh the page.';
} else {
    while ($row = mbus_db_fetch_assoc($routes_query)) {
        $routes[] = mbus_route_apply_terminal_defaults($row);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Routes &amp; Fares</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_routes.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/admin/manage_routes.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/notif_bell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/notif_bell.css'); ?>">
</head>
<body class="stf has-sidebar">
<button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
    <i class="fa fa-bars" aria-hidden="true"></i>
</button>
<div class="sidebar-overlay" onclick="toggleSidebar()" aria-hidden="true"></div>

<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="logo-container"><img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo"></div>
    <h2>Admin</h2>
    <nav class="menu-top" aria-label="Admin menu">
        <a href="admin_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="manage_schedules.php"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
        <a href="manage_operators.php"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
        <a href="manage_routes.php" aria-current="page"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
    </nav>
    <div class="menu-bottom">
        <a href="../logout.php" class="logout-btn btn btn-outline btn-block"><i class="fa fa-sign-out" aria-hidden="true"></i> Logout</a>
    </div>
</aside>

<main class="main" role="main">
    <header class="page-header">
        <div>
            <p class="routes-eyebrow">NETWORK SETTINGS</p>
            <h1>Routes &amp; fares</h1>
            <p class="routes-intro">Manage the fares for your existing routes. Route stops and route setup stay fixed.</p>
        </div>
        <div class="page-header-right">
                            <div class="notif-wrap">
                    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
                    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
                </div>
                <div class="profile-menu-wrap">
                <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                    <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span><?php include_once dirname(__DIR__) . '/Includes/admin_menu.php'; echo mbus_admin_dot($conn); ?>
                </button>
                <div class="profile-menu" id="profileMenu" role="menu">
                    <div class="profile-menu-header">
                        <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="profile-menu-role">Admin account</span>
                    </div>
                    <?php include_once '../Includes/admin_menu.php'; echo mbus_admin_approvals_link($conn); ?>
                    <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                </div>
            </div>
        </div>
    </header>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success" role="status"><i class="fa fa-circle-check" aria-hidden="true"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="routes-panel" aria-labelledby="existing-routes-title">
        <div class="routes-panel-heading">
            <div>
                <h2 id="existing-routes-title">Existing routes</h2>
                <p><?php echo count($routes); ?> <?php echo count($routes) === 1 ? 'route' : 'routes'; ?> · fare editing only</p>
            </div>
            <span class="routes-lock"><i class="fa fa-lock" aria-hidden="true"></i> Route details fixed</span>
        </div>

        <?php if ($routes): ?>
            <div class="route-fare-list">
                <?php foreach ($routes as $index => $route):
                    $routeId = (int)$route['route_id'];
                    $fareFormId = 'fare-form-' . $routeId;
                ?>
                    <article class="route-fare-card">
                        <div class="route-card-topline">
                            <span class="route-number">ROUTE <?php echo str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                            <span class="route-fixed"><i class="fa fa-lock" aria-hidden="true"></i> Fixed route</span>
                        </div>
                        <div class="route-journey" aria-label="<?php echo htmlspecialchars($route['origin_label'] . ' to ' . $route['destination_label'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="route-stop">
                                <span class="route-stop-caption">FROM</span>
                                <strong><?php echo htmlspecialchars($route['origin_label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <span class="route-direction" aria-hidden="true"><i class="fa fa-arrow-right-long"></i></span>
                            <div class="route-stop route-stop-destination">
                                <span class="route-stop-caption">TO</span>
                                <strong><?php echo htmlspecialchars($route['destination_label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="route-fare-current">
                            <span>Current fare</span>
                            <strong>₱<?php echo number_format((float)$route['fare'], 2); ?></strong>
                        </div>
                        <form method="POST" class="fare-editor" id="<?php echo htmlspecialchars($fareFormId, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="route_id" value="<?php echo $routeId; ?>">
                            <label for="fare-<?php echo $routeId; ?>">Update fare (PHP)</label>
                            <div class="fare-editor-controls">
                                <div class="fare-input-wrap">
                                    <span aria-hidden="true">₱</span>
                                    <input type="number" id="fare-<?php echo $routeId; ?>" name="fare" min="0.01" max="100000" step="0.01" value="<?php echo htmlspecialchars(number_format((float)$route['fare'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required inputmode="decimal" aria-describedby="fare-help-<?php echo $routeId; ?>">
                                </div>
                                <button type="submit" name="update_fare" value="1" class="btn btn-primary"><i class="fa fa-floppy-disk" aria-hidden="true"></i> Save fare</button>
                            </div>
                            <small id="fare-help-<?php echo $routeId; ?>">Only this route’s fare can be changed.</small>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="routes-empty" role="status">
                <i class="fa fa-route" aria-hidden="true"></i>
                <h3>No routes are available</h3>
                <p>Route setup is fixed for this project. Contact the system maintainer if the existing routes are missing.</p>
            </div>
        <?php endif; ?>
    </section>
</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="admin_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="manage_schedules.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedules</span></a>
    <a href="manage_buses.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span></a>
    <a href="manage_operators.php" class="bottom-nav-item"><i class="fa fa-users" aria-hidden="true"></i><span>Operators</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>
<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.sidebar-toggle');
    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    if (toggle) toggle.setAttribute('aria-expanded', isOpen);
    document.body.style.overflow = isOpen ? 'hidden' : '';
}
document.querySelectorAll('.menu-top a').forEach(link => link.addEventListener('click', () => {
    if (window.innerWidth <= 768 && document.querySelector('.sidebar').classList.contains('open')) toggleSidebar();
}));
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.querySelector('.sidebar').classList.contains('open')) toggleSidebar();
});
</script>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/notifications.js"></script>

</body>
</html>
