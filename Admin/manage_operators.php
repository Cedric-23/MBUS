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

/* ADD OPERATOR */
if(isset($_POST['add_operator'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone_number = trim($_POST['phone_number']);
        $password = trim($_POST['password']);

        // Validate phone number
        if(!preg_match('/^09\d{9}$/', $phone_number)){
            $error = "Phone number must be 11 digits starting with 09 (e.g., 09123456789).";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            /* CHECK EMAIL using prepared statement */
            $check = mbus_db_prepare($conn, "
SELECT * FROM users WHERE email = ?
", [$email]);

            if(mbus_db_num_rows($check) > 0){
                $error = "Email already exists";
            } else {
                mbus_db_prepare($conn, "
INSERT INTO users
(full_name, email, phone_number, password, user_type)
VALUES
(?, ?, ?, ?, 'operator')
", [$full_name, $email, $phone_number, $hashed_password]);

                $success = "Operator account created";
            }
        }
    }
}

/* DELETE OPERATOR */
if(isset($_POST['delete_operator'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $user_id = (int)$_POST['user_id'];
        mbus_db_prepare($conn, "DELETE FROM users WHERE user_id = ? AND LOWER(user_type) = 'operator'", [$user_id]);
        $success = "Operator deleted successfully";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Operators</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_operators.css">
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
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
        <a href="manage_operators.php" aria-current="page"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
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
    <h1>Manage Operators</h1>
</header>

<?php if(isset($success)): ?>
<div class="alert alert-success" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<!-- ADD OPERATOR -->
<section class="card" style="margin-bottom: var(--space-6);">
    <h2>Create Operator Account</h2>
    <form method="POST" class="form-section">
        <?php csrf_field(); ?>
        
        <div class="form-grid">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" name="full_name" id="full_name" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="phone_number">Phone Number</label>
                <input type="text" name="phone_number" id="phone_number" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" name="add_operator" class="btn btn-primary">
                <i class="fa fa-plus" aria-hidden="true"></i> Create Operator
            </button>
        </div>
    </form>
</section>

<!-- OPERATOR LIST -->
<section class="card">
    <h2>Operator List</h2>

    <div class="table-responsive">
        <table class="operators-table">
            <thead>
                <tr>
                    <th scope="col">Full Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Phone Number</th>
                    <th scope="col">Role</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $query = mbus_db_query($conn, "
                    SELECT * FROM users
                    WHERE LOWER(user_type)='operator'
                    ORDER BY user_id DESC
                ");

                while($row = mbus_db_fetch_assoc($query)){
                ?>
                <tr>
                    <form method="POST" class="operator-form">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="user_id" value="<?php echo $row['user_id']; ?>">
                        
                        <td data-label="Full Name"><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td data-label="Email"><?php echo htmlspecialchars($row['email']); ?></td>
                        <td data-label="Phone Number"><?php echo htmlspecialchars($row['phone_number']); ?></td>
                        <td data-label="Role">
                            <span class="badge badge-primary">Operator</span>
                        </td>
                        <td data-label="Actions">
                            <div class="action-buttons">
                                <button type="submit" name="delete_operator" class="btn btn-sm btn-danger delete-btn" 
                                    onclick="return confirm('Delete this operator?')">
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