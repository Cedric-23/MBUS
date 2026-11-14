<?php
// PayMongo webhook: the authoritative "payment received" signal. Register it for checkout_session.payment.paid.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../Includes/paymongo.php';
require_once __DIR__ . '/../Includes/payment_finalize.php';

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$raw = file_get_contents('php://input') ?: '';
$secret = trim((string)getenv('PAYMONGO_WEBHOOK_SECRET'));
if (!paymongo_verify_signature($raw, (string)($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? ''), $secret)) {
    http_response_code(401); echo '{"ok":false,"message":"bad signature"}'; exit;
}

$event = json_decode($raw, true);
$type = $event['data']['attributes']['type'] ?? '';
if ($type !== 'checkout_session.payment.paid') { echo '{"ok":true,"ignored":true}'; exit; }

$session = $event['data']['attributes']['data'] ?? [];
$sessionId = (string)($session['id'] ?? '');
$payRef = (string)($session['attributes']['payments'][0]['id'] ?? $sessionId);
if ($sessionId === '') { echo '{"ok":true,"ignored":true}'; exit; }

$res = mbus_finalize_payment($conn, $sessionId, $payRef);
// 200 when handled (including "unknown session" and refund cases) so PayMongo stops retrying; 500 only for transient errors.
if (!empty($res['ok']) || !empty($res['refund']) || ($res['message'] ?? '') === 'Unknown checkout session.') { echo json_encode(['ok' => true]); exit; }
http_response_code(500); echo json_encode(['ok' => false]);
