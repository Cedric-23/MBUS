<?php
/* Buses come from driver (operator) applications.
   A driver submits their bus (plate, type, seat layout, photos) when they apply. When the admin approves the
   application the bus is created from that submission (type, seat layout, photos) and given to the new driver. It
   starts out named after the driver's last name; the admin can rename it in Manage Buses. Admins do not add buses by hand,
   and they can reassign a bus to another driver (for example when the usual driver is sick). */
require_once __DIR__ . '/seat_layout.php';

/* Last name of the applicant: the saved last name, else the last word of the full name ("Last, First" is understood too). */
function mbus_driver_last_name(array $app): string {
    $last = trim((string)($app['last_name'] ?? ''));
    if ($last !== '') return mb_substr($last, 0, 40);
    $full = trim((string)($app['full_name'] ?? ''));
    if (strpos($full, ',') !== false) return mb_substr(trim(explode(',', $full)[0]), 0, 40);
    $parts = preg_split('/\s+/', $full);
    return mb_substr((string)end($parts), 0, 40);
}

/* Create the bus from one application row and give it to $operatorId.
   Returns ['bus_id' => int, 'bus_number' => string], or null when the submission has no usable bus (no usable seat layout). */
function mbus_bus_from_application(PDO $pdo, array $app, int $operatorId): ?array {
    $layout = mbus_seat_layout_parse((string)($app['seat_layout'] ?? ''));
    $plate = trim((string)($app['vehicle_plate_number'] ?? ''));
    if ($layout === null) return null;
    $count = mbus_seat_layout_count($layout);
    if ($count < 1) return null;
    $type = stripos((string)($app['vehicle_type'] ?? ''), 'air') !== false ? 'Aircon' : 'Ordinary';

    /* The bus starts out named after the driver's last name (the admin can rename it); if that name is taken, add -2, -3 ... */
    $base = mbus_driver_last_name($app);
    if ($base === '') $base = $plate !== '' ? $plate : 'Bus';
    $number = $base;
    $chk = $pdo->prepare("SELECT 1 FROM buses WHERE LOWER(bus_number) = LOWER(?)");
    for ($n = 2; $n <= 50; $n++) {
        $chk->execute([$number]);
        if (!$chk->fetchColumn()) break;
        $number = $base . '-' . $n;
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
