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
require_once __DIR__ . '/../Includes/route_locations.php';

/* ROUTE OPTIONS */
$places = [
    "Morong Terminal" => "Morong Terminal",
    "SBMA" => "Harbor Point"
];

/* ADD ROUTE */
if(isset($_POST['add_route'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $origin = isset($_POST['origin']) && is_scalar($_POST['origin']) ? trim((string)$_POST['origin']) : '';
        $destination = isset($_POST['destination']) && is_scalar($_POST['destination']) ? trim((string)$_POST['destination']) : '';

        if($origin == "custom"){
            $origin = isset($_POST['custom_origin']) && is_scalar($_POST['custom_origin']) ? trim((string)$_POST['custom_origin']) : '';
        }

        if($destination == "custom"){
            $destination = isset($_POST['custom_destination']) && is_scalar($_POST['custom_destination']) ? trim((string)$_POST['custom_destination']) : '';
        }

        $fare = isset($_POST['fare']) && is_scalar($_POST['fare']) ? trim((string)$_POST['fare']) : '';
        $origin_lat = isset($_POST['origin_lat']) && is_scalar($_POST['origin_lat']) ? trim((string)$_POST['origin_lat']) : '';
        $origin_lng = isset($_POST['origin_lng']) && is_scalar($_POST['origin_lng']) ? trim((string)$_POST['origin_lng']) : '';
        $destination_lat = isset($_POST['destination_lat']) && is_scalar($_POST['destination_lat']) ? trim((string)$_POST['destination_lat']) : '';
        $destination_lng = isset($_POST['destination_lng']) && is_scalar($_POST['destination_lng']) ? trim((string)$_POST['destination_lng']) : '';

        $knownOrigin = mbus_route_known_terminal($origin);
        $knownDestination = mbus_route_known_terminal($destination);
        if ($knownOrigin) {
            $origin_lat = (string)$knownOrigin['latitude'];
            $origin_lng = (string)$knownOrigin['longitude'];
        }
        if ($knownDestination) {
            $destination_lat = (string)$knownDestination['latitude'];
            $destination_lng = (string)$knownDestination['longitude'];
        }
        $coordinatesValid = is_numeric($origin_lat) && is_numeric($origin_lng) && is_numeric($destination_lat) && is_numeric($destination_lng)
            && is_finite((float)$origin_lat) && is_finite((float)$origin_lng) && is_finite((float)$destination_lat) && is_finite((float)$destination_lng)
            && abs((float)$origin_lat) <= 90 && abs((float)$destination_lat) <= 90
            && abs((float)$origin_lng) <= 180 && abs((float)$destination_lng) <= 180;

        if(!$coordinatesValid){
            $error = "Choose both route points on the map before adding this route.";
        } elseif($origin == $destination){
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
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
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

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                            <span class="profile-menu-role">Admin account</span>
                        </div>
                        <?php include_once "../Includes/admin_menu.php"; echo mbus_admin_approvals_link($conn); ?>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
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
                    <?php foreach($places as $value => $label){ ?>
                    <option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($label); ?>
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
                    <?php foreach($places as $value => $label){ ?>
                    <option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($label); ?>
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

            <!-- Coordinates are set automatically from the map (hidden) -->
            <input type="hidden" name="origin_lat" id="origin_lat">
            <input type="hidden" name="origin_lng" id="origin_lng">
            <input type="hidden" name="destination_lat" id="destination_lat">
            <input type="hidden" name="destination_lng" id="destination_lng">

            <!-- MAP -->
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Route map</label>
                <div id="routeMap" class="map-canvas" style="height: 280px;"></div>
                <p class="form-hint">Terminals are placed automatically. For a custom route, tap the map to set the start, then the end.</p>
                <p id="routeEstimate" class="route-estimate" role="status" aria-live="polite">Choose both stops to see a road distance and driving-time estimate.</p>
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
                $routeDisplay = mbus_route_apply_terminal_defaults($row);
                ?>
                <tr>
                    <form method="POST" class="route-form">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="route_id" value="<?php echo $row['route_id']; ?>">
                        
                        <td data-label="Route">
                            <span class="route-badge">
                                <?php echo htmlspecialchars($routeDisplay['origin_label']); ?> → <?php echo htmlspecialchars($routeDisplay['destination_label']); ?>
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
    clickCount = 0;
    applyEndpoint('origin');
    updateDestination();
    applyEndpoint('destination');
    drawRoute();
});

/* DESTINATION */
destination.addEventListener("change", function(){
    clickCount = 0;
    applyEndpoint('destination');
    drawRoute();
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

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/route_utils.js?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/js/route_utils.js'); ?>"></script>
<script>
let map;
let originMarker = null;
let destinationMarker = null;
let clickCount = 0;
let routeLine = null;
let routeRequest = 0;
const terminalPins = {
    'Morong Terminal': { lat: 14.6798552, lng: 120.2728757, label: 'Morong Terminal' },
    'SBMA': { lat: 14.8243652, lng: 120.2801434, label: 'Harbor Point' }
};

function markerIcon(label) {
    return L.divIcon({ className: 'route-endpoint-icon', html: '<span>' + label + '</span>', iconSize: [30, 36], iconAnchor: [15, 34] });
}

function setEndpoint(kind, lat, lng, label) {
    const isOrigin = kind === 'origin';
    document.getElementById(isOrigin ? 'origin_lat' : 'destination_lat').value = Number(lat).toFixed(7);
    document.getElementById(isOrigin ? 'origin_lng' : 'destination_lng').value = Number(lng).toFixed(7);
    if (!map || !window.L) return;
    const position = [Number(lat), Number(lng)];
    if (isOrigin) originMarker = originMarker ? originMarker.setLatLng(position) : L.marker(position, { icon: markerIcon('A'), title: 'Origin' }).addTo(map);
    else destinationMarker = destinationMarker ? destinationMarker.setLatLng(position) : L.marker(position, { icon: markerIcon('B'), title: 'Destination' }).addTo(map);
    (isOrigin ? originMarker : destinationMarker).bindTooltip(label || (isOrigin ? 'Origin' : 'Destination'));
}

function clearEndpoint(kind) {
    const isOrigin = kind === 'origin';
    document.getElementById(isOrigin ? 'origin_lat' : 'destination_lat').value = '';
    document.getElementById(isOrigin ? 'origin_lng' : 'destination_lng').value = '';
    if (isOrigin && originMarker) { map.removeLayer(originMarker); originMarker = null; }
    if (!isOrigin && destinationMarker) { map.removeLayer(destinationMarker); destinationMarker = null; }
}

function applyEndpoint(kind) {
    const select = document.getElementById(kind);
    const custom = document.getElementById(kind === 'origin' ? 'custom_origin' : 'custom_destination');
    const isCustom = select.value === 'custom';
    custom.style.display = isCustom ? 'block' : 'none';
    custom.required = isCustom;
    if (isCustom) { clearEndpoint(kind); return; }
    const pin = terminalPins[select.value];
    if (pin) setEndpoint(kind, pin.lat, pin.lng, pin.label);
    else clearEndpoint(kind);
}

function initMap() {
    if (!window.L) {
        document.getElementById('routeEstimate').textContent = 'The map library did not load. Try again or choose another connection.';
        return;
    }
    const morong = [14.6798552, 120.2728757];
    const harborPoint = [14.8243652, 120.2801434];
    map = L.map('routeMap', { zoomControl: true }).fitBounds([morong, harborPoint], { padding: [24, 24] });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · routing by OSRM'
    }).addTo(map);
    routeLine = L.layerGroup().addTo(map);
    map.on('click', event => handleMapClick(event.latlng));
}

function handleMapClick(latLng) {
    routeRequest++;
    routeLine.clearLayers();
    const kind = clickCount === 0 ? 'origin' : 'destination';
    const select = document.getElementById(kind);
    const custom = document.getElementById(kind === 'origin' ? 'custom_origin' : 'custom_destination');
    if (select.value !== 'custom') select.value = 'custom';
    custom.style.display = 'block';
    custom.required = true;
    if (!custom.value.trim()) custom.value = kind === 'origin' ? 'Custom origin' : 'Custom destination';
    setEndpoint(kind, latLng.lat, latLng.lng, kind === 'origin' ? 'Custom origin' : 'Custom destination');
    clickCount = kind === 'origin' ? 1 : 0;
    if (clickCount === 0) drawRoute();
    else document.getElementById('routeEstimate').textContent = 'Origin pinned. Click the destination on the map.';
}

async function drawRoute() {
    const estimate = document.getElementById('routeEstimate');
    const fields = ['origin_lat', 'origin_lng', 'destination_lat', 'destination_lng'].map(id => document.getElementById(id).value.trim());
    if (fields.some(value => value === '')) {
        routeRequest++;
        estimate.textContent = 'Choose both stops to see a road distance and driving-time estimate.';
        if (routeLine) routeLine.clearLayers();
        return;
    }
    const originPoint = { lat: Number(fields[0]), lng: Number(fields[1]) };
    const destinationPoint = { lat: Number(fields[2]), lng: Number(fields[3]) };
    if (!Number.isFinite(originPoint.lat) || !Number.isFinite(originPoint.lng) || !Number.isFinite(destinationPoint.lat) || !Number.isFinite(destinationPoint.lng)) {
        estimate.textContent = 'Choose both stops to see a road distance and driving-time estimate.';
        if (routeLine) routeLine.clearLayers();
        return;
    }
    if (!map || !routeLine || !window.MBUSRouting) {
        estimate.textContent = 'The map or road-routing service is unavailable right now.';
        return;
    }
    const requestId = ++routeRequest;
    estimate.textContent = 'Loading the road route and estimate…';
    routeLine.clearLayers();
    try {
        const route = await window.MBUSRouting.getDrivingRoute(originPoint, destinationPoint);
        if (requestId !== routeRequest) return;
        L.polyline(route.points, { color: '#1e90ff', weight: 5, opacity: 0.88 }).addTo(routeLine);
        map.fitBounds(L.latLngBounds(route.points).pad(0.12), { maxZoom: 14 });
        estimate.textContent = window.MBUSRouting.formatDistance(route.distanceMeters) + ' road route · ' + window.MBUSRouting.formatDuration(route.durationSeconds) + ' driving. Traffic, stops and detours may add time.';
    } catch (error) {
        if (requestId !== routeRequest) return;
        estimate.textContent = error.message || 'Road directions are unavailable right now.';
    }
}

initMap();
applyEndpoint('origin');
applyEndpoint('destination');
drawRoute();
</script>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="admin_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="manage_schedules.php" class="bottom-nav-item">
        <i class="fa fa-calendar" aria-hidden="true"></i><span>Schedules</span>
    </a>
    <a href="manage_buses.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span>
    </a>
    <a href="manage_operators.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Operators</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
