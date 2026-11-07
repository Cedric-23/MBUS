<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/mysqli_compat.php";
require_once "../Includes/csrf.php";
require_once "../Includes/activity_log.php";

if(!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type']) !== 'operator'){
    header("Location: ../login.php");
    exit();
}

$operator_id = (int)$_SESSION['user_id'];
$success = null;
$error = null;
$departed_now = false;
$track_focus = 0;
$archive_table_check = mbus_db_query($conn, "SELECT to_regclass('public.operator_trip_archives') AS archive_table");
$archive_table_row = $archive_table_check !== false ? mbus_db_fetch_assoc($archive_table_check) : false;
$archive_table_ready = is_array($archive_table_row) && !empty($archive_table_row['archive_table']);

// Archive terminal trips per operator; this never deletes operational records.
if($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['archive_trip']) || isset($_POST['clear_history']))){
    if(!csrf_validate_token()) $error = "Invalid CSRF token.";
    elseif(!$archive_table_ready) $error = "Trip history is not enabled yet. Run supabase/09_operator_trip_history.sql first.";
    elseif(isset($_POST['archive_trip'])){
        $schedule_id = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if($schedule_id === false) $error = "Invalid trip.";
        else {
            $result = mbus_db_prepare($conn, "
                INSERT INTO operator_trip_archives(operator_id, schedule_id)
                SELECT ?, s.schedule_id FROM schedule s
                JOIN operator_bus_assignments oba ON oba.bus_id=s.bus_id AND oba.operator_id=?
                WHERE s.schedule_id=? AND (s.trip_status IN ('Arrived','Cancelled') OR s.schedule_status IN ('Completed','Cancelled'))
                ON CONFLICT (operator_id, schedule_id) DO NOTHING
            ", [(int)$operator_id, (int)$operator_id, $schedule_id]);
            if($result === false) $error = "Trip could not be archived.";
            else $success = "Trip moved to history archive.";
        }
    } else {
        $result = mbus_db_prepare($conn, "
            INSERT INTO operator_trip_archives(operator_id, schedule_id)
            SELECT ?, s.schedule_id FROM schedule s
            JOIN operator_bus_assignments oba ON oba.bus_id=s.bus_id AND oba.operator_id=?
            WHERE s.trip_status IN ('Arrived','Cancelled') OR s.schedule_status IN ('Completed','Cancelled')
            ON CONFLICT (operator_id, schedule_id) DO NOTHING
        ", [(int)$operator_id, (int)$operator_id]);
        if($result === false) $error = "History could not be cleared.";
        else $success = "Completed and cancelled trips were archived safely.";
    }
}

// Handle status update
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])){
    if(!csrf_validate_token()){
        $error = "Invalid CSRF token";
    } else {
        if(!isset($_POST['schedule_id'], $_POST['trip_status']) || !is_scalar($_POST['schedule_id']) || !is_scalar($_POST['trip_status'])){
            $error = "Invalid trip update.";
            $schedule_id = 0;
            $trip_status = '';
        } else {
        $schedule_id = (int)$_POST['schedule_id'];
        $trip_status = (string)$_POST['trip_status'];
        $allowed_statuses = ['Scheduled','Boarding','Departed','Arrived','Cancelled'];
        if(in_array($trip_status, $allowed_statuses)){
            $stmt = mbus_db_prepare($conn, "UPDATE schedule SET trip_status=? WHERE schedule_id=? AND bus_id IN (SELECT bus_id FROM operator_bus_assignments WHERE operator_id=?)", [$trip_status, $schedule_id, $operator_id]);
            if($stmt !== false && $conn->affectedRows > 0){
                $success = "Trip status updated";
                $departed_now = ($trip_status === 'Departed');
                if ($departed_now) { $track_focus = (int)$schedule_id; }
            } else {
                $error = "Failed to update status";
            }
        } else {
            $error = "Invalid status";
        }
        }
    }
}

// Handle mark boarded
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_boarded'])){
    if(!csrf_validate_token()){
        $error = "Invalid CSRF token";
    } else {
        if(!isset($_POST['reservation_id']) || !is_scalar($_POST['reservation_id'])){
            $error = "Invalid reservation.";
            $reservation_id = 0;
        } else {
        $reservation_id = (int)$_POST['reservation_id'];
        $stmt = mbus_db_prepare($conn, "UPDATE reservation r SET status='Boarded'
            WHERE r.reservation_id = ?
              AND r.status = 'Paid'
              AND r.schedule_id IN (
                  SELECT s.schedule_id FROM schedule s
                  JOIN operator_bus_assignments oba ON oba.bus_id = s.bus_id
                  WHERE oba.operator_id = ? AND s.trip_status IN ('Scheduled', 'Boarding')
              )", [$reservation_id, $operator_id]);
        if($stmt !== false && $conn->affectedRows > 0){
            $success = "Passenger marked as boarded";
        } else {
            $error = "Failed to update passenger";
        }
        }
    }
}

// Filters
$allowed_filters = ['upcoming','active','today','tomorrow','this_week','next_week','history','all'];
$filter = isset($_GET['filter']) && is_scalar($_GET['filter']) ? (string)$_GET['filter'] : 'upcoming';
if(!in_array($filter, $allowed_filters, true)) $filter='upcoming';
$open_schedule_input = $_POST['open_schedule'] ?? $_GET['open_schedule'] ?? null;
$open_schedule = is_scalar($open_schedule_input) && filter_var($open_schedule_input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false ? (int)$open_schedule_input : null;
$where = "WHERE oba.operator_id = ?";
$params = [(int)$operator_id];
if($archive_table_ready) $where .= " AND NOT EXISTS (SELECT 1 FROM operator_trip_archives ota WHERE ota.operator_id=oba.operator_id AND ota.schedule_id=s.schedule_id)";
$b = mbus_local_bounds();
$live_since = $b['today']; // Keep today's departed trip visible so its operator GPS watch can continue.
switch($filter){
    case 'today': $where .= " AND s.departure_time >= '{$b['today']}' AND s.departure_time < '{$b['tomorrow']}'"; break;
    case 'tomorrow': $where .= " AND s.departure_time >= '{$b['tomorrow']}' AND s.departure_time < '{$b['day_after']}'"; break;
    case 'this_week': $where .= " AND s.departure_time >= '{$b['week_start']}' AND s.departure_time < '{$b['next_week']}'"; break;
    case 'next_week': $where .= " AND s.departure_time >= '{$b['next_week']}' AND s.departure_time < '{$b['week_after_next']}'"; break;
    case 'history': $where .= " AND (s.trip_status IN ('Arrived','Cancelled') OR s.schedule_status IN ('Completed','Cancelled'))"; break;
    case 'active': $where .= " AND s.departure_time >= '$live_since' AND s.trip_status IN ('Scheduled','Boarding','Departed') AND s.schedule_status='Active'"; break;
    case 'all': break;
    default: $where .= " AND s.departure_time >= '$live_since' AND s.trip_status IN ('Scheduled','Boarding','Departed') AND s.schedule_status='Active'"; break;
}
$query = mbus_db_prepare($conn, "
    SELECT s.schedule_id, s.departure_time, s.arrival_time, s.trip_status, s.schedule_status,
           b.bus_number, b.bus_type, r.origin, r.destination
    FROM schedule s
    JOIN buses b ON s.bus_id=b.bus_id
    JOIN routes r ON s.route_id=r.route_id
    JOIN operator_bus_assignments oba ON oba.bus_id=s.bus_id
    $where
    ORDER BY s.departure_time ASC
", $params);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
    <title>Trip Operations</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_manage_trips.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/professional-filters.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/images/mbus_icon.png">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/gps_status.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_trip_cards.css">
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

    <h2>Operator</h2>

    <nav class="menu-top" aria-label="Operator menu">
        <a href="operator_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="scan.php"><i class="fa fa-qrcode" aria-hidden="true"></i> Scan Ticket</a>
        <a href="manage_trips.php" aria-current="page"><i class="fa fa-bus" aria-hidden="true"></i> Manage Trips</a>
        <a href="passengers.php"><i class="fa fa-users" aria-hidden="true"></i> Passenger List</a>
    </nav>

    <div class="menu-bottom">
        <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
            <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
        </a>
    </div>
</aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Trip Operations</h1>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></span>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                            <span class="profile-menu-role">Operator account</span>
                        </div>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
</header>

<?php if(isset($success)): ?>
<div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<section class="gps-card" aria-labelledby="trip-context-heading">
    <div class="gps-card-head">
        <h2 id="trip-context-heading">Bus location</h2>
        <span id="operatorTrackingBadge" class="gps-badge">Off</span>
    </div>
    <p id="operatorTrackingStatus" role="status" aria-live="polite">Starting location…</p>
</section>
<?php if(!empty($departed_now)): ?>
<div class="departing-banner" id="departingBanner" role="status"><i class="fa fa-location-crosshairs" aria-hidden="true"></i> Departed — pinning your location for riders and admin.</div>
<?php endif; ?>
<div class="date-filter-container">
<nav class="date-filters" aria-label="Trip filters">
<a href="?filter=upcoming" class="date-filter-btn <?php echo $filter==='upcoming' ? 'active' : ''; ?>">Upcoming</a>
<a href="?filter=active" class="date-filter-btn <?php echo $filter==='active' ? 'active' : ''; ?>">Active</a>
<a href="?filter=today" class="date-filter-btn <?php echo $filter==='today' ? 'active' : ''; ?>">Today</a>
<a href="?filter=history" class="date-filter-btn <?php echo $filter==='history' ? 'active' : ''; ?>">History</a>
</nav>
</div>
<?php if($filter==='history'): ?>
<div class="card" style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;">
    <span>History is preserved for audit records; clearing only hides terminal trips from this workspace.</span>
    <form method="POST" onsubmit="return confirm('Archive completed and cancelled trips from your workspace? Records will not be deleted.');">
        <?php csrf_field(); ?><button type="submit" name="clear_history" class="btn btn-outline"><i class="fa fa-box-archive" aria-hidden="true"></i> Clear history</button>
    </form>
</div>
<?php endif; ?>

<?php
$trip_rows = [];
mbus_db_data_seek($query, 0);
while($tr = mbus_db_fetch_assoc($query)) $trip_rows[] = $tr;
?>
<?php if(!$trip_rows){ ?>
<p class="trips-empty">No trips here. Try another filter.</p>
<?php } ?>
<div class="trip-cards">
<?php foreach($trip_rows as $row){
    $sid = (int)$row['schedule_id'];
    $pq = mbus_db_prepare($conn, "SELECT reservation.reservation_id, reservation.seat_number, reservation.pickup_location, reservation.destination, reservation.status, users.full_name
        FROM reservation JOIN users ON reservation.user_id = users.user_id
        WHERE reservation.schedule_id = ? AND reservation.status IN ('Paid','Boarded')
        ORDER BY NULLIF(regexp_replace(reservation.seat_number,'\\D','','g'),'')::int", [$sid]);
    $riders = [];
    while($pq !== false && ($pr = mbus_db_fetch_assoc($pq))) $riders[] = $pr;
    $boarded = count(array_filter($riders, fn($r) => $r['status'] === 'Boarded'));
    $is_open = $open_schedule === $sid;
?>
<article class="trip-card trip-row" data-schedule-id="<?php echo $sid; ?>" data-trip-status="<?php echo htmlspecialchars($row['trip_status'], ENT_QUOTES); ?>">
    <div class="tc-top">
        <span class="tc-bus"><i class="fa fa-bus" aria-hidden="true"></i> <?php echo htmlspecialchars($row['bus_number']); ?><small><?php echo htmlspecialchars($row['bus_type']); ?></small></span>
        <span class="status-badge <?php echo strtolower($row['trip_status']); ?>"><?php echo htmlspecialchars($row['trip_status']); ?></span>
    </div>
    <p class="tc-route"><?php echo htmlspecialchars($row['origin']); ?> <span aria-hidden="true">&rarr;</span> <?php echo htmlspecialchars($row['destination']); ?></p>
    <p class="tc-time"><?php echo date('D, M j', strtotime($row['departure_time'])); ?> &middot; <?php echo date('g:i A', strtotime($row['departure_time'])); ?> to <?php echo date('g:i A', strtotime($row['arrival_time'])); ?></p>

    <div class="tc-actions">
        <form method="POST" class="status-form">
            <?php csrf_field(); ?>
            <input type="hidden" name="schedule_id" value="<?php echo $sid; ?>">
            <input type="hidden" name="open_schedule" value="<?php echo $open_schedule ?? $sid; ?>">
            <input type="hidden" name="update_status" value="1">
            <label class="sr-only" for="st-<?php echo $sid; ?>">Trip status</label>
            <select id="st-<?php echo $sid; ?>" name="trip_status" class="form-control status-select" onchange="this.form.submit()">
                <?php foreach(['Scheduled','Boarding','Departed','Arrived','Cancelled'] as $st){ ?>
                <option value="<?php echo $st; ?>" <?php echo $row['trip_status']===$st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                <?php } ?>
            </select>
        </form>
        <button type="button" class="tc-riders toggle-passengers" data-schedule-id="<?php echo $sid; ?>" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>" aria-controls="passengers-<?php echo $sid; ?>">
            <i class="fa fa-users" aria-hidden="true"></i> Riders <b><?php echo $boarded; ?>/<?php echo count($riders); ?></b>
            <i class="fa fa-chevron-down tc-chev" aria-hidden="true"></i>
        </button>
    </div>
    <?php if($archive_table_ready && ($row['trip_status']==='Arrived' || $row['trip_status']==='Cancelled' || $row['schedule_status']==='Completed' || $row['schedule_status']==='Cancelled')): ?>
    <form method="POST" class="action-form" onsubmit="return confirm('Archive this completed trip? Records will not be deleted.');">
        <?php csrf_field(); ?>
        <input type="hidden" name="schedule_id" value="<?php echo $sid; ?>">
        <button type="submit" name="archive_trip" class="btn btn-sm btn-outline"><i class="fa fa-box-archive" aria-hidden="true"></i> Archive</button>
    </form>
    <?php endif; ?>

    <div class="manifest" id="passengers-<?php echo $sid; ?>" <?php echo $is_open ? '' : 'hidden'; ?>>
        <?php if(!$riders){ ?>
            <p class="manifest-empty">No paid riders yet.</p>
        <?php } else { ?>
        <ul class="manifest-list">
            <?php foreach($riders as $r){ ?>
            <li class="<?php echo $r['status'] === 'Boarded' ? 'is-boarded' : ''; ?>">
                <span class="m-seat"><?php echo htmlspecialchars($r['seat_number']); ?></span>
                <span class="m-info"><strong><?php echo htmlspecialchars($r['full_name']); ?></strong><small><?php echo htmlspecialchars($r['pickup_location'] . ' to ' . $r['destination']); ?></small></span>
                <?php if($r['status'] === 'Boarded'){ ?>
                    <span class="m-done">Boarded</span>
                <?php } else { ?>
                <form method="POST" class="action-form">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="reservation_id" value="<?php echo (int)$r['reservation_id']; ?>">
                    <input type="hidden" name="open_schedule" value="<?php echo $sid; ?>">
                    <button type="submit" name="mark_boarded" class="m-board">Board</button>
                </form>
                <?php } ?>
            </li>
            <?php } ?>
        </ul>
        <?php } ?>
    </div>
</article>
<?php } ?>
</div>

</main>

<script>
window.OPERATOR_LIVE_ENDPOINT = '../api/live_tracking.php';
window.OPERATOR_CSRF_TOKEN = <?php echo json_encode(csrf_get_token()); ?>;
window.OPERATOR_TRACK_SCHEDULE_ID = <?php echo json_encode($track_focus ?: $open_schedule); ?>;
document.addEventListener('DOMContentLoaded', function() {
    // Show / hide each trip's riders
    document.querySelectorAll('.toggle-passengers').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var panel = document.getElementById('passengers-' + this.dataset.scheduleId);
            if (!panel) return;
            var open = panel.hasAttribute('hidden');
            if (open) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
});

// Toggle sidebar for mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.sidebar-toggle');

    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    document.body.classList.toggle('sidebar-open', isOpen);

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
<script src="../Assets/js/pickup_staff.js"></script>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="manage_trips.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span>
    </a>
    <a href="scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="passengers.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Riders</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
