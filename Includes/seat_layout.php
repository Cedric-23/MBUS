<?php
/* Bus seat layouts.
 * A layout is a list of rows; every row has 4 booleans (2 seats, the aisle, 2 seats).
 * true = a seat is there, false = no seat. Seats are numbered 1..N from the front, left to right.
 * Saved in buses.seat_layout (JSON). A bus without one gets a plain 4-per-row layout from its capacity. */

/** Validated layout (1-25 rows, 1-100 seats) from JSON or an array, or null when it is not usable. */
function mbus_seat_layout_parse($raw): ?array
{
    $layout = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($layout) || count($layout) < 1 || count($layout) > 25) return null;
    $seats = 0;
    $clean = [];
    foreach ($layout as $row) {
        if (!is_array($row) || count($row) !== 4) return null;
        $cells = [];
        foreach (array_values($row) as $seat) {
            if (!is_bool($seat)) return null;
            if ($seat) $seats++;
            $cells[] = $seat;
        }
        $clean[] = $cells;
    }
    return ($seats >= 1 && $seats <= 100) ? $clean : null;
}

function mbus_seat_layout_count(array $layout): int
{
    $n = 0;
    foreach ($layout as $row) foreach ($row as $seat) if ($seat === true) $n++;
    return $n;
}

/** Plain layout for a bus with no saved one: full rows of 4, last row filled from the left. */
function mbus_seat_layout_default(int $capacity): array
{
    $capacity = max(1, min(100, $capacity));
    $layout = [];
    for ($n = 0; $n < $capacity; $n += 4) {
        $row = [];
        for ($i = 0; $i < 4; $i++) $row[] = ($n + $i) < $capacity;
        $layout[] = $row;
    }
    return $layout;
}

/**
 * Rows of 4 cells for drawing a seat map: the seat number, or null where there is no seat.
 * A saved layout is only used while its seat count still equals the bus capacity.
 */
function mbus_bus_seat_rows($savedLayout, int $capacity): array
{
    if ($capacity < 1) return [];
    $layout = ($savedLayout === null || $savedLayout === '') ? null : mbus_seat_layout_parse($savedLayout);
    if ($layout === null || mbus_seat_layout_count($layout) !== $capacity) $layout = mbus_seat_layout_default($capacity);
    $number = 1;
    $rows = [];
    foreach ($layout as $row) {
        $cells = [];
        foreach ($row as $seat) $cells[] = $seat ? $number++ : null;
        $rows[] = $cells;
    }
    return $rows;
}
