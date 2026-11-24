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

/* Buses are not added here: approving a driver application creates the bus they submitted (see
   Includes/bus_from_application.php). It arrives with its plate as the number, and the admin gives it the real
   bus number and can reassign it to another driver. */

/* Give a bus to one operator (0 = unassigned). Replaces whoever had the bus before.
   An operator can still keep several buses: each bus has one operator, an operator can have many buses. */
function mbus_set_bus_operator($conn, int $bus_id, int $operator_id): bool {
    $pdo = $conn->pdo;
    try {
        $pdo->beginTransaction();
        if($operator_id > 0){
            $chk = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ? AND LOWER(user_type) IN ('operator', 'driver')");
            $chk->execute([$operator_id]);
            if(!$chk->fetchColumn()){ $pdo->rollBack(); return false; }
        }
        $pdo->prepare("DELETE FROM operator_bus_assignments WHERE bus_id = ?")->execute([$bus_id]);
        if($operator_id > 0){
            $pdo->prepare("INSERT INTO operator_bus_assignments (operator_id, bus_id) VALUES (?, ?)")->execute([$operator_id, $bus_id]);
        }
        $pdo->commit();
        return true;
    } catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        error_log('Bus operator assignment failed: ' . $e->getMessage());
        return false;
    }
}

function mbus_set_bus_conductor($conn, int $bus_id, int $conductor_id): bool {
    $pdo = $conn->pdo;
    try {
        $pdo->beginTransaction();
        if($conductor_id > 0){
            $chk = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ? AND LOWER(user_type) = 'conductor'");
            $chk->execute([$conductor_id]);
            if(!$chk->fetchColumn()){ $pdo->rollBack(); return false; }
        }
        $pdo->prepare("DELETE FROM conductor_bus_assignments WHERE bus_id = ?")->execute([$bus_id]);
        if($conductor_id > 0){
            $pdo->prepare("INSERT INTO conductor_bus_assignments (conductor_id, bus_id) VALUES (?, ?)")->execute([$conductor_id, $bus_id]);
        }
        $pdo->commit();
        return true;
    } catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        error_log('Bus conductor assignment failed: ' . $e->getMessage());
        return false;
    }
}

/* EDIT BUS: the admin gives the bus number and can reassign the driver (for example when the usual driver
   is sick). Type, seats and photos stay as the driver submitted them. */
if(isset($_POST['update_bus'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $bus_id = (int)($_POST['bus_id'] ?? 0);
        $bus_number = trim((string)($_POST['bus_number'] ?? ''));
        $prev_bus_number = trim((string)($_POST['prev_bus_number'] ?? ''));
        $operator_id = (int)($_POST['operator_id'] ?? 0);
        $prev_operator_id = (int)($_POST['prev_operator_id'] ?? 0);
        $conductor_id = (int)($_POST['conductor_id'] ?? 0);
        $prev_conductor_id = (int)($_POST['prev_conductor_id'] ?? 0);
        $exists = $bus_id > 0 ? mbus_db_prepare($conn, "SELECT 1 FROM buses WHERE bus_id = ?", [$bus_id]) : false;
        if($exists === false || mbus_db_num_rows($exists) < 1){
            $error = "That bus no longer exists.";
        } elseif($bus_number === '' || mb_strlen($bus_number) > 30){
            $error = "Give the bus a number (up to 30 characters).";
        } else {
            $dup = mbus_db_prepare($conn, "SELECT 1 FROM buses WHERE LOWER(bus_number) = LOWER(?) AND bus_id <> ?", [$bus_number, $bus_id]);
            if($dup !== false && mbus_db_num_rows($dup) > 0){
                $error = "Another bus already uses the number " . $bus_number . ".";
            } else {
                $changed = [];
                if($bus_number !== $prev_bus_number){
                    $u = mbus_db_prepare($conn, "UPDATE buses SET bus_number = ? WHERE bus_id = ?", [$bus_number, $bus_id]);
                    if($u === false){ $error = "The bus number could not be saved."; } else { $changed[] = "number set to " . $bus_number; }
                }
                if(!isset($error) && $operator_id !== $prev_operator_id){
                    if(mbus_set_bus_operator($conn, $bus_id, $operator_id)){ $changed[] = $operator_id > 0 ? "driver reassigned" : "driver removed"; }
                    else { $error = "The driver could not be assigned. Please try again."; }
                }
                if(!isset($error) && $conductor_id !== $prev_conductor_id){
                    if(mbus_set_bus_conductor($conn, $bus_id, $conductor_id)){ $changed[] = $conductor_id > 0 ? "conductor assigned" : "conductor removed"; }
                    else { $error = "The conductor could not be assigned. Please try again."; }
                }
                if(!isset($error)){ $success = $changed ? "Bus updated: " . implode(", ", $changed) . "." : "Nothing changed."; }
            }
        }
    }
}

/* DELETE BUS */
if(isset($_POST['delete_bus'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $bus_id = (int)$_POST['bus_id'];
        mbus_db_prepare($conn, "DELETE FROM conductor_bus_assignments WHERE bus_id = ?", [$bus_id]);
        mbus_db_prepare($conn, "DELETE FROM operator_bus_assignments WHERE bus_id = ?", [$bus_id]);
        mbus_db_prepare($conn, "DELETE FROM buses WHERE bus_id = ?", [$bus_id]);
        $success = "Bus deleted successfully";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Buses</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_buses.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
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
        <a href="manage_buses.php" aria-current="page"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
        <a href="manage_operators.php"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
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
    <h1>Manage Buses</h1>

            <div class="page-header-right">
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
$bq = mbus_db_query($conn, "
    SELECT b.bus_id, b.bus_number, b.bus_type, b.capacity,
      (SELECT COUNT(*) FROM schedule sc WHERE sc.bus_id=b.bus_id AND sc.departure_time >= NOW() AND sc.schedule_status='Active') AS upcoming,
      (SELECT string_agg(u.full_name, ', ') FROM operator_bus_assignments oa JOIN users u ON u.user_id=oa.operator_id WHERE oa.bus_id=b.bus_id) AS operators,
      (SELECT oa.operator_id FROM operator_bus_assignments oa WHERE oa.bus_id=b.bus_id ORDER BY oa.assignment_id LIMIT 1) AS operator_id,
      (SELECT u.full_name FROM conductor_bus_assignments ca JOIN users u ON u.user_id=ca.conductor_id WHERE ca.bus_id=b.bus_id LIMIT 1) AS conductor,
      (SELECT ca.conductor_id FROM conductor_bus_assignments ca WHERE ca.bus_id=b.bus_id LIMIT 1) AS conductor_id
    FROM buses b ORDER BY b.bus_number");
$bus_list = [];
while($bq !== false && ($row = mbus_db_fetch_assoc($bq))){ $bus_list[] = $row; }

/* drivers an admin can give a bus to */
$opq = mbus_db_query($conn, "SELECT user_id, full_name FROM users WHERE LOWER(user_type) IN ('operator', 'driver') ORDER BY full_name");
$operator_options = [];
while($opq !== false && ($orow = mbus_db_fetch_assoc($opq))){ $operator_options[] = $orow; }
$cq = mbus_db_query($conn, "SELECT user_id, full_name FROM users WHERE LOWER(user_type) = 'conductor' ORDER BY full_name");
$conductor_options = [];
while($cq !== false && ($crow = mbus_db_fetch_assoc($cq))){ $conductor_options[] = $crow; }
$yes = fn($v) => in_array($v, [true, 1, '1', 't', 'true'], true);
?>
<?php if($bus_list): $n_ac=0;$n_ord=0;$n_nd=0;$n_nc=0; foreach($bus_list as $x){ if(strtolower($x['bus_type'])==='aircon')$n_ac++; else $n_ord++; if(empty($x['operators']))$n_nd++; if(empty($x['conductor']))$n_nc++; } ?>
<div class="op-tools">
    <div class="op-search"><i class="fa fa-search" aria-hidden="true"></i><input type="search" id="opSearch" placeholder="Search bus, driver or conductor" autocomplete="off" aria-label="Search buses"><button type="button" class="op-clear" id="opClear" aria-label="Clear search" hidden><i class="fa fa-xmark" aria-hidden="true"></i></button></div>
    <div class="op-chips" role="group" aria-label="Filter buses">
        <button type="button" class="op-chip on" data-f="all">All <span><?php echo count($bus_list); ?></span></button>
        <button type="button" class="op-chip" data-f="aircon">Aircon <span><?php echo $n_ac; ?></span></button>
        <button type="button" class="op-chip" data-f="ordinary">Ordinary <span><?php echo $n_ord; ?></span></button>
        <button type="button" class="op-chip" data-f="nodriver">No driver <span><?php echo $n_nd; ?></span></button>
        <button type="button" class="op-chip" data-f="noconductor">No conductor <span><?php echo $n_nc; ?></span></button>
    </div>
    <p class="op-count" id="opCount" aria-live="polite"></p>
</div>
<?php endif; ?>
<?php if(!$bus_list): ?><div class="ad-empty"><i class="fa fa-bus" aria-hidden="true"></i><p>No buses yet. They appear here once a driver application is approved.</p></div><?php endif; ?>
<div class="ad-cards">
<?php foreach($bus_list as $row):
    $is_ac = strtolower($row['bus_type']) === 'aircon';
    $needs = false;
    $label = $needs ? 'Plate ' . $row['bus_number'] : ((ctype_digit((string)$row['bus_number']) ? 'Bus ' : '') . $row['bus_number']);
    $edit = ['bus_id'=>(int)$row['bus_id'],'bus_number'=>$needs ? '' : $row['bus_number'],'prev_bus_number'=>$needs ? '' : $row['bus_number'],'operator_id'=>(int)($row['operator_id'] ?? 0),'prev_operator_id'=>(int)($row['operator_id'] ?? 0),'conductor_id'=>(int)($row['conductor_id'] ?? 0),'prev_conductor_id'=>(int)($row['conductor_id'] ?? 0)];
?>
<article class="ad-card" data-item data-tags="<?php echo ($is_ac ? 'aircon' : 'ordinary') . (empty($row['operators']) ? ' nodriver' : '') . (empty($row['conductor']) ? ' noconductor' : ''); ?>" data-q="<?php echo htmlspecialchars(strtolower('bus '.$row['bus_number'].' '.$row['bus_type'].' '.($row['operators'] ?? '').' '.($row['conductor'] ?? '')), ENT_QUOTES); ?>">
    <header>
        <span class="ad-ico <?php echo $is_ac ? '' : 'green'; ?>"><i class="fa fa-bus" aria-hidden="true"></i></span>
        <div><h3><?php echo htmlspecialchars($label); ?></h3><span class="ad-pill <?php echo $is_ac ? 'info' : 'active'; ?>"><?php echo htmlspecialchars($row['bus_type']); ?></span><?php if($needs): ?> <span class="ad-pill amber">Needs bus number</span><?php endif; ?></div>
    </header>
    <dl>
        <div><dt>Capacity</dt><dd><?php echo (int)$row['capacity']; ?> seats</dd></div>
        <div><dt>Upcoming</dt><dd><?php echo (int)$row['upcoming']; ?> trip<?php echo (int)$row['upcoming']===1?'':'s'; ?></dd></div>
        <div><dt>Driver</dt><dd><?php echo !empty($row['operators']) ? htmlspecialchars($row['operators']) : '<span class="muted">Unassigned</span>'; ?></dd></div>
        <div><dt>Conductor</dt><dd><?php echo !empty($row['conductor']) ? htmlspecialchars($row['conductor']) : '<span class="muted">Unassigned</span>'; ?></dd></div>
    </dl>
    <footer>
        <button type="button" class="ad-btn sm" data-ad-open="edit" data-title="<?php echo $needs ? 'Give bus number' : 'Edit bus'; ?>" data-action="update_bus" data-edit="<?php echo htmlspecialchars(json_encode($edit), ENT_QUOTES); ?>"><i class="fa fa-pen" aria-hidden="true"></i> <?php echo $needs ? 'Give bus number' : 'Number / staff'; ?></button>
        <form method="POST" data-confirm="Delete this bus?"><?php csrf_field(); ?><input type="hidden" name="bus_id" value="<?php echo (int)$row['bus_id']; ?>"><button type="submit" name="delete_bus" class="ad-icon danger" aria-label="Delete bus"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
    </footer>
</article>
<?php endforeach; ?>
</div>
<div class="ad-empty" id="opNone" hidden><i class="fa fa-filter" aria-hidden="true"></i><p>No buses match.</p></div>
<nav class="op-pager" id="opPager" aria-label="Bus pages" hidden>
    <button type="button" data-d="-1">Previous</button><span id="opPageLabel"></span><button type="button" data-d="1">Next</button>
</nav>

<dialog class="ad-dialog" id="adDialog" aria-labelledby="adTitle">
    <form method="POST" class="ad-form">
        <?php csrf_field(); ?>
        <header class="ad-dialog-head"><h2 id="adTitle">Edit bus</h2><button type="button" class="ad-x" data-ad-close aria-label="Close"><i class="fa fa-xmark" aria-hidden="true"></i></button></header>
        <div class="ad-dialog-body">
            <input type="hidden" name="bus_id">
            <input type="hidden" name="prev_bus_number" value="">
            <input type="hidden" name="prev_operator_id" value="0">
            <input type="hidden" name="prev_conductor_id" value="0">
            <label class="ad-field"><span>Bus number</span><input type="text" name="bus_number" required maxlength="30" placeholder="e.g. BUS-05"></label>
            <label class="ad-field"><span>Driver</span>
                <select name="operator_id">
                    <option value="0">Unassigned</option>
                    <?php foreach($operator_options as $op): ?>
                    <option value="<?php echo (int)$op['user_id']; ?>"><?php echo htmlspecialchars($op['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="ad-field"><span>Conductor</span>
                <select name="conductor_id">
                    <option value="0">Unassigned</option>
                    <?php foreach($conductor_options as $co): ?>
                    <option value="<?php echo (int)$co['user_id']; ?>"><?php echo htmlspecialchars($co['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <footer class="ad-dialog-foot">
            <button type="button" class="ad-btn" data-ad-close>Cancel</button>
            <button type="submit" class="ad-btn primary" id="adSubmit" name="update_bus" value="1">Save changes</button>
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
    <a href="manage_buses.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span>
    </a>
    <a href="manage_operators.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Operators</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script>
/* Filter + search + pages of 4 */
(function () {
  var q = document.getElementById('opSearch'); if (!q) return;
  var PER = 4, rows = [].slice.call(document.querySelectorAll('[data-item]')), page = 0, f = 'all';
  var pager = document.getElementById('opPager'), label = document.getElementById('opPageLabel'), none = document.getElementById('opNone');
  var cnt = document.getElementById('opCount'), clr = document.getElementById('opClear');
  clr.addEventListener('click', function () { q.value = ''; page = 0; apply(); q.focus(); });
  function apply() {
    var term = q.value.trim().toLowerCase();
    var hit = rows.filter(function (r) { return (f === 'all' || (' ' + r.dataset.tags + ' ').indexOf(' ' + f + ' ') !== -1) && (term === '' || r.dataset.q.indexOf(term) !== -1); });
    var pages = Math.max(1, Math.ceil(hit.length / PER));
    page = Math.min(page, pages - 1);
    rows.forEach(function (r) { r.hidden = true; });
    hit.slice(page * PER, page * PER + PER).forEach(function (r) { r.hidden = false; });
    none.hidden = hit.length > 0;
    cnt.textContent = hit.length === rows.length ? rows.length + ' buses' : hit.length + ' of ' + rows.length + ' buses';
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
  pager.addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b || b.disabled) return; page += +b.dataset.d; apply(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
  apply();
})();
</script>
<script src="../Assets/js/admin_list.js?v=<?php echo filemtime(__DIR__ . '/../Assets/js/admin_list.js'); ?>"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
