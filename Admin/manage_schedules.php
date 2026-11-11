<?php
session_start();
if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != 'admin'){
    header("Location: ../login.php");
    exit();
}
include("../config/db_connect.php");
include("../Includes/csrf.php");

function mbus_admin_schedule_valid(array $data, bool $with_id=false): bool
{
    $fields=$with_id ? ['schedule_id','bus_id','route_id','departure_time','arrival_time','schedule_status'] : ['bus_id','route_id','departure_time','arrival_time','schedule_status'];
    foreach($fields as $field){
        if(!isset($data[$field]) || !is_scalar($data[$field]) || trim((string)$data[$field]) === '') return false;
    }
    if($with_id && filter_var($data['schedule_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) return false;
    if(filter_var($data['bus_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false || filter_var($data['route_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false || !in_array((string)$data['schedule_status'], ['Active','Cancelled','Completed'], true)) return false;
    $departure=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string)$data['departure_time']);
    $arrival=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string)$data['arrival_time']);
    $departure_errors=DateTimeImmutable::getLastErrors();
    $arrival_errors=DateTimeImmutable::getLastErrors();
    if($departure === false || $arrival === false || ($departure_errors !== false && ($departure_errors['warning_count'] > 0 || $departure_errors['error_count'] > 0)) || ($arrival_errors !== false && ($arrival_errors['warning_count'] > 0 || $arrival_errors['error_count'] > 0))) return false;
    return $arrival > $departure;
}

/* UPDATE */
if(isset($_POST['update_schedule'])){
    if(!csrf_validate_token()) $error = "Security validation failed. Please try again.";
    elseif(!mbus_admin_schedule_valid($_POST, true)) $error = "Please provide valid schedule values.";
    else {
        $schedule_id=(int)$_POST['schedule_id']; $bus_id=(int)$_POST['bus_id']; $route_id=(int)$_POST['route_id'];
        $departure_time=(string)$_POST['departure_time']; $arrival_time=(string)$_POST['arrival_time']; $schedule_status=(string)$_POST['schedule_status'];
        $updated=mbus_db_prepare($conn,"UPDATE schedule SET bus_id=?, route_id=?, departure_time=?, arrival_time=?, schedule_status=? WHERE schedule_id=?",[$bus_id,$route_id,$departure_time,$arrival_time,$schedule_status,$schedule_id]);
        if($updated===false) $error="Schedule could not be updated. Please verify the selected values.";
        else { header("Location: manage_schedules.php"); exit(); }
    }
}

/* DELETE */
if(isset($_POST['delete_schedule'])){
    if(!csrf_validate_token()) $error="Security validation failed. Please try again.";
    elseif(!isset($_POST['schedule_id']) || !is_scalar($_POST['schedule_id']) || filter_var($_POST['schedule_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) $error="Invalid schedule.";
    else {
        $deleted=mbus_db_prepare($conn,"DELETE FROM schedule WHERE schedule_id=?",[(int)$_POST['schedule_id']]);
        if($deleted===false) $error="Schedule could not be deleted.";
        else { header("Location: manage_schedules.php"); exit(); }
    }
}

/* ADD */
if(isset($_POST['add_schedule'])){
    if(!csrf_validate_token()) $error="Security validation failed. Please try again.";
    elseif(!mbus_admin_schedule_valid($_POST)) $error="Please provide valid schedule values.";
    else {
        $inserted=mbus_db_prepare($conn,"INSERT INTO schedule(bus_id,route_id,departure_time,arrival_time,schedule_status) VALUES(?,?,?,?,?)",[(int)$_POST['bus_id'],(int)$_POST['route_id'],(string)$_POST['departure_time'],(string)$_POST['arrival_time'],(string)$_POST['schedule_status']]);
        if($inserted===false) $error="Schedule could not be added. Please verify the selected values.";
        else { header("Location: manage_schedules.php"); exit(); }
    }
}

/* FETCH */

$routes = mbus_db_query($conn, "SELECT * FROM routes ORDER BY origin ASC");
$buses  = mbus_db_query($conn, "SELECT * FROM buses ORDER BY bus_id ASC");

$query = mbus_db_query($conn, "
SELECT schedule.*, routes.origin, routes.destination, buses.capacity, buses.bus_type,
  (SELECT COUNT(*) FROM reservation rv WHERE rv.schedule_id=schedule.schedule_id AND rv.status IN ('Paid','Confirmed','Boarded')) AS taken
FROM schedule
LEFT JOIN routes ON schedule.route_id=routes.route_id
LEFT JOIN buses ON buses.bus_id=schedule.bus_id
ORDER BY departure_time ASC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Schedules</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_schedules.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/professional-filters.css">
    <link rel="stylesheet" href="../Assets/css/admin/schedules_compact.css">
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
        <a href="manage_schedules.php" aria-current="page"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
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
    <h1>Manage Schedules</h1>

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

<!-- Filter Tabs -->
<div class="route-filters" aria-label="Schedule filters">
<a href="?filter=all" class="route-chip <?php echo (!isset($_GET['filter']) || $_GET['filter']==='all') ? 'active' : ''; ?>" aria-current="<?php echo (!isset($_GET['filter']) || $_GET['filter']==='all') ? 'page' : 'false'; ?>">All Routes</a>
<a href="?filter=morong" class="route-chip <?php echo (isset($_GET['filter']) && $_GET['filter']==='morong') ? 'active' : ''; ?>" aria-current="<?php echo (isset($_GET['filter']) && $_GET['filter']==='morong') ? 'page' : 'false'; ?>">Morong → SBMA</a>
<a href="?filter=sbma" class="route-chip <?php echo (isset($_GET['filter']) && $_GET['filter']==='sbma') ? 'active' : ''; ?>" aria-current="<?php echo (isset($_GET['filter']) && $_GET['filter']==='sbma') ? 'page' : 'false'; ?>">SBMA → Morong</a>
</div>

<?php
$bus_rows=[]; mbus_db_data_seek($buses, 0);
while($b=mbus_db_fetch_assoc($buses)){ $bus_rows[]=$b; }
$route_rows=[]; mbus_db_data_seek($routes, 0);
while($r=mbus_db_fetch_assoc($routes)){ $route_rows[]=$r; }
$bus_label=[]; foreach($bus_rows as $b){ $bus_label[(int)$b['bus_id']]=$b['bus_number']; }
$filter = $_GET['filter'] ?? 'all';
$groups=[]; $sched_ids=[];
mbus_db_data_seek($query, 0);
while($row = mbus_db_fetch_assoc($query)){
    $route_type = (stripos((string)$row['origin'], 'morong') !== false) ? 'morong' : 'sbma';
    if($filter !== 'all' && $filter !== $route_type) continue;
    $row['route_type']=$route_type;
    $groups[date('Y-m-d', strtotime($row['departure_time']))][]=$row;
}
$bus_options = function($selected=null) use ($bus_rows){
    $o='';
    foreach($bus_rows as $b){ $o.='<option value="'.(int)$b['bus_id'].'"'.($selected!==null && (int)$b['bus_id']===(int)$selected?' selected':'').'>Bus '.htmlspecialchars($b['bus_number']).' · '.(int)$b['capacity'].' seats</option>'; }
    return $o;
};
$route_options = function($selected=null) use ($route_rows){
    $o='';
    foreach($route_rows as $r){ $o.='<option value="'.(int)$r['route_id'].'"'.($selected!==null && (int)$r['route_id']===(int)$selected?' selected':'').'>'.htmlspecialchars($r['origin']).' → '.htmlspecialchars($r['destination']).'</option>'; }
    return $o;
};
?>

<!-- ADD SCHEDULE -->
<details class="sc-add" <?php echo isset($error) && isset($_POST['add_schedule']) ? 'open' : ''; ?>>
    <summary class="btn btn-primary btn-sm"><i class="fa fa-plus" aria-hidden="true"></i> Add schedule</summary>
    <form method="POST" class="sc-add-form">
        <?php csrf_field(); ?>
        <select name="bus_id" aria-label="Bus" class="form-control" required>
            <option value="">Bus</option><?php echo $bus_options(); ?>
        </select>
        <select name="route_id" aria-label="Route" class="form-control" required>
            <option value="">Route</option><?php echo $route_options(); ?>
        </select>
        <input type="datetime-local" name="departure_time" aria-label="Departure" class="form-control" required>
        <input type="datetime-local" name="arrival_time" aria-label="Arrival" class="form-control" required>
        <select name="schedule_status" aria-label="Status" class="form-control">
            <option value="Active">Active</option><option value="Cancelled">Cancelled</option><option value="Completed">Completed</option>
        </select>
        <button type="submit" name="add_schedule" class="btn btn-primary btn-sm">Save</button>
    </form>
</details>

<!-- SCHEDULE LIST -->
<section class="sc-list" aria-label="Schedules">
<?php if(!$groups): ?>
    <p class="sc-empty">No schedules yet.</p>
<?php endif; ?>
<?php foreach($groups as $day => $rows): ?>
    <h2 class="sc-day"><?php echo date('D, M j', strtotime($day)); ?></h2>
    <?php foreach($rows as $row):
        $sid=(int)$row['schedule_id']; $fid='sf-'.$sid; $sched_ids[]=$sid;
        $status=(string)$row['schedule_status'];
        $dep=strtotime($row['departure_time']); $arr=strtotime($row['arrival_time']);
        $bn=$bus_label[(int)$row['bus_id']] ?? $row['bus_id'];
    ?>
    <article class="sc-row dir-<?php echo $row['route_type']; ?> st-<?php echo strtolower($status); ?>" data-route="<?php echo $row['route_type']; ?>">
        <div class="sc-main">
            <span class="sc-time"><?php echo date('g:i A', $dep); ?><small> – <?php echo date('g:i A', $arr); ?></small></span>
            <span class="sc-route"><?php echo htmlspecialchars($row['origin']); ?> → <?php echo htmlspecialchars($row['destination']); ?></span>
            <span class="sc-bus"><i class="fa fa-bus" aria-hidden="true"></i> Bus <?php echo htmlspecialchars((string)$bn); ?></span>
            <span class="sc-status sc-<?php echo strtolower($status); ?>"><?php echo htmlspecialchars($status); ?></span>
            <span class="sc-actions">
                <button type="button" class="sc-edit-btn" onclick="scEdit(this,true)"><i class="fa fa-pen" aria-hidden="true"></i> Edit</button>
                <button type="submit" form="<?php echo $fid; ?>" name="delete_schedule" class="sc-icon sc-del" onclick="return confirm('Delete this schedule?')" aria-label="Delete schedule"><i class="fa fa-trash" aria-hidden="true"></i></button>
            </span>
        </div>
        <div class="sc-meta">
            <span><i class="fa fa-clock" aria-hidden="true"></i> <?php $mins=max(0,(int)round(($arr-$dep)/60)); echo intdiv($mins,60).'h '.($mins%60).'m'; ?></span>
            <span><i class="fa fa-chair" aria-hidden="true"></i> <?php echo (int)$row['taken']; ?>/<?php echo (int)$row['capacity']; ?> seats</span>
            <?php if(!empty($row['bus_type'])): ?><span><i class="fa fa-snowflake" aria-hidden="true"></i> <?php echo htmlspecialchars($row['bus_type']); ?></span><?php endif; ?>
            <?php if(!empty($row['trip_status'])): ?><span class="sc-trip"><?php echo htmlspecialchars($row['trip_status']); ?></span><?php endif; ?>
        </div>
        <div class="sc-edit" hidden>
            <select form="<?php echo $fid; ?>" name="bus_id" aria-label="Bus" class="form-control"><?php echo $bus_options($row['bus_id']); ?></select>
            <select form="<?php echo $fid; ?>" name="route_id" aria-label="Route" class="form-control"><?php echo $route_options($row['route_id']); ?></select>
            <input type="datetime-local" form="<?php echo $fid; ?>" name="departure_time" aria-label="Departure" class="form-control" value="<?php echo date('Y-m-d\TH:i', $dep); ?>">
            <input type="datetime-local" form="<?php echo $fid; ?>" name="arrival_time" aria-label="Arrival" class="form-control" value="<?php echo date('Y-m-d\TH:i', $arr); ?>">
            <select form="<?php echo $fid; ?>" name="schedule_status" aria-label="Status" class="form-control">
                <?php foreach(['Active','Cancelled','Completed'] as $st){ echo '<option'.($status===$st?' selected':'').'>'.$st.'</option>'; } ?>
            </select>
            <span class="sc-edit-actions">
                <button type="submit" form="<?php echo $fid; ?>" name="update_schedule" class="btn btn-sm btn-primary">Save</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="scEdit(this,false)">Cancel</button>
            </span>
        </div>
    </article>
    <?php endforeach; ?>
<?php endforeach; ?>
<?php foreach($sched_ids as $sid){ ?>
    <form id="sf-<?php echo $sid; ?>" method="POST" hidden><?php csrf_field(); ?><input type="hidden" name="schedule_id" value="<?php echo $sid; ?>"></form>
<?php } ?>
</section>

</main>

<script>
function scEdit(btn, on) {
    const row = btn.closest('.sc-row');
    row.classList.toggle('editing', on);
    row.querySelector('.sc-edit').hidden = !on;
    if (!on) row.querySelectorAll('.sc-edit select, .sc-edit input').forEach(el => {
        if (el.tagName === 'SELECT') Array.from(el.options).forEach(o => { o.selected = o.defaultSelected; });
        else el.value = el.defaultValue;
    });
    else { const f = row.querySelector('.sc-edit select'); if (f) f.focus(); }
}

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
    <a href="manage_schedules.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-calendar" aria-hidden="true"></i><span>Schedules</span>
    </a>
    <a href="manage_buses.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span>
    </a>
    <a href="manage_operators.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Operators</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
