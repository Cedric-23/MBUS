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

/* AUTHORIZATION CHECK */
$operator_id = $_SESSION['user_id'];
$operator_check = mbus_db_prepare($conn,"
    SELECT user_id FROM users WHERE user_id = ? AND LOWER(user_type) = 'operator'
", [$operator_id]);

if(!mbus_db_fetch_assoc($operator_check)){
    die("Unauthorized access.");
}

/* FILTER */

$filter=isset($_GET['filter'])
?$_GET['filter']
:'all';

/* SEARCH */

$search=isset($_GET['search'])
?mbus_db_escape($conn,$_GET['search'])
:'';

$where="
WHERE operator_bus_assignments.operator_id = ?
AND schedule.departure_time >= NOW()
";

$params = [$operator_id];

/* FILTER LOGIC */

if($filter=="today"){

$where.="
AND DATE(schedule.departure_time)=CURDATE()
";

}elseif($filter=="tomorrow"){

$where.="
AND DATE(schedule.departure_time)=DATE_ADD(CURDATE(),INTERVAL 1 DAY)
";

}elseif($filter=="this_week"){

$where.="
AND YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1)
";

}elseif($filter=="next_week"){

$where.="
AND YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1)+1
";

}

/* SEARCH LOGIC */

if($search!=""){

$where.="
AND(
users.full_name LIKE ?
OR reservation.ticket_code LIKE ?
OR reservation.seat_number LIKE ?
)
";

$search_term = "%$search%";
$params[] = $search_term;
$params[] = $search_term;
$params[] = $search_term;

}

/* STATS - using prepared statements */

$total_passengers_query = mbus_db_prepare($conn, "
SELECT COUNT(*) total
FROM reservation
JOIN schedule ON reservation.schedule_id=schedule.schedule_id
JOIN operator_bus_assignments ON schedule.bus_id=operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id = ?
AND schedule.departure_time >= NOW()
", [$operator_id]);

$total_passengers = mbus_db_fetch_assoc($total_passengers_query);

$paid_passengers_query = mbus_db_prepare($conn, "
SELECT COUNT(*) total
FROM reservation
JOIN schedule ON reservation.schedule_id=schedule.schedule_id
JOIN operator_bus_assignments ON schedule.bus_id=operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id = ?
AND reservation.status='Paid'
AND schedule.departure_time >= NOW()
", [$operator_id]);

$paid_passengers = mbus_db_fetch_assoc($paid_passengers_query);

$boarded_passengers_query = mbus_db_prepare($conn, "
SELECT COUNT(*) total
FROM reservation
JOIN schedule ON reservation.schedule_id=schedule.schedule_id
JOIN operator_bus_assignments ON schedule.bus_id=operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id = ?
AND reservation.status='Boarded'
AND schedule.departure_time >= NOW()
", [$operator_id]);

$boarded_passengers = mbus_db_fetch_assoc($boarded_passengers_query);

$today_trips_query = mbus_db_prepare($conn, "
SELECT COUNT(*) total
FROM schedule
JOIN operator_bus_assignments ON schedule.bus_id=operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id = ?
AND DATE(schedule.departure_time)=CURDATE()
AND schedule.departure_time >= NOW()
", [$operator_id]);

$today_trips = mbus_db_fetch_assoc($today_trips_query);

/* FETCH PASSENGERS - using prepared statement */

$passenger_sql = "
SELECT
reservation.*,
users.full_name,
schedule.departure_time,
routes.origin,
routes.destination,
buses.bus_number
FROM reservation
JOIN users ON reservation.user_id=users.user_id
JOIN schedule ON reservation.schedule_id=schedule.schedule_id
JOIN routes ON schedule.route_id=routes.route_id
JOIN buses ON schedule.bus_id=buses.bus_id
JOIN operator_bus_assignments ON schedule.bus_id=operator_bus_assignments.bus_id
$where
ORDER BY schedule.departure_time ASC, reservation.seat_number ASC
";

$query = mbus_db_prepare($conn, $passenger_sql, $params);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passenger List</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_passengers.css">
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
            <a href="manage_trips.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Trips</a>
            <a href="passengers.php" aria-current="page"><i class="fa fa-users" aria-hidden="true"></i> Passenger List</a>
        </nav>

        <div class="menu-bottom">
            <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
                <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
            </a>
        </div>
    </aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Passenger List</h1>
    <p>Monitor all passengers and trip reservations.</p>
</header>

<!-- STATS CARDS -->
<div class="cards">
    <div class="card">
        <h3>Total Passengers</h3>
        <div class="number"><?php echo $total_passengers['total']; ?></div>
    </div>
    <div class="card">
        <h3>Paid Passengers</h3>
        <div class="number"><?php echo $paid_passengers['total']; ?></div>
    </div>
    <div class="card">
        <h3>Boarded Passengers</h3>
        <div class="number"><?php echo $boarded_passengers['total']; ?></div>
    </div>
    <div class="card">
        <h3>Trips Today</h3>
        <div class="number"><?php echo $today_trips['total']; ?></div>
    </div>
</div>

<!-- TABLE CARD -->
<section class="card table-card">

<div class="filters" role="group" aria-label="Filter passengers">
    <a href="?filter=all" class="filter-btn <?php echo ($filter=='all')?'active-filter':''; ?>">All</a>
    <a href="?filter=today" class="filter-btn <?php echo ($filter=='today')?'active-filter':''; ?>">Today</a>
    <a href="?filter=tomorrow" class="filter-btn <?php echo ($filter=='tomorrow')?'active-filter':''; ?>">Tomorrow</a>
    <a href="?filter=this_week" class="filter-btn <?php echo ($filter=='this_week')?'active-filter':''; ?>">This Week</a>
    <a href="?filter=next_week" class="filter-btn <?php echo ($filter=='next_week')?'active-filter':''; ?>">Next Week</a>
</div>

<form method="GET" class="search-box">
    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
    <input type="text" name="search" class="form-control" placeholder="Search passenger, ticket code, seat..." value="<?php echo htmlspecialchars($search); ?>">
    <button type="submit" class="btn btn-primary">Search</button>
</form>

<div class="table-responsive">
    <table>
        <thead>
            <tr>
                <th>Passenger</th>
                <th>Bus</th>
                <th>Route</th>
                <th>Seat</th>
                <th>Pickup</th>
                <th>Destination</th>
                <th>Ticket Code</th>
                <th>Departure</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if(mbus_db_num_rows($query)>0){
                while($row=mbus_db_fetch_assoc($query)){
                    $status_class = strtolower($row['status']);
            ?>
            <tr>
                <td data-label="Passenger" class="passenger-name"><?php echo htmlspecialchars($row['full_name']); ?></td>
                <td data-label="Bus"><?php echo htmlspecialchars($row['bus_number']); ?></td>
                <td data-label="Route">
                    <span class="route-badge">
                        <?php echo htmlspecialchars($row['origin']); ?> → <?php echo htmlspecialchars($row['destination']); ?>
                    </span>
                </td>
                <td data-label="Seat"><?php echo $row['seat_number']; ?></td>
                <td data-label="Pickup"><?php echo htmlspecialchars($row['pickup_location']); ?></td>
                <td data-label="Destination"><?php echo htmlspecialchars($row['destination']); ?></td>
                <td data-label="Ticket Code" class="ticket"><?php echo htmlspecialchars($row['ticket_code']); ?></td>
                <td data-label="Departure"><?php echo date("M j, g:i A", strtotime($row['departure_time'])); ?></td>
                <td data-label="Status">
                    <span class="status-badge status-<?php echo $status_class; ?>">
                        <?php echo $row['status']; ?>
                    </span>
                </td>
            </tr>
            <?php
                }
            }else{
            ?>
            <tr>
                <td colspan="9" data-label="No passengers found">No passengers found.</td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

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

</body>
</html>