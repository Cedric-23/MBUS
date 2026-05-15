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

/* ADD BUS */
if(isset($_POST['add_bus'])){

$bus_number = trim($_POST['bus_number']);
$bus_type   = trim($_POST['bus_type']);
$capacity   = trim($_POST['capacity']);

mysqli_query($conn,"
INSERT INTO buses (bus_number,bus_type,capacity)
VALUES ('$bus_number','$bus_type','$capacity')
");

echo "
<script>
alert('Bus added successfully');
window.location='manage_buses.php';
</script>
";

}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Buses</title>

<!-- ✅ SIDEBAR CSS -->
<link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">

<!-- ✅ PAGE CSS -->
<link rel="stylesheet" href="../Assets/css/admin/manage_buses.css">

<!-- ✅ ICONS -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body>

<!-- ✅ SIDEBAR -->
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

<!-- MAIN -->
<div class="main">

<!-- ADD BUS -->
<div class="box">

<h2>Add New Bus</h2>

<form method="POST">

<div class="form-grid">

<!-- BUS NUMBER -->
<div class="form-group">
<label>Bus Number</label>
<input type="text" name="bus_number" required>
</div>

<!-- BUS TYPE -->
<div class="form-group">
<label>Bus Type</label>
<select name="bus_type" required>
<option value="Aircon">Aircon</option>
<option value="Ordinary">Ordinary</option>
</select>
</div>

<!-- CAPACITY -->
<div class="form-group">
<label>Capacity</label>
<input type="number" name="capacity" required>
</div>

</div>

<button type="submit" name="add_bus" class="btn">
Add Bus
</button>

</form>

</div>

<!-- BUS LIST -->
<div class="box">

<h2>Bus List</h2>

<table>

<tr>
<th>Bus Number</th>
<th>Bus Type</th>
<th>Capacity</th>
</tr>

<?php

$query = mysqli_query($conn,"
SELECT * FROM buses
ORDER BY bus_id DESC
");

while($row = mysqli_fetch_assoc($query)){

?>

<tr>

<td><?= $row['bus_number']; ?></td>

<td>

<?php
$type = strtolower($row['bus_type']);

if($type=="aircon"){
echo "<span class='badge aircon'>Aircon</span>";
}else{
echo "<span class='badge ordinary'>Ordinary</span>";
}
?>

</td>

<td><?= $row['capacity']; ?></td>

</tr>

<?php } ?>

</table>

</div>

</div>

</body>
</html>