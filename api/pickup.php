<?php
require_once __DIR__ . '/../config/app.php';
session_start();
require_once __DIR__ . '/../Includes/csrf.php';
require_once __DIR__ . '/../Includes/pickup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function mbus_pickup_json(array $payload, int $status = 200): never
{
    $GLOBALS['mbus_pickup_response_sent'] = true;
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$roleInput = $_SESSION['user_type'] ?? '';
$role = is_string($roleInput) ? strtolower($roleInput) : '';
try {
    $userId = mbus_pickup_positive_id($userId);
} catch (MbusPickupException $error) {
    mbus_pickup_json(['ok' => false, 'message' => 'Please sign in to use live pickup.'], 401);
}
if (!in_array($role, ['commuter', 'operator', 'admin'], true)) {
    mbus_pickup_json(['ok' => false, 'message' => 'Your account cannot access live pickup.'], 403);
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    mbus_pickup_json(['ok' => false, 'message' => 'Use GET or POST for this endpoint.'], 405);
}
$data = null;
if ($method === 'POST') {
    try {
        $data = mbus_pickup_validate_form($_POST, $role);
    } catch (MbusPickupException $error) {
        mbus_pickup_json(['ok' => false, 'message' => $error->getMessage()], $error->httpStatus);
    }
    if (!csrf_validate_token()) {
        mbus_pickup_json(['ok' => false, 'message' => 'Security validation failed. Refresh the page and try again.'], 403);
    }
}
$csrfToken = csrf_get_token();
session_write_close(); // Polling does not hold the PHP session lock while waiting on PostgreSQL.

// The existing connection file uses die() for setup/connection failures. Buffer that
// output and replace it with JSON so neither credentials nor raw SQL errors leak.
$initialBufferLevel = ob_get_level();
ob_start();
register_shutdown_function(static function () use ($initialBufferLevel): void {
    if (empty($GLOBALS['mbus_pickup_response_sent'])) {
        while (ob_get_level() > $initialBufferLevel) {
            ob_end_clean();
        }
        http_response_code(503);
        echo json_encode(['ok' => false, 'ready' => false, 'message' => 'Live pickup is temporarily unavailable. Please ask an administrator to check the database connection and deploy supabase/10_live_pickup.sql.']);
    }
});

try {
    require_once __DIR__ . '/../config/db_connect.php';
    if ($method === 'GET') {
        $response = mbus_pickup_feed($conn->pdo, $role, $userId);
        $response['csrf_token'] = $csrfToken;
    } else {
        $response = mbus_pickup_mutate($conn->pdo, $role, $userId, $data);
        $response['csrf_token'] = $csrfToken;
        $response['server_time'] = date(DATE_ATOM);
    }
    ob_end_clean();
    mbus_pickup_json($response);
} catch (MbusPickupException $error) {
    ob_end_clean();
    $response = ['ok' => false, 'message' => $error->getMessage()];
    if ($error->httpStatus === 503) {
        $response['ready'] = false;
    }
    mbus_pickup_json($response, $error->httpStatus);
} catch (Throwable $error) {
    ob_end_clean();
    error_log('Live pickup API: ' . $error->getMessage());
    $missing = $error instanceof PDOException && (string)$error->getCode() === '42P01';
    mbus_pickup_json(['ok' => false, 'ready' => false, 'message' => $missing ? MBUS_PICKUP_SETUP_MESSAGE : 'Live pickup is temporarily unavailable. Please refresh and try again.'], 503);
}
