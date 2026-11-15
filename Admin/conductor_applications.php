<?php
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'admin') { header('Location: ../login.php'); exit(); }
include "../config/db_connect.php";
include "../Includes/csrf.php";
include_once "../Includes/email_helper.php";
$flash = $_SESSION['ca_flash'] ?? ''; unset($_SESSION['ca_flash']); $error = '';
$admin_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['application_id'] ?? 0);
    if (!csrf_validate_token()) { $error = 'Security validation failed. Please try again.'; }
    else {
        $pdo = $conn->pdo;
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM conductor_application WHERE application_id = ? AND application_status = 'Pending' FOR UPDATE");
            $st->execute([$id]); $app = $st->fetch(PDO::FETCH_ASSOC);
            if (!$app) throw new RuntimeException('That application was already reviewed.');
            if (isset($_POST['approve'])) {
                $st = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)"); $st->execute([$app['email']]);
                if ($st->fetchColumn()) throw new RuntimeException('An account with this email already exists.');
                $st = $pdo->prepare("INSERT INTO users (full_name, email, phone_number, password, user_type) VALUES (?, ?, ?, ?, 'Conductor') RETURNING user_id");
                $st->execute([$app['full_name'], $app['email'], $app['phone_number'], $app['password_hash']]);
                $uid = (int)$st->fetchColumn();
                $pdo->prepare("UPDATE conductor_application SET application_status='Approved', user_id=?, reviewed_at=NOW(), reviewed_by=? WHERE application_id=?")->execute([$uid, $admin_id, $id]);
                $pdo->commit();
                @send_email_notification($app['email'], 'Your MBUS conductor account', '<p>Hi ' . htmlspecialchars($app['full_name']) . ', your conductor account is approved. Log in with the password you chose.</p>');
                $_SESSION['ca_flash'] = 'Approved ' . $app['full_name'] . '. They can log in now.';
            } else {
                $reason = trim((string)($_POST['rejection_reason'] ?? ''));
                if ($reason === '' || strlen($reason) > 500) throw new RuntimeException('Give a short reason (up to 500 characters) when rejecting.');
                $pdo->prepare("UPDATE conductor_application SET application_status='Rejected', reviewed_at=NOW(), reviewed_by=?, rejection_reason=? WHERE application_id=?")->execute([$admin_id, $reason, $id]);
                $pdo->commit();
                $_SESSION['ca_flash'] = 'Rejected ' . $app['full_name'] . '.';
            }
            header('Location: conductor_applications.php'); exit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the review. Please try again.';
        }
    }
}
$rows = []; $q = mbus_db_prepare($conn, "SELECT application_id, full_name, email, phone_number, application_status, submitted_at, rejection_reason FROM conductor_application ORDER BY (application_status='Pending') DESC, submitted_at DESC LIMIT 100", []);
while ($q !== false && ($r = mbus_db_fetch_assoc($q))) $rows[] = $r;
$h = fn($v) => htmlspecialchars((string)$v);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Conductor applications - MBUS</title>
<style>body{font-family:Inter,system-ui,sans-serif;background:#f5f7fa;margin:0;padding:20px;color:#101828}.w{max-width:760px;margin:auto}h1{color:#0a2540;font-size:1.3rem}
.card{background:#fff;border-radius:12px;padding:16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(16,24,40,.1)}.m{color:#667085;font-size:.85rem}
.b{padding:8px 14px;border:0;border-radius:8px;font:inherit;font-weight:600;cursor:pointer;color:#fff;background:#1a7f37}.r{background:#d92d20}input[type=text]{padding:8px;border:1px solid #d0d5dd;border-radius:8px;width:60%}
.ok{background:#e7f6ec;color:#1a7f37;padding:10px;border-radius:8px;margin-bottom:12px}.er{background:#fdecea;color:#b42318;padding:10px;border-radius:8px;margin-bottom:12px}a{color:#086FCF}</style></head>
<body><div class="w"><p><a href="manage_operators.php">&larr; Manage operators</a></p><h1>Conductor applications</h1>
<?php if ($flash): ?><div class="ok"><?php echo $h($flash); ?></div><?php endif; ?><?php if ($error): ?><div class="er"><?php echo $h($error); ?></div><?php endif; ?>
<?php if (!$rows): ?><p class="m">No conductor applications yet.</p><?php endif; ?>
<?php foreach ($rows as $r): ?><div class="card"><strong><?php echo $h($r['full_name']); ?></strong> <span class="m">&middot; <?php echo $h($r['application_status']); ?></span>
<div class="m"><?php echo $h($r['email']); ?> &middot; <?php echo $h($r['phone_number']); ?> &middot; <?php echo $h(substr((string)$r['submitted_at'], 0, 16)); ?></div>
<?php if ($r['rejection_reason']): ?><div class="m">Reason: <?php echo $h($r['rejection_reason']); ?></div><?php endif; ?>
<?php if ($r['application_status'] === 'Pending'): ?><form method="POST" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><?php csrf_field(); ?><input type="hidden" name="application_id" value="<?php echo (int)$r['application_id']; ?>">
<button class="b" name="approve" value="1">Approve</button><input type="text" name="rejection_reason" maxlength="500" placeholder="Reason (required to reject)"><button class="b r" name="reject" value="1">Reject</button></form><?php endif; ?></div>
<?php endforeach; ?></div></body></html>
