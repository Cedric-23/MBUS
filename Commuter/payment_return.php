<?php
// Where GCash sends the commuter back. The ticket is issued only after PayMongo confirms the payment
// (this page checks with PayMongo directly; the webhook does the same in the background).
session_start();
include "../config/db_connect.php";
include "../Includes/paymongo.php";
include "../Includes/payment_finalize.php";

if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit(); }
$user_id = (int)$_SESSION['user_id'];
$schedule_id = (int)($_GET['schedule_id'] ?? 0);

$st = $conn->pdo->prepare("SELECT session_id, status, reject_reason FROM paymongo_checkout WHERE user_id = ? AND schedule_id = ? ORDER BY created_at DESC LIMIT 1");
$st->execute([$user_id, $schedule_id]);
$co = $st->fetch(PDO::FETCH_ASSOC);
if(!$co){ header("Location: my_reservations.php"); exit(); }

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
    include_once "../Includes/gcash_manual.php";
    $m = mbus_try_autoapprove($conn, $co['session_id']);
    $state = !empty($m['matched']) ? 'paid' : 'review';
} elseif($co['status'] === 'Rejected'){
    $state = 'rejected';
    $message = trim((string)$co['reject_reason']);
} elseif($co['status'] === 'Needs refund'){
    $state = 'refund';
    $message = 'Your payment arrived after the seat hold expired and a seat was taken. It will be refunded.';
}
if($state === 'paid'){ header("Location: ticket.php?schedule_id=" . $schedule_id); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirming payment — MBUS</title>
<?php if($state === 'waiting' || $state === 'review'): ?><meta http-equiv="refresh" content="4"><?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6fa;font-family:Inter,system-ui,sans-serif;color:#172b4d}
.box{width:min(380px,calc(100vw - 32px));padding:28px 24px;border-radius:20px;background:#fff;box-shadow:0 8px 30px rgba(23,43,77,.12);text-align:center}
.spin{width:38px;height:38px;margin:0 auto 16px;border:4px solid #dbe5f1;border-top-color:#1e3a5f;border-radius:50%;animation:s 1s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
h1{margin:0 0 8px;font-size:1.15rem}p{margin:0;color:#5b6b7e;font-size:.92rem;line-height:1.5}
a{display:inline-flex;align-items:center;justify-content:center;min-height:46px;margin-top:18px;padding:0 20px;border-radius:13px;background:#1e3a5f;color:#fff;font-weight:700;text-decoration:none}
@media(prefers-reduced-motion:reduce){.spin{animation:none}}
</style>
</head>
<body>
<main class="box" role="status" aria-live="polite">
<?php if($state === 'review'): ?>
    <div class="spin" aria-hidden="true"></div>
    <h1>Waiting for your GCash payment</h1>
    <p>We match your reference number as soon as GCash reports it. Your seats are held for a few minutes. If you haven&rsquo;t paid yet, send the exact amount to the GCash number shown on the payment page.</p>
    <a href="payment.php?schedule_id=<?php echo $schedule_id; ?>">Back to payment</a>
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
</body>
</html>
