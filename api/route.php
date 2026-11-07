<?php
require_once __DIR__ . '/../config/app.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

function mbus_route_response(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    mbus_route_response(401, ['ok' => false, 'message' => 'Sign in to view road directions.']);
}

$now = microtime(true);
$last = (float)($_SESSION['route_api_last_request'] ?? 0);
if ($last > 0 && $now - $last < 1.0) {
    mbus_route_response(429, ['ok' => false, 'message' => 'Please wait a moment before requesting another route.']);
}
$_SESSION['route_api_last_request'] = $now;
session_write_close();

$readCoordinate = static function (string $key, float $max): ?float {
    $raw = $_GET[$key] ?? null;
    if (!is_string($raw) || !is_numeric($raw)) return null;
    $value = (float)$raw;
    if (!is_finite($value) || abs($value) > $max) return null;
    return round($value, 7);
};
$originLat = $readCoordinate('origin_lat', 90);
$originLng = $readCoordinate('origin_lng', 180);
$destinationLat = $readCoordinate('destination_lat', 90);
$destinationLng = $readCoordinate('destination_lng', 180);
if ($originLat === null || $originLng === null || $destinationLat === null || $destinationLng === null) {
    mbus_route_response(400, ['ok' => false, 'message' => 'Valid origin and destination map pins are required.']);
}

// Only fixed route endpoints are sent to the router. The moving operator GPS point is never included.
$base = trim((string)(getenv('MBUS_ROUTING_ENDPOINT') ?: 'https://router.project-osrm.org'));
$parts = parse_url($base);
if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['query']) || isset($parts['user']) || isset($parts['pass'])) {
    mbus_route_response(503, ['ok' => false, 'message' => 'Road directions are not configured.']);
}
$base = rtrim($base, '/');
$coordinates = sprintf('%.7F,%.7F;%.7F,%.7F', $originLng, $originLat, $destinationLng, $destinationLat);
$url = $base . '/route/v1/driving/' . $coordinates . '?overview=full&geometries=geojson&steps=false';

$headers = [
    'Accept: application/json',
    'User-Agent: MBUS route estimate/1.0 (https://mbus-bnf9.onrender.com/)',
];
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $headers[] = 'Referer: ' . ($isHttps ? 'https://' : 'http://') . $host . '/';
}
$context = stream_context_create(['http' => [
    'method' => 'GET',
    'timeout' => 12,
    'ignore_errors' => true,
    'follow_location' => 0,
    'header' => implode("\r\n", $headers),
]]);
$body = @file_get_contents($url, false, $context);
if (!is_string($body)) {
    mbus_route_response(502, ['ok' => false, 'message' => 'Road directions are unavailable right now.']);
}
$data = json_decode($body, true);
$route = is_array($data) && isset($data['routes'][0]) ? $data['routes'][0] : null;
$rawCoordinates = is_array($route) ? ($route['geometry']['coordinates'] ?? null) : null;
if (!is_array($route) || ($data['code'] ?? '') !== 'Ok' || !is_array($rawCoordinates) || count($rawCoordinates) < 2) {
    mbus_route_response(502, ['ok' => false, 'message' => 'No road route could be found between these stops.']);
}
$distance = $route['distance'] ?? null;
$duration = $route['duration'] ?? null;
if (!is_numeric($distance) || !is_numeric($duration) || !is_finite((float)$distance) || !is_finite((float)$duration) || (float)$distance <= 0 || (float)$duration <= 0) {
    mbus_route_response(502, ['ok' => false, 'message' => 'The road route estimate was invalid.']);
}
$coordinatesOut = [];
foreach (array_slice($rawCoordinates, 0, 12000) as $point) {
    if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) continue;
    $lng = (float)$point[0];
    $lat = (float)$point[1];
    if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) continue;
    $coordinatesOut[] = [$lng, $lat];
}
if (count($coordinatesOut) < 2) {
    mbus_route_response(502, ['ok' => false, 'message' => 'The road route did not contain enough map points.']);
}
mbus_route_response(200, ['ok' => true, 'route' => [
    'distance_m' => (float)$distance,
    'duration_s' => (float)$duration,
    'coordinates' => $coordinatesOut,
    'provider' => 'OSRM',
]]);
