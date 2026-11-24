<?php
/* Transportation updates for staff: announcements sent to everyone or to staff (operators, drivers, conductors). */
require_once __DIR__ . "/../config/app.php";
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
$role = strtolower((string)($_SESSION['user_type'] ?? ''));
if (!in_array($role, ['driver', 'operator', 'conductor'], true)) {
    header("Location: ../login.php");
    exit();
}
include "../config/db_connect.php";
include "../Includes/csrf.php";
include_once "../Includes/announcements.php";

$items = mbus_announcements_active($conn, ['All', 'Staff']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service updates - MBUS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; background: #f4f7fb; margin: 0; }
        .upd-wrap { max-width: 720px; margin: 0 auto; padding: 20px 16px 40px; }
        .upd-back { display: inline-block; margin-bottom: 12px; color: #2f6fe4; text-decoration: none; font-weight: 600; font-size: .9rem; }
        .upd-wrap h1 { margin: 0 0 4px; font-size: 1.4rem; }
        .upd-sub { margin: 0 0 16px; color: #6b7a90; font-size: .9rem; }
        .upd-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
        .upd-card { padding: 14px 16px; border-radius: 14px; background: #fff; box-shadow: 0 1px 4px rgba(20, 40, 80, .08); }
        .upd-card h2 { margin: 6px 0 4px; font-size: 1rem; }
        .upd-card p { margin: 0; font-size: .9rem; line-height: 1.4; overflow-wrap: anywhere; }
        .upd-card small { color: #6b7a90; }
        .upd-tag { display: inline-block; padding: 2px 10px; border-radius: 999px; background: #e3ecfb; color: #2f6fe4; font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .upd-tag.is-suspension, .upd-tag.is-delay, .upd-tag.is-service-alert { background: #ffe9d6; color: #b4531a; }
        .upd-tag.is-service-resumed { background: #dff5e6; color: #1f7a3d; }
        .upd-empty { padding: 24px; text-align: center; color: #6b7a90; background: #fff; border-radius: 14px; }
    </style>
</head>
<body>
<main class="upd-wrap" role="main">
    <a class="upd-back" href="javascript:history.back()"><i class="fa fa-arrow-left" aria-hidden="true"></i> Back</a>
    <h1>Service updates</h1>
    <p class="upd-sub">Announcements about bus operations and service availability.</p>

    <?php if (!$items): ?>
        <p class="upd-empty">No service updates right now.</p>
    <?php else: ?>
    <ul class="upd-list">
        <?php foreach ($items as $a): ?>
        <li class="upd-card">
            <span class="upd-tag is-<?php echo htmlspecialchars(strtolower(str_replace(' ', '-', $a['category']))); ?>"><?php echo htmlspecialchars($a['category']); ?></span>
            <h2><?php echo htmlspecialchars($a['title']); ?></h2>
            <p><?php echo nl2br(htmlspecialchars($a['body'])); ?></p>
            <small><?php echo date('M j, g:i A', strtotime($a['created_at'])); ?><?php echo $a['expires_at'] ? ' &middot; until ' . date('M j, g:i A', strtotime($a['expires_at'])) : ''; ?></small>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</main>
</body>
</html>
