<?php
// Receives GCash "You have received ..." SMS text from the admin's phone (SMS forwarder app), records it,
// and instantly approves any waiting commuter payment with the same reference number and enough amount.
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../Includes/payment_finalize.php';
require_once __DIR__ . '/../Includes/gcash_manual.php';
header('Content-Type: application/json');

$token = (string)($_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_X_MBUS_TOKEN'] ?? ''));
if (!hash_equals(mbus_sms_token($conn), $token)) { http_response_code(401); echo '{"ok":false}'; exit; }

$body = file_get_contents('php://input') ?: '';
$json = json_decode($body, true);
$src = is_array($json) ? $json : $_POST;
$text = '';
foreach (['message', 'text', 'body', 'content', 'msg', 'sms'] as $k) { if (!empty($src[$k]) && is_string($src[$k])) { $text = $src[$k]; break; } }
if ($text === '' && $body !== '' && !is_array($json)) $text = $body;
$parsed = mbus_parse_gcash_sms($text);
if (!$parsed) { echo '{"ok":true,"ignored":true}'; exit; }

$ins = $conn->pdo->prepare("INSERT INTO incoming_payments (reference_number, amount, sender, raw_message) VALUES (?,?,?,?) ON CONFLICT (reference_number) DO NOTHING");
$ins->execute([$parsed['reference'], $parsed['amount'], $parsed['sender'], substr($text, 0, 500)]);

$q = $conn->pdo->prepare("SELECT session_id FROM paymongo_checkout WHERE status = 'Submitted' AND reference_number = ?");
$q->execute([$parsed['reference']]);
$approved = 0;
foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sid) { if (!empty(mbus_try_autoapprove($conn, $sid)['matched'])) $approved++; }
echo json_encode(['ok' => true, 'recorded' => true, 'approved' => $approved]);
