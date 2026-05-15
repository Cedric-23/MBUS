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

/* ROUTE OPTIONS */
$places = [
"Morong Terminal",
"SBMA"
];

/* ADD ROUTE */
if(isset($_POST['add_route'])){

$origin = $_POST['origin'];
$destination = $_POST['destination'];

if($origin=="custom"){
$origin = trim($_POST['custom_origin']);
}

if($destination=="custom"){
$destination = trim($_POST['custom_destination']);
}

$fare = trim($_POST['fare']);

if($origin == $destination){

echo "<script>alert('Origin and destination cannot be the same');</script>";

}else{

$check = mysqli_query($conn,"
SELECT * FROM routes
WHERE origin='$origin'
AND destination='$destination'
");

if(mysqli_num_rows($check)>0){

echo "<script>alert('Route already exists');</script>";

}else{

mysqli_query($conn,"
INSERT INTO routes(origin,destination,fare)
VALUES('$origin','$destination','$fare')
");

echo "
<script>
alert('Route added successfully');
window.location='manage_routes.php';
</script>
";

}

}

}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Routes</title>

<!-- ✅ SIDEBAR CSS -->
<link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">

<!-- ✅ PAGE CSS -->
<link rel="stylesheet" href="../Assets/css/admin/manage_routes.css">

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

<!-- ADD ROUTE -->
<div class="box">

<h2>Add Route</h2>

<form method="POST">

<div class="form-grid">

<!-- ORIGIN -->
<div class="form-group">

<label>Origin</label>

<select name="origin" id="origin" required>

<option value="">Select Origin</option>

<?php foreach($places as $place){ ?>
<option value="<?= $place; ?>">
<?= $place; ?>
</option>
<?php } ?>

<option value="custom">Custom Route</option>

</select>

<input type="text"
name="custom_origin"
id="custom_origin"
class="custom-input"
placeholder="Enter Custom Origin">

</div>

<!-- DESTINATION -->
<div class="form-group">

<label>Destination</label>

<select name="destination" id="destination" required>

<option value="">Select Destination</option>

<?php foreach($places as $place){ ?>
<option value="<?= $place; ?>">
<?= $place; ?>
</option>
<?php } ?>

<option value="custom">Custom Route</option>

</select>

<input type="text"
name="custom_destination"
id="custom_destination"
class="custom-input"
placeholder="Enter Custom Destination">

</div>

<!-- FARE -->
<div class="form-group">

<label>Fare</label>

<input type="number"
step="0.01"
name="fare"
placeholder="Enter Fare"
required>

</div>

</div>

<button type="submit"
name="add_route"
class="btn">

Add Route

</button>

</form>

</div>

<!-- ROUTE LIST -->
<div class="box">

<h2>Route List</h2>

<table>

<tr>
<th>Route</th>
<th>Fare</th>
</tr>

<?php

$query = mysqli_query($conn,"
SELECT * FROM routes
ORDER BY route_id DESC
");

while($row=mysqli_fetch_assoc($query)){

?>

<tr>

<td>
<span class="route-badge">
<?= $row['origin']; ?> → <?= $row['destination']; ?>
</span>
</td>

<td>
₱<?= number_format($row['fare'],2); ?>
</td>

</tr>

<?php } ?>

</table>

</div>

</div>

<script>

const origin = document.getElementById("origin");
const destination = document.getElementById("destination");
const customOrigin = document.getElementById("custom_origin");
const customDestination = document.getElementById("custom_destination");

/* ORIGIN */
origin.addEventListener("change",function(){

customOrigin.style.display =
(this.value=="custom") ? "block" : "none";

updateDestination();

});

/* DESTINATION */
destination.addEventListener("change",function(){

customDestination.style.display =
(this.value=="custom") ? "block" : "none";

});

/* DISABLE SAME */
function updateDestination(){

let originValue = origin.value;

for(let option of destination.options){

option.disabled = false;

if(option.value==originValue && option.value!=""){
option.disabled = true;
}

}

if(destination.value==originValue){
destination.value="";
}

}

updateDestination();

</script>

</body>
</html>