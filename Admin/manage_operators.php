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

/* EDIT OPERATOR (accounts are created through approved applications, so there is no manual "add") */
if(isset($_POST['update_operator'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email = mbus_normalize_email($_POST['email'] ?? '');
        $phone_number = mbus_normalize_phone($_POST['phone_number'] ?? '');

        $name_error = mbus_validate_full_name($full_name);
        $email_error = mbus_validate_email($email);
        $phone_error = mbus_validate_phone($phone_number);

        if($user_id <= 0){
            $error = "Account not found.";
        } elseif($name_error !== null){
            $error = $name_error;
        } elseif($email_error !== null){
            $error = $email_error;
        } elseif($phone_error !== null){
            $error = $phone_error;
        } else {
            $dup = mbus_db_prepare($conn, "SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ?", [$email, $user_id]);
            if($dup === false){
                error_log('Admin operator email lookup failed: ' . mbus_db_error($conn));
                $error = "We could not check this email address right now. Please try again.";
            } elseif(mbus_db_num_rows($dup) > 0){
                $error = "This email address is already used by another account.";
            } else {
                $saved = mbus_db_prepare($conn, "UPDATE users SET full_name = ?, email = ?, phone_number = ? WHERE user_id = ? AND LOWER(user_type) IN ('operator', 'driver', 'conductor')", [$full_name, $email, $phone_number, $user_id]);
                if($saved === false){
                    error_log('Admin operator update failed: ' . mbus_db_error($conn));
                    $error = "The details could not be saved. Please check them and try again.";
                } else {
                    $success = "Details saved.";
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
        $deleted = mbus_db_prepare($conn, "DELETE FROM users WHERE user_id = ? AND LOWER(user_type) IN ('operator', 'driver', 'conductor')", [$user_id]);
        if($deleted === false){
            error_log('Operator delete failed: ' . mbus_db_error($conn));
            $error = "The account could not be deleted. Please try again.";
        } else {
            $success = "Account deleted successfully";
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
    <link rel="stylesheet" href="../Assets/css/admin/manage_operators.css?v=<?php echo (int)filemtime(__DIR__ . "/../Assets/css/admin/manage_operators.css"); ?>">
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/notif_bell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/notif_bell.css'); ?>">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
</head>

<body class="stf has-sidebar">

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
    SELECT u.user_id, u.full_name, u.email, u.phone_number, LOWER(u.user_type) AS role,
      CASE WHEN LOWER(u.user_type) = 'conductor'
        THEN (SELECT string_agg(b.bus_number, ', ') FROM conductor_bus_assignments ca JOIN buses b ON b.bus_id=ca.bus_id WHERE ca.conductor_id=u.user_id)
        ELSE (SELECT string_agg(b.bus_number, ', ') FROM operator_bus_assignments oa JOIN buses b ON b.bus_id=oa.bus_id WHERE oa.operator_id=u.user_id)
      END AS buses
    FROM users u WHERE LOWER(u.user_type) IN ('operator', 'driver', 'conductor') ORDER BY u.full_name");
if($oq === false){
    /* conductor assignments table not created yet: still list the drivers */
    $oq = mbus_db_query($conn, "
        SELECT u.user_id, u.full_name, u.email, u.phone_number, LOWER(u.user_type) AS role,
          (SELECT string_agg(b.bus_number, ', ') FROM operator_bus_assignments oa JOIN buses b ON b.bus_id=oa.bus_id WHERE oa.operator_id=u.user_id) AS buses
        FROM users u WHERE LOWER(u.user_type) IN ('operator', 'driver') ORDER BY u.full_name");
}
$op_list = [];
while($oq !== false && ($row = mbus_db_fetch_assoc($oq))){
    $row['role'] = $row['role'] === 'conductor' ? 'conductor' : 'driver';
    $op_list[] = $row;
}
$assigned = count(array_filter($op_list, fn($x) => !empty($x['buses'])));
$n_drivers = count(array_filter($op_list, fn($x) => $x['role'] === 'driver'));
$n_conductors = count($op_list) - $n_drivers;
?>
<div class="op-tools has-tabs">
    <div class="op-tabs" role="tablist" aria-label="Staff role">
        <button type="button" class="op-tab on" role="tab" aria-selected="true" data-role="all">All <span><?php echo count($op_list); ?></span></button>
        <button type="button" class="op-tab" role="tab" aria-selected="false" data-role="driver"><i class="fa fa-id-card-clip" aria-hidden="true"></i> Drivers <span><?php echo $n_drivers; ?></span></button>
        <button type="button" class="op-tab" role="tab" aria-selected="false" data-role="conductor"><i class="fa fa-ticket" aria-hidden="true"></i> Conductors <span><?php echo $n_conductors; ?></span></button>
    </div>
    <div class="op-search"><i class="fa fa-search" aria-hidden="true"></i><input type="search" id="opSearch" placeholder="Search name, email or bus" autocomplete="off" aria-label="Search drivers and conductors"><button type="button" class="op-clear" id="opClear" aria-label="Clear search" hidden><i class="fa fa-xmark" aria-hidden="true"></i></button></div>
    <div class="op-chips" role="group" aria-label="Filter by bus">
        <button type="button" class="op-chip on" data-f="all">Any bus</button>
        <button type="button" class="op-chip" data-f="bus">With bus <span><?php echo $assigned; ?></span></button>
        <button type="button" class="op-chip" data-f="nobus">No bus <span><?php echo count($op_list) - $assigned; ?></span></button>
    </div>
    <p class="op-count" id="opCount" aria-live="polite"></p>
</div>

<?php if(!$op_list): ?><div class="ad-empty"><i class="fa fa-user-gear" aria-hidden="true"></i><p>No drivers or conductors yet.</p></div><?php endif; ?>
<div class="ad-table ops">
    <div class="ad-th"><span>Name</span><span>Phone</span><span>Bus</span><span></span></div>
    <?php foreach($op_list as $row):
        $initial = strtoupper(mb_substr(trim((string)$row['full_name']) ?: '?', 0, 1));
    ?>
    <div class="ad-tr" data-op data-role="<?php echo $row['role']; ?>" data-has="<?php echo !empty($row['buses']) ? 'bus' : 'nobus'; ?>" data-q="<?php echo htmlspecialchars(strtolower($row['full_name'].' '.$row['email'].' '.$row['role'].' '.($row['buses'] ?? '')), ENT_QUOTES); ?>">
        <div class="ad-c person"><span class="ad-avatar"><?php echo htmlspecialchars($initial); ?></span><div><b><?php echo htmlspecialchars($row['full_name']); ?> <span class="op-role is-<?php echo $row['role']; ?>"><?php echo ucfirst($row['role']); ?></span></b><small><?php echo htmlspecialchars($row['email']); ?></small></div></div>
        <div class="ad-c phone"><?php echo htmlspecialchars((string)$row['phone_number']); ?></div>
        <div class="ad-c bus"><?php echo !empty($row['buses']) ? '<span class="ad-pill active"><i class="fa fa-bus" aria-hidden="true"></i> '.htmlspecialchars($row['buses']).'</span>' : '<span class="ad-pill muted">No bus</span>'; ?></div>
        <div class="ad-c actions">
            <button type="button" class="ad-icon" data-ad-open="edit" data-title="Edit <?php echo $row['role']; ?>" data-action="update_operator" data-edit="<?php echo htmlspecialchars(json_encode(['user_id'=>(int)$row['user_id'],'full_name'=>$row['full_name'],'email'=>$row['email'],'phone_number'=>preg_replace('/^0/','',(string)$row['phone_number'])]), ENT_QUOTES); ?>" aria-label="Edit <?php echo $row['role']; ?>"><i class="fa fa-pen" aria-hidden="true"></i></button>
            <form method="POST" data-confirm="Delete this <?php echo $row['role']; ?>?"><?php csrf_field(); ?><input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>"><button type="submit" name="delete_operator" class="ad-icon danger" aria-label="Delete <?php echo $row['role']; ?>"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="ad-empty" id="opNone" hidden><i class="fa fa-filter" aria-hidden="true"></i><p>Nothing matches your search.</p></div>
<nav class="op-pager" id="opPager" aria-label="Pages" hidden>
    <button type="button" data-d="-1">Previous</button><span id="opPageLabel"></span><button type="button" data-d="1">Next</button>
</nav>

<dialog class="ad-dialog" id="adDialog" aria-labelledby="adTitle">
    <form method="POST" class="ad-form">
        <?php csrf_field(); ?>
        <input type="hidden" name="user_id" value="">
        <header class="ad-dialog-head"><h2 id="adTitle">Edit details</h2><button type="button" class="ad-x" data-ad-close aria-label="Close"><i class="fa fa-xmark" aria-hidden="true"></i></button></header>
        <div class="ad-dialog-body">
            <label class="ad-field"><span>Full name</span><input type="text" name="full_name" maxlength="100" required></label>
            <label class="ad-field"><span>Email</span><input type="email" name="email" maxlength="100" required></label>
            <label class="ad-field"><span>Phone</span><div class="phone-input"><span class="phone-prefix" aria-hidden="true">+63</span><input type="tel" name="phone_number" inputmode="numeric" pattern="9[0-9]{9}" minlength="10" maxlength="10" placeholder="9XXXXXXXXX" required></div></label>
        </div>
        <footer class="ad-dialog-foot">
            <button type="button" class="ad-btn" data-ad-close>Cancel</button>
            <button type="submit" class="ad-btn primary" id="adSubmit" name="update_operator" value="1">Save changes</button>
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
<script src="../Assets/js/notifications.js"></script>

<script>
/* Filter + search + pages of 4 */
(function () {
  var PER = 4, rows = [].slice.call(document.querySelectorAll('[data-op]')), page = 0, f = 'all', role = 'all';
  var q = document.getElementById('opSearch'), pager = document.getElementById('opPager'), label = document.getElementById('opPageLabel'), none = document.getElementById('opNone');
  var table = document.querySelector('.ad-table.ops');
  var cnt = document.getElementById('opCount'), clr = document.getElementById('opClear');
  clr.addEventListener('click', function () { q.value = ''; page = 0; apply(); q.focus(); });
  function apply() {
    var term = q.value.trim().toLowerCase();
    var hit = rows.filter(function (r) { return (role === 'all' || r.dataset.role === role) && (f === 'all' || r.dataset.has === f) && (term === '' || r.dataset.q.indexOf(term) !== -1); });
    var pages = Math.max(1, Math.ceil(hit.length / PER));
    page = Math.min(page, pages - 1);
    rows.forEach(function (r) { r.hidden = true; });
    hit.slice(page * PER, page * PER + PER).forEach(function (r) { r.hidden = false; });
    if (table) table.hidden = rows.length > 0 && hit.length === 0;
    none.hidden = !(rows.length > 0 && hit.length === 0);
    var inRole = rows.filter(function (r) { return role === 'all' || r.dataset.role === role; }).length;
    var noun = role === 'driver' ? 'driver' : role === 'conductor' ? 'conductor' : 'staff';
    if (noun !== 'staff' && inRole !== 1) noun += 's';
    cnt.textContent = hit.length === inRole ? inRole + ' ' + noun : hit.length + ' of ' + inRole + ' ' + noun;
    clr.hidden = term === '';
    pager.hidden = pages < 2;
    label.textContent = 'Page ' + (page + 1) + ' of ' + pages;
    pager.querySelector('[data-d="-1"]').disabled = page === 0;
    pager.querySelector('[data-d="1"]').disabled = page >= pages - 1;
  }
  q.addEventListener('input', function () { page = 0; apply(); });
  document.querySelectorAll('.op-chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('.op-chip').forEach(function (x) { x.classList.toggle('on', x === c); });
      f = c.dataset.f; page = 0; apply();
    });
  });
  document.querySelectorAll('.op-tab').forEach(function (t) {
    t.addEventListener('click', function () {
      document.querySelectorAll('.op-tab').forEach(function (x) { var on = x === t; x.classList.toggle('on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
      role = t.dataset.role; page = 0; apply();
    });
  });
  pager.addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b || b.disabled) return; page += +b.dataset.d; apply(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
  apply();
})();
</script>
<script src="../Assets/js/password-toggle.js"></script>
</body>
</html>
