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
$origin_lat = trim($_POST['origin_lat']);
$origin_lng = trim($_POST['origin_lng']);
$destination_lat = trim($_POST['destination_lat']);
$destination_lng = trim($_POST['destination_lng']);

if($origin == $destination){

echo "<script>alert('Origin and destination cannot be the same');</script>";

}else{

$check = mbus_db_query($conn,"
SELECT * FROM routes
WHERE origin='$origin'
AND destination='$destination'
");

if(mbus_db_num_rows($check)>0){

echo "<script>alert('Route already exists');</script>";

}else{

mbus_db_query($conn,"
INSERT INTO routes(origin,destination,fare,origin_lat,origin_lng,destination_lat,destination_lng)
VALUES('$origin','$destination','$fare','$origin_lat','$origin_lng','$destination_lat','$destination_lng')
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

<!-- ✅ GOOGLE MAPS API -->
<script async defer
src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&callback=initMap">
</script>

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

<!-- ORIGIN COORDINATES -->
<div class="form-group">

<label>Origin Coordinates</label>

<div class="coord-inputs">
<input type="number"
step="any"
name="origin_lat"
id="origin_lat"
placeholder="Latitude"
required>
<input type="number"
step="any"
name="origin_lng"
id="origin_lng"
placeholder="Longitude"
required>
</div>

</div>

<!-- DESTINATION COORDINATES -->
<div class="form-group">

<label>Destination Coordinates</label>

<div class="coord-inputs">
<input type="number"
step="any"
name="destination_lat"
id="destination_lat"
placeholder="Latitude"
required>
<input type="number"
step="any"
name="destination_lng"
id="destination_lng"
placeholder="Longitude"
required>
</div>

</div>

<!-- MAP -->
<div class="form-group" style="grid-column: 1 / -1;">

<label>Route Map (Click to set origin and destination)</label>

<div id="routeMap" style="height: 400px; width: 100%; border-radius: 8px;"></div>

<p style="font-size: 12px; color: #666; margin-top: 5px;">
Click on the map to set origin (first click) and destination (second click). 
The route will be displayed between the two points.
</p>

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

$query = mbus_db_query($conn,"
SELECT * FROM routes
ORDER BY route_id DESC
");

while($row=mbus_db_fetch_assoc($query)){

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

<script>
let map;
let originMarker = null;
let destinationMarker = null;
let directionsService = null;
let directionsRenderer = null;
let clickCount = 0;

function initMap() {
    // Initialize map centered on Philippines
    map = new google.maps.Map(document.getElementById("routeMap"), {
        center: { lat: 14.5995, lng: 120.9842 },
        zoom: 10,
    });

    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
        map: map,
        suppressMarkers: true
    });

    // Add click listener to map
    map.addListener("click", function(event) {
        handleMapClick(event.latLng);
    });
}

function handleMapClick(latLng) {
    const lat = latLng.lat();
    const lng = latLng.lng();

    if (clickCount === 0) {
        // Set origin
        document.getElementById("origin_lat").value = lat;
        document.getElementById("origin_lng").value = lng;

        if (originMarker) {
            originMarker.setMap(null);
        }

        originMarker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: map,
            label: "A",
            title: "Origin"
        });

        clickCount++;
    } else if (clickCount === 1) {
        // Set destination
        document.getElementById("destination_lat").value = lat;
        document.getElementById("destination_lng").value = lng;

        if (destinationMarker) {
            destinationMarker.setMap(null);
        }

        destinationMarker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: map,
            label: "B",
            title: "Destination"
        });

        // Draw route
        drawRoute();

        clickCount = 0;
    }
}

function drawRoute() {
    const originLat = parseFloat(document.getElementById("origin_lat").value);
    const originLng = parseFloat(document.getElementById("origin_lng").value);
    const destLat = parseFloat(document.getElementById("destination_lat").value);
    const destLng = parseFloat(document.getElementById("destination_lng").value);

    if (originLat && originLng && destLat && destLng) {
        const request = {
            origin: { lat: originLat, lng: originLng },
            destination: { lat: destLat, lng: destLng },
            travelMode: google.maps.TravelMode.DRIVING
        };

        directionsService.route(request, function(result, status) {
            if (status === "OK") {
                directionsRenderer.setDirections(result);
            }
        });
    }
}
</script>

<style>
.coord-inputs {
    display: flex;
    gap: 10px;
}

.coord-inputs input {
    flex: 1;
}
</style>

</body>
</html>