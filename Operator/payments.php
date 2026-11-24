<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/mysqli_compat.php";
require_once "../Includes/csrf.php";
require_once "../Includes/fare.php";
require_once "../Includes/payment_finalize.php";
require_once "../Includes/notify.php";
require_once "../Includes/gcash_manual.php";
require_once "../Includes/staff_assignment_scope.php";
require_once "../Includes/staff_nav.php";

/* Conductor: check and approve (or reject) the GCash payments of commuters on the assigned buses.
 * The GCash account itself is set up by the Driver (gcash_account.php). */
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_type'] ?? '')) !== 'conductor') {
    http_response_code(403);
    exit('GCash payment review is available to assigned Conductors only.');
}

$operator_id = (int)$_SESSION['user_id'];
$assignment = mbus_staff_assignment_condition('conductor', 'assigned_schedule.bus_id');
$assignmentForDuplicates = mbus_staff_assignment_condition('conductor', 'duplicate_schedule.bus_id');
$success = $_SESSION['conductor_pay_flash'] ?? '';
$error = '';
unset($_SESSION['conductor_pay_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_token()) {
        $error = 'Security check failed. Please try again.';
    } elseif (isset($_POST['approve']) || isset($_POST['reject'])) {
        $sid = (string)($_POST['session_id'] ?? '');
        $st = $conn->pdo->prepare("SELECT c.* FROM paymongo_checkout c WHERE c.session_id = ? AND c.status = 'Submitted' AND c.session_id LIKE 'manual\\_%'
            AND EXISTS (SELECT 1 FROM schedule assigned_schedule WHERE assigned_schedule.schedule_id = c.schedule_id AND $assignment)");
        $st->execute([$sid, $operator_id]);
        $co = $st->fetch(PDO::FETCH_ASSOC);
        if (!$co) {
            $error = 'That payment was already handled.';
        } elseif (isset($_POST['approve'])) {
            $res = mbus_finalize_payment($conn, $sid, (string)($co['reference_number'] ?: 'OP-' . substr($sid, 7, 8)));
            if (!empty($res['ok'])) {
                $conn->pdo->prepare("UPDATE paymongo_checkout SET reviewed_by = ?, reviewed_at = NOW() WHERE session_id = ?")->execute([$operator_id, $sid]);
                $_SESSION['conductor_pay_flash'] = 'Approved. Ticket ' . ($res['ticket'] ?? '') . ' issued.';
                header('Location: payments.php'); exit();
            }
            $error = $res['message'] ?? 'Could not approve this payment.';
        } else {
            $reason = trim((string)($_POST['reject_reason'] ?? ''));
            if ($reason === '' || strlen($reason) > 300) {
                $error = 'Add a short reason (up to 300 characters).';
            } else {
                $ids = json_decode((string)$co['reservation_ids'], true) ?: [];
                $conn->pdo->beginTransaction();
                $conn->pdo->prepare("UPDATE paymongo_checkout SET status = 'Rejected', reject_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE session_id = ?")->execute([$reason, $operator_id, $sid]);
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $conn->pdo->prepare("UPDATE reservation SET status = 'Cancelled' WHERE status = 'Pending' AND user_id = ? AND reservation_id IN ($in)")->execute(array_merge([(int)$co['user_id']], $ids));
                }
                $conn->pdo->commit();
                mbus_notify($conn, (int)$co['user_id'], 'Payment rejected: ' . $reason . ' Your seats were released.');
                $_SESSION['conductor_pay_flash'] = 'Rejected. Seats released.';
                header('Location: payments.php?status=rejected'); exit();
            }
        }
    }
}

mbus_sweep_open_payments($conn);

$opts = ['submitted' => 'To check', 'paid' => 'Approved', 'rejected' => 'Rejected'];
$f = $_GET['status'] ?? 'submitted';
if (!isset($opts[$f])) $f = 'submitted';

$counts = array_fill_keys(array_keys($opts), 0);
$cq = mbus_db_prepare($conn, "SELECT LOWER(c.status) AS s, COUNT(*) AS c FROM paymongo_checkout c
    WHERE c.session_id LIKE 'manual\\_%' AND EXISTS (SELECT 1 FROM schedule assigned_schedule
        WHERE assigned_schedule.schedule_id = c.schedule_id AND $assignment) GROUP BY 1", [$operator_id]);
while ($cq !== false && ($r = mbus_db_fetch_assoc($cq))) if (isset($counts[$r['s']])) $counts[$r['s']] = (int)$r['c'];

$rows = [];
$q = mbus_db_prepare($conn, "SELECT (c.receipt_b64 IS NOT NULL AND c.receipt_b64 <> '') AS has_receipt, c.session_id, c.amount, c.reference_number, c.submitted_at, c.status, c.reject_reason, c.lines, u.full_name,
    (SELECT COUNT(*) FROM paymongo_checkout d WHERE d.reference_number = c.reference_number AND d.session_id <> c.session_id
        AND EXISTS (SELECT 1 FROM schedule duplicate_schedule WHERE duplicate_schedule.schedule_id = d.schedule_id AND $assignmentForDuplicates)) AS dups,
    (SELECT r.origin || ' → ' || r.destination FROM schedule s JOIN routes r ON r.route_id = s.route_id WHERE s.schedule_id = c.schedule_id) AS route,
    (SELECT s.departure_time FROM schedule s WHERE s.schedule_id = c.schedule_id) AS departure
    FROM paymongo_checkout c JOIN users u ON u.user_id = c.user_id
    WHERE c.session_id LIKE 'manual\\_%' AND LOWER(c.status) = ?
      AND EXISTS (SELECT 1 FROM schedule assigned_schedule WHERE assigned_schedule.schedule_id = c.schedule_id AND $assignment)
    ORDER BY c.submitted_at DESC LIMIT 100", [$operator_id, $f, $operator_id]);
while ($q !== false && ($r = mbus_db_fetch_assoc($q))) $rows[] = $r;

$gNum = mbus_setting($conn, 'gcash_number');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
    <title>GCash payments — MBUS Conductor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/operator_payments.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/operator_payments.css'); ?>">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
</head>
<body class="stf has-sidebar has-bottom-nav pay-page">

<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="logo-container"><img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo"></div>
    <h2>Conductor</h2>
    <?php echo mbus_staff_sidebar_nav('conductor', ''); ?>
    <div class="menu-bottom">
        <a href="../logout.php" class="logout-btn btn btn-outline btn-block"><i class="fa fa-sign-out" aria-hidden="true"></i> Logout</a>
    </div>
</aside>

<main class="main" role="main">
<header class="page-header">
    <h1>GCash payments</h1>
    <div class="page-header-right">
        <div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
            </button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header">
                    <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                    <span class="profile-menu-role">Conductor account</span>
                </div>
                <?php echo mbus_staff_profile_links('conductor', 'payments'); ?>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div>
    </div>
</header>

<div class="pay-wrap">

<?php if ($success !== ''): ?><div class="pay-alert ok" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="pay-alert bad" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<?php if ($gNum === ''): ?>
<div class="pay-alert warn" role="status"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i> No GCash account is set up yet. The driver sets it up from their account, then an admin approves it.</div>
<?php endif; ?>

<nav class="pay-tabs" aria-label="Payment status">
    <?php foreach ($opts as $k => $label): ?>
    <a href="?status=<?php echo $k; ?>" class="<?php echo $f === $k ? 'on' : ''; ?>"><?php echo $label; ?><span><?php echo $counts[$k]; ?></span></a>
    <?php endforeach; ?>
</nav>

<?php if (!$rows): ?>
<p class="pay-empty"><i class="fa fa-circle-check" aria-hidden="true"></i><?php echo $f === 'submitted' ? 'Nothing to check.' : 'Nothing here.'; ?></p>
<?php endif; ?>

<div class="pay-list">
<?php foreach ($rows as $c):
    $seats = array_map(fn($l) => $l['seat'], json_decode((string)$c['lines'], true) ?: []);
    $isOpen = strtolower($c['status']) === 'submitted';
?>
    <article class="pay-card">
        <div class="pay-top">
            <div>
                <strong><?php echo htmlspecialchars($c['full_name']); ?></strong>
                <small><?php echo htmlspecialchars((string)$c['route']); ?><?php echo $c['departure'] ? ' · ' . date('M j, g:i A', strtotime($c['departure'])) : ''; ?></small>
            </div>
            <span class="pay-amt">&#8369;<?php echo number_format((float)$c['amount'], 2); ?></span>
        </div>
        <div class="pay-meta">
            <span><i class="fa fa-chair" aria-hidden="true"></i> <?php echo htmlspecialchars(implode(', ', $seats)); ?></span>
            <span><i class="fa fa-hashtag" aria-hidden="true"></i> <?php echo $c['reference_number'] ? htmlspecialchars((string)$c['reference_number']) : 'No reference yet'; ?></span>
            <?php if ((int)$c['dups'] > 0): ?><span class="pay-flag"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i> Reference used before</span><?php endif; ?>
            <?php if (!empty($c['has_receipt'])): ?><a href="payment_receipt.php?id=<?php echo urlencode($c['session_id']); ?>" target="_blank" rel="noopener"><i class="fa fa-image" aria-hidden="true"></i> Screenshot</a><?php endif; ?>
        </div>
        <?php if (strtolower($c['status']) === 'rejected' && $c['reject_reason']): ?><p class="pay-reason"><?php echo htmlspecialchars($c['reject_reason']); ?></p><?php endif; ?>
        <?php if ($isOpen): ?>
        <form method="POST" class="pay-actions">
            <?php csrf_field(); ?>
            <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($c['session_id']); ?>">
            <button type="submit" name="approve" value="1" class="pay-btn primary" onclick="return confirm('Did &#8369;<?php echo number_format((float)$c['amount'], 2); ?> arrive in your GCash?');"><i class="fa fa-check" aria-hidden="true"></i> Approve</button>
            <details class="pay-reject">
                <summary class="pay-btn">Reject</summary>
                <textarea name="reject_reason" rows="2" maxlength="300" placeholder="Reason for the commuter"></textarea>
                <button type="submit" name="reject" value="1" class="pay-btn danger">Reject and release seats</button>
            </details>
        </form>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>

</div>
</main>

<?php echo mbus_staff_bottom_nav('conductor', ''); ?>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
