<?php
session_start();

if(
!isset($_SESSION['user_type'])
||
strtolower($_SESSION['user_type'])!='operator'
){
header("Location: ../login.php");
exit();
}

include("../config/db_connect.php");
include("../Includes/csrf.php");

$open_schedule=isset($_GET['open'])
?$_GET['open']
:'';

/* FILTER */

$filter=isset($_GET['filter'])
?$_GET['filter']
:'all';

$where_filter="";

if($filter=="today"){

$where_filter="
AND DATE(schedule.departure_time)=CURDATE()
";

}elseif($filter=="tomorrow"){

$where_filter="
AND DATE(schedule.departure_time)=DATE_ADD(CURDATE(),INTERVAL 1 DAY)
";

}elseif($filter=="this_week"){

$where_filter="
AND YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1)
";

}elseif($filter=="next_week"){

$where_filter="
AND YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1)+1
";

}

/* UPDATE TRIP STATUS */

if(isset($_POST['update_status'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $schedule_id=$_POST['schedule_id'];
        $trip_status=$_POST['trip_status'];

        // Use prepared statement
        mbus_db_prepare($conn,"
UPDATE schedule
SET trip_status = ?
WHERE schedule_id = ?
", [$trip_status, $schedule_id]);

header("Location: manage_trips.php?filter=".$filter);
exit();
}
}

/* BOARD PASSENGER */

if(isset($_POST['mark_boarded'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $reservation_id=$_POST['reservation_id'];

        // Use prepared statement
        mbus_db_prepare($conn,"
UPDATE reservation
SET status = 'Boarded'
WHERE reservation_id = ?
", [$reservation_id]);

header("Location: manage_trips.php?filter=".$filter."&open=".$_POST['open_schedule']);
exit();
}
}

/* FETCH TRIPS */

$operator_id=$_SESSION['user_id'];

// Authorization check - verify operator exists
$operator_check = mbus_db_prepare($conn,"
    SELECT user_id FROM users WHERE user_id = ? AND LOWER(user_type) = 'operator'
", [$operator_id]);

if(!mbus_db_fetch_assoc($operator_check)){
    die("Unauthorized access.");
}

$query=mbus_db_prepare($conn,"
SELECT

schedule.schedule_id,
schedule.departure_time,
schedule.arrival_time,
schedule.trip_status,

routes.origin,
routes.destination,

buses.bus_number,
buses.bus_type

FROM schedule

LEFT JOIN routes
ON schedule.route_id=routes.route_id

LEFT JOIN buses
ON schedule.bus_id=buses.bus_id

LEFT JOIN operator_bus_assignments
ON schedule.bus_id=operator_bus_assignments.bus_id

WHERE operator_bus_assignments.operator_id = ?

$where_filter

ORDER BY schedule.departure_time ASC
", [$operator_id]);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Trips</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_manage_trips.css">
</head>

<body class="has-sidebar">

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle btn btn-icon" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
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
    <h1>Manage Trips</h1>
    <p>Monitor and manage passenger boarding.</p>
</header>

<?php if(isset($error)): ?>
    <div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<section class="card">

<!-- FILTER BUTTONS -->
<div class="filters" role="group" aria-label="Filter trips">
    <a href="?filter=all" class="filter-btn <?php echo ($filter=='all')?'active-filter':''; ?>">All</a>
    <a href="?filter=today" class="filter-btn <?php echo ($filter=='today')?'active-filter':''; ?>">Today</a>
    <a href="?filter=tomorrow" class="filter-btn <?php echo ($filter=='tomorrow')?'active-filter':''; ?>">Tomorrow</a>
    <a href="?filter=this_week" class="filter-btn <?php echo ($filter=='this_week')?'active-filter':''; ?>">This Week</a>
    <a href="?filter=next_week" class="filter-btn <?php echo ($filter=='next_week')?'active-filter':''; ?>">Next Week</a>
</div>

<div class="table-responsive">
    <table>
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
            <tr>
                <td data-label="Schedule ID"><?php echo $row['schedule_id']; ?></td>

                <td data-label="Bus">
                    <?php echo htmlspecialchars($row['bus_number']); ?>
                    <br>
                    <small><?php echo htmlspecialchars($row['bus_type']); ?></small>
                </td>

                <td data-label="Route">
                    <span class="route-badge">
                        <?php echo htmlspecialchars($row['origin']); ?> → <?php echo htmlspecialchars($row['destination']); ?>
                    </span>
                </td>

                <td data-label="Departure"><?php echo $row['departure_time']; ?></td>
                <td data-label="Arrival"><?php echo $row['arrival_time']; ?></td>

                <td data-label="Status">
                    <span class="status-badge status-<?php echo strtolower($row['trip_status']); ?>">
                        <?php echo $row['trip_status']; ?>
                    </span>
                </td>

                <td data-label="Update">
                    <form method="POST" class="action-form">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="schedule_id" value="<?php echo $row['schedule_id']; ?>">
                        <select name="trip_status" class="form-control status-select">
                            <option value="Scheduled" <?php echo ($row['trip_status']=="Scheduled")?'selected':''; ?>>Scheduled</option>
                            <option value="Boarding" <?php echo ($row['trip_status']=="Boarding")?'selected':''; ?>>Boarding</option>
                            <option value="Departed" <?php echo ($row['trip_status']=="Departed")?'selected':''; ?>>Departed</option>
                            <option value="Arrived" <?php echo ($row['trip_status']=="Arrived")?'selected':''; ?>>Arrived</option>
                            <option value="Cancelled" <?php echo ($row['trip_status']=="Cancelled")?'selected':''; ?>>Cancelled</option>
                        </select>
                        <button type="submit" name="update_status" class="btn btn-sm btn-primary update-btn">Update</button>
                    </form>
                </td>

                <td data-label="Passengers">
                    <button type="button" class="btn btn-sm btn-secondary view-btn" onclick="togglePassengers(<?php echo $row['schedule_id']; ?>)">View</button>
                </td>
            </tr>

            <tr id="passengers-<?php echo $row['schedule_id']; ?>" class="manifest-row" <?php echo ($open_schedule==$row['schedule_id'])?'':'style="display:none;"'; ?>>
                <td colspan="8">
                    <div class="manifest-wrapper">
                        <h3 class="manifest-title">Passenger Manifest</h3>
                        
                        <div class="table-responsive">
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
                                    $schedule_id=$row['schedule_id'];

                                    $passengers=mbus_db_prepare($conn,"
SELECT
reservation.*,
users.full_name

FROM reservation

JOIN users
ON reservation.user_id=users.user_id

WHERE reservation.schedule_id=?
AND(
reservation.status='Paid'
OR reservation.status='Boarded'
)

ORDER BY reservation.seat_number ASC
", [$schedule_id]);

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

<script src="../Assets/js/operator/manage_trips.js"></script>

<script>
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

function togglePassengers(scheduleId) {
    const row = document.getElementById('passengers-' + scheduleId);
    if (row.style.display === 'none' || row.style.display === '') {
        row.style.display = 'table-row';
    } else {
        row.style.display = 'none';
    }
}
</script>

</body>
</html>