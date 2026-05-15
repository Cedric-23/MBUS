<?php
session_start();

if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != 'admin'){
    header("Location: ../login.php");
    exit();
}

include("../config/db_connect.php");

/* UPDATE */
if(isset($_POST['update_schedule'])){
    $schedule_id = $_POST['schedule_id'];
    $bus_id = $_POST['bus_id'];
    $route_id = $_POST['route_id'];
    $departure_time = $_POST['departure_time'];
    $arrival_time = $_POST['arrival_time'];
    $schedule_status = $_POST['schedule_status'];

    mbus_db_query($conn,"
        UPDATE schedule SET
        bus_id='$bus_id',
        route_id='$route_id',
        departure_time='$departure_time',
        arrival_time='$arrival_time',
        schedule_status='$schedule_status'
        WHERE schedule_id='$schedule_id'
    ");

    header("Location: manage_schedules.php");
    exit();
}

/* DELETE */
if(isset($_POST['delete_schedule'])){
    mbus_db_query($conn,"
        DELETE FROM schedule
        WHERE schedule_id='".$_POST['schedule_id']."'
    ");

    header("Location: manage_schedules.php");
    exit();
}

/* ADD */
if(isset($_POST['add_schedule'])){
    mbus_db_query($conn,"
        INSERT INTO schedule(
            bus_id, route_id,
            departure_time, arrival_time,
            schedule_status
        )
        VALUES(
            '".$_POST['bus_id']."',
            '".$_POST['route_id']."',
            '".$_POST['departure_time']."',
            '".$_POST['arrival_time']."',
            '".$_POST['schedule_status']."'
        )
    ");

    header("Location: manage_schedules.php");
    exit();
}

/* FETCH */
$routes = mbus_db_query($conn,"SELECT * FROM routes ORDER BY origin ASC");
$buses  = mbus_db_query($conn,"SELECT * FROM buses ORDER BY bus_id ASC");

$query = mbus_db_query($conn,"
SELECT schedule.*, routes.origin, routes.destination
FROM schedule
LEFT JOIN routes ON schedule.route_id=routes.route_id
ORDER BY departure_time ASC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Schedules</title>

<!-- ✅ SIDEBAR CSS -->
<link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">

<!-- ✅ PAGE CSS -->
<link rel="stylesheet" href="../Assets/css/admin/manage_schedules.css">

<!-- ✅ ICONS -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body>

<!-- ✅ SIDEBAR (UPDATED) -->
<div class="sidebar">

<div class="logo-container">
    <img src="../Assets/images/mbus_logo.png" class="logo">
</div>

<h2>Admin</h2>

<div class="menu-top">

<a href="admin_dashboard.php">
<i class="fa-solid fa-house"></i> Dashboard
</a>

<a href="manage_schedules.php">
<i class="fa-solid fa-calendar"></i> Manage Schedules
</a>

<a href="manage_buses.php">
<i class="fa-solid fa-bus"></i> Manage Buses
</a>

<a href="manage_operators.php">
<i class="fa-solid fa-user-gear"></i> Manage Operators
</a>

<a href="manage_routes.php">
<i class="fa-solid fa-route"></i> Manage Routes
</a>

</div>

<div class="logout-btn">
<a href="../logout.php">
<i class="fa-solid fa-right-from-bracket"></i> Logout
</a>
</div>

</div>


<!-- MAIN (UNCHANGED) -->
<div class="main">

<!-- ADD -->
<div class="card">
<h2 style="color:#1e3a5f; margin-bottom:20px;">Add Schedule</h2>

<form method="POST">

<div class="form-grid">

<div class="form-group">
<label>Select Bus</label>
<select name="bus_id" required>
<option value="">Select Bus</option>

<?php mbus_db_data_seek($buses,0); while($bus=mbus_db_fetch_assoc($buses)){ ?>
<option value="<?= $bus['bus_id']; ?>">
<?= $bus['bus_number']; ?> - <?= $bus['bus_type']; ?> - <?= $bus['capacity']; ?> Seats
</option>
<?php } ?>

</select>
</div>

<div class="form-group">
<label>Select Route</label>
<select name="route_id" required>
<option value="">Select Route</option>

<?php mbus_db_data_seek($routes,0); while($route=mbus_db_fetch_assoc($routes)){ ?>
<option value="<?= $route['route_id']; ?>">
<?= $route['origin']; ?> → <?= $route['destination']; ?>
</option>
<?php } ?>

</select>
</div>

<div class="form-group">
<label>Departure Time</label>
<input type="datetime-local" name="departure_time" required>
</div>

<div class="form-group">
<label>Arrival Time</label>
<input type="datetime-local" name="arrival_time" required>
</div>

<div class="form-group">
<label>Status</label>
<select name="schedule_status">
<option value="Active">Active</option>
<option value="Inactive">Inactive</option>
</select>
</div>

</div>

<button type="submit" name="add_schedule" class="btn">
Add Schedule
</button>

</form>
</div>

<!-- LIST (UNCHANGED) -->
<div class="card">

<div class="top-actions">
<h2 style="color:#1e3a5f;">Schedule List</h2>
<button class="edit-mode-btn" onclick="toggleEditMode()">Edit Mode</button>
</div>

<div class="filter-buttons">
<button class="filter-btn active-filter" onclick="filterSchedules('all',this)">All</button>
<button class="filter-btn" onclick="filterSchedules('morong',this)">Morong → SBMA</button>
<button class="filter-btn" onclick="filterSchedules('sbma',this)">SBMA → Morong</button>
</div>

<table>

<tr>
<th>ID</th>
<th>Bus</th>
<th>Route</th>
<th>Departure</th>
<th>Arrival</th>
<th>Status</th>
<th class="edit-controls">Actions</th>
</tr>

<?php while($row=mbus_db_fetch_assoc($query)){ ?>

<tr class="schedule-row"
data-route="<?= ($row['origin']=='Morong Terminal')?'morong':'sbma'; ?>">

<form method="POST">

<input type="hidden" name="schedule_id" value="<?= $row['schedule_id']; ?>">

<td><?= $row['schedule_id']; ?></td>

<td>
<span class="view-mode">Bus <?= $row['bus_id']; ?></span>
<div class="edit-controls">
<select name="bus_id" class="edit-input">
<?php mbus_db_data_seek($buses,0); while($bus=mbus_db_fetch_assoc($buses)){ ?>
<option value="<?= $bus['bus_id']; ?>"
<?= ($bus['bus_id']==$row['bus_id'])?'selected':''; ?>>
<?= $bus['bus_number']; ?> - <?= $bus['bus_type']; ?>
</option>
<?php } ?>
</select>
</div>
</td>

<td>
<span class="view-mode">
<span class="route-badge">
<?= $row['origin']; ?> → <?= $row['destination']; ?>
</span>
</span>

<div class="edit-controls">
<select name="route_id" class="edit-input">
<?php mbus_db_data_seek($routes,0); while($route=mbus_db_fetch_assoc($routes)){ ?>
<option value="<?= $route['route_id']; ?>"
<?= ($route['route_id']==$row['route_id'])?'selected':''; ?>>
<?= $route['origin']; ?> → <?= $route['destination']; ?>
</option>
<?php } ?>
</select>
</div>
</td>

<td>
<span class="view-mode"><?= $row['departure_time']; ?></span>
<div class="edit-controls">
<input type="datetime-local" name="departure_time"
value="<?= date('Y-m-d\TH:i',strtotime($row['departure_time'])); ?>">
</div>
</td>

<td>
<span class="view-mode"><?= $row['arrival_time']; ?></span>
<div class="edit-controls">
<input type="datetime-local" name="arrival_time"
value="<?= date('Y-m-d\TH:i',strtotime($row['arrival_time'])); ?>">
</div>
</td>

<td>
<span class="view-mode">
<span class="status <?= strtolower($row['schedule_status']); ?>">
<?= $row['schedule_status']; ?>
</span>
</span>

<div class="edit-controls">
<select name="schedule_status">
<option value="Active" <?= ($row['schedule_status']=="Active")?'selected':''; ?>>Active</option>
<option value="Inactive" <?= ($row['schedule_status']=="Inactive")?'selected':''; ?>>Inactive</option>
</select>
</div>
</td>

<td class="edit-controls">
<button name="update_schedule" class="save-btn">Save</button>
<button name="delete_schedule" class="delete-btn"
onclick="return confirm('Delete this schedule?')">Delete</button>
</td>

</form>
</tr>

<?php } ?>

</table>

</div>
</div>

<script src="../Assets/js/admin/manage_schedules.js"></script>

</body>
</html>