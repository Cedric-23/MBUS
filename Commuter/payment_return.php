<?php
// Where GCash sends the commuter back. The ticket is issued only after PayMongo confirms the payment
// (this page checks with PayMongo directly; the webhook does the same in the background).
session_start();
include "../config/db_connect.php";
include "../Includes/paymongo.php";
include "../Includes/payment_finalize.php";
include_once "../Includes/gcash_manual.php";
include_once "../Includes/csrf.php";

if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit(); }
$user_id = (int)$_SESSION['user_id'];
$schedule_id = (int)($_GET['schedule_id'] ?? 0);

$st = $conn->pdo->prepare("SELECT session_id, status, reject_reason, amount, submitted_at, reference_number FROM paymongo_checkout WHERE user_id = ? AND schedule_id = ? ORDER BY created_at DESC LIMIT 1");
$st->execute([$user_id, $schedule_id]);
$co = $st->fetch(PDO::FETCH_ASSOC);
if(!$co){ header("Location: my_reservations.php"); exit(); }

$refError = '';
$refSent = false;
if($co['status'] === 'Submitted' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_ref'])){
    $ref = preg_replace('/\D/', '', (string)($_POST['ref'] ?? ''));
    if(!csrf_validate_token()){
        $refError = 'Security check failed. Please try again.';
    } elseif(strlen($ref) < 10 || strlen($ref) > 16){
        $refError = 'Enter the 13-digit reference number from your GCash receipt.';
    } else {
        $dup = $conn->pdo->prepare("SELECT 1 FROM paymongo_checkout WHERE reference_number = ? AND session_id <> ? AND status IN ('Paid','Submitted') LIMIT 1");
        $dup->execute([$ref, $co['session_id']]);
        if($dup->fetchColumn()){
            $refError = 'That reference number was already used.';
        } else {
            $shot = null;
            if(isset($_FILES['shot']) && $_FILES['shot']['error'] !== UPLOAD_ERR_NO_FILE){
                $shotErr = '';
                $shot = mbus_receipt_upload('shot', $shotErr);
                if(!$shot) $refError = $shotErr;
            }
            if($refError === ''){
                // If the SMS from the operator's phone already recorded this payment, it is confirmed right now.
                $claim = $conn->pdo->prepare("UPDATE incoming_payments SET used_by = ? WHERE id = (
                        SELECT id FROM incoming_payments WHERE used_by IS NULL AND REPLACE(reference_number, ' ', '') = ? AND ABS(amount - ?) < 0.005
                        LIMIT 1 FOR UPDATE SKIP LOCKED) RETURNING id");
                $claim->execute([$co['session_id'], $ref, $co['amount']]);
                $conn->pdo->prepare("UPDATE paymongo_checkout SET reference_number = ?" . ($shot ? ", receipt_b64 = ?, receipt_mime = ?" : "") . " WHERE session_id = ?")
                    ->execute($shot ? [$ref, $shot['b64'], $shot['mime'], $co['session_id']] : [$ref, $co['session_id']]);
                if($claim->fetchColumn()){
                    $res = mbus_finalize_payment($conn, $co['session_id'], $ref);
                    if(!empty($res['ok'])){ header("Location: ticket.php?schedule_id=" . $schedule_id); exit(); }
                    $conn->pdo->prepare("UPDATE incoming_payments SET used_by = NULL WHERE used_by = ?")->execute([$co['session_id']]);
                }
                header("Location: payment_return.php?schedule_id=" . $schedule_id); exit();
            }
        }
    }
}

$message = '';
$state = 'waiting';
if($co['status'] === 'Paid'){
    $state = 'paid';
} elseif($co['status'] === 'Created' && paymongo_enabled()){
    try{
        $payRef = paymongo_session_paid($co['session_id']);
        if($payRef !== null){
            $res = mbus_finalize_payment($conn, $co['session_id'], $payRef);
            if(!empty($res['ok'])) $state = 'paid';
            elseif(!empty($res['refund'])){ $state = 'refund'; $message = $res['message']; }
        }
    } catch(Throwable $e){
        error_log('Payment return check failed: ' . $e->getMessage());
        $message = 'We could not reach PayMongo just now. This page will keep trying.';
    }
} elseif($co['status'] === 'Submitted'){
    $m = mbus_try_autoapprove($conn, $co['session_id']);
    $state = !empty($m['matched']) ? 'paid' : 'review';
} elseif($co['status'] === 'Expired'){
    $state = 'expired';
} elseif($co['status'] === 'Rejected'){
    $state = 'rejected';
    $message = trim((string)$co['reject_reason']);
} elseif($co['status'] === 'Needs refund'){
    $state = 'refund';
    $message = 'Your payment arrived after the seat hold expired and a seat was taken. It will be refunded.';
}
if(isset($_GET['json'])){ header('Content-Type: application/json'); echo json_encode(['state' => $state]); exit(); }
if($state === 'paid'){ header("Location: ticket.php?schedule_id=" . $schedule_id); exit(); }
$gName = mbus_setting($conn, 'gcash_name'); $gNum = mbus_setting($conn, 'gcash_number'); $gQr = mbus_setting($conn, 'gcash_qr');
$until = $co['submitted_at'] ? date('g:i A', strtotime($co['submitted_at']) + 15 * 60) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirming payment — MBUS</title>
<?php if($state === 'waiting'): ?><meta http-equiv="refresh" content="4"><?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6fa;font-family:Inter,system-ui,sans-serif;color:#172b4d}
.box{width:min(380px,calc(100vw - 32px));padding:28px 24px;border-radius:20px;background:#fff;box-shadow:0 8px 30px rgba(23,43,77,.12);text-align:center}
.spin{width:38px;height:38px;margin:0 auto 16px;border:4px solid #dbe5f1;border-top-color:#1e3a5f;border-radius:50%;animation:s 1s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
h1{margin:0 0 8px;font-size:1.15rem}p{margin:0;color:#5b6b7e;font-size:.92rem;line-height:1.5}
a{display:inline-flex;align-items:center;justify-content:center;min-height:46px;margin-top:18px;padding:0 20px;border-radius:13px;background:#1e3a5f;color:#fff;font-weight:700;text-decoration:none}
p.lbl{margin:0;font-size:.82rem}.amt{margin:2px 0 6px;font-size:2.1rem;font-weight:700;color:#1e3a5f;letter-spacing:-.5px}
.hint{font-size:.8rem;margin-top:8px}.qr{display:block;width:min(220px,70%);margin:14px auto 6px;border-radius:12px;border:1px solid #e1e7ef}
.acct{margin:10px 0;display:grid;gap:2px}.acct strong{font-size:.98rem}.acct span{font-size:1.05rem;letter-spacing:.5px}
.row{display:flex;gap:8px;margin-top:6px}.cp{flex:1;min-height:44px;border:1.5px solid #1e3a5f;border-radius:12px;background:#fff;color:#1e3a5f;font:700 .9rem Inter,system-ui,sans-serif;cursor:pointer}
.wait{display:flex;align-items:center;gap:10px;margin-top:16px;padding:12px;border-radius:12px;background:#f1f5fa;text-align:left;font-size:.85rem;color:#33445c}.spin.sm{width:20px;height:20px;margin:0;border-width:3px;flex:none}
a.alt{background:none;color:#5b6b7e;font-weight:600;min-height:40px;margin-top:6px}
details.ref{margin-top:12px;text-align:left;border:1px solid #e1e7ef;border-radius:12px;padding:0 12px}
details.ref summary{min-height:44px;display:flex;align-items:center;font-weight:600;font-size:.86rem;color:#33445c;cursor:pointer}
.refform{display:grid;gap:8px;padding-bottom:12px}.refform input[type=text]{min-height:44px;padding:0 12px;border:1.5px solid #cfd8e6;border-radius:12px;font:inherit;font-size:.95rem}
.refform .shot{font-size:.82rem;color:#5b6b7e;display:grid;gap:4px}.refform .shot i{display:none}.refform input[type=file]{font-size:.8rem}
.refform .cp{flex:none}details.ref .sent{margin:0 0 8px;font-size:.84rem;color:#1a7f37}details.ref .err{margin:0 0 8px;font-size:.84rem;color:#b42318}
@media(prefers-reduced-motion:reduce){.spin{animation:none}}
</style>
</head>
<body>
<main class="box" role="status" aria-live="polite">
<?php if($state === 'review'): ?>
    <p class="lbl">Send exactly</p>
    <p class="amt">&#8369;<?php echo number_format((float)$co['amount'], 2); ?></p>
    <p class="hint">Use this exact amount, centavos included. It&rsquo;s how we know the payment is yours.</p>
    <?php if($gQr !== '' && strpos($gQr, 'data:image/') === 0): ?><img class="qr" src="<?php echo htmlspecialchars($gQr, ENT_QUOTES); ?>" alt="GCash QR code"><?php endif; ?>
    <div class="acct"><strong><?php echo htmlspecialchars($gName); ?></strong><span><?php echo htmlspecialchars($gNum); ?></span></div>
    <div class="row">
        <button type="button" class="cp" data-copy="<?php echo htmlspecialchars($gNum); ?>">Copy number</button>
        <button type="button" class="cp" data-copy="<?php echo number_format((float)$co['amount'], 2, '.', ''); ?>">Copy amount</button>
    </div>
    <div class="wait"><div class="spin sm" aria-hidden="true"></div><span id="waitMsg">Waiting for your payment. Your ticket appears here automatically.</span></div>
    <details class="ref"<?php echo ($refError !== '') ? ' open' : ''; ?>>
        <summary>Paid already? Enter reference no.</summary>
        <?php if(!empty($co['reference_number'])): ?><p class="sent"><strong>Sent.</strong> An operator will confirm reference <?php echo htmlspecialchars($co['reference_number']); ?> shortly.</p><?php endif; ?>
        <?php if($refError !== ''): ?><p class="err" role="alert"><?php echo htmlspecialchars($refError); ?></p><?php endif; ?>
        <form method="POST" enctype="multipart/form-data" class="refform">
            <?php csrf_field(); ?>
            <input type="text" name="ref" inputmode="numeric" autocomplete="off" maxlength="20" placeholder="13-digit reference no." value="<?php echo htmlspecialchars((string)($co['reference_number'] ?? '')); ?>" aria-label="GCash reference number" required>
            <label class="shot"><i></i>Add screenshot (optional)<input type="file" name="shot" accept="image/jpeg,image/png"></label>
            <button type="submit" name="send_ref" value="1" class="cp">Submit</button>
        </form>
    </details>
    <p class="hint">Seats held until <?php echo htmlspecialchars($until); ?>.</p>
    <a href="my_reservations.php" class="alt">Back to my trips</a>
<?php elseif($state === 'expired'): ?>
    <h1>Payment window ended</h1>
    <p>We didn&rsquo;t receive a matching payment in time. If you already paid, show your GCash receipt to an operator. Otherwise, start again.</p>
    <a href="payment.php?schedule_id=<?php echo $schedule_id; ?>">Try again</a>
<?php elseif($state === 'rejected'): ?>
    <h1>Payment not accepted</h1>
    <p><?php echo $message !== '' ? htmlspecialchars($message) : 'The receipt could not be verified.'; ?> Your seats were released. Please reserve again.</p>
    <a href="schedule.php">Back to schedules</a>
<?php elseif($state === 'refund'): ?>
    <h1>Payment needs a refund</h1>
    <p><?php echo htmlspecialchars($message); ?></p>
    <a href="schedule.php">Back to schedules</a>
<?php else: ?>
    <div class="spin" aria-hidden="true"></div>
    <h1>Confirming your payment…</h1>
    <p><?php echo $message !== '' ? htmlspecialchars($message) : 'Waiting for GCash to confirm. This usually takes a few seconds.'; ?></p>
    <a href="payment.php?schedule_id=<?php echo $schedule_id; ?>">Back to payment</a>
<?php endif; ?>
</main>
<?php if($state === 'review'): ?>
<script>
document.querySelectorAll('.cp').forEach(function(b){b.addEventListener('click',function(){
    var t=b.dataset.copy,l=b.textContent;
    (navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).catch(function(){var i=document.createElement('input');i.value=t;document.body.appendChild(i);i.select();document.execCommand('copy');i.remove();});
    b.textContent='Copied';setTimeout(function(){b.textContent=l;},1500);});});
(function poll(){
    fetch('payment_return.php?schedule_id=<?php echo $schedule_id; ?>&json=1',{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){
        if(d.state==='paid'){location.href='ticket.php?schedule_id=<?php echo $schedule_id; ?>';return;}
        if(d.state!=='review'){location.reload();return;}
        setTimeout(poll,4000);
    }).catch(function(){setTimeout(poll,6000);});
})();
</script>
<?php endif; ?>
</body>
</html>
