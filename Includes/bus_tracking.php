<?php
require_once __DIR__ . '/pickup.php';
require_once __DIR__ . '/route_locations.php';

function mbus_tracking_form(array $post, string $role): array
{
    foreach ($post as $v) {
        if (!is_scalar($v)) throw new MbusPickupException('Invalid form values.', 400);
    }
    $action = $post['action'] ?? '';
    if (!is_string($action) || !in_array($action, ['update_location', 'mark_waiting', 'cancel_waiting'], true)) {
        throw new MbusPickupException('Unknown live tracking action.', 400);
    }
    if (($action === 'update_location' && $role !== 'operator') || ($action !== 'update_location' && $role !== 'commuter')) {
        throw new MbusPickupException('This action is not permitted for your account.', 403);
    }
    $data = ['action' => $action, 'schedule_id' => mbus_pickup_positive_id($post['schedule_id'] ?? null)];
    if ($action !== 'cancel_waiting') {
        $data['latitude'] = mbus_pickup_coordinate($post['latitude'] ?? null, 90);
        $data['longitude'] = mbus_pickup_coordinate($post['longitude'] ?? null, 180);
    }
    if ($action === 'update_location') {
        $accuracy = $post['accuracy'] ?? null;
        if (!is_scalar($accuracy) || is_bool($accuracy) || !is_numeric($accuracy) || !is_finite((float)$accuracy) || (float)$accuracy < 0) {
            throw new MbusPickupException('Please provide valid GPS accuracy.', 400);
        }
        // Desktop browsers locate by Wi-Fi/IP and report accuracy in the thousands of meters; the table stores at most 1000.
        $data['accuracy'] = min(1000.0, (float)$accuracy);
    }
    if ($action === 'mark_waiting') {
        $data['pickup_label'] = mbus_pickup_text($post['pickup_label'] ?? '', 100, false) ?: 'Pinned pickup point';
    }
    return $data;
}

/** Check trip/day and role scope before returning or mutating location data. */
function mbus_tracking_schedule(PDO $pdo, int $scheduleId, string $role, int $userId): ?array
{
    $sql = "SELECT s.schedule_id, s.bus_id, b.bus_number, s.departure_time, s.trip_status, s.schedule_status,
            r.origin, r.destination,
            to_jsonb(r)->>'origin_lat' AS origin_lat, to_jsonb(r)->>'origin_lng' AS origin_lng,
            to_jsonb(r)->>'destination_lat' AS destination_lat, to_jsonb(r)->>'destination_lng' AS destination_lng
        FROM public.schedule s
        JOIN public.buses b ON b.bus_id = s.bus_id
        JOIN public.routes r ON r.route_id = s.route_id
        WHERE s.schedule_id = ? AND s.schedule_status = 'Active'
            AND s.trip_status IN ('Boarding', 'Departed') AND s.departure_time >= ?";
    $params = [$scheduleId, date('Y-m-d 00:00:00')];
    if ($role === 'operator') {
        $sql .= ' AND EXISTS (SELECT 1 FROM public.operator_bus_assignments oba WHERE oba.bus_id = s.bus_id AND oba.operator_id = ?)';
        $params[] = $userId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function mbus_tracking_schedule_payload(array $schedule): array
{
    $schedule = mbus_route_apply_terminal_defaults($schedule);
    foreach (['schedule_id'] as $key) $schedule[$key] = (int)$schedule[$key];
    foreach (['origin_lat', 'origin_lng', 'destination_lat', 'destination_lng'] as $key) {
        $schedule[$key] = isset($schedule[$key]) && is_numeric($schedule[$key]) && is_finite((float)$schedule[$key]) ? (float)$schedule[$key] : null;
    }
    $schedule['departure_time'] = (new DateTimeImmutable($schedule['departure_time']))->format(DATE_ATOM);
    return $schedule;
}

function mbus_tracking_feed(PDO $pdo, string $role, int $userId, ?int $chosen): array
{
    $sql = "SELECT DISTINCT s.schedule_id FROM public.schedule s
        WHERE s.schedule_status = 'Active' AND s.trip_status IN ('Boarding', 'Departed') AND s.departure_time >= ?";
    $params = [date('Y-m-d 00:00:00')];
    if ($role === 'operator') {
        $sql .= ' AND EXISTS (SELECT 1 FROM public.operator_bus_assignments oba WHERE oba.bus_id = s.bus_id AND oba.operator_id = ?)';
        $params[] = $userId;
    }
    $sql .= ' ORDER BY s.schedule_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $schedules = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $schedule = mbus_tracking_schedule($pdo, (int)$id, $role, $userId);
        if ($schedule) $schedules[(int)$id] = mbus_tracking_schedule_payload($schedule);
    }

    $selectedId = $chosen !== null && isset($schedules[$chosen]) ? $chosen : (array_key_first($schedules) ?: null);
    $schedule = $selectedId !== null ? $schedules[$selectedId] : null;
    $location = null;
    $waiting = null;
    $myRequest = null;
    $requests = [];
    // Latest operator GPS point for every active trip in scope, so riders and staff can
    // see the bus on a map without guessing which trip is selected.
    $locationSql = "SELECT t.schedule_id, b.bus_number, t.latitude, t.longitude, t.accuracy, t.updated_at
        FROM public.bus_live_tracking t
        JOIN public.buses b ON b.bus_id = t.bus_id
        JOIN public.schedule s ON s.schedule_id = t.schedule_id
        WHERE s.schedule_status = 'Active' AND s.trip_status IN ('Boarding', 'Departed') AND s.departure_time >= ?";
    $locationParams = [date('Y-m-d 00:00:00')];
    if ($role === 'operator') {
        $locationSql .= ' AND EXISTS (SELECT 1 FROM public.operator_bus_assignments oba WHERE oba.bus_id = s.bus_id AND oba.operator_id = ?)';
        $locationParams[] = $userId;
    }
    $stmt = $pdo->prepare($locationSql);
    $stmt->execute($locationParams);
    $locations = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $locations[] = [
            'schedule_id' => (int)$row['schedule_id'],
            'bus_number' => $row['bus_number'],
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'accuracy' => (float)$row['accuracy'],
            'updated_at' => (new DateTimeImmutable($row['updated_at']))->format(DATE_ATOM),
        ];
    }
    if ($role === 'commuter') {
        // The rider's own waiting request is independent of which bus is selected.
        $stmt = $pdo->prepare("SELECT schedule_id, CASE WHEN status='Waiting' AND expires_at <= clock_timestamp() THEN 'Expired' ELSE status END AS status,
            pickup_label, latitude, longitude, updated_at, expires_at
            FROM public.pickup_requests WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $myRequest = [
                'schedule_id' => (int)$row['schedule_id'],
                'status' => $row['status'],
                'pickup_label' => $row['pickup_label'],
                'latitude' => (float)$row['latitude'],
                'longitude' => (float)$row['longitude'],
                'updated_at' => (new DateTimeImmutable($row['updated_at']))->format(DATE_ATOM),
                'expires_at' => (new DateTimeImmutable($row['expires_at']))->format(DATE_ATOM),
            ];
        }
    }
    if ($selectedId !== null) {
        $stmt = $pdo->prepare('SELECT latitude, longitude, accuracy, updated_at FROM public.bus_live_tracking WHERE schedule_id = ?');
        $stmt->execute([$selectedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $location = ['schedule_id' => $selectedId, 'latitude' => (float)$row['latitude'], 'longitude' => (float)$row['longitude'], 'accuracy' => (float)$row['accuracy'], 'updated_at' => (new DateTimeImmutable($row['updated_at']))->format(DATE_ATOM)];
        }
        if ($role === 'commuter') {
            $stmt = $pdo->prepare("SELECT CASE WHEN status='Waiting' AND expires_at <= clock_timestamp() THEN 'Expired' ELSE status END AS status,
                pickup_label, latitude, longitude, updated_at, expires_at
                FROM public.pickup_requests WHERE user_id = ? AND schedule_id = ?");
            $stmt->execute([$userId, $selectedId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $waiting = ['status' => $row['status'], 'pickup_label' => $row['pickup_label'], 'latitude' => (float)$row['latitude'], 'longitude' => (float)$row['longitude'], 'updated_at' => (new DateTimeImmutable($row['updated_at']))->format(DATE_ATOM), 'expires_at' => (new DateTimeImmutable($row['expires_at']))->format(DATE_ATOM)];
                $requests[] = $waiting;
            }
        } else {
            $stmt = $pdo->prepare("SELECT p.user_id, u.full_name, p.pickup_label, p.destination, p.latitude, p.longitude, p.updated_at, p.expires_at
                FROM public.pickup_requests p JOIN public.users u ON u.user_id = p.user_id
                WHERE p.schedule_id = ? AND p.status = 'Waiting' AND p.expires_at > clock_timestamp()
                ORDER BY p.updated_at");
            $stmt->execute([$selectedId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $requests[] = ['user_id' => (int)$row['user_id'], 'full_name' => $row['full_name'], 'name' => $row['full_name'], 'pickup_label' => $row['pickup_label'], 'destination' => $row['destination'], 'latitude' => (float)$row['latitude'], 'longitude' => (float)$row['longitude'], 'updated_at' => (new DateTimeImmutable($row['updated_at']))->format(DATE_ATOM), 'expires_at' => (new DateTimeImmutable($row['expires_at']))->format(DATE_ATOM)];
            }
        }
    }
    return ['ok' => true, 'active' => (bool)$schedule, 'schedule' => $schedule, 'schedules' => array_values($schedules), 'location' => $location, 'locations' => $locations, 'waiting' => $waiting, 'my_request' => $myRequest, 'requests' => $requests, 'server_time' => date(DATE_ATOM)];
}

function mbus_tracking_mutate(PDO $pdo, string $role, int $userId, array $data): array
{
    $action = $data['action'];
    $scheduleId = $data['schedule_id'];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT schedule_id, bus_id FROM public.schedule WHERE schedule_id = ? FOR UPDATE');
        $stmt->execute([$scheduleId]);
        $base = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$base) throw new MbusPickupException('Active trip not found.', 404);
        if ($action === 'cancel_waiting') {
            // A commuter must still be able to clear their waiting state after the trip
            // leaves the active tracking feed, as long as the request itself is valid.
            $stmt = $pdo->prepare("UPDATE public.pickup_requests SET status = 'Cancelled', updated_at = clock_timestamp()
                WHERE user_id = ? AND schedule_id = ? AND status = 'Waiting' AND expires_at > clock_timestamp() RETURNING user_id");
            $stmt->execute([$userId, $scheduleId]);
            if (!$stmt->fetchColumn()) throw new MbusPickupException('Your waiting request was not found or has expired.', 404);
            $pdo->commit();
            return ['ok' => true, 'message' => 'Your waiting request was cancelled.'];
        }
        $schedule = mbus_tracking_schedule($pdo, $scheduleId, $role, $userId);
        if (!$schedule) throw new MbusPickupException('This trip is not active or is not available to your account.', 403);
        if ($action === 'update_location') {
            $stmt = $pdo->prepare("SELECT updated_at > clock_timestamp() - INTERVAL '3 seconds' FROM public.bus_live_tracking WHERE schedule_id = ?");
            $stmt->execute([$scheduleId]);
            if ($stmt->fetchColumn()) { $pdo->commit(); return ['ok' => true, 'message' => 'Location update received.']; }
            $stmt = $pdo->prepare('INSERT INTO public.bus_live_tracking(schedule_id,bus_id,operator_id,latitude,longitude,accuracy,captured_at,updated_at)
                VALUES(?,?,?,?,?,?,clock_timestamp(),clock_timestamp()) ON CONFLICT(schedule_id) DO UPDATE SET
                bus_id=EXCLUDED.bus_id,operator_id=EXCLUDED.operator_id,latitude=EXCLUDED.latitude,longitude=EXCLUDED.longitude,
                accuracy=EXCLUDED.accuracy,captured_at=EXCLUDED.captured_at,updated_at=EXCLUDED.updated_at');
            $stmt->execute([$scheduleId, $base['bus_id'], $userId, $data['latitude'], $data['longitude'], $data['accuracy']]);
        } elseif ($action === 'mark_waiting') {
            // Any authenticated commuter may signal that they are waiting, including someone
            // who could not reserve a seat after the cutoff or when a trip is full.
            // Serialize this commuter's request changes and require cancel-then-select to
            // move a still-valid Waiting request to another bus.
            $stmt = $pdo->prepare('SELECT user_id FROM public.users WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $stmt = $pdo->prepare("SELECT schedule_id FROM public.pickup_requests
                WHERE user_id = ? AND status = 'Waiting' AND expires_at > clock_timestamp() FOR UPDATE");
            $stmt->execute([$userId]);
            $existingWait = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existingWait && (int)$existingWait['schedule_id'] !== $scheduleId) {
                throw new MbusPickupException('You are already waiting for another bus. Cancel that request before choosing a different bus.', 409);
            }
            $stmt = $pdo->prepare('INSERT INTO public.pickup_requests
                (user_id,schedule_id,status,latitude,longitude,pickup_label,destination,created_at,updated_at,expires_at)
                VALUES(?, ?, \'Waiting\', ?, ?, ?, ?, clock_timestamp(), clock_timestamp(), clock_timestamp() + INTERVAL \'30 minutes\')
                ON CONFLICT(user_id) DO UPDATE SET schedule_id=EXCLUDED.schedule_id,status=\'Waiting\',
                latitude=EXCLUDED.latitude,longitude=EXCLUDED.longitude,pickup_label=EXCLUDED.pickup_label,
                destination=EXCLUDED.destination,created_at=EXCLUDED.created_at,updated_at=EXCLUDED.updated_at,expires_at=EXCLUDED.expires_at');
            $stmt->execute([$userId, $scheduleId, $data['latitude'], $data['longitude'], $data['pickup_label'], $schedule['destination']]);
        }
        $pdo->commit();
        return ['ok' => true, 'message' => $action === 'mark_waiting' ? 'You are on the waiting list.' : 'Live bus location updated.'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
