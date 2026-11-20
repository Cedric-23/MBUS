<?php
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'admin') { header('Location: ../login.php'); exit(); }
include "../config/db_connect.php";
include "../Includes/csrf.php";
include_once "../Includes/email_helper.php";

$success = $_SESSION['oa_flash'] ?? ''; $error = ''; unset($_SESSION['oa_flash']);
$admin_id = (int)$_SESSION['user_id'];

function oa_temp_password(): string { return substr(strtr(base64_encode(random_bytes(9)), '+/=', 'xyz'), 0, 10); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['application_id'] ?? 0);
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please try again.';
    } elseif (isset($_POST['approve']) || isset($_POST['reject'])) {
        $pdo = $conn->pdo;
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM operator_application WHERE application_id = ? AND application_status::text = 'Pending' FOR UPDATE");
            $st->execute([$id]);
            $app = $st->fetch(PDO::FETCH_ASSOC);
            if (!$app) throw new RuntimeException('That application was already reviewed.');
            if (isset($_POST['approve'])) {
                $st = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)");
                $st->execute([$app['email']]);
                if ($st->fetchColumn()) throw new RuntimeException('An account with this email already exists. Reject this application or ask the applicant to use another email.');
                // Applicants choose their own password when they apply; older applications get a temporary one.
                $temp = empty($app['password_hash']) ? oa_temp_password() : '';
                $hashToStore = $temp !== '' ? password_hash($temp, PASSWORD_DEFAULT) : $app['password_hash'];
                $st = $pdo->prepare("INSERT INTO users (full_name, email, phone_number, password, user_type) VALUES (?, ?, ?, ?, 'operator') RETURNING user_id");
                $st->execute([$app['full_name'], $app['email'], $app['phone_number'], $hashToStore]);
                $uid = (int)$st->fetchColumn();
                $st = $pdo->prepare("UPDATE operator_application SET application_status = 'Approved', user_id = ?, reviewed_at = NOW(), reviewed_by = ?, rejection_reason = NULL WHERE application_id = ?");
                $st->execute([$uid, $admin_id, $id]);
                $pdo->commit();
                $mail = "<h2>Operator application approved</h2><p>Hi " . htmlspecialchars($app['full_name']) . ", your MBUS operator account is ready.</p>"
                    . "<p><strong>Email:</strong> " . htmlspecialchars($app['email']) . "</p>"
                    . ($temp !== '' ? "<p><strong>Temporary password:</strong> " . htmlspecialchars($temp) . "<br>Log in and change it from your profile.</p>" : "<p>Log in with the password you chose when you applied.</p>");
                $sent = @send_email_notification($app['email'], 'Your MBUS operator account', $mail);
                $_SESSION['oa_flash'] = 'Approved ' . $app['full_name'] . '. ' . ($temp !== '' ? 'Temporary password: ' . $temp . ($sent ? ' (also emailed).' : ' (email could not be sent, so give it to them directly).')
                    : 'They can log in now with the password they chose.');
            } else {
                $reason = trim((string)($_POST['rejection_reason'] ?? ''));
                if ($reason === '' || strlen($reason) > 500) throw new RuntimeException('Give a short reason (up to 500 characters) when rejecting.');
                $st = $pdo->prepare("UPDATE operator_application SET application_status = 'Rejected', reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ? WHERE application_id = ?");
                $st->execute([$admin_id, $reason, $id]);
                $pdo->commit();
                @send_email_notification($app['email'], 'Your MBUS operator application', get_operator_rejected_email(htmlspecialchars($reason)));
                $_SESSION['oa_flash'] = 'Rejected ' . $app['full_name'] . '.';
            }
            header('Location: operator_applications.php?status=' . urlencode($_GET['status'] ?? 'pending')); exit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the review. Please try again.';
            if (!($e instanceof RuntimeException)) error_log('Operator application review: ' . $e->getMessage());
        }
    }
}

$opts = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$f = $_GET['status'] ?? 'pending'; if ($f !== 'all' && !isset($opts[$f])) $f = 'pending';
$counts = array_fill_keys(array_keys($opts), 0);
$cq = mbus_db_prepare($conn, "SELECT LOWER(application_status::text) AS s, COUNT(*) AS c FROM operator_application GROUP BY 1", []);
while ($cq !== false && ($r = mbus_db_fetch_assoc($cq))) if (isset($counts[$r['s']])) $counts[$r['s']] = (int)$r['c'];
$where = $f === 'all' ? '' : "WHERE LOWER(application_status::text) = '" . $f . "'";
$rows = [];
$q = mbus_db_prepare($conn, "SELECT application_id, full_name, email, phone_number, address, license_number, license_expiry, vehicle_type, vehicle_plate_number,
    (license_document_b64 IS NOT NULL AND license_document_b64 <> '') AS has_lic, (vehicle_document_b64 IS NOT NULL AND vehicle_document_b64 <> '') AS has_veh,
    (bus_photo_b64 IS NOT NULL AND bus_photo_b64 <> '') AS has_bus_photo,
    (interior_photo_b64 IS NOT NULL AND interior_photo_b64 <> '') AS has_interior_photo,
    seat_layout, application_status::text AS status, submitted_at, rejection_reason
    FROM operator_application $where ORDER BY (application_status::text = 'Pending') DESC, submitted_at DESC LIMIT 100", []);
while ($q !== false && ($r = mbus_db_fetch_assoc($q))) $rows[] = $r;
$yes = fn($v) => in_array($v, [true, 1, '1', 't', 'true'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operator applications - MBUS</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_operators.css">
    <link rel="stylesheet" href="../Assets/css/admin/operator_application_bus_review.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/admin/operator_application_bus_review.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=1">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/admin/admin_application_review.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/admin/admin_application_review.css'); ?>">
</head>

<body class="stf has-sidebar">

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
        <a href="manage_operators.php" aria-current="page"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
        <a href="manage_routes.php"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
    </nav>

    <div class="menu-bottom">
<a href="../logout.php" class="logout-btn btn btn-outline btn-block">
            <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
        </a>
    </div>
</aside>

<main class="main" role="main">
<header class="page-header">
    <h1>Operator applications</h1>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span><?php include_once dirname(__DIR__) . '/Includes/admin_menu.php'; echo mbus_admin_dot($conn); ?>
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

<nav class="ar-page-nav" aria-label="Application navigation">
    <a class="ar-back-link" href="manage_operators.php" aria-label="Back to Manage Operators"><i class="fa fa-arrow-left" aria-hidden="true"></i></a>
<a class="ad-btn" href="conductor_applications.php"><i class="fa fa-id-badge" aria-hidden="true"></i><span>Conductor applications</span></a>
</nav>

<?php if($success !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<nav class="ad-seg vf-tabs" aria-label="Status">
    <?php foreach($opts as $k => $label): ?>
    <a href="?status=<?php echo $k; ?>" class="<?php echo $f === $k ? 'on' : ''; ?>"><?php echo $label; ?> <span class="vf-count"><?php echo $counts[$k]; ?></span></a>
    <?php endforeach; ?>
    <a href="?status=all" class="<?php echo $f === 'all' ? 'on' : ''; ?>">All <span class="vf-count"><?php echo array_sum($counts); ?></span></a>
</nav>

<?php if(!$rows): ?>
<div class="ca-empty"><i class="fa fa-inbox" aria-hidden="true"></i><strong>No <?php echo $f === 'all' ? '' : htmlspecialchars($f) . ' '; ?>applications</strong><span>Applications matching this status will appear here.</span></div>
<?php endif; ?>

<div class="oa-list">
<?php foreach($rows as $a): $st = strtolower($a['status']); $expired = $a['license_expiry'] && strtotime($a['license_expiry']) < strtotime('today'); $layout = json_decode((string)($a['seat_layout'] ?? ''), true); ?>
    <article class="oa-card">
        <div class="oa-head">
            <div><h2><?php echo htmlspecialchars($a['full_name']); ?></h2>
                <p><?php echo htmlspecialchars($a['email']); ?> · <?php echo htmlspecialchars($a['phone_number']); ?></p></div>
            <span class="ad-pill <?php echo $st === 'pending' ? 'amber' : ($st === 'approved' ? 'active' : 'cancelled'); ?>"><?php echo ucfirst($st); ?></span>
        </div>
        <dl class="oa-facts">
            <div><dt>Address</dt><dd><?php echo htmlspecialchars($a['address']); ?></dd></div>
            <div><dt>License</dt><dd><?php echo htmlspecialchars($a['license_number']); ?> · <span class="<?php echo $expired ? 'oa-bad' : ''; ?>"><?php echo $expired ? 'Expired ' : 'Valid until '; echo date('M j, Y', strtotime($a['license_expiry'])); ?></span></dd></div>
            <div><dt>Vehicle</dt><dd><?php echo htmlspecialchars($a['vehicle_type']); ?> · <?php echo htmlspecialchars($a['vehicle_plate_number']); ?></dd></div>
            <div><dt>Submitted</dt><dd><?php echo date('M j, Y g:i A', strtotime($a['submitted_at'])); ?></dd></div>
        </dl>
        <div class="oa-docs">
            <?php if($yes($a['has_lic'])): ?><a class="ad-btn sm" target="_blank" rel="noopener" href="application_file.php?id=<?php echo (int)$a['application_id']; ?>&doc=license"><i class="fa fa-id-card" aria-hidden="true"></i> License</a><?php else: ?><span class="oa-miss">No license file</span><?php endif; ?>
            <?php if($yes($a['has_veh'])): ?><a class="ad-btn sm" target="_blank" rel="noopener" href="application_file.php?id=<?php echo (int)$a['application_id']; ?>&doc=vehicle"><i class="fa fa-bus" aria-hidden="true"></i> Vehicle registration</a><?php else: ?><span class="oa-miss">No vehicle file</span><?php endif; ?>
            <?php if($yes($a['has_bus_photo'])): ?><a class="ad-btn sm" target="_blank" rel="noopener" href="application_file.php?id=<?php echo (int)$a['application_id']; ?>&doc=bus"><i class="fa fa-camera" aria-hidden="true"></i> Bus exterior</a><?php else: ?><span class="oa-miss">No bus exterior photo</span><?php endif; ?>
            <?php if($yes($a['has_interior_photo'])): ?><a class="ad-btn sm" target="_blank" rel="noopener" href="application_file.php?id=<?php echo (int)$a['application_id']; ?>&doc=interior"><i class="fa fa-camera" aria-hidden="true"></i> Bus interior</a><?php else: ?><span class="oa-miss">No bus interior photo</span><?php endif; ?>
        </div>
        <?php if(is_array($layout)): $seatTotal = 0; foreach($layout as $seatRow) if(is_array($seatRow)) foreach($seatRow as $seat) if($seat === true) $seatTotal++; ?>
        <section class="oa-seat-review" aria-label="Submitted bus seat layout">
            <strong>Submitted seat layout · <?php echo $seatTotal; ?> seats</strong>
            <div class="oa-seat-rows">
            <?php $seatNumber = 1; foreach($layout as $seatRow): if(!is_array($seatRow) || count($seatRow) !== 4) continue; ?>
                <div class="oa-seat-row" aria-label="Seat row">
                    <?php foreach($seatRow as $i => $seatRowValue): if($i === 2): ?><span class="oa-seat-aisle" aria-hidden="true">Aisle</span><?php endif; ?>
                    <?php if($seatRowValue === true): ?><span class="oa-seat-chip"><?php echo $seatNumber++; ?></span><?php else: ?><span class="oa-seat-empty" aria-hidden="true">—</span><?php endif; endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php if($st === 'rejected' && $a['rejection_reason']): ?><p class="oa-reason">Reason: <?php echo htmlspecialchars($a['rejection_reason']); ?></p><?php endif; ?>
        <?php if($st === 'pending'): ?>
        <form method="POST" class="oa-actions">
            <?php csrf_field(); ?>
            <input type="hidden" name="application_id" value="<?php echo (int)$a['application_id']; ?>">
            <button type="submit" name="approve" value="1" class="ad-btn sm primary" <?php echo $expired ? 'disabled title="License expired"' : ''; ?> onclick="return confirm('Create an operator account for <?php echo htmlspecialchars(addslashes($a['full_name']), ENT_QUOTES); ?>?');">Approve</button>
            <details class="oa-reject"><summary class="ad-btn sm">Reject</summary>
                <textarea name="rejection_reason" rows="2" maxlength="500" placeholder="Reason shown to the applicant"></textarea>
                <button type="submit" name="reject" value="1" class="ad-btn sm">Send rejection</button>
            </details>
        </form>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>

</main>

<script>
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
    <a href="manage_operators.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-users" aria-hidden="true"></i><span>Operators</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/password-toggle.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
