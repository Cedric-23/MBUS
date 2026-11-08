<?php
/** Live pickup is a location signal only: no reservation, seat allocation or payment. */
require_once __DIR__ . '/schedule_booking.php';
require_once __DIR__ . '/route_locations.php';

const MBUS_PICKUP_SETUP_MESSAGE = 'Live pickup is not enabled yet. Ask an administrator to deploy supabase/10_live_pickup.sql before using this feature.';

class MbusPickupException extends RuntimeException
{
    public int $httpStatus;
    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

/** Same ordered corridor stops and reversal as Commuter/reserve.php. */
function mbus_pickup_stops(array $schedule): array
{
    $stops = ['MORONG TERMINAL', 'BINARITAN', 'POBLACION', 'HILLTOP', 'SABANG', 'ANVAYA', 'MABAYO', 'MINANGA', 'MORONG GATE', 'GROUP 6', 'IDESS', 'APPAREL', 'GATE 2', 'TRIBOA', 'TOWER', 'KORYO', 'TECHNO', 'UA', 'NSD', 'PETRON', 'INDUSTRIAL', 'HARBOR POINT', 'MAIN GATE'];
    if (strtoupper(trim((string)($schedule['origin'] ?? ''))) === 'SBMA'
        && strtoupper(trim((string)($schedule['destination'] ?? ''))) === 'MORONG TERMINAL') {
        $stops = array_reverse($stops);
    }
    return $stops;
}

function mbus_pickup_positive_id(mixed $value): int
{
    if (!is_scalar($value) || is_bool($value)) {
        throw new MbusPickupException('Please provide a valid request or schedule ID.');
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]);
    if ($id === false) {
        throw new MbusPickupException('Please provide a valid request or schedule ID.');
    }
    return $id;
}

function mbus_pickup_coordinate(mixed $value, float $limit): float
{
    if (!is_scalar($value) || is_bool($value) || !is_numeric($value)) {
        throw new MbusPickupException('Please provide a valid map location.');
    }
    $coordinate = (float)$value;
    if (!is_finite($coordinate) || abs($coordinate) > $limit) {
        throw new MbusPickupException('Please provide a valid map location.');
    }
    return $coordinate;
}

function mbus_pickup_text(mixed $value, int $maxLength, bool $required = true): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new MbusPickupException('Please provide valid pickup and destination details.');
    }
    $value = trim($value);
    // Conservative byte limits also fit the database character limits without mbstring.
    if (($required && $value === '') || strlen($value) > $maxLength) {
        throw new MbusPickupException('Pickup or destination details are missing or too long.');
    }
    return $value;
}

/** Validate all form shapes before calling the existing hash_equals CSRF helper. */
function mbus_pickup_validate_form(array $post, string $role): array
{
    foreach ($post as $value) {
        if (!is_scalar($value)) {
            throw new MbusPickupException('Invalid form values. Please refresh and try again.');
        }
    }
    if (!isset($post['csrf_token']) || !is_string($post['csrf_token']) || strlen($post['csrf_token']) > 256) {
        throw new MbusPickupException('Invalid security token. Please refresh and try again.');
    }
    $action = $post['action'] ?? '';
    if (!is_string($action) || !in_array($action, ['wait', 'cancel', 'picked_up'], true)) {
        throw new MbusPickupException('Unknown pickup action.');
    }
    if (($action === 'picked_up' && !in_array($role, ['operator', 'admin'], true))
        || ($action !== 'picked_up' && $role !== 'commuter')) {
        throw new MbusPickupException('This action is not permitted for your account.', 403);
    }
    $data = ['action' => $action];
    if ($action === 'wait') {
        $data['schedule_id'] = mbus_pickup_positive_id($post['schedule_id'] ?? null);
        $data['latitude'] = mbus_pickup_coordinate($post['latitude'] ?? null, 90);
        $data['longitude'] = mbus_pickup_coordinate($post['longitude'] ?? null, 180);
        $data['pickup_label'] = mbus_pickup_text($post['pickup_label'] ?? '', 100, false) ?: 'Pinned pickup point';
        $data['destination'] = strtoupper(mbus_pickup_text($post['destination'] ?? null, 100));
    } else {
        $data['request_id'] = mbus_pickup_positive_id($post['request_id'] ?? null);
    }
    return $data;
}

function mbus_pickup_ready(PDO $pdo): bool
{
    return (bool)$pdo->query("SELECT to_regclass('public.pickup_requests') IS NOT NULL")->fetchColumn();
}

/** EXISTS avoids duplicate riders/schedules when assignment rows are duplicated. */
function mbus_pickup_scope(string $role, int $userId, array &$params, string $scheduleAlias = 's'): string
{
    if ($role === 'operator') {
        $params[] = $userId;
        return " AND EXISTS (SELECT 1 FROM public.operator_bus_assignments oba WHERE oba.bus_id = {$scheduleAlias}.bus_id AND oba.operator_id = ?)";
    }
    return '';
}

function mbus_pickup_schedule_sql(): string
{
    // to_jsonb permits read-only fallback when route coordinate migrations are absent.
    return "SELECT s.schedule_id, s.bus_id, s.departure_time, s.arrival_time, s.schedule_status, s.trip_status,
        b.bus_number, b.capacity, r.origin, r.destination,
        to_jsonb(r)->>'origin_lat' AS origin_lat, to_jsonb(r)->>'origin_lng' AS origin_lng,
        to_jsonb(r)->>'destination_lat' AS destination_lat, to_jsonb(r)->>'destination_lng' AS destination_lng,
        (SELECT COUNT(*) FROM public.reservation rv WHERE rv.schedule_id = s.schedule_id
            AND (rv.status IN ('Paid', 'Confirmed', 'Boarded')
                OR (rv.status = 'Pending' AND rv.created_at > NOW() - INTERVAL '10 minutes'))) AS reserved_count
        FROM public.schedule s JOIN public.buses b ON b.bus_id = s.bus_id
        JOIN public.routes r ON r.route_id = s.route_id";
}

function mbus_pickup_eligible_sql(): string
{
    return "s.schedule_status = 'Active' AND s.trip_status IN ('Scheduled', 'Boarding', 'Departed') AND s.departure_time >= ?";
}

function mbus_pickup_schedule_payload(array $row, ?int $now = null): array
{
    $row = mbus_route_apply_terminal_defaults($row);
    $available = max(0, (int)$row['capacity'] - (int)$row['reserved_count']);
    $action = mbus_schedule_action($row, $available, $now);
    $result = [];
    foreach (['schedule_id', 'bus_number', 'origin', 'destination', 'origin_label', 'destination_label', 'departure_time', 'arrival_time', 'trip_status', 'origin_lat', 'origin_lng', 'destination_lat', 'destination_lng'] as $key) {
        $result[$key] = $row[$key] ?? null;
    }
    $result['schedule_id'] = (int)$row['schedule_id'];
    foreach (['origin_lat', 'origin_lng', 'destination_lat', 'destination_lng'] as $key) {
        $value = $result[$key];
        $result[$key] = $value !== null && is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
    }
    foreach (['departure_time', 'arrival_time'] as $key) {
        $result[$key] = (new DateTimeImmutable((string)$row[$key]))->format(DATE_ATOM);
    }
    $result['action'] = $action;
    $result['stops'] = mbus_pickup_stops($row);
    return $result;
}

/** Intentionally SELECT-only: expiry never causes a heartbeat or a cleanup write. */
function mbus_pickup_feed(PDO $pdo, string $role, int $userId): array
{
    $now = time();
    $params = [date('Y-m-d 00:00:00', $now)];
    $scope = mbus_pickup_scope($role, $userId, $params);
    $stmt = $pdo->prepare(mbus_pickup_schedule_sql() . ' WHERE ' . mbus_pickup_eligible_sql() . $scope . ' ORDER BY s.departure_time, s.schedule_id');
    $stmt->execute($params);
    $schedules = [];
    $eligible = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = mbus_pickup_schedule_payload($row, $now);
        if ($payload['action'] === 'unavailable') {
            continue;
        }
        $schedules[] = $payload;
        $eligible[$payload['schedule_id']] = true;
    }
    $ready = mbus_pickup_ready($pdo);
    $requests = [];
    if ($ready) {
        $params = [];
        $name = $role === 'commuter' ? '' : ", u.user_id, u.full_name, u.profile_picture, u.phone_number,
            (u.profile_picture_b64 IS NOT NULL AND u.profile_picture_b64 <> '') AS has_pic, LEFT(MD5(COALESCE(u.profile_picture_b64,'')),8) AS pic_v,
            (SELECT cv.verification_type FROM public.commuter_verification cv WHERE cv.user_id = u.user_id AND cv.verification_status = 'Approved'
             AND (cv.expiry_date IS NULL OR cv.expiry_date >= CURRENT_DATE) ORDER BY cv.reviewed_at DESC NULLS LAST, cv.verification_id DESC LIMIT 1) AS passenger_type";
        $sql = "SELECT p.request_id, p.schedule_id, p.status, p.latitude, p.longitude, p.pickup_label,
            p.destination, p.updated_at, p.expires_at, b.bus_number, r.origin, r.destination AS route_destination,
            (p.expires_at <= NOW()) AS expired {$name}
            FROM public.pickup_requests p JOIN public.schedule s ON s.schedule_id = p.schedule_id
            JOIN public.buses b ON b.bus_id = s.bus_id JOIN public.routes r ON r.route_id = s.route_id";
        if ($role === 'commuter') {
            $sql .= ' WHERE p.user_id = ?';
            $params[] = $userId;
        } else {
            $sql .= " JOIN public.users u ON u.user_id = p.user_id WHERE p.status = 'Waiting' AND p.expires_at > NOW() AND " . mbus_pickup_eligible_sql();
            $params[] = date('Y-m-d 00:00:00', $now);
            $sql .= mbus_pickup_scope($role, $userId, $params);
        }
        $sql .= ' ORDER BY p.updated_at DESC, p.request_id DESC';
        if ($role === 'commuter') {
            $sql .= ' LIMIT 1'; // unique user_id retains only the latest request, including final status.
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($role !== 'commuter' && !isset($eligible[(int)$row['schedule_id']])) {
                continue;
            }
            if ($row['status'] === 'Waiting' && (in_array($row['expired'], [true, 1, '1', 't'], true) || !isset($eligible[(int)$row['schedule_id']]))) {
                $row['status'] = 'Expired';
            }
            unset($row['expired']);
            if ($role !== 'commuter') {
                $row['has_pic'] = in_array($row['has_pic'] ?? null, [true, 1, '1', 't', 'true'], true);
                $row['user_id'] = (int)$row['user_id'];
                $row['passenger_type'] = $row['passenger_type'] ?: 'Regular';
            }
            foreach (['request_id', 'schedule_id'] as $key) {
                $row[$key] = (int)$row[$key];
            }
            foreach (['latitude', 'longitude'] as $key) {
                $row[$key] = (float)$row[$key];
            }
            foreach (['updated_at', 'expires_at'] as $key) {
                $row[$key] = (new DateTimeImmutable($row[$key]))->format(DATE_ATOM);
            }
            $requests[] = $row;
        }
    }
    $result = ['ok' => true, 'ready' => $ready, 'schedules' => $schedules, 'requests' => $requests, 'server_time' => date(DATE_ATOM, $now)];
    if (!$ready) {
        $result['message'] = MBUS_PICKUP_SETUP_MESSAGE;
    }
    return $result;
}

function mbus_pickup_mutate(PDO $pdo, string $role, int $userId, array $data): array
{
    if (!mbus_pickup_ready($pdo)) {
        throw new MbusPickupException(MBUS_PICKUP_SETUP_MESSAGE, 503);
    }
    // Defense in depth: callers cannot bypass role/form validation.
    $action = $data['action'] ?? '';
    if (($action === 'picked_up' && !in_array($role, ['operator', 'admin'], true))
        || (in_array($action, ['wait', 'cancel'], true) && $role !== 'commuter')
        || !in_array($action, ['wait', 'cancel', 'picked_up'], true)) {
        throw new MbusPickupException('This action is not permitted for your account.', 403);
    }
    $pdo->beginTransaction();
    try {
        if ($action === 'wait') {
            // Serialize against staff status changes; seat count/action are rechecked under the schedule lock.
            $stmt = $pdo->prepare('SELECT schedule_id FROM public.schedule WHERE schedule_id = ? FOR UPDATE');
            $stmt->execute([$data['schedule_id']]);
            if (!$stmt->fetchColumn()) {
                throw new MbusPickupException('Schedule not found.', 404);
            }
            $stmt = $pdo->prepare(mbus_pickup_schedule_sql() . ' WHERE s.schedule_id = ?');
            $stmt->execute([$data['schedule_id']]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$schedule || mbus_schedule_action($schedule, max(0, (int)$schedule['capacity'] - (int)$schedule['reserved_count'])) !== 'pickup') {
                throw new MbusPickupException('This trip is no longer available for live pickup. Refresh the schedule list.', 409);
            }
            if (!in_array($data['destination'], mbus_pickup_stops($schedule), true)) {
                throw new MbusPickupException('Choose a destination from this route’s stop list.');
            }
            $stmt = $pdo->prepare("INSERT INTO public.pickup_requests
                (user_id, schedule_id, status, latitude, longitude, pickup_label, destination, created_at, updated_at, expires_at)
                VALUES (?, ?, 'Waiting', ?, ?, ?, ?, clock_timestamp(), clock_timestamp(), clock_timestamp() + INTERVAL '30 minutes')
                ON CONFLICT (user_id) DO UPDATE SET
                    request_id = EXCLUDED.request_id, schedule_id = EXCLUDED.schedule_id, status = 'Waiting',
                    latitude = EXCLUDED.latitude, longitude = EXCLUDED.longitude, pickup_label = EXCLUDED.pickup_label,
                    destination = EXCLUDED.destination, created_at = EXCLUDED.created_at, updated_at = EXCLUDED.updated_at,
                    expires_at = EXCLUDED.expires_at
                RETURNING request_id, schedule_id, status, updated_at, expires_at");
            $stmt->execute([$userId, $data['schedule_id'], $data['latitude'], $data['longitude'], $data['pickup_label'], $data['destination']]);
            $message = 'Your pickup location has been shared for 30 minutes. This does not reserve or guarantee a seat.';
        } elseif ($action === 'cancel') {
            $stmt = $pdo->prepare("UPDATE public.pickup_requests SET status = 'Cancelled', updated_at = clock_timestamp()
                WHERE request_id = ? AND user_id = ? AND status = 'Waiting' AND expires_at > clock_timestamp()
                RETURNING request_id, schedule_id, status, updated_at, expires_at");
            $stmt->execute([$data['request_id'], $userId]);
            $message = 'Your pickup request has been cancelled.';
        } else {
            $params = [$data['request_id']];
            $scope = mbus_pickup_scope($role, $userId, $params);
            // Always lock the schedule first, just like wait, to avoid inconsistent lock order.
            $stmt = $pdo->prepare('SELECT s.schedule_id FROM public.schedule s WHERE EXISTS (SELECT 1 FROM public.pickup_requests p WHERE p.schedule_id = s.schedule_id AND p.request_id = ?)' . $scope . ' FOR UPDATE OF s');
            $stmt->execute($params);
            $scheduleId = $stmt->fetchColumn();
            if (!$scheduleId) {
                throw new MbusPickupException('Pickup request not found or not assigned to your bus.', 404);
            }
            $stmt = $pdo->prepare(mbus_pickup_schedule_sql() . ' WHERE s.schedule_id = ?');
            $stmt->execute([$scheduleId]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$schedule || mbus_schedule_action($schedule, max(0, (int)$schedule['capacity'] - (int)$schedule['reserved_count'])) === 'unavailable') {
                throw new MbusPickupException('This trip is no longer active. Refresh the waiting list.', 409);
            }
            $params = [$data['request_id'], $scheduleId];
            $scope = mbus_pickup_scope($role, $userId, $params);
            $stmt = $pdo->prepare("UPDATE public.pickup_requests p SET status = 'Picked up', updated_at = clock_timestamp()
                FROM public.schedule s WHERE s.schedule_id = p.schedule_id AND p.request_id = ? AND p.schedule_id = ?
                AND p.status = 'Waiting' AND p.expires_at > clock_timestamp()" . $scope . '
                RETURNING p.request_id, p.schedule_id, p.user_id, p.status, p.updated_at, p.expires_at');
            $stmt->execute($params);
            $message = 'Picked up. Give them a seat in Riders → Pickup.';
            $picked = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($picked) {
                // Already reserved and paid on this trip: board that seat instead of asking for a second one.
                $bs = $pdo->prepare("UPDATE public.reservation SET status = 'Boarded' WHERE user_id = ? AND schedule_id = ? AND status IN ('Paid', 'Confirmed') RETURNING seat_number");
                $bs->execute([$picked['user_id'], $picked['schedule_id']]);
                $seat = $bs->fetchColumn();
                if ($seat !== false) { $message = 'Picked up. Reserved seat ' . $seat . ' is now boarded.'; }
            }
            $request = $picked;
        }
        $request = $request ?? $stmt->fetch(PDO::FETCH_ASSOC); // direct PDO: the legacy adapter discards INSERT RETURNING rows.
        if (!$request) {
            throw new MbusPickupException('This request has expired, changed, or is no longer waiting. Refresh and try again.', 409);
        }
        $pdo->commit();
        foreach (['request_id', 'schedule_id'] as $key) {
            $request[$key] = (int)$request[$key];
        }
        foreach (['updated_at', 'expires_at'] as $key) {
            $request[$key] = (new DateTimeImmutable($request[$key]))->format(DATE_ATOM);
        }
        return ['ok' => true, 'message' => $message, 'request' => $request];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
