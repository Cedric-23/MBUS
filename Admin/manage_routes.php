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

/* ROUTE OPTIONS */
$places = [
    "Morong Terminal",
    "SBMA"
];

/* ADD ROUTE */
if(isset($_POST['add_route'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $origin = $_POST['origin'];
        $destination = $_POST['destination'];

        if($origin == "custom"){
            $origin = trim($_POST['custom_origin']);
        }

        if($destination == "custom"){
            $destination = trim($_POST['custom_destination']);
        }

        $fare = trim($_POST['fare']);
        $origin_lat = trim($_POST['origin_lat']);
        $origin_lng = trim($_POST['origin_lng']);
        $destination_lat = trim($_POST['destination_lat']);
        $destination_lng = trim($_POST['destination_lng']);

        if($origin == $destination){
            $error = "Origin and destination cannot be the same";
        } else {
            // Use prepared statement to check
            $check = mbus_db_prepare($conn, "
SELECT * FROM routes
WHERE origin = ? AND destination = ?
", [$origin, $destination]);

            if(mbus_db_num_rows($check) > 0){
                $error = "Route already exists";
            } else {
                mbus_db_prepare($conn, "
INSERT INTO routes(origin, destination, fare, origin_lat, origin_lng, destination_lat, destination_lng)
VALUES (?, ?, ?, ?, ?, ?, ?)
", [$origin, $destination, $fare, $origin_lat, $origin_lng, $destination_lat, $destination_lng]);

                $success = "Route added successfully";
                header("Location: manage_routes.php");
                exit();
            }
        }
    }
}

/* DELETE ROUTE */
if(isset($_POST['delete_route'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $route_id = (int)$_POST['route_id'];
        mbus_db_prepare($conn, "DELETE FROM routes WHERE route_id = ?", [$route_id]);
        $success = "Route deleted successfully";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Routes</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_routes.css">

    <!-- GOOGLE MAPS API -->
    <script async defer
src="https://maps.googleapis.com/maps/api/js?key=<?php echo getenv('GOOGLE_MAPS_API_KEY') ?: 'YOUR_API_KEY'; ?>&callback=initMap">
    </script>
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
        <a href="manage_operators.php"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
        <a href="manage_routes.php" aria-current="page"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
    </nav>

    <div class="menu-bottom">
        <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
            <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
        </a>
    </div>
</aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Manage Routes</h1>
</header>

<?php if(isset($success)): ?>
<div class="alert alert-success" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<!-- ADD ROUTE -->
<section class="card" style="margin-bottom: var(--space-6);">
    <h2>Add Route</h2>
    <form method="POST" class="form-section">
        <?php csrf_field(); ?>
        
        <div class="form-grid">
            <!-- ORIGIN -->
            <div class="form-group">
                <label for="origin">Origin</label>
                <select name="origin" id="origin" class="form-control" required>
                    <option value="">Select Origin</option>
                    <?php foreach($places as $place){ ?>
                    <option value="<?php echo $place; ?>">
                        <?php echo $place; ?>
                    </option>
                    <?php } ?>
                    <option value="custom">Custom Route</option>
                </select>
                <input type="text" name="custom_origin" id="custom_origin" class="form-control custom-input" placeholder="Enter Custom Origin" style="display: none;">
            </div>

            <!-- DESTINATION -->
            <div class="form-group">
                <label for="destination">Destination</label>
                <select name="destination" id="destination" class="form-control" required>
                    <option value="">Select Destination</option>
                    <?php foreach($places as $place){ ?>
                    <option value="<?php echo $place; ?>">
                        <?php echo $place; ?>
                    </option>
                    <?php } ?>
                    <option value="custom">Custom Route</option>
                </select>
                <input type="text" name="custom_destination" id="custom_destination" class="form-control custom-input" placeholder="Enter Custom Destination" style="display: none;">
            </div>

            <!-- FARE -->
            <div class="form-group">
                <label for="fare">Fare</label>
                <input type="number" step="0.01" name="fare" id="fare" class="form-control" placeholder="Enter Fare" required>
            </div>

            <!-- ORIGIN COORDINATES -->
            <div class="form-group">
                <label>Origin Coordinates</label>
                <div class="coord-inputs">
                    <input type="number" step="any" name="origin_lat" id="origin_lat" class="form-control" placeholder="Latitude" required>
                    <input type="number" step="any" name="origin_lng" id="origin_lng" class="form-control" placeholder="Longitude" required>
                </div>
            </div>

            <!-- DESTINATION COORDINATES -->
            <div class="form-group">
                <label>Destination Coordinates</label>
                <div class="coord-inputs">
                    <input type="number" step="any" name="destination_lat" id="destination_lat" class="form-control" placeholder="Latitude" required>
                    <input type="number" step="any" name="destination_lng" id="destination_lng" class="form-control" placeholder="Longitude" required>
                </div>
            </div>

            <!-- MAP -->
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Route Map (Click to set origin and destination)</label>
                <div id="routeMap" class="map-canvas" style="height: 400px;"></div>
                <p class="form-hint">Click on the map to set origin (first click) and destination (second click). The route will be displayed between the two points.</p>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" name="add_route" class="btn btn-primary">
                <i class="fa fa-plus" aria-hidden="true"></i> Add Route
            </button>
        </div>
    </form>
</section>

<!-- ROUTE LIST -->
<section class="card">
    <h2>Route List</h2>

    <div class="table-responsive">
        <table class="routes-table">
            <thead>
                <tr>
                    <th scope="col">Route</th>
                    <th scope="col">Fare</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $query = mbus_db_query($conn, "
                    SELECT * FROM routes
                    ORDER BY route_id DESC
                ");

                while($row = mbus_db_fetch_assoc($query)){
                ?>
                <tr>
                    <form method="POST" class="route-form">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="route_id" value="<?php echo $row['route_id']; ?>">
                        
                        <td data-label="Route">
                            <span class="route-badge">
                                <?php echo htmlspecialchars($row['origin']); ?> → <?php echo htmlspecialchars($row['destination']); ?>
                            </span>
                        </td>
                        <td data-label="Fare">₱<?php echo number_format($row['fare'], 2); ?></td>
                        <td data-label="Actions">
                            <div class="action-buttons">
                                <button type="submit" name="delete_route" class="btn btn-sm btn-danger delete-btn" 
                                    onclick="return confirm('Delete this route?')">
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
const origin = document.getElementById("origin");
const destination = document.getElementById("destination");
const customOrigin = document.getElementById("custom_origin");
const customDestination = document.getElementById("custom_destination");

/* ORIGIN */
origin.addEventListener("change", function(){
    customOrigin.style.display = (this.value == "custom") ? "block" : "none";
    updateDestination();
});

/* DESTINATION */
destination.addEventListener("change", function(){
    customDestination.style.display = (this.value == "custom") ? "block" : "none";
});

/* DISABLE SAME */
function updateDestination(){
    let originValue = origin.value;
    for(let option of destination.options){
        option.disabled = false;
        if(option.value == originValue && option.value != ""){
            option.disabled = true;
        }
    }
    if(destination.value == originValue){
        destination.value = "";
    }
}

updateDestination();

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

</body>
</html>