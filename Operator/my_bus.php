<?php
session_start();
include_once __DIR__ . '/../config/db_connect.php';
include_once __DIR__ . '/../Includes/csrf.php';
include_once __DIR__ . '/../Includes/seat_layout.php';
include_once __DIR__ . '/../Includes/profile_photo.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type'] ?? '') !== 'operator') {
    header('Location: ../login.php');
    exit();
}
$userId = (int)$_SESSION['user_id'];

function mbus_bus_photo_upload(string $field, ?string &$error): ?array {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $error = 'A photo could not be uploaded. Try a smaller image.'; return null; }
    if ($f['size'] > 786432) { $error = 'Each photo must be 768 KB or smaller.'; return null; }
    $data = file_get_contents($f['tmp_name']);
    $mime = str_starts_with($data, "\xFF\xD8\xFF") ? 'image/jpeg' : (str_starts_with($data, "\x89PNG\r\n\x1a\n") ? 'image/png' : '');
    if ($mime === '') { $error = 'Photos must be JPG or PNG images.'; return null; }
    return ['b64' => base64_encode($data), 'mime' => $mime];
}

/* ---- this driver's buses ---- */
$buses = [];
$bq = mbus_db_prepare($conn, "SELECT b.bus_id, b.bus_number, b.bus_type, b.capacity, b.seat_layout,
        (b.bus_photo_b64 IS NOT NULL AND b.bus_photo_b64 <> '') AS has_ext, (b.interior_photo_b64 IS NOT NULL AND b.interior_photo_b64 <> '') AS has_int
    FROM buses b JOIN operator_bus_assignments oba ON oba.bus_id = b.bus_id WHERE oba.operator_id = ? ORDER BY b.bus_number", [$userId]);
while ($bq !== false && ($r = mbus_db_fetch_assoc($bq))) $buses[(int)$r['bus_id']] = $r;

$busId = (int)($_POST['bus_id'] ?? $_GET['bus_id'] ?? 0);
if (!isset($buses[$busId])) $busId = $buses ? (int)array_key_first($buses) : 0;

$ok = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $busId > 0) {
    if (!csrf_validate_token()) {
        $err = 'Your session expired. Reload the page and try again.';
    } else {
        $bus = $buses[$busId];
        $updates = []; $params = []; $notes = [];

        // seat layout
        if ($err === '') {
            $newLayout = mbus_seat_layout_parse((string)($_POST['seat-layout'] ?? ''));
            if ($newLayout === null) {
                $err = 'The seat layout is invalid. Choose between 1 and 100 seats.';
            } else {
                $count = mbus_seat_layout_count($newLayout);
                $current = mbus_bus_seat_rows($bus['seat_layout'], (int)$bus['capacity']);
                $currentFlat = [];
                foreach ($current as $row) foreach ($row as $cell) $currentFlat[] = $cell !== null;
                $newFlat = [];
                foreach ($newLayout as $row) foreach ($row as $cell) $newFlat[] = (bool)$cell;
                if ($currentFlat !== $newFlat) {
                    $busy = mbus_db_prepare($conn, "SELECT COUNT(*) AS n FROM reservation r JOIN schedule s ON s.schedule_id = r.schedule_id
                        WHERE s.bus_id = ? AND r.status IN ('Pending','Paid','Confirmed','Boarded') AND s.trip_status IN ('Scheduled','Boarding','Departed')", [$busId]);
                    $n = $busy !== false ? (int)(mbus_db_fetch_assoc($busy)['n'] ?? 0) : 0;
                    if ($n > 0) {
                        $err = 'This bus has booked passengers on an upcoming or running trip, so the seats can\'t change yet. Update the layout after that trip.';
                    } else {
                        $updates[] = 'seat_layout = ?'; $params[] = json_encode($newLayout);
                        $updates[] = 'capacity = ?'; $params[] = $count;
                        $notes[] = "seat layout ($count seats)";
                    }
                }
            }
        }

        if ($err === '' && $updates) {
            $params[] = $busId;
            $res = mbus_db_prepare($conn, 'UPDATE buses SET ' . implode(', ', $updates) . ' WHERE bus_id = ?', $params);
            if ($res === false) $err = 'We could not save the changes. Please try again.';
            else $ok = 'Saved: ' . implode(', ', $notes) . '.';
        } elseif ($err === '') {
            $ok = 'Nothing changed.';
        }
        // reload
        $bq = mbus_db_prepare($conn, "SELECT b.bus_id, b.bus_number, b.bus_type, b.capacity, b.seat_layout,
            (b.bus_photo_b64 IS NOT NULL AND b.bus_photo_b64 <> '') AS has_ext, (b.interior_photo_b64 IS NOT NULL AND b.interior_photo_b64 <> '') AS has_int
            FROM buses b JOIN operator_bus_assignments oba ON oba.bus_id = b.bus_id WHERE oba.operator_id = ? ORDER BY b.bus_number", [$userId]);
        $buses = [];
        while ($bq !== false && ($r = mbus_db_fetch_assoc($bq))) $buses[(int)$r['bus_id']] = $r;
    }
}

$bus = $buses[$busId] ?? null;
$layout = [];
if ($bus) {
    $saved = $bus['seat_layout'] ? mbus_seat_layout_parse($bus['seat_layout']) : null;
    $layout = ($saved !== null && mbus_seat_layout_count($saved) === (int)$bus['capacity']) ? $saved : mbus_seat_layout_default((int)$bus['capacity']);
}
$yes = static fn($v) => $v === true || $v === 't' || $v === '1' || $v === 1;
$name = htmlspecialchars((string)($_SESSION['full_name'] ?? 'Operator'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bus - Operator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/responsive.css"><link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css"><link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=2">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/main/driver_bus_setup.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/main/driver_bus_setup.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/operator_my_bus.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/operator_my_bus.css'); ?>">
</head>
<body class="stf has-bottom-nav mybus-page">
<main class="main" role="main">
    <header class="page-header" role="banner">
        <div class="page-header-left"><h1>My Bus</h1></div>
        <div class="page-header-right"><div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu"><span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span></button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header"><span class="profile-menu-name"><?php echo $name; ?></span><span class="profile-menu-role">Operator account</span></div>
                <a href="my_bus.php" role="menuitem"><i class="fa fa-bus" aria-hidden="true"></i> My Bus</a>
                <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div></div>
    </header>

<?php if (!$bus): ?>
    <section class="card mb-card"><h2>No bus assigned yet</h2><p class="mb-hint">Once an admin assigns a bus to you, you can set up its seats here.</p></section>
<?php else: ?>
    <nav class="mb-tabs" aria-label="Your buses"><?php foreach ($buses as $id => $b): $bl = ctype_digit((string)$b['bus_number']) ? 'Bus ' . $b['bus_number'] : $b['bus_number']; ?>
        <a href="my_bus.php?bus_id=<?php echo (int)$id; ?>"<?php echo $id === $busId ? ' class="active" aria-current="page"' : ''; ?>><strong><?php echo htmlspecialchars($bl); ?></strong><small><?php echo htmlspecialchars($b['bus_type']); ?> &middot; <?php echo (int)$b['capacity']; ?> seats</small></a>
    <?php endforeach; ?></nav>

    <?php if ($ok): ?><div class="alert alert-success mb-alert" role="status"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error mb-alert" role="alert"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

    <form method="POST" class="mb-form">
        <?php csrf_field(); ?>
        <input type="hidden" name="bus_id" value="<?php echo (int)$busId; ?>">

        <section class="card mb-card">
            <section class="seat-builder" data-seat-builder aria-labelledby="seat-builder-title">
                <div class="seat-builder-head">
                    <div><h3 id="seat-builder-title">Seat layout</h3><p>Tap a seat to add or remove it. The aisle sits between the two seats on each side.</p></div>
                    <div class="seat-builder-actions"><button type="button" class="seat-layout-button" data-seat-add-row>Add row</button><button type="button" class="seat-layout-button" data-seat-reset>Reset</button></div>
                </div>
                <div class="seat-builder-meta"><span data-seat-count aria-live="polite"><?php echo (int)$bus['capacity']; ?> seats selected</span><span>1–100 seats</span></div>
                <div class="seat-layout-preview" data-seat-grid role="grid" aria-label="Bus seat layout"></div>
                <input type="hidden" name="seat-layout" data-seat-layout value="<?php echo htmlspecialchars(json_encode($layout), ENT_QUOTES); ?>">
            </section>
            <p class="mb-hint">Changing seats updates the bus's seat count. It is blocked while passengers are booked on an upcoming or running trip.</p>
        </section>

        <div class="mb-save"><button type="submit" class="btn btn-primary">Save changes</button></div>
    </form>
<?php endif; ?>
</main>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="manage_trips.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span></a>
    <a href="scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="passengers.php" class="bottom-nav-item"><i class="fa fa-users" aria-hidden="true"></i><span>Riders</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/driver_bus_setup.js?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/js/driver_bus_setup.js'); ?>"></script>
<script>
(function () { var c = document.querySelector('[data-seat-count]'), t = document.getElementById('mbSeatTotal'); if (!c || !t) return;
  new MutationObserver(function () { var m = c.textContent.match(/\d+/); if (m) t.textContent = m[0]; }).observe(c, { childList: true, characterData: true, subtree: true }); })();
</script>
<script src="../Assets/js/operator_tracker.js"></script>
</body></html>
