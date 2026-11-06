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
$open_schedule = isset($_GET['open_schedule']) && is_scalar($_GET['open_schedule']) ? (int)$_GET['open_schedule'] : null;
$where = "WHERE oba.operator_id = ?";
$params = [(int)$operator_id];
if($archive_table_ready) $where .= " AND NOT EXISTS (SELECT 1 FROM operator_trip_archives ota WHERE ota.operator_id=oba.operator_id AND ota.schedule_id=s.schedule_id)";
$b = mbus_local_bounds();
switch($filter){
    case 'today': $where .= " AND s.departure_time >= '{$b['today']}' AND s.departure_time < '{$b['tomorrow']}'"; break;
    case 'tomorrow': $where .= " AND s.departure_time >= '{$b['tomorrow']}' AND s.departure_time < '{$b['day_after']}'"; break;
    case 'this_week': $where .= " AND s.departure_time >= '{$b['week_start']}' AND s.departure_time < '{$b['next_week']}'"; break;
    case 'next_week': $where .= " AND s.departure_time >= '{$b['next_week']}' AND s.departure_time < '{$b['week_after_next']}'"; break;
    case 'history': $where .= " AND (s.trip_status IN ('Arrived','Cancelled') OR s.schedule_status IN ('Completed','Cancelled'))"; break;
    case 'active': $where .= " AND s.departure_time >= NOW() AND s.trip_status IN ('Scheduled','Boarding')"; break;
    case 'all': break;
    default: $where .= " AND s.departure_time >= NOW() AND s.trip_status IN ('Scheduled','Boarding')"; break;
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

<section class="card" aria-labelledby="trip-context-heading">
    <h2 id="trip-context-heading" style="margin:0 0 .35rem;">Trip Operations</h2>
    <p style="margin:0;color:var(--color-text-muted);">Use Scan Ticket for boarding, update trip status here, and review completed work in History.</p>
</section>
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

<div class="table-responsive trips-table-responsive">
    <table class="trips-table">
        <thead>
            <tr>
                <th scope="col">Schedule ID</th>
                <th scope="col">Bus</th>
                <th scope="col">Route</th>
                <th scope="col">Departure</th>
                <th scope="col">Arrival</th>
                <th scope="col">Status</th>
                <th scope="col">Update</th>
                <th scope="col">Passengers</th>
            </tr>
        </thead>
        <tbody>
            <?php
            mbus_db_data_seek($query, 0);
            while($row=mbus_db_fetch_assoc($query)){
            ?>
            <tr class="trip-row" data-schedule-id="<?php echo $row['schedule_id']; ?>">

                    <td data-label="Schedule ID"><?php echo $row['schedule_id']; ?></td>

                    <td data-label="Bus">
                        <div class="bus-info">
                            <span class="bus-number"><?php echo htmlspecialchars($row['bus_number']); ?></span>
                            <span class="bus-type"><?php echo htmlspecialchars($row['bus_type']); ?></span>
                        </div>
                    </td>

                    <td data-label="Route">
                        <span class="route-badge">
                            <i class="fa fa-map-marker-alt" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars($row['origin']); ?> → <?php echo htmlspecialchars($row['destination']); ?></span>
                        </span>
                    </td>

                    <td data-label="Departure"><?php echo date('M j, Y g:i A', strtotime($row['departure_time'])); ?></td>
                    <td data-label="Arrival"><?php echo date('M j, Y g:i A', strtotime($row['arrival_time'])); ?></td>

                    <td data-label="Status">
                        <span class="status-badge <?php echo strtolower($row['trip_status']); ?>"><?php echo htmlspecialchars($row['trip_status']); ?></span>
                    </td>

                    <td data-label="Update">
                        <form method="POST" class="status-form">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="schedule_id" value="<?php echo $row['schedule_id']; ?>">
                            <input type="hidden" name="open_schedule" value="<?php echo $open_schedule ?? $row['schedule_id']; ?>">
                            <select name="trip_status" class="form-control status-select" onchange="this.form.submit()">
                                <option value="Scheduled" <?php echo $row['trip_status']==='Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="Boarding" <?php echo $row['trip_status']==='Boarding' ? 'selected' : ''; ?>>Boarding</option>
                                <option value="Departed" <?php echo $row['trip_status']==='Departed' ? 'selected' : ''; ?>>Departed</option>
                                <option value="Arrived" <?php echo $row['trip_status']==='Arrived' ? 'selected' : ''; ?>>Arrived</option>
                                <option value="Cancelled" <?php echo $row['trip_status']==='Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                            <input type="hidden" name="update_status" value="1">
                        </form>
                        <?php if($archive_table_ready && ($row['trip_status']==='Arrived' || $row['trip_status']==='Cancelled' || $row['schedule_status']==='Completed' || $row['schedule_status']==='Cancelled')): ?>
                        <form method="POST" class="action-form" onsubmit="return confirm('Archive this completed trip? Records will not be deleted.');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="schedule_id" value="<?php echo (int)$row['schedule_id']; ?>">
                            <button type="submit" name="archive_trip" class="btn btn-sm btn-outline"><i class="fa fa-box-archive" aria-hidden="true"></i> Archive</button>
                        </form>
                        <?php endif; ?>
                    </td>

                    <td data-label="Passengers">
                        <button type="button" class="btn btn-sm btn-outline toggle-passengers" data-schedule-id="<?php echo $row['schedule_id']; ?>" aria-expanded="false" aria-controls="passengers-<?php echo $row['schedule_id']; ?>">
                            <i class="fa fa-users" aria-hidden="true"></i> View
                        </button>
                    </td>
            </tr>
            <tr id="passengers-<?php echo $row['schedule_id']; ?>" class="manifest-row" style="display: none;">
                <td colspan="8">
                    <div class="manifest-wrapper">
                        <div class="manifest-table-responsive">
                            <table class="manifest-table">
                                <thead>
                                    <tr>
                                        <th>Passenger</th>
                                        <th>Seat</th>
                                        <th>Pickup</th>
                                        <th>Destination</th>
                                        <th>Ticket Code</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sid = $row['schedule_id'];

                                    $sql = "SELECT reservation.*, users.full_name FROM reservation JOIN users ON reservation.user_id = users.user_id WHERE reservation.schedule_id = ? AND (reservation.status = 'Paid' OR reservation.status = 'Boarded') ORDER BY reservation.seat_number ASC";
                                    $passengers = mbus_db_prepare($conn, $sql, [$sid]);

                                    if(mbus_db_num_rows($passengers)>0){
                                        while($passenger=mbus_db_fetch_assoc($passengers)){
                                    ?>
                                    <tr>
                                        <td data-label="Passenger" class="passenger-name"><?php echo htmlspecialchars($passenger['full_name']); ?></td>
                                        <td data-label="Seat"><?php echo $passenger['seat_number']; ?></td>
                                        <td data-label="Pickup"><?php echo htmlspecialchars($passenger['pickup_location']); ?></td>
                                        <td data-label="Destination"><?php echo htmlspecialchars($passenger['destination']); ?></td>
                                        <td data-label="Ticket Code"><?php echo htmlspecialchars($passenger['ticket_code']); ?></td>
                                        <td data-label="Status">
                                            <?php if($passenger['status']=="Boarded"){ ?>
                                            <span class="badge badge-success boarded-badge">Boarded</span>
                                            <?php } else { ?>
                                            <span class="badge badge-warning paid-badge">Paid</span>
                                            <?php } ?>
                                        </td>
                                        <td data-label="Action">
                                            <?php if($passenger['status']!="Boarded"){ ?>
                                            <form method="POST" class="action-form">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="reservation_id" value="<?php echo $passenger['reservation_id']; ?>">
                                                <input type="hidden" name="open_schedule" value="<?php echo $row['schedule_id']; ?>">
                                                <button type="submit" name="mark_boarded" class="btn btn-sm btn-success board-btn">Board</button>
                                            </form>
                                            <?php } else { ?>
                                            <span class="badge badge-success">✔ Boarded</span>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                    <?php
                                        }
                                    }else{
                                    ?>
                                    <tr>
                                        <td colspan="7" data-label="No passengers found">No passengers found.</td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </td>
            </tr>
            <tr class="divider-row">
                <td colspan="8"><div class="trip-divider"></div></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle passenger manifest rows
    document.querySelectorAll('.toggle-passengers').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var scheduleId = this.dataset.scheduleId;
            var manifestRow = document.getElementById('passengers-' + scheduleId);
            var isOpen = manifestRow.style.display !== 'none';
            manifestRow.style.display = isOpen ? 'none' : 'table-row';
            this.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            this.innerHTML = isOpen ? '<i class="fa fa-users" aria-hidden="true"></i> View' : '<i class="fa fa-chevron-up" aria-hidden="true"></i> Hide';
        });
    });

    // Auto-open manifest if open_schedule parameter is set
    <?php if($open_schedule): ?>
    var openRow = document.getElementById('passengers-<?php echo $open_schedule; ?>');
    var openBtn = document.querySelector('.toggle-passengers[data-schedule-id="<?php echo $open_schedule; ?>"]');
    if(openRow && openBtn) {
        openRow.style.display = 'table-row';
        openBtn.setAttribute('aria-expanded', 'true');
        openBtn.innerHTML = '<i class="fa fa-chevron-up" aria-hidden="true"></i> Hide';
    }
    <?php endif; ?>
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
    <a href="notifications.php" class="bottom-nav-item">
        <i class="fa fa-bell" aria-hidden="true"></i><span>Notifications</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
