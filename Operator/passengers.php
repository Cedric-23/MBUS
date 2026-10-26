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

$conn = mbus_db_connect();

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

// Build WHERE clause
$where = "
WHERE oba.operator_id = ?
AND s.departure_time >= NOW()
";

$params = [$operator_id];

// Filter logic (PostgreSQL compatible)
if($filter === 'today'){
    $where .= " AND s.departure_time::date = CURRENT_DATE";
}
elseif($filter === 'tomorrow'){
    $where .= " AND s.departure_time::date = CURRENT_DATE + INTERVAL '1 day'";
}
elseif($filter === 'this_week'){
    $where .= " AND s.departure_time >= DATE_TRUNC('week', CURRENT_DATE) AND s.departure_time < DATE_TRUNC('week', CURRENT_DATE) + INTERVAL '1 week'";
}
elseif($filter === 'next_week'){
    $where .= " AND s.departure_time >= DATE_TRUNC('week', CURRENT_DATE) + INTERVAL '1 week' AND s.departure_time < DATE_TRUNC('week', CURRENT_DATE) + INTERVAL '2 week'";
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

// Stats queries
$total_passengers_query = mbus_db_prepare($conn, "
    SELECT COUNT(*) total
    FROM reservation r
    JOIN schedules s ON r.schedule_id = s.schedule_id
    JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
    WHERE oba.operator_id = ?
    AND s.departure_time >= NOW()
", [$operator_id]);
$total_passengers = mbus_db_fetch_assoc($total_passengers_query);

$paid_passengers_query = mbus_db_prepare($conn, "
    SELECT COUNT(*) total
    FROM reservation r
    JOIN schedules s ON r.schedule_id = s.schedule_id
    JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
    WHERE oba.operator_id = ?
    AND r.status = 'Paid'
    AND s.departure_time >= NOW()
", [$operator_id]);
$paid_passengers = mbus_db_fetch_assoc($paid_passengers_query);

$boarded_passengers_query = mbus_db_prepare($conn, "
    SELECT COUNT(*) total
    FROM reservation r
    JOIN schedules s ON r.schedule_id = s.schedule_id
    JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
    WHERE oba.operator_id = ?
    AND r.status = 'Boarded'
    AND s.departure_time >= NOW()
", [$operator_id]);
$boarded_passengers = mbus_db_fetch_assoc($boarded_passengers_query);

$today_trips_query = mbus_db_prepare($conn, "
    SELECT COUNT(*) total
    FROM schedules s
    JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
    WHERE oba.operator_id = ?
    AND s.departure_time::date = CURRENT_DATE
    AND s.departure_time >= NOW()
", [$operator_id]);
$today_trips = mbus_db_fetch_assoc($today_trips_query);

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
JOIN schedules s ON r.schedule_id = s.schedule_id
JOIN routes rt ON s.route_id = rt.route_id
JOIN buses b ON s.bus_id = b.bus_id
JOIN operator_bus_assignments oba ON s.bus_id = oba.bus_id
$where
ORDER BY s.departure_time ASC, r.seat_number ASC
";

$query = mbus_db_prepare($conn, $passenger_sql, $params);

$title = "Passenger List";
$page_header = "Passenger List";
$page_subtitle = "Monitor all passengers and trip reservations";
$active_nav = "passengers";

include "includes/operator_header.php";
?>
<main class="main">
    <header class="page-header">
        <div class="page-header-left">
            <h1><?php echo $page_header; ?></h1>
            <p><?php echo $page_subtitle; ?></p>
        </div>
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
        <a href="?filter=all" class="filter-btn <?php echo $filter==='all' ? 'active-filter' : ''; ?>">All</a>
        <a href="?filter=today" class="filter-btn <?php echo $filter==='today' ? 'active-filter' : ''; ?>">Today</a>
        <a href="?filter=tomorrow" class="filter-btn <?php echo $filter==='tomorrow' ? 'active-filter' : ''; ?>">Tomorrow</a>
        <a href="?filter=this_week" class="filter-btn <?php echo $filter==='this_week' ? 'active-filter' : ''; ?>">This Week</a>
        <a href="?filter=next_week" class="filter-btn <?php echo $filter==='next_week' ? 'active-filter' : ''; ?>">Next Week</a>
    </div>

    <form method="GET" class="search-box">
        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
        <input type="text" name="search" class="form-control" placeholder="Search passenger, ticket code, seat..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-primary">Search</button>
    </form>

    <div class="table-responsive passengers-table-responsive">
        <table class="passengers-table">
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
                if(mbus_db_num_rows($query) > 0){
                    while($row = mbus_db_fetch_assoc($query)){
                        $status_class = strtolower($row['status']);
                ?>
                <tr>
                    <td data-label="Passenger" class="passenger-name"><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td data-label="Bus"><?php echo htmlspecialchars($row['bus_number']); ?></td>
                    <td data-label="Route">
                        <span class="route-badge">
                            <?php echo htmlspecialchars($row['origin']); ?> &rarr; <?php echo htmlspecialchars($row['destination']); ?>
                        </span>
                    </td>
                    <td data-label="Seat"><?php echo $row['seat_number']; ?></td>
                    <td data-label="Pickup"><?php echo htmlspecialchars($row['pickup_location']); ?></td>
                    <td data-label="Destination"><?php echo htmlspecialchars($row['destination']); ?></td>
                    <td data-label="Ticket Code" class="ticket"><?php echo htmlspecialchars($row['ticket_code']); ?></td>
                    <td data-label="Departure"><?php echo date('M j, g:i A', strtotime($row['departure_time'])); ?></td>
                    <td data-label="Status">
                        <span class="status-badge status-<?php echo $status_class; ?>">
                            <?php echo htmlspecialchars($row['status']); ?>
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
document.addEventListener('DOMContentLoaded', function() {
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

    // Make toggleSidebar globally available
    window.toggleSidebar = toggleSidebar;

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
});
</script>

<?php include "includes/operator_footer.php"; ?>
