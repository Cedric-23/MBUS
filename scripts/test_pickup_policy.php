<?php
/** Local-only regression checks. Does not load db_connect.php or contact Supabase. */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../Includes/pickup.php';

$checks = 0;
function pickup_check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
}
function pickup_rejects(callable $call, int $status = 400): void
{
    try {
        $call();
    } catch (MbusPickupException $error) {
        pickup_check($error->httpStatus === $status, 'expected rejection status ' . $status);
        return;
    }
    pickup_check(false, 'expected validation rejection');
}

$now = strtotime('2026-11-07 09:00:00');
$base = ['schedule_id' => 5, 'bus_number' => 'MBUS-5', 'capacity' => 20, 'reserved_count' => 0,
    'origin' => 'Morong Terminal', 'destination' => 'SBMA', 'schedule_status' => 'Active', 'trip_status' => 'Scheduled',
    'departure_time' => '2026-11-07 10:00:00', 'arrival_time' => '2026-11-07 11:00:00'];
pickup_check(mbus_pickup_schedule_payload($base, $now)['action'] === 'reserve', 'future available trip is reserve');
pickup_check(mbus_pickup_schedule_payload(array_replace($base, ['reserved_count' => 20]), $now)['action'] === 'pickup', 'full trip is pickup');
pickup_check(mbus_pickup_schedule_payload(array_replace($base, ['departure_time' => '2026-11-07 09:29:00']), $now)['action'] === 'pickup', 'under 30 minutes is pickup');
pickup_check(mbus_pickup_schedule_payload(array_replace($base, ['departure_time' => '2026-11-07 09:30:00']), $now)['action'] === 'reserve', '30 minute boundary retains reserve');
pickup_check(mbus_pickup_schedule_payload(array_replace($base, ['trip_status' => 'Departed']), $now)['action'] === 'pickup', 'departed today is pickup');
foreach ([['trip_status' => 'Arrived'], ['trip_status' => 'Cancelled'], ['schedule_status' => 'Completed'], ['departure_time' => '2026-11-06 10:00:00', 'trip_status' => 'Departed']] as $change) {
    pickup_check(mbus_pickup_schedule_payload(array_replace($base, $change), $now)['action'] === 'unavailable', 'terminal or stale trip unavailable');
}
$stops = mbus_pickup_stops($base);
pickup_check(count($stops) === 23 && $stops[0] === 'MORONG TERMINAL' && end($stops) === 'MAIN GATE', 'forward stop order');
pickup_check(mbus_pickup_stops(['origin' => 'SBMA', 'destination' => 'Morong Terminal']) === array_reverse($stops), 'reserve-compatible stop reversal');
$payload = mbus_pickup_schedule_payload($base, $now);
pickup_check($payload['stops'] === $stops && $payload['origin_lat'] === null, 'stop JSON and missing coordinate fallback');
pickup_check(str_ends_with($payload['departure_time'], '+08:00'), 'schedule uses app-timezone ISO8601');

$form = ['csrf_token' => str_repeat('a', 64), 'action' => 'wait', 'schedule_id' => '5', 'latitude' => '14.72', 'longitude' => '120.26', 'pickup_label' => 'Near terminal', 'destination' => 'Main Gate'];
$data = mbus_pickup_validate_form($form, 'commuter');
pickup_check($data['latitude'] === 14.72 && $data['destination'] === 'MAIN GATE', 'form coordinate and destination normalization');
pickup_check(mbus_pickup_validate_form(array_replace($form, ['pickup_label' => '']), 'commuter')['pickup_label'] === 'Pinned pickup point', 'blank pickup label has safe default');
foreach (['schedule_id', 'latitude', 'longitude', 'pickup_label', 'destination', 'csrf_token', 'action'] as $field) {
    pickup_rejects(fn() => mbus_pickup_validate_form(array_replace($form, [$field => ['nested']]), 'commuter'));
}
foreach (['NaN', 'INF', '1e309', '90.0001', '-90.0001', '', true] as $value) {
    pickup_rejects(fn() => mbus_pickup_coordinate($value, 90));
}
foreach (['180.0001', '-180.0001', 'Infinity'] as $value) {
    pickup_rejects(fn() => mbus_pickup_coordinate($value, 180));
}
pickup_check(mbus_pickup_coordinate('-90', 90) === -90.0 && mbus_pickup_coordinate('180', 180) === 180.0, 'legal coordinate endpoints');
foreach ([0, '-2', '5x', false, [], null] as $id) pickup_rejects(fn() => mbus_pickup_positive_id($id));
pickup_rejects(fn() => mbus_pickup_validate_form(array_replace($form, ['pickup_label' => str_repeat('a', 101)]), 'commuter'));
pickup_rejects(fn() => mbus_pickup_validate_form(array_replace($form, ['destination' => "MAIN GATE\n"]), 'commuter'));
pickup_rejects(fn() => mbus_pickup_validate_form(array_replace($form, ['destination' => "\xFF"]), 'commuter'));
pickup_rejects(fn() => mbus_pickup_validate_form($form, 'operator'), 403);
pickup_rejects(fn() => mbus_pickup_validate_form(['csrf_token' => 'x', 'action' => 'picked_up', 'request_id' => 1], 'commuter'), 403);
pickup_check(mbus_pickup_validate_form(['csrf_token' => 'x', 'action' => 'picked_up', 'request_id' => 1], 'operator')['request_id'] === 1, 'staff complete shape');
pickup_check(mbus_pickup_validate_form(['csrf_token' => 'x', 'action' => 'cancel', 'request_id' => 1], 'commuter')['action'] === 'cancel', 'commuter cancel shape');
$params = [];
$scope = mbus_pickup_scope('operator', 9, $params);
pickup_check(str_contains($scope, 'EXISTS') && str_contains($scope, 'oba.operator_id = ?') && $params === [9], 'assignment scope uses EXISTS and parameter');
$params = [];
pickup_check(mbus_pickup_scope('admin', 9, $params) === '' && $params === [], 'admin scope all buses');
pickup_check(str_contains(mbus_pickup_schedule_sql(), 'r.route_id = s.route_id'), 'route join follows canonical schema');
pickup_check(str_contains(mbus_pickup_schedule_sql(), "INTERVAL '10 minutes'") && str_contains(mbus_pickup_schedule_sql(), "'Confirmed', 'Boarded'"), 'seat policy includes active pending and allocated statuses');

/** PDO doubles record SQL only. They cannot connect to any database. */
class PickupPolicyTestStatement extends PDOStatement
{
    private array $rows;
    private int $position = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return $this->rows[$this->position++] ?? false; }
    public function fetchColumn(int $column = 0): mixed { $row = $this->fetch(); return $row === false ? false : array_values($row)[$column]; }
}
class PickupPolicyTestPDO extends PDO
{
    public array $sql = [];
    public array $scheduleRows = [];
    public array $requestRows = [];
    public bool $tableReady = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sql[] = $query;
        return new PickupPolicyTestStatement(str_contains($query, 'SELECT p.request_id') ? $this->requestRows : $this->scheduleRows);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->sql[] = $query;
        return new PickupPolicyTestStatement([['ready' => $this->tableReady]]);
    }
}
$db = new PickupPolicyTestPDO();
$base['departure_time'] = date('Y-m-d H:i:s', time() + 3600);
$base['arrival_time'] = date('Y-m-d H:i:s', time() + 7200);
$db->scheduleRows = [$base];
$feed = mbus_pickup_feed($db, 'commuter', 11);
pickup_check($feed['ready'] === false && count($feed['schedules']) === 1 && $feed['requests'] === [], 'missing table retains schedules');
pickup_check(str_contains($feed['message'], '10_live_pickup.sql'), 'missing table useful deployment message');
$db->tableReady = true;
$db->requestRows = [['request_id' => '3', 'schedule_id' => '5', 'status' => 'Waiting', 'latitude' => '14.72', 'longitude' => '120.26', 'pickup_label' => 'Here', 'destination' => 'MAIN GATE', 'updated_at' => date(DATE_ATOM), 'expires_at' => date(DATE_ATOM, time() - 1), 'bus_number' => 'MBUS-5', 'origin' => 'Morong Terminal', 'route_destination' => 'SBMA', 'expired' => true]];
$feed = mbus_pickup_feed($db, 'commuter', 11);
pickup_check($feed['requests'][0]['status'] === 'Expired', 'expired status derived without write');
pickup_check(!isset($feed['requests'][0]['full_name']) && !isset($feed['requests'][0]['user_id']), 'commuter payload has no other identity data');
$commuterSql = end($db->sql);
pickup_check(str_contains($commuterSql, 'WHERE p.user_id = ?') && str_contains($commuterSql, 'LIMIT 1') && !str_contains($commuterSql, 'u.full_name'), 'commuter SQL own latest only');
$db->requestRows[0]['expired'] = false;
$db->requestRows[0]['full_name'] = 'Local test commuter';
mbus_pickup_feed($db, 'operator', 9);
$staffSql = end($db->sql);
pickup_check(str_contains($staffSql, "p.status = 'Waiting' AND p.expires_at > NOW()") && str_contains($staffSql, 'EXISTS') && str_contains($staffSql, 'u.full_name'), 'staff SQL active waiting assignment scope');
foreach ($db->sql as $sql) pickup_check(preg_match('/^SELECT\b/', $sql) === 1, 'all feed statements are SELECT-only');
$db->tableReady = false;
pickup_rejects(fn() => mbus_pickup_mutate($db, 'commuter', 11, $data), 503);

echo "Pickup policy checks passed: {$checks}. No database connections or writes performed.\n";
