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

/* ADD OPERATOR */
if(isset($_POST['add_operator'])){

$full_name = trim($_POST['full_name']);
$email = trim($_POST['email']);
$phone_number = trim($_POST['phone_number']);
$password = trim($_POST['password']);

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

/* CHECK EMAIL */
$check = mysqli_query($conn,"
SELECT * FROM users WHERE email='$email'
");

if(mysqli_num_rows($check) > 0){

echo "<script>alert('Email already exists');</script>";

}else{

mysqli_query($conn,"
INSERT INTO users
(full_name,email,phone_number,password,user_type)
VALUES
('$full_name','$email','$phone_number','$hashed_password','operator')
");

echo "
<script>
alert('Operator account created');
window.location='manage_operators.php';
</script>
";

}

}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Operators</title>

<!-- ✅ SIDEBAR CSS -->
<link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">

<!-- ✅ PAGE CSS -->
<link rel="stylesheet" href="../Assets/css/admin/manage_operators.css">

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

<!-- ADD OPERATOR -->
<div class="box">

<h2>Create Operator Account</h2>

<form method="POST">

<div class="form-grid">

<div class="form-group">
<label>Full Name</label>
<input type="text" name="full_name" required>
</div>

<div class="form-group">
<label>Email</label>
<input type="email" name="email" required>
</div>

<div class="form-group">
<label>Phone Number</label>
<input type="text" name="phone_number" required>
</div>

<div class="form-group">
<label>Password</label>
<input type="password" name="password" required>
</div>

</div>

<button type="submit" name="add_operator" class="btn">
Create Operator
</button>

</form>

</div>

<!-- OPERATOR LIST -->
<div class="box">

<h2>Operator List</h2>

<table>

<tr>
<th>Full Name</th>
<th>Email</th>
<th>Phone Number</th>
<th>Role</th>
</tr>

<?php

$query = mysqli_query($conn,"
SELECT * FROM users
WHERE LOWER(user_type)='operator'
ORDER BY user_id DESC
");

while($row = mysqli_fetch_assoc($query)){

?>

<tr>

<td><?= $row['full_name']; ?></td>
<td><?= $row['email']; ?></td>
<td><?= $row['phone_number']; ?></td>

<td>
<span class="badge">Operator</span>
</td>

</tr>

<?php } ?>

</table>

</div>

</div>

</body>
</html>