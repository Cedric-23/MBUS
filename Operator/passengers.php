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

$operator_id = $_SESSION['user_id'];

// Authorization check
$operator_check = mbus_db_prepare($conn, "
    SELECT user_id FROM users WHERE user_id = ? AND LOWER(user_type) = 'operator'
", [$operator_id]);

if(!mbus_db_fetch_assoc($operator_check)){
    die("Unauthorized access.");
}

// Filters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

// App-timezone date boundaries (schedule times are local; don't use the DB clock)
$b = mbus_local_bounds();

// Build WHERE clause
$where = "
WHERE oba.operator_id = ?
AND s.departure_time >= '{$b['now']}'
";

$params = [$operator_id];

// Filter logic (PostgreSQL compatible)
if($filter === 'today'){
    $where .= " AND s.departure_time < '{$b['tomorrow']}'";
}
elseif($filter === 'tomorrow'){
    $where .= " AND s.departure_time >= '{$b['tomorrow']}' AND s.departure_time < '{$b['day_after']}'";
}
elseif($filter === 'this_week'){
    $where .= " AND s.departure_time >= '{$b['week_start']}' AND s.departure_time < '{$b['next_week']}'";
}
elseif($filter === 'next_week'){
    $where .= " AND s.departure_time >= '{$b['next_week']}' AND s.departure_time < '{$b['week_after_next']}'";
}

if(in_array($status, ['Paid', 'Boarded'], true)){
    $where .= " AND r.status = ?";
    $params[] = $status;
}
// Search logic
if($search !== ''){
    $where .= " AND (
        u.full_name ILIKE ?
        OR r.ticket_code ILIKE ?
        OR r.seat_number ILIKE ?
    )";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Stats queries - handle false return for empty results
$total_passengers_query = mbus_db_prepare($conn, "
    SELECT COUNT(*) total
    FROM reservation r
    JOIN schedule s ON r.schedule_id = s.schedule_id
    JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
    WHERE oba.operator_id = ?
    AND s.departure_time >= '{$b['now']}'
", [$operator_id]);
$total_passengers = mbus_db_fetch_assoc($total_passengers_query) ?: ['total' => 0];

$paid_passengers_query = mbus_db_prepare($conn, "
    SELECT COUNT(*) total
    FROM reservation r
    JOIN schedule s ON r.schedule_id = s.schedule_id
    JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
    WHERE oba.operator_id = ?
    AND r.status = 'Paid'
    AND s.departure_time >= '{$b['now']}'
", [$operator_id]);
$paid_passengers = mbus_db_fetch_assoc($paid_passengers_query) ?: ['total' => 0];

$boarded_passengers_query = mbus_db_prepare($conn, "
    SELECT COUNT(*) total
    FROM reservation r
    JOIN schedule s ON r.schedule_id = s.schedule_id
    JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
    WHERE oba.operator_id = ?
    AND r.status = 'Boarded'
    AND s.departure_time >= '{$b['now']}'
", [$operator_id]);
$boarded_passengers = mbus_db_fetch_assoc($boarded_passengers_query) ?: ['total' => 0];

$today_trips_query = mbus_db_prepare($conn, "
    SELECT COUNT(*) total
    FROM schedule s
    JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
    WHERE oba.operator_id = ?
    AND s.departure_time >= '{$b['now']}'
    AND s.departure_time < '{$b['tomorrow']}'
", [$operator_id]);
$today_trips = mbus_db_fetch_assoc($today_trips_query) ?: ['total' => 0];

// Fetch passengers
$passenger_sql = "
SELECT
    r.*,
    u.full_name,
    s.departure_time,
    rt.origin,
    rt.destination,
    b.bus_number
FROM reservation r
JOIN users u ON r.user_id = u.user_id
JOIN schedule s ON r.schedule_id = s.schedule_id
JOIN routes rt ON s.route_id = rt.route_id
JOIN buses b ON s.bus_id = b.bus_id
JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
$where
ORDER BY r.created_at DESC
";

$passengers = mbus_db_prepare($conn, $passenger_sql, $params);
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

<!-- Filter Tabs -->
<div class="date-filter-container">
<nav class="date-filters" aria-label="Passenger filters">
<a href="?filter=all" class="date-filter-btn <?php echo $filter==='all' ? 'active' : ''; ?>" aria-current="<?php echo $filter==='all' ? 'page' : 'false'; ?>">
<i class="fa fa-users" aria-hidden="true"></i> All
</a>
<a href="?filter=today" class="date-filter-btn <?php echo $filter==='today' ? 'active' : ''; ?>" aria-current="<?php echo $filter==='today' ? 'page' : 'false'; ?>">
<i class="fa fa-calendar-day" aria-hidden="true"></i>Today
</a>
<a href="?filter=tomorrow" class="date-filter-btn <?php echo $filter==='tomorrow' ? 'active' : ''; ?>" aria-current="<?php echo $filter==='tomorrow' ? 'page' : 'false'; ?>">
<i class="fa fa-calendar-plus" aria-hidden="true"></i>Tomorrow
</a>
<a href="?filter=this_week" class="date-filter-btn <?php echo $filter==='this_week' ? 'active' : ''; ?>" aria-current="<?php echo $filter==='this_week' ? 'page' : 'false'; ?>">
<i class="fa fa-calendar-week" aria-hidden="true"></i>This Week
</a>
<a href="?filter=next_week" class="date-filter-btn <?php echo $filter==='next_week' ? 'active' : ''; ?>" aria-current="<?php echo $filter==='next_week' ? 'page' : 'false'; ?>">
<i class="fa fa-calendar-alt" aria-hidden="true"></i>Next Week
</a>
</nav>
</div>

<!-- Search -->
<div class="search-modern">
<i class="fa fa-search" aria-hidden="true"></i>
<form method="GET" role="search">
<input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
<input type="search" name="search" placeholder="Search by name, ticket code, or seat..." value="<?php echo htmlspecialchars($search); ?>" aria-label="Search passengers">
</form>
</div>

<!-- Stats Cards -->
<section class="cards" aria-labelledby="stats-heading">
    <h2 id="stats-heading" class="visually-hidden">Passenger Statistics</h2>
    
    <article class="card card-animated">
        <h3 class="day"><?php echo $total_passengers['total']; ?></h3>
        <p class="date">Total Passengers</p>
    </article>
    
    <article class="card card-animated">
        <h3 class="day"><?php echo $paid_passengers['total']; ?></h3>
        <p class="date">Paid</p>
    </article>
    
    <article class="card card-animated">
        <h3 class="day"><?php echo $boarded_passengers['total']; ?></h3>
        <p class="date">Boarded</p>
    </article>
    
    <article class="card card-animated">
        <h3 class="day"><?php echo $today_trips['total']; ?></h3>
        <p class="date">Trips Today</p>
    </article>
</section>

<div class="table-responsive passengers-table-responsive">
    <table class="passengers-table">
        <thead>
            <tr>
                <th scope="col">Passenger</th>
                <th scope="col">Bus</th>
                <th scope="col">Route</th>
                <th scope="col">Departure</th>
                <th scope="col">Seat</th>
                <th scope="col">Ticket Code</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            mbus_db_data_seek($passengers, 0);
            while($row=mbus_db_fetch_assoc($passengers)){ 
            ?>
            <tr>
                <td data-label="Passenger" class="passenger-name"><?php echo htmlspecialchars($row['full_name']); ?></td>
                <td data-label="Bus">
                    <span class="bus-number"><?php echo htmlspecialchars($row['bus_number']); ?></span>
                </td>
                <td data-label="Route">
                    <span class="route-badge">
                        <i class="fa fa-map-marker-alt" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($row['origin']); ?> → <?php echo htmlspecialchars($row['destination']); ?></span>
                    </span>
                </td>
                <td data-label="Departure"><?php echo date('M j, Y g:i A', strtotime($row['departure_time'])); ?></td>
                <td data-label="Seat"><?php echo $row['seat_number']; ?></td>
                <td data-label="Ticket Code" class="ticket-code"><?php echo htmlspecialchars($row['ticket_code']); ?></td>
                <td data-label="Status">
                    <?php if($row['status']=="Boarded"){ ?>
                    <span class="status-badge boarded">Boarded</span>
                    <?php } else { ?>
                    <span class="status-badge paid">Paid</span>
                    <?php } ?>
                </td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

<?php if(mbus_db_num_rows($passengers) === 0): ?>
<div class="empty-state">
    <i class="fa fa-users" aria-hidden="true"></i>
    <h3>No passengers found</h3>
    <p>No passenger records match your current filters.</p>
</div>
<?php endif; ?>

</main>

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
</script>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="manage_trips.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span>
    </a>
    <a href="passengers.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-users" aria-hidden="true"></i><span>Riders</span>
    </a>
    <a href="notifications.php" class="bottom-nav-item">
        <i class="fa fa-bell" aria-hidden="true"></i><span>Alerts</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>