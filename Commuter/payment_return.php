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
// A post bigger than the server limit arrives completely empty: say so instead of silently reloading.
if($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])){
    $refError = 'That upload was too large. Try again with a smaller screenshot, or without one.';
}
// The form is recognised by its reference field (a button disabled while submitting is not sent by the browser).
if($co['status'] === 'Submitted' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ref'])){
    $ref = preg_replace('/\D/', '', (string)($_POST['ref'] ?? ''));
    if(!csrf_validate_token()){
        $refError = 'Security check failed. Please try again.';
    } elseif(strlen($ref) !== 13){
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
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
<?php if($state === 'waiting'): ?><meta http-equiv="refresh" content="4"><?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../Assets/css/notif_bell.css">
<style>
body{margin:0;min-height:100vh;display:grid;grid-template-columns:minmax(0,1fr);place-items:center;padding:24px 0;box-sizing:border-box;background:linear-gradient(180deg,#e6efff,#f5f8ff);font-family:Inter,system-ui,sans-serif;color:#172b4d}
.box{box-sizing:border-box;width:min(380px,calc(100vw - 32px));min-width:0;padding:28px 20px;border-radius:28px;background:#fff;box-shadow:0 14px 36px rgba(47,111,228,.14);text-align:center}
.spin{width:38px;height:38px;margin:0 auto 16px;border:4px solid #dbe5f1;border-top-color:#2f6fe4;border-radius:50%;animation:s 1s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
h1{margin:0 0 8px;font-size:1.15rem}p{margin:0;color:#5b6b7e;font-size:.92rem;line-height:1.5}
a{display:inline-flex;align-items:center;justify-content:center;min-height:46px;margin-top:18px;padding:0 20px;border-radius:999px;background:#2f6fe4;color:#fff;font-weight:700;text-decoration:none}
p.lbl{margin:0;font-size:.82rem}.amt{margin:2px 0 6px;font-size:2.1rem;font-weight:700;color:#2f6fe4;letter-spacing:-.5px}
.hint{font-size:.8rem;margin-top:8px}.qr{display:block;width:min(220px,70%);margin:14px auto 6px;border-radius:12px;border:1px solid #e1e7ef}
.acct{margin:10px 0;display:grid;gap:2px}.acct strong{font-size:.98rem}.acct span{font-size:1.05rem;letter-spacing:.5px}
.row{display:flex;gap:8px;margin-top:6px}.row .cp:only-child{background:#eaf1ff;border-color:#eaf1ff}.cp{flex:1;min-height:44px;border:1.5px solid #cfe0fb;border-radius:999px;background:#fff;color:#2f6fe4;font:700 .9rem Inter,system-ui,sans-serif;cursor:pointer}
.wait{display:flex;align-items:center;gap:10px;margin-top:16px;padding:12px;border-radius:12px;background:#f1f5fa;text-align:left;font-size:.85rem;color:#33445c}.spin.sm{width:20px;height:20px;margin:0;border-width:3px;flex:none}
a.alt{background:none;color:#5b6b7e;font-weight:600;min-height:40px;margin-top:6px}
/* Reference number + screenshot are always visible (no extra tap to reveal them) */
.ref{margin-top:12px;text-align:left;border:1px solid #e1e7ef;border-radius:12px;padding:0 12px}
.ref .reflabel{margin:0;min-height:44px;display:flex;align-items:center;font-weight:600;font-size:.86rem;color:#33445c}
.refform{display:grid;gap:8px;padding-bottom:12px;min-width:0}.refform input{box-sizing:border-box;width:100%;min-width:0}.row .cp{min-width:0;padding:0 8px}.refform input[type=text]{min-height:44px;padding:0 12px;border:1.5px solid #cfd8e6;border-radius:12px;font:inherit;font-size:.95rem}
.refform .shot{font-size:.82rem;color:#5b6b7e;display:grid;gap:4px}.refform .shot i{display:none}.refform input[type=file]{font-size:.8rem}
.refform .cp{flex:none}.refform button.cp{background:#2f6fe4;border-color:#2f6fe4;color:#fff}.refform button.cp:disabled{opacity:.7}.refform input[type=file]::file-selector-button{margin-right:10px;padding:8px 14px;border:0;border-radius:999px;background:#eaf1ff;color:#2f6fe4;font:700 .8rem Inter,system-ui,sans-serif;cursor:pointer}.refform input[type=text]{letter-spacing:1px;text-align:center;font-weight:600}.ref .sent{margin:0 0 8px;font-size:.84rem;color:#1a7f37}.ref .err{margin:0 0 8px;font-size:.84rem;color:#b42318}
@media(prefers-reduced-motion:reduce){.spin{animation:none}}
.payment-notif{position:fixed;top:16px;right:16px;z-index:20}.payment-notif .notif-btn{position:relative;width:44px;height:44px;padding:0;border:0;border-radius:50%;background:#fff;color:#2f6fe4;box-shadow:0 2px 10px rgba(47,111,228,.18);cursor:pointer;font-size:1.1rem}.payment-notif .notif-btn[aria-expanded="true"]{background:#2f6fe4;color:#fff}.payment-notif .notif-dot{position:absolute;top:-3px;right:-3px;min-width:20px;height:20px;padding:0 5px;border:2px solid #fff;border-radius:10px;background:#e5484d;color:#fff;font-size:.65rem;font-weight:700}.payment-notif .notif-dot[hidden],.payment-notif .notif-panel[hidden]{display:none}.payment-notif .notif-panel{position:absolute;right:0;top:54px;width:min(280px,calc(100vw - 32px));padding:12px;border-radius:16px;background:#fff;box-shadow:0 12px 40px rgba(20,40,90,.2);text-align:left}.payment-notif .notif-panel h2{margin:2px 4px 8px;font-size:.9rem}.payment-notif .notif-list{list-style:none;margin:0;padding:0;display:grid;gap:6px}.payment-notif .notif-list li{padding:8px 10px;border-radius:12px;background:#f5f8fe;font-size:.78rem;line-height:1.3}.payment-notif .notif-list small{display:block;color:#6b7a90;margin-top:2px}.payment-notif .notif-empty{margin:0;color:#6b7a90;font-size:.8rem}
.approval{text-align:center;padding:6px 0 4px}.approval-ico{width:72px;height:72px;margin:0 auto 12px;border-radius:50%;background:#eaf1ff;display:grid;place-items:center}.approval-ico .ring{width:34px;height:34px;border:4px solid #cfe0fb;border-top-color:#2f6fe4;border-radius:50%;animation:s .9s linear infinite}.approval h1{margin:0 0 6px;font-size:1.3rem;color:#14213d}.approval p{margin:0 0 14px;font-size:.9rem;color:#4a5b78;line-height:1.5}.approval-amt{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-radius:16px;background:#f1f6ff;margin-bottom:14px}.approval-amt span{color:#7a8aa6;font-size:.85rem}.approval-amt strong{font-size:1.2rem;color:#2f6fe4}.approval-btn{display:flex;align-items:center;justify-content:center;min-height:48px;padding:0 13px;border-radius:999px;background:#2f6fe4;color:#fff;font-weight:700;text-decoration:none}details.paydetails{margin-top:14px;border-top:1px solid #e8effb;padding-top:10px}details.paydetails>summary{cursor:pointer;font-weight:600;color:#2f6fe4;font-size:.9rem;margin-bottom:8px}
</style>
</head>
<body>
<div class="payment-notif notif-wrap">
    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
</div>
<main class="box" role="status" aria-live="polite">
<?php if($state === 'review'): ?>
    <?php $hasRef = !empty($co['reference_number']); ?>
    <?php if($hasRef): ?>
    <div class="approval">
        <div class="approval-ico" aria-hidden="true"><span class="ring"></span></div>
        <h1>Waiting for approval</h1>
        <p>An operator is checking reference <strong><?php echo htmlspecialchars($co['reference_number']); ?></strong>. Your ticket appears here automatically once it&rsquo;s approved.</p>
        <div class="approval-amt"><span>Amount</span><strong>&#8369;<?php echo number_format((float)$co['amount'], 2); ?></strong></div>
        <a href="my_reservations.php" class="approval-btn">View my trips</a>
    </div>
    <details class="paydetails"><summary>Payment details</summary>
    <?php endif; ?>
    <p class="lbl">Send exactly</p>
    <p class="amt">&#8369;<?php echo number_format((float)$co['amount'], 2); ?></p>
    <p class="hint">Use this exact amount, centavos included. It&rsquo;s how we know the payment is yours.</p>
    <?php if($gQr !== '' && strpos($gQr, 'data:image/') === 0): ?><img class="qr" src="<?php echo htmlspecialchars($gQr, ENT_QUOTES); ?>" alt="GCash QR code"><?php endif; ?>
    <div class="acct"><strong><?php echo htmlspecialchars($gName); ?></strong><span><?php echo htmlspecialchars($gNum); ?></span></div>
    <div class="row">
        <button type="button" class="cp" data-copy="<?php echo htmlspecialchars($gNum); ?>">Copy number</button>
    </div>
    <div class="ref">
        <p class="reflabel">Paid already? Enter reference no.</p>
        <?php if(!empty($co['reference_number'])): ?><p class="sent"><strong>Sent.</strong> An operator will confirm reference <?php echo htmlspecialchars($co['reference_number']); ?> shortly.</p><?php endif; ?>
        <?php if($refError !== ''): ?><p class="err" role="alert"><?php echo htmlspecialchars($refError); ?></p><?php endif; ?>
        <form method="POST" enctype="multipart/form-data" class="refform">
            <?php csrf_field(); ?>
            <input type="text" name="ref" inputmode="numeric" autocomplete="off" maxlength="13" minlength="13" pattern="[0-9]{13}" title="13 digits from your GCash receipt" placeholder="13-digit reference no." value="<?php echo htmlspecialchars((string)($co['reference_number'] ?? '')); ?>" aria-label="GCash reference number" required>
            <label class="shot"><i></i>Add screenshot (optional)<input type="file" name="shot" accept="image/jpeg,image/png"></label>
            <button type="submit" name="send_ref" value="1" class="cp">Submit</button>
        </form>
    </div>
    <?php if($hasRef): ?></details><?php endif; ?>
    <p class="hint">Seats held until <?php echo htmlspecialchars($until); ?>.</p>
    <?php if(!$hasRef): ?><a href="my_reservations.php" class="alt">Back to my trips</a><?php endif; ?>
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
document.querySelectorAll('.cp[data-copy]').forEach(function(b){b.addEventListener('click',function(){
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
var refIn=document.querySelector('.refform input[name=ref]');
if(refIn)refIn.addEventListener('input',function(){var v=refIn.value.replace(/\D/g,'').slice(0,13);if(v!==refIn.value)refIn.value=v;});
var rf=document.querySelector('.refform');
// Disable the button only after the browser has collected the form data; disabling it inside the submit event drops it from the post.
if(rf)rf.addEventListener('submit',function(){setTimeout(function(){var b=rf.querySelector('button[type=submit]');if(b){b.disabled=true;b.textContent='Sending…';}},0);});
</script>
<?php endif; ?>
<script src="../Assets/js/notifications.js"></script>
</body>
</html>
