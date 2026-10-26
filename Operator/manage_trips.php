<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/mysqli_compat.php";
require_once "../Includes/csrf.php";
require_once "../Includes/activity_log.php";

if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Operator'){
    header("Location: ../login.php");
    exit();
}

$operator_id = $_SESSION['user_id'];

$conn = mbus_db_connect();

// Handle status update
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])){
    if(!csrf_validate_token()){
        $error = "Invalid CSRF token";
    } else {
        $schedule_id = (int)$_POST['schedule_id'];
        $trip_status = $_POST['trip_status'];
        $allowed_statuses = ['Scheduled','Boarding','Departed','Arrived','Cancelled'];
        if(in_array($trip_status, $allowed_statuses)){
            $stmt = mbus_db_prepare($conn, "UPDATE schedules SET trip_status=? WHERE schedule_id=? AND operator_id=?", [$trip_status, $schedule_id, $operator_id]);
            if(mbus_db_execute($stmt)){
                $success = "Trip status updated";
            } else {
                $error = "Failed to update status";
            }
        } else {
            $error = "Invalid status";
        }
    }
}

// Handle mark boarded
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_boarded'])){
    if(!csrf_validate_token()){
        $error = "Invalid CSRF token";
    } else {
        $reservation_id = (int)$_POST['reservation_id'];
        $stmt = mbus_db_prepare($conn, "UPDATE reservation SET status='Boarded' WHERE reservation_id=?", [$reservation_id]);
        if(mbus_db_execute($stmt)){
            $success = "Passenger marked as boarded";
        } else {
            $error = "Failed to update passenger";
        }
    }
}

// Filters
$filter = $_GET['filter'] ?? 'all';
$open_schedule = isset($_GET['open_schedule']) ? (int)$_GET['open_schedule'] : null;

$where = "WHERE s.operator_id = ?";
$params = [$operator_id];

switch($filter){
    case 'today':
        $where .= " AND s.departure_time::date = CURRENT_DATE";
        break;
    case 'tomorrow':
        $where .= " AND s.departure_time::date = CURRENT_DATE + INTERVAL '1 day'";
        break;
    case 'this_week':
        $where .= " AND s.departure_time >= DATE_TRUNC('week', CURRENT_DATE) AND s.departure_time < DATE_TRUNC('week', CURRENT_DATE) + INTERVAL '1 week'";
        break;
    case 'next_week':
        $where .= " AND s.departure_time >= DATE_TRUNC('week', CURRENT_DATE) + INTERVAL '1 week' AND s.departure_time < DATE_TRUNC('week', CURRENT_DATE) + INTERVAL '2 week'";
        break;
    default:
        // all
        break;
}

$query = mbus_db_prepare($conn, "
    SELECT 
        s.schedule_id,
        s.departure_time,
        s.arrival_time,
        s.trip_status,
        b.bus_number,
        b.bus_type,
        r.origin,
        r.destination
    FROM schedules s
    JOIN buses b ON s.bus_id = b.bus_id
    JOIN routes r ON s.route_id = r.route_id
    $where
    ORDER BY s.departure_time DESC
", $params);

$title = "Manage Trips";
$page_header = "Manage Trips";
$page_subtitle = "View and update your scheduled trips";
$active_nav = "manage_trips";

include "includes/operator_header.php";
?>
<main class="main">
    <header class="page-header">
        <div class="page-header-left">
            <h1><?php echo $page_header; ?></h1>
            <p><?php echo $page_subtitle; ?></p>
        </div>
    </header>

    <?php if(isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <section class="card">
        <div class="card-header">
            <div class="filters">
                <a href="?filter=all" class="filter-btn <?php echo $filter==='all' ? 'active' : ''; ?>">All</a>
                <a href="?filter=today" class="filter-btn <?php echo $filter==='today' ? 'active' : ''; ?>">Today</a>
                <a href="?filter=tomorrow" class="filter-btn <?php echo $filter==='tomorrow' ? 'active' : ''; ?>">Tomorrow</a>
                <a href="?filter=this_week" class="filter-btn <?php echo $filter==='this_week' ? 'active' : ''; ?>">This Week</a>
                <a href="?filter=next_week" class="filter-btn <?php echo $filter==='next_week' ? 'active' : ''; ?>">Next Week</a>
            </div>
        </div>

        <div class="table-responsive trips-table-responsive">
            <table class="trips-table">
                <thead>
                    <tr>
                        <th>Schedule ID</th>
                        <th>Bus</th>
                        <th>Route</th>
                        <th>Departure</th>
                        <th>Arrival</th>
                        <th>Status</th>
                        <th>Update</th>
                        <th>Passengers</th>
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
                            <div class="route-badge">
                                <i class="fa fa-map-marker-alt" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars($row['origin']); ?> → <?php echo htmlspecialchars($row['destination']); ?></span>
                            </div>
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
    </section>
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
</script>

<?php include "includes/operator_footer.php"; ?>
