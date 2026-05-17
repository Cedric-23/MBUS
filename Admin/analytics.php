<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";

if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin'){
    header("Location: ../login.php");
    exit();
}

/* GET ANALYTICS DATA */

// Total reservations
$total_reservations_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM reservation");
$total_reservations = mbus_db_fetch_assoc($total_reservations_query)['count'];

// Total revenue (paid reservations)
$total_revenue_query = mbus_db_query($conn, "SELECT SUM(amount) as total FROM payment WHERE status = 'Completed'");
$total_revenue = mbus_db_fetch_assoc($total_revenue_query)['total'] ?? 0;

// Total users
$total_users_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM users");
$total_users = mbus_db_fetch_assoc($total_users_query)['count'];

// Active schedules
$active_schedules_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM schedule WHERE schedule_status = 'Active'");
$active_schedules = mbus_db_fetch_assoc($active_schedules_query)['count'];

// Total buses
$total_buses_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM buses");
$total_buses = mbus_db_fetch_assoc($total_buses_query)['count'];

// Pending verifications
$pending_verifications_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM commuter_verification WHERE verification_status = 'Pending'");
$pending_verifications = mbus_db_fetch_assoc($pending_verifications_query)['count'];

// Pending operator applications
$pending_applications_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM operator_application WHERE application_status = 'Pending'");
$pending_applications = mbus_db_fetch_assoc($pending_applications_query)['count'];

// Reservations by status
$reservations_by_status_query = mbus_db_query($conn, "
    SELECT status, COUNT(*) as count 
    FROM reservation 
    GROUP BY status
");
$reservations_by_status = [];
while($row = mbus_db_fetch_assoc($reservations_by_status_query)){
    $reservations_by_status[$row['status']] = $row['count'];
}

// Reservations this month
$reservations_this_month_query = mbus_db_query($conn, "
    SELECT COUNT(*) as count 
    FROM reservation 
    WHERE MONTH(reservation_date) = MONTH(CURRENT_DATE) 
    AND YEAR(reservation_date) = YEAR(CURRENT_DATE)
");
$reservations_this_month = mbus_db_fetch_assoc($reservations_this_month_query)['count'];

// Revenue this month
$revenue_this_month_query = mbus_db_query($conn, "
    SELECT SUM(amount) as total 
    FROM payment 
    WHERE MONTH(payment_date) = MONTH(CURRENT_DATE) 
    AND YEAR(payment_date) = YEAR(CURRENT_DATE)
    AND status = 'Completed'
");
$revenue_this_month = mbus_db_fetch_assoc($revenue_this_month_query)['total'] ?? 0;

// Recent activity logs
$recent_activity_query = mbus_db_query($conn, "
    SELECT al.*, u.full_name 
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    ORDER BY al.created_at DESC
    LIMIT 10
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - MBUS Admin</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/modern_theme.css">
    <link rel="stylesheet" href="../Assets/css/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/analytics.css">
</head>
<body>

<div class="sidebar">
    <div class="logo-container">
        <img src="../Assets/images/mbus_logo.png" class="logo">
    </div>
    
    <h2>Admin</h2>
    
    <div class="menu-top">
        <a href="admin_dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="analytics.php" class="active"><i class="fa fa-chart-line"></i> Analytics</a>
        <a href="manage_routes.php"><i class="fa fa-route"></i> Manage Routes</a>
        <a href="manage_schedules.php"><i class="fa fa-calendar"></i> Manage Schedules</a>
        <a href="manage_buses.php"><i class="fa fa-bus"></i> Manage Buses</a>
        <a href="manage_users.php"><i class="fa fa-users"></i> Manage Users</a>
        <a href="manage_verifications.php"><i class="fa fa-id-card"></i> Verifications</a>
        <a href="manage_applications.php"><i class="fa fa-user-plus"></i> Applications</a>
        <a href="activity_logs.php"><i class="fa fa-history"></i> Activity Logs</a>
    </div>
    
    <a href="../logout.php" class="logout-btn">
        <i class="fa fa-sign-out"></i> Logout
    </a>
</div>

<div class="main">
    <h1>Analytics Dashboard</h1>
    
    <!-- Key Metrics -->
    <div class="metrics-grid">
        <div class="metric-card glass-card card-animated">
            <div class="metric-icon reservations">
                <i class="fa fa-ticket"></i>
            </div>
            <div class="metric-details">
                <h3>Total Reservations</h3>
                <p class="metric-value"><?php echo number_format($total_reservations); ?></p>
                <p class="metric-sub">This month: <?php echo number_format($reservations_this_month); ?></p>
            </div>
        </div>
        
        <div class="metric-card glass-card card-animated">
            <div class="metric-icon revenue">
                <i class="fa fa-money-bill-wave"></i>
            </div>
            <div class="metric-details">
                <h3>Total Revenue</h3>
                <p class="metric-value">₱<?php echo number_format($total_revenue, 2); ?></p>
                <p class="metric-sub">This month: ₱<?php echo number_format($revenue_this_month, 2); ?></p>
            </div>
        </div>
        
        <div class="metric-card glass-card card-animated">
            <div class="metric-icon users">
                <i class="fa fa-users"></i>
            </div>
            <div class="metric-details">
                <h3>Total Users</h3>
                <p class="metric-value"><?php echo number_format($total_users); ?></p>
                <p class="metric-sub">Registered accounts</p>
            </div>
        </div>
        
        <div class="metric-card glass-card card-animated">
            <div class="metric-icon schedules">
                <i class="fa fa-calendar-alt"></i>
            </div>
            <div class="metric-details">
                <h3>Active Schedules</h3>
                <p class="metric-value"><?php echo number_format($active_schedules); ?></p>
                <p class="metric-sub">Currently active</p>
            </div>
        </div>
        
        <div class="metric-card glass-card card-animated">
            <div class="metric-icon buses">
                <i class="fa fa-bus"></i>
            </div>
            <div class="metric-details">
                <h3>Total Buses</h3>
                <p class="metric-value"><?php echo number_format($total_buses); ?></p>
                <p class="metric-sub">In fleet</p>
            </div>
        </div>
        
        <div class="metric-card glass-card card-animated">
            <div class="metric-icon pending">
                <i class="fa fa-clock"></i>
            </div>
            <div class="metric-details">
                <h3>Pending Actions</h3>
                <p class="metric-value"><?php echo number_format($pending_verifications + $pending_applications); ?></p>
                <p class="metric-sub"><?php echo $pending_verifications; ?> verifications, <?php echo $pending_applications; ?> applications</p>
            </div>
        </div>
    </div>
    
    <!-- Charts Section -->
    <div class="charts-section">
        <div class="chart-card glass-card">
            <h2>Reservations by Status</h2>
            <div class="chart-container">
                <?php if(!empty($reservations_by_status)): ?>
                <div class="bar-chart">
                    <?php foreach($reservations_by_status as $status => $count): ?>
                    <div class="bar-item">
                        <div class="bar-label"><?php echo $status; ?></div>
                        <div class="bar">
                            <div class="bar-fill" style="width: <?php echo ($count / $total_reservations) * 100; ?>%;"></div>
                        </div>
                        <div class="bar-value"><?php echo $count; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p>No reservation data available</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="chart-card glass-card">
            <h2>Quick Actions</h2>
            <div class="quick-actions">
                <a href="manage_verifications.php" class="action-btn <?php echo $pending_verifications > 0 ? 'urgent' : ''; ?>">
                    <i class="fa fa-id-card"></i>
                    <span>Review Verifications</span>
                    <span class="badge"><?php echo $pending_verifications; ?></span>
                </a>
                <a href="manage_applications.php" class="action-btn <?php echo $pending_applications > 0 ? 'urgent' : ''; ?>">
                    <i class="fa fa-user-plus"></i>
                    <span>Review Applications</span>
                    <span class="badge"><?php echo $pending_applications; ?></span>
                </a>
                <a href="manage_schedules.php" class="action-btn">
                    <i class="fa fa-calendar-plus"></i>
                    <span>Add New Schedule</span>
                </a>
                <a href="manage_routes.php" class="action-btn">
                    <i class="fa fa-route"></i>
                    <span>Manage Routes</span>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Recent Activity -->
    <div class="activity-section glass-card">
        <h2>Recent Activity</h2>
        <div class="activity-list">
            <?php if(mbus_db_num_rows($recent_activity_query) > 0): ?>
            <?php while($activity = mbus_db_fetch_assoc($recent_activity_query)): ?>
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fa fa-circle-info"></i>
                </div>
                <div class="activity-details">
                    <p class="activity-action"><?php echo htmlspecialchars($activity['action']); ?></p>
                    <p class="activity-description"><?php echo htmlspecialchars($activity['description'] ?? ''); ?></p>
                    <p class="activity-meta">
                        <span><?php echo htmlspecialchars($activity['full_name'] ?? 'System'); ?></span>
                        <span>•</span>
                        <span><?php echo date('M d, Y g:i A', strtotime($activity['created_at'])); ?></span>
                    </p>
                </div>
            </div>
            <?php endwhile; ?>
            <?php else: ?>
            <p>No recent activity</p>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
