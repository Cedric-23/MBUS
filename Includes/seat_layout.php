<?php
/* Bus seat layouts.
 * A layout is a list of rows; every row has 4 booleans (2 seats, the aisle, 2 seats).
 * true = a seat is there, false = no seat. Seats are numbered 1..N from the front, left to right.
 * Saved in buses.seat_layout (JSON). A bus without one gets a plain layout from its capacity: the driver's cell
 * stays free in the front row, then full rows of 4. */

/** Most seats and doors a bus layout may have. A door is a pair of side-by-side cells with no seats (the driver's cell does not count). */
if (!defined('MBUS_MAX_SEATS')) define('MBUS_MAX_SEATS', 40);
if (!defined('MBUS_MAX_DOORS')) define('MBUS_MAX_DOORS', 2);

/** Validated layout (1-25 rows, 1-40 seats, at most 2 doors) from JSON or an array, or null when it is not usable. */
function mbus_seat_layout_parse($raw): ?array
{
    $layout = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($layout) || count($layout) < 1 || count($layout) > 25) return null;
    $seats = 0; $doors = 0;
    $clean = [];
    foreach ($layout as $row) {
        if (!is_array($row) || count($row) !== 4) return null;
        $cells = [];
        foreach (array_values($row) as $seat) {
            if (!is_bool($seat)) return null;
            if ($seat) $seats++;
            $cells[] = $seat;
        }
        foreach ([0, 2] as $start) {
            if (count($clean) === 0 && $start === 0) continue;   // the driver's cell
            if (!$cells[$start] && !$cells[$start + 1]) $doors++;
        }
        $clean[] = $cells;
    }
    return ($seats >= 1 && $seats <= MBUS_MAX_SEATS && $doors <= MBUS_MAX_DOORS) ? $clean : null;
}

function mbus_seat_layout_count(array $layout): int
{
    $n = 0;
    foreach ($layout as $row) foreach ($row as $seat) if ($seat === true) $n++;
    return $n;
}

/** Plain layout for a bus with no saved one: the driver's cell stays free in the front row, then full rows of 4. */
function mbus_seat_layout_default(int $capacity): array
{
    $capacity = max(1, min(MBUS_MAX_SEATS, $capacity));
    $left = $capacity;
    /* Front row: the front-left pair is the driver's cell, so only the right pair can hold seats. */
    $row = [false, false, false, false];
    for ($i = 2; $i < 4 && $left > 0; $i++) { $row[$i] = true; $left--; }
    $layout = [$row];
    while ($left > 0) {
        $row = [];
        for ($i = 0; $i < 4; $i++) {
            $row[] = $left > 0;
            if ($left > 0) $left--;
        }
        $layout[] = $row;
    }
    return $layout;
}

/**
 * True when the front row already keeps its front-left pair free, so the driver tile fits inside row 1.
 * Buses saved before that pair was reserved keep their seats and get a driver row above the map instead,
 * so no seat number ever moves.
 */
function mbus_seat_rows_driver_inside(array $rows): bool
{
    return isset($rows[0]) && ($rows[0][0] ?? null) === null && ($rows[0][1] ?? null) === null;
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
    /* Legacy buses stored four seats in row 1 before the driver cell was part of the layout. Keep every seat ID,
       but place the old front-right seats (3 and 4) beside the driver and put seats 1 and 2 in the next row. */
    if (isset($rows[0]) && $rows[0][0] !== null && $rows[0][1] !== null && $rows[0][2] !== null && $rows[0][3] !== null) {
        $front = $rows[0];
        $rows[0] = [null, null, $front[2], $front[3]];
        array_splice($rows, 1, 0, [[ $front[0], $front[1], null, null ]]);
    }
    return $rows;
}
