<?php
/* Buses come from driver (operator) applications.
   A driver submits their bus (plate, type, seat layout, photos) when they apply. When the admin approves the
   application the bus is created from that submission and given to the new driver. Admins do not add buses by hand:
   they can only reassign a bus to another driver (for example when the usual driver is sick). */
require_once __DIR__ . '/seat_layout.php';

/* Create the bus from one application row and give it to $operatorId.
   Returns ['bus_id' => int, 'bus_number' => string], or null when the submission has no usable bus (no plate or no seats). */
function mbus_bus_from_application(PDO $pdo, array $app, int $operatorId): ?array {
    $layout = mbus_seat_layout_parse((string)($app['seat_layout'] ?? ''));
    $plate = trim((string)($app['vehicle_plate_number'] ?? ''));
    if ($layout === null || $plate === '') return null;
    $count = mbus_seat_layout_count($layout);
    if ($count < 1) return null;
    $type = stripos((string)($app['vehicle_type'] ?? ''), 'air') !== false ? 'Aircon' : 'Ordinary';

    /* the plate is the bus number; if it is taken, add -2, -3 ... */
    $number = $plate;
    $chk = $pdo->prepare("SELECT 1 FROM buses WHERE LOWER(bus_number) = LOWER(?)");
    for ($n = 2; $n <= 50; $n++) {
        $chk->execute([$number]);
        if (!$chk->fetchColumn()) break;
        $number = $plate . '-' . $n;
        if ($n === 50) return null;
    }

    $ins = $pdo->prepare("INSERT INTO buses (bus_number, bus_type, capacity, seat_layout, bus_photo_b64, bus_photo_mime, interior_photo_b64, interior_photo_mime)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING bus_id");
    $ins->execute([
        $number, $type, $count, json_encode($layout),
        $app['bus_photo_b64'] ?? null, $app['bus_photo_mime'] ?? null,
        $app['interior_photo_b64'] ?? null, $app['interior_photo_mime'] ?? null,
    ]);
    $busId = (int)$ins->fetchColumn();
    if ($busId < 1) return null;
    $pdo->prepare("INSERT INTO operator_bus_assignments (operator_id, bus_id) VALUES (?, ?)")->execute([$operatorId, $busId]);
    return ['bus_id' => $busId, 'bus_number' => $number];
}

/* Catch up drivers who were approved before buses were created automatically: every approved driver with no bus
   and no bus carrying their plate gets one from their submission. A bus that was handed to another driver keeps
   its plate, so it is never created a second time. Returns how many buses were created. */
function mbus_sync_driver_buses(PDO $pdo): int {
    $made = 0;
    try {
        $rows = $pdo->query("SELECT a.application_id, a.user_id, a.vehicle_type, a.vehicle_plate_number, a.seat_layout,
                a.bus_photo_b64, a.bus_photo_mime, a.interior_photo_b64, a.interior_photo_mime
            FROM operator_application a
            WHERE a.application_status::text = 'Approved' AND a.user_id IS NOT NULL
              AND TRIM(COALESCE(a.vehicle_plate_number, '')) <> ''
              AND NOT EXISTS (SELECT 1 FROM operator_bus_assignments oa WHERE oa.operator_id = a.user_id)
              AND NOT EXISTS (SELECT 1 FROM buses b WHERE LOWER(b.bus_number) = LOWER(TRIM(a.vehicle_plate_number)))
            ORDER BY a.application_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Driver bus sync lookup failed: ' . $e->getMessage());
        return 0;
    }
    foreach ($rows as $app) {
        try {
            $pdo->beginTransaction();
            if (mbus_bus_from_application($pdo, $app, (int)$app['user_id'])) $made++;
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Driver bus sync failed for application ' . $app['application_id'] . ': ' . $e->getMessage());
        }
    }
    return $made;
}
