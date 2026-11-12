<?php
session_start();
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Live Operations · MBUS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../Assets/css/bottom-nav.css"><link rel="stylesheet" href="../Assets/css/app-header.css">
<style>
:root{--ink:#162033;--muted:#65748a;--line:#e5eaf1;--blue:#1e3a5f;--soft:#f6f8fb}
*{box-sizing:border-box}body{font-family:Inter,system-ui,sans-serif;background:var(--soft);color:var(--ink);margin:0}.wrap{max-width:1100px;margin:auto;padding:1.25rem 1rem 7rem}.eyebrow{color:#517096;font-size:.78rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin:0 0 .35rem}.title{margin:.1rem 0 .35rem;font-size:clamp(1.65rem,4vw,2.25rem)}.muted{color:var(--muted)}.map-summary{min-height:92px;border-radius:18px;padding:1rem 1.15rem;display:flex;align-items:center;gap:1rem;background:linear-gradient(135deg,#dbeafe,#ecfeff);color:#285f8f;margin:1.2rem 0}.map-summary i{font-size:1.5rem}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:1rem;box-shadow:0 8px 24px #182b4b0d}.card h2{margin:.1rem 0 .3rem;font-size:1.1rem}.route{margin:.1rem 0 .8rem;color:var(--muted);font-size:.9rem}.metrics{display:flex;flex-wrap:wrap;gap:.45rem}.metric{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .62rem;border-radius:999px;background:#f0f5fb;font-size:.82rem}.waiting-title{font-size:.85rem;margin:1rem 0 .35rem}.passenger{display:flex;align-items:center;gap:.65rem;border-top:1px solid #edf1f5;padding:.65rem 0}.avatar{width:42px;height:42px;flex:0 0 42px;border-radius:50%;object-fit:cover;background:#e8eef6;border:2px solid #fff;box-shadow:0 1px 5px #182b4b26}.passenger strong{font-size:.9rem}.passenger small{display:block;color:var(--muted);margin-top:.15rem}.empty{color:var(--muted);font-size:.9rem;margin:.25rem 0}.bottom-nav{z-index:10}
</style>
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
</head>
<body>
<main class="wrap">
    <p class="eyebrow"><i class="fa fa-tower-broadcast"></i> Operations control</p>
    <h1 class="title">Live operations</h1>
    <p class="muted">Monitor every active bus, onboard count, and passenger waiting for pickup.</p>
    <div id="map" class="map-summary" role="status"><i class="fa fa-location-dot"></i><span>Live operations map loading…</span></div>
    <div id="buses" class="grid"></div>
</main>
<nav class="bottom-nav">
    <a href="admin_dashboard.php" class="bottom-nav-item"><i class="fa fa-home"></i><span>Home</span></a>
    <a href="manage_schedules.php" class="bottom-nav-item"><i class="fa fa-calendar"></i><span>Schedules</span></a>
    <a href="live_map.php" class="bottom-nav-item active"><i class="fa fa-map-location-dot"></i><span>Live</span></a>
    <a href="manage_buses.php" class="bottom-nav-item"><i class="fa fa-bus"></i><span>Buses</span></a>
</nav>
<script>
const fallbackAvatar = '../Assets/images/mbus_icon.png';
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const avatarUrl = filename => filename ? '../Assets/profile_pictures/' + encodeURIComponent(String(filename).split('/').pop()) : fallbackAvatar;
async function refresh() {
    const map = document.querySelector('#map span');
    try {
        const response = await fetch('../api/get_live_map_data.php', {cache:'no-store'});
        const data = await response.json();
        const buses = Array.isArray(data.buses) ? data.buses : [];
        const pickups = Array.isArray(data.pickups) ? data.pickups : [];
        map.textContent = `${buses.length} live bus${buses.length === 1 ? '' : 'es'} · ${pickups.length} passenger${pickups.length === 1 ? '' : 's'} waiting`;
        document.querySelector('#buses').innerHTML = buses.length ? buses.map(bus => {
            const passengers = pickups.filter(p => String(p.bus_number) === String(bus.bus_number));
            return `<article class="card"><h2>Bus ${esc(bus.bus_number)}</h2><p class="route">${esc(bus.origin || 'No active route')} → ${esc(bus.destination || '')}</p><div class="metrics"><span class="metric"><i class="fa fa-user-clock"></i>${esc(bus.waiting_count || 0)} waiting</span><span class="metric"><i class="fa fa-users"></i>${esc(bus.onboard_count || 0)} onboard</span><span class="metric"><i class="fa fa-signal"></i>${esc(bus.trip_status || 'Live')}</span></div>${passengers.length ? `<h3 class="waiting-title">Waiting passengers</h3>${passengers.map(p => `<div class="passenger"><img class="avatar" src="${avatarUrl(p.profile_picture)}" alt="" onerror="this.onerror=null;this.src='${fallbackAvatar}'"><div><strong>${esc(p.full_name || 'Passenger')}</strong><small>${esc(p.pickup_location || 'Location not specified')}</small></div></div>`).join('')}` : '<p class="empty">No waiting passengers for this bus.</p>'}</article>`;
        }).join('') : '<article class="card"><p class="empty">No recent bus locations. Operators must have an active trip and allow location access.</p></article>';
    } catch (error) {
        map.textContent = 'Live map data is temporarily unavailable.';
    }
}
refresh();
setInterval(refresh, 15000);
</script>
</body>
</html>
