<?php
session_start();
if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != "admin"){
    header("Location: ../login.php");
    exit();
}
include "../config/db_connect.php";
include "../Includes/csrf.php";
include "../Includes/activity_log.php";

$error = ""; $success = "";
$status_opts = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$type_opts = ['Student', 'Senior', 'PWD'];

/* ---------- Review (single or bulk) ---------- */
if(isset($_POST['review'])){
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => $i > 0)));
    $decision = $_POST['review'] === 'approve' ? 'Approved' : 'Rejected';
    $reason = trim((string)($_POST['rejection_reason'] ?? ''));
    if(!csrf_validate_token()){
        $_SESSION['vf_flash'] = ['error', "Security validation failed. Please try again."];
    } elseif(!$ids){
        $_SESSION['vf_flash'] = ['error', "Select at least one registrant."];
    } elseif($ids && count($ids) > 500){
        $_SESSION['vf_flash'] = ['error', "Please review at most 500 registrants at a time."];
    } elseif($decision === 'Rejected' && $reason === ''){
        $_SESSION['vf_flash'] = ['error', "Add a short reason so the commuter knows what to fix."];
    } else {
        $done = 0; $skipped_expired = 0; $skipped_done = 0;
        foreach($ids as $vid){
            $q = mbus_db_prepare($conn, "SELECT user_id, verification_type, expiry_date FROM commuter_verification WHERE verification_id = ? AND verification_status = 'Pending'", [$vid]);
            $v = $q === false ? false : mbus_db_fetch_assoc($q);
            if(!$v){ $skipped_done++; continue; }
            if($decision === 'Approved' && !empty($v['expiry_date']) && $v['expiry_date'] < date('Y-m-d')){ $skipped_expired++; continue; }
            mbus_db_prepare($conn, "UPDATE commuter_verification SET verification_status = ?, reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ? WHERE verification_id = ?",
                [$decision, (int)$_SESSION['user_id'], $decision === 'Rejected' ? $reason : null, $vid]);
            if($decision === 'Approved'){
                mbus_db_prepare($conn, "UPDATE users SET verification_status = 'Approved', verification_type = ? WHERE user_id = ?", [$v['verification_type'], (int)$v['user_id']]);
            } else {
                mbus_db_prepare($conn, "UPDATE users SET verification_status = 'Rejected', verification_type = NULL WHERE user_id = ?", [(int)$v['user_id']]);
            }
            $done++;
        }
        if($done){
            log_activity($conn, (int)$_SESSION['user_id'], $decision === 'Approved' ? ACTION_VERIFICATION_APPROVE : ACTION_VERIFICATION_REJECT, "$decision $done verification(s): " . implode(',', array_slice($ids, 0, 50)));
        }
        $m = "$done " . strtolower($decision);
        if($skipped_expired) $m .= ", $skipped_expired skipped (ID expired: reject or ask for a new ID)";
        if($skipped_done) $m .= ", $skipped_done already reviewed";
        $_SESSION['vf_flash'] = [$done ? 'success' : 'error', $m . "."];
    }
    header("Location: verifications.php?" . http_build_query(array_filter(['status' => $_POST['f_status'] ?? '', 'type' => $_POST['f_type'] ?? '', 'q' => $_POST['f_q'] ?? '', 'page' => $_POST['f_page'] ?? ''], fn($x) => $x !== '' && $x !== null)));
    exit();
}
if(!empty($_SESSION['vf_flash'])){
    [$kind, $text] = $_SESSION['vf_flash']; unset($_SESSION['vf_flash']);
    if($kind === 'success') $success = $text; else $error = $text;
}

/* ---------- Filters ---------- */
$f_status = strtolower((string)($_GET['status'] ?? 'pending'));
if($f_status !== 'all' && !isset($status_opts[$f_status])) $f_status = 'pending';
$f_type = in_array($_GET['type'] ?? '', $type_opts, true) ? $_GET['type'] : '';
$f_q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if($f_status !== 'all'){ $where[] = "v.verification_status = ?"; $params[] = $status_opts[$f_status]; }
if($f_type !== ''){ $where[] = "v.verification_type = ?"; $params[] = $f_type; }
if($f_q !== ''){
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr($f_q, 0, 80)) . '%';
    $where[] = "(u.full_name ILIKE ? OR u.email ILIKE ? OR COALESCE(v.document_number,'') ILIKE ?)";
    array_push($params, $like, $like, $like);
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$order = $f_status === 'pending' ? 'v.submitted_at ASC' : 'COALESCE(v.reviewed_at, v.submitted_at) DESC';
$base = "FROM commuter_verification v JOIN users u ON u.user_id = v.user_id $where_sql";

/* CSV export of the current filter */
if(isset($_GET['export'])){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="verifications-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Type', 'ID number', 'ID expiry', 'Submitted', 'Status', 'Reviewed', 'Reason']);
    $safe = fn($x) => (is_string($x) && $x !== '' && strpos("=+-@\t\r", $x[0]) !== false) ? "'" . $x : $x;
    $q = mbus_db_prepare($conn, "SELECT u.full_name, u.email, v.verification_type, v.document_number, v.expiry_date, v.submitted_at, v.verification_status, v.reviewed_at, v.rejection_reason $base ORDER BY $order", $params);
    while($q !== false && ($r = mbus_db_fetch_assoc($q))){ fputcsv($out, array_map($safe, array_values($r))); }
    exit();
}

/* Counts for the tabs */
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$q = mbus_db_prepare($conn, "SELECT LOWER(verification_status::text) AS s, COUNT(*) AS c FROM commuter_verification GROUP BY 1", []);
while($q !== false && ($r = mbus_db_fetch_assoc($q))){ if(isset($counts[$r['s']])) $counts[$r['s']] = (int)$r['c']; }

/* Page of rows */
$per = 25;
$q = mbus_db_prepare($conn, "SELECT COUNT(*) AS c $base", $params);
$row = $q === false ? false : mbus_db_fetch_assoc($q);
$total = $row ? (int)$row['c'] : 0;
$pages = max(1, (int)ceil($total / $per));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$rows = [];
$q = mbus_db_prepare($conn, "SELECT v.verification_id, v.verification_type, v.document_number, v.expiry_date, v.document_mime, v.submitted_at,
        v.verification_status, v.reviewed_at, v.rejection_reason, u.full_name, u.email
    $base ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $params);
while($q !== false && ($r = mbus_db_fetch_assoc($q))) $rows[] = $r;
$qs = fn(array $over = []) => '?' . http_build_query(array_filter(array_merge(['status' => $f_status, 'type' => $f_type, 'q' => $f_q], $over), fn($x) => $x !== '' && $x !== null));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifications - MBUS</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_operators.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
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
    <h1>Verifications</h1>

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

<?php if($success !== ''): ?><div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<nav class="ad-seg vf-tabs" aria-label="Status">
    <?php foreach($status_opts as $k => $label): ?>
    <a href="<?php echo $qs(['status' => $k, 'page' => '']); ?>" class="<?php echo $f_status === $k ? 'on' : ''; ?>"><?php echo $label; ?> <span class="vf-count"><?php echo number_format($counts[$k]); ?></span></a>
    <?php endforeach; ?>
    <a href="<?php echo $qs(['status' => 'all', 'page' => '']); ?>" class="<?php echo $f_status === 'all' ? 'on' : ''; ?>">All <span class="vf-count"><?php echo number_format(array_sum($counts)); ?></span></a>
</nav>

<form method="GET" class="vf-filters" role="search">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($f_status); ?>">
    <input type="search" name="q" value="<?php echo htmlspecialchars($f_q); ?>" placeholder="Name, email or ID number" aria-label="Search registrants">
    <select name="type" aria-label="ID type" onchange="this.form.submit()">
        <option value="">All ID types</option>
        <?php foreach($type_opts as $t): ?><option <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?>
    </select>
    <button class="ad-btn" type="submit">Search</button>
    <a class="ad-btn" href="<?php echo $qs(['export' => '1']); ?>" download><i class="fa fa-download" aria-hidden="true"></i> Export CSV</a>
</form>

<form method="POST" id="vfForm">
    <?php csrf_field(); ?>
    <input type="hidden" name="f_status" value="<?php echo htmlspecialchars($f_status); ?>">
    <input type="hidden" name="f_type" value="<?php echo htmlspecialchars($f_type); ?>">
    <input type="hidden" name="f_q" value="<?php echo htmlspecialchars($f_q); ?>">
    <input type="hidden" name="f_page" value="<?php echo (int)$page; ?>">
    <input type="hidden" name="rejection_reason" id="vfReason">

    <div class="vf-bulk" id="vfBulk" hidden>
        <span><b id="vfSel">0</b> selected</span>
        <span class="vf-bulk-actions">
            <button type="submit" name="review" value="approve" class="ad-btn sm primary">Approve selected</button>
            <button type="button" class="ad-btn sm" id="vfRejectBtn">Reject selected</button>
        </span>
    </div>

    <?php if(!$rows): ?>
    <div class="ad-empty"><i class="fa fa-id-card" aria-hidden="true"></i><p>No registrants match this view.</p></div>
    <?php else: ?>
    <div class="ad-table vf-table">
        <div class="ad-th vf-row"><span><input type="checkbox" id="vfAll" aria-label="Select all on this page"></span><span>Registrant</span><span>ID type</span><span>ID number</span><span>Expiry</span><span>Submitted</span><span>Status</span><span></span></div>
        <?php foreach($rows as $r):
            $vid = (int)$r['verification_id'];
            $exp = $r['expiry_date'] ?: null;
            $expired = $exp && $exp < date('Y-m-d');
            $soon = $exp && !$expired && $exp <= date('Y-m-d', strtotime('+30 days'));
            $isPending = $r['verification_status'] === 'Pending';
            $data = ['id' => $vid, 'name' => $r['full_name'], 'email' => $r['email'], 'type' => $r['verification_type'], 'number' => $r['document_number'] ?: '—',
                'expiry' => $exp ? date('M j, Y', strtotime($exp)) : 'No expiry', 'expired' => (bool)$expired, 'status' => $r['verification_status'],
                'mime' => $r['document_mime'], 'reason' => $r['rejection_reason'], 'pending' => $isPending, 'submitted' => date('M j, Y g:i A', strtotime($r['submitted_at']))];
        ?>
        <div class="ad-tr vf-row">
            <div class="ad-c c-chk"><?php if($isPending): ?><input type="checkbox" name="ids[]" value="<?php echo $vid; ?>" class="vf-check" aria-label="Select <?php echo htmlspecialchars($r['full_name'], ENT_QUOTES); ?>"><?php endif; ?></div>
            <div class="ad-c person c-person"><span class="ad-avatar"><?php echo htmlspecialchars(strtoupper(mb_substr(trim((string)$r['full_name']) ?: '?', 0, 1))); ?></span><div><b><?php echo htmlspecialchars($r['full_name']); ?></b><small><?php echo htmlspecialchars($r['email']); ?></small></div></div>
            <div class="ad-c c-type"><span class="ad-pill info"><?php echo htmlspecialchars($r['verification_type']); ?></span></div>
            <div class="ad-c vf-num c-num"><?php echo htmlspecialchars($r['document_number'] ?: '—'); ?></div>
            <div class="ad-c c-exp"><?php if(!$exp): ?><span class="muted">None</span><?php else: ?><span class="vf-exp <?php echo $expired ? 'bad' : ($soon ? 'warn' : ''); ?>"><?php echo date('M j, Y', strtotime($exp)); ?><?php echo $expired ? ' · Expired' : ($soon ? ' · Soon' : ''); ?></span><?php endif; ?></div>
            <div class="ad-c c-sub"><small><?php echo date('M j, g:i A', strtotime($r['submitted_at'])); ?></small></div>
            <div class="ad-c c-status"><span class="ad-pill <?php echo $isPending ? 'amber' : ($r['verification_status'] === 'Approved' ? 'active' : 'cancelled'); ?>"><?php echo htmlspecialchars($r['verification_status']); ?></span></div>
            <div class="ad-c actions c-act"><button type="button" class="ad-btn sm" data-vf-open="<?php echo htmlspecialchars(json_encode($data), ENT_QUOTES); ?>"><?php echo $isPending ? 'Review' : 'View'; ?></button></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="vf-pager">
        <span>Showing <?php echo number_format(($page - 1) * $per + 1); ?>–<?php echo number_format(($page - 1) * $per + count($rows)); ?> of <?php echo number_format($total); ?></span>
        <span class="vf-pages">
            <?php if($page > 1): ?><a class="ad-btn sm" href="<?php echo $qs(['page' => $page - 1]); ?>">Previous</a><?php endif; ?>
            <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
            <?php if($page < $pages): ?><a class="ad-btn sm" href="<?php echo $qs(['page' => $page + 1]); ?>">Next</a><?php endif; ?>
        </span>
    </div>
    <?php endif; ?>
</form>

<!-- Review dialog -->
<dialog class="ad-dialog vf-dialog" id="vfDialog" aria-labelledby="vfTitle">
    <form method="POST" class="ad-form" id="vfOne">
        <?php csrf_field(); ?>
        <input type="hidden" name="f_status" value="<?php echo htmlspecialchars($f_status); ?>">
        <input type="hidden" name="f_type" value="<?php echo htmlspecialchars($f_type); ?>">
        <input type="hidden" name="f_q" value="<?php echo htmlspecialchars($f_q); ?>">
        <input type="hidden" name="f_page" value="<?php echo (int)$page; ?>">
        <input type="hidden" name="ids[]" id="vfId">
        <header class="ad-dialog-head"><h2 id="vfTitle">Review</h2><button type="button" class="ad-x" data-vf-close aria-label="Close"><i class="fa fa-xmark" aria-hidden="true"></i></button></header>
        <div class="ad-dialog-body">
            <dl class="vf-dl" id="vfDl"></dl>
            <div class="vf-doc" id="vfDoc"></div>
            <label class="ad-field" id="vfReasonWrap"><span>Reason (required to reject)</span><input type="text" name="rejection_reason" id="vfOneReason" maxlength="200" placeholder="e.g. ID photo is blurry"></label>
        </div>
        <footer class="ad-dialog-foot" id="vfFoot">
            <button type="button" class="ad-btn" data-vf-close>Close</button>
            <button type="submit" name="review" value="reject" class="ad-btn" id="vfRej">Reject</button>
            <button type="submit" name="review" value="approve" class="ad-btn primary" id="vfApp">Approve</button>
        </footer>
    </form>
</dialog>

<!-- Bulk reject reason -->
<dialog class="ad-dialog" id="vfBulkDialog" aria-labelledby="vfBulkTitle">
    <div class="ad-form">
        <header class="ad-dialog-head"><h2 id="vfBulkTitle">Reject selected</h2><button type="button" class="ad-x" data-vf-close aria-label="Close"><i class="fa fa-xmark" aria-hidden="true"></i></button></header>
        <div class="ad-dialog-body"><label class="ad-field"><span>Reason sent to every selected registrant</span><input type="text" id="vfBulkReason" maxlength="200" placeholder="e.g. ID not readable, please upload again"></label></div>
        <footer class="ad-dialog-foot"><button type="button" class="ad-btn" data-vf-close>Cancel</button><button type="button" class="ad-btn primary" id="vfBulkGo">Reject</button></footer>
    </div>
</dialog>
</main>
<style>
.v-tabs{display:flex;gap:.4rem;margin:0 0 .75rem}.v-tabs a{padding:.4rem .8rem;border-radius:999px;border:1px solid #e5eaf1;color:#3c4a60;text-decoration:none;font-size:.9rem}.v-tabs a.on{background:#1e3a5f;border-color:#1e3a5f;color:#fff}.v-tabs span{opacity:.75;margin-left:.2rem}
.v-h{font-size:1.0625rem;margin:20px 0 10px}.v-empty{color:var(--color-text-muted)}
.v-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
.v-item{padding:16px;border-radius:16px}.v-item-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.v-pill{padding:2px 10px;border-radius:999px;background:var(--color-primary-light-bg);color:var(--color-primary);font-weight:600;font-size:.8125rem}
.v-meta{margin:8px 0;font-size:.875rem;color:var(--color-text-muted)}
.v-img{display:block;width:100%;max-height:260px;object-fit:contain;background:#f1f5f9;border-radius:10px;margin-bottom:10px}
.v-actions{display:grid;gap:8px}.v-actions .btn{min-height:44px}
.v-history{list-style:none;padding:0;margin:0;display:grid;gap:6px}.v-history li{display:flex;justify-content:space-between;padding:10px 14px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:10px}
.v-history .ok{color:#067647}.v-history .no{color:#b42318}
</style>

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
<script src="../Assets/js/admin_verifications.js?v=<?php echo filemtime(__DIR__ . '/../Assets/js/admin_verifications.js'); ?>"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>