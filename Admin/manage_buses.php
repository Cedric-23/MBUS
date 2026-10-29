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

/* ADD BUS */
if(isset($_POST['add_bus'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $bus_number = trim($_POST['bus_number']);
        $bus_type   = trim($_POST['bus_type']);
        $capacity   = trim($_POST['capacity']);

        // Use prepared statement
        mbus_db_prepare($conn, "
INSERT INTO buses (bus_number, bus_type, capacity)
VALUES (?, ?, ?)
", [$bus_number, $bus_type, $capacity]);

        $success = "Bus added successfully";
    }
}

/* DELETE BUS */
if(isset($_POST['delete_bus'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $bus_id = (int)$_POST['bus_id'];
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
</header>

<?php if(isset($success)): ?>
<div class="alert alert-success" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<!-- ADD BUS -->
<section class="card" style="margin-bottom: var(--space-6);">
    <h2>Add New Bus</h2>

    <form method="POST" class="form-section">
        <?php csrf_field(); ?>
        
        <div class="form-grid">
            <!-- BUS NUMBER -->
            <div class="form-group">
                <label for="bus_number">Bus Number</label>
                <input type="text" name="bus_number" id="bus_number" class="form-control" required>
            </div>

            <!-- BUS TYPE -->
            <div class="form-group">
                <label for="bus_type">Bus Type</label>
                <select name="bus_type" id="bus_type" class="form-control" required>
                    <option value="">Select Type</option>
                    <option value="Aircon">Aircon</option>
                    <option value="Ordinary">Ordinary</option>
                </select>
            </div>

            <!-- CAPACITY -->
            <div class="form-group">
                <label for="capacity">Capacity</label>
                <input type="number" name="capacity" id="capacity" class="form-control" required min="1" max="100">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" name="add_bus" class="btn btn-primary">
                <i class="fa fa-plus" aria-hidden="true"></i> Add Bus
            </button>
        </div>
    </form>
</section>

<!-- BUS LIST -->
<section class="card">
    <h2>Bus List</h2>

    <div class="table-responsive">
        <table class="buses-table">
            <thead>
                <tr>
                    <th scope="col">Bus Number</th>
                    <th scope="col">Bus Type</th>
                    <th scope="col">Capacity</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $query = mbus_db_query($conn, "
                    SELECT * FROM buses
                    ORDER BY bus_id DESC
                ");

                while($row = mbus_db_fetch_assoc($query)){
                    $type_class = strtolower($row['bus_type']) == 'aircon' ? 'badge-primary' : 'badge-success';
                ?>
                <tr>
                    <form method="POST" class="bus-form">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="bus_id" value="<?php echo $row['bus_id']; ?>">
                        
                        <td data-label="Bus Number"><?php echo htmlspecialchars($row['bus_number']); ?></td>
                        <td data-label="Bus Type">
                            <span class="badge <?php echo $type_class; ?>">
                                <?php echo htmlspecialchars($row['bus_type']); ?>
                            </span>
                        </td>
                        <td data-label="Capacity"><?php echo htmlspecialchars($row['capacity']); ?></td>
                        <td data-label="Actions">
                            <div class="action-buttons">
                                <button type="submit" name="delete_bus" class="btn btn-sm btn-danger delete-btn" 
                                    onclick="return confirm('Delete this bus?')">
                                    <i class="fa fa-trash" aria-hidden="true"></i> Delete
                                </button>
                            </div>
                        </td>
                    </form>
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