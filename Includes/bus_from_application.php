<?php
/* Buses come from driver (operator) applications.
   A driver submits their bus (plate, type, seat layout, photos) when they apply. When the admin approves the
   application the bus is created from that submission and given to the new driver. It starts out with the plate
   as its number; the admin then gives it the real bus number in Manage Buses. Admins do not add buses by hand,
   and they can reassign a bus to another driver (for example when the usual driver is sick). */
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

    /* the plate is the starting bus number; if it is taken, add -2, -3 ... */
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
