<?php
require_once __DIR__ . '/config/app.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/Includes/gps_gate.php';

$role = strtolower((string)($_SESSION['user_type'] ?? 'commuter'));
$next = mbus_safe_next($_GET['next'] ?? null, $role);

if (mbus_gps_enabled()) {
    header('Location: ' . $next);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Turn on location — MBUS</title>
    <link rel="icon" href="Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="Assets/css/gps_gate.css">
</head>
<body>
<main class="gate">
    <div class="gate-brand">
        <img src="Assets/images/mbus_logo.png" alt="" aria-hidden="true">
        <span>MBUS</span>
    </div>

    <section class="gate-card" aria-labelledby="gateTitle">
        <div class="gate-pin" aria-hidden="true"><i class="fa fa-location-crosshairs"></i></div>
        <h1 id="gateTitle">Turn on location</h1>
        <p class="gate-sub">So you and your operator can see the bus.</p>

        <button type="button" id="gateEnable" class="gate-cta">
            <i class="fa fa-location-arrow" aria-hidden="true"></i>
            <span>Enable location</span>
        </button>

        <p id="gateStatus" class="gate-status" role="status" aria-live="polite">Required to continue</p>
    </section>

    <p class="gate-foot">You can turn location off anytime in your browser.</p>
</main>

<script>
(function () {
    'use strict';
    var button = document.getElementById('gateEnable');
    var status = document.getElementById('gateStatus');
    var next = <?php echo json_encode($next, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var busy = false;

    function say(text, kind) {
        status.textContent = text;
        status.className = 'gate-status' + (kind ? ' is-' + kind : '');
    }

    function store(lat, lng) {
        try { window.localStorage.setItem('mbus_last_fix', JSON.stringify({ lat: lat, lng: lng, at: Date.now() })); } catch (e) {}
    }

    function unlock() {
        document.cookie = 'mbus_gps=1; path=/; max-age=31536000; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
        document.body.classList.add('gate-done');
        say('Location on', 'ok');
        window.setTimeout(function () { window.location.replace(next); }, 450);
    }

    function fail(message) {
        busy = false;
        button.disabled = false;
        button.classList.remove('is-busy');
        say(message, 'error');
    }

    button.addEventListener('click', function () {
        if (busy) return;
        if (!window.isSecureContext) {
            fail('Location needs HTTPS. Open MBUS on its secure address.');
            return;
        }
        if (!navigator.geolocation) {
            fail('This browser cannot share location. Try Chrome or Safari.');
            return;
        }
        busy = true;
        button.disabled = true;
        button.classList.add('is-busy');
        say('Getting your location…');

        navigator.geolocation.getCurrentPosition(function (position) {
            store(position.coords.latitude, position.coords.longitude);
            unlock();
        }, function (error) {
            if (error && error.code === 1) {
                fail('Location is blocked. Allow it in your browser, then tap again.');
            } else if (error && error.code === 2) {
                fail('No location signal yet. Move near a window and tap again.');
            } else {
                fail('Location timed out. Tap again to retry.');
            }
        }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });
})();
</script>
</body>
</html>
