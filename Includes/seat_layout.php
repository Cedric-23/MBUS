<?php
/* Bus seat layouts.
 * A layout is a list of rows; every row has 4 booleans (2 seats, the aisle, 2 seats). The last row may instead be
 * a back bench of 5 seats with no aisle.
 * true = a seat is there, false = no seat. Seats are numbered 1..N from the front, left to right.
 * Saved in buses.seat_layout (JSON). A bus without one gets a plain layout from its capacity: the driver's cell
 * stays free in the front row, then full rows of 4.
 *
 * Doors: every bus has ONE door, and a door takes the place of two side-by-side seats. So a layout is built with
 * two extra seats (the real seat count + 2); the driver removes those two seats in My Bus to make the door and ends up
 * with the real count. Until the door is made the layout is allowed two seats over the normal maximum. */

/** Most seats a bus may have once its door is made. */
if (!defined('MBUS_MAX_SEATS')) define('MBUS_MAX_SEATS', 40);
/** Seats the one door replaces: they exist in a new layout and are removed when the door is made. */
if (!defined('MBUS_DOOR_SEATS')) define('MBUS_DOOR_SEATS', 2);
/** A bus has a single door: a pair of side-by-side cells with no seats (the driver's cell does not count). */
if (!defined('MBUS_MAX_DOORS')) define('MBUS_MAX_DOORS', 1);

/** Number of doors in a layout (side-by-side empty pairs; the driver's front-left pair does not count). */
function mbus_seat_layout_doors(array $layout): int
{
    $doors = 0;
    foreach (array_values($layout) as $index => $row) {
        if (!is_array($row) || count($row) !== 4) continue;   // the 5-seat back bench has no doors
        foreach ([0, 2] as $start) {
            if ($index === 0 && $start === 0) continue;       // the driver's cell
            if (empty($row[$start]) && empty($row[$start + 1])) $doors++;
        }
    }
    return $doors;
}

/** Validated layout (1-25 rows, at most 1 door, 1-40 seats, or up to 42 while the door is not made yet) from JSON or an array, or null when it is not usable. */
function mbus_seat_layout_parse($raw): ?array
{
    $layout = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($layout) || count($layout) < 1 || count($layout) > 25) return null;
    $seats = 0;
    $clean = [];
    foreach ($layout as $row) {
        if (!is_array($row)) return null;
        $isBench = count($row) === 5 && count($clean) === count($layout) - 1;   // the back row: 5 seats side by side, no aisle
        if (count($row) !== 4 && !$isBench) return null;
        $cells = [];
        foreach (array_values($row) as $seat) {
            if (!is_bool($seat)) return null;
            if ($seat) $seats++;
            $cells[] = $seat;
        }
        if ($isBench && in_array(false, $cells, true)) return null;
        $clean[] = $cells;
    }
    $doors = mbus_seat_layout_doors($clean);
    $maxSeats = MBUS_MAX_SEATS + ($doors === 0 ? MBUS_DOOR_SEATS : 0);
    return ($seats >= 1 && $seats <= $maxSeats && $doors <= MBUS_MAX_DOORS) ? $clean : null;
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
    $capacity = max(1, min(MBUS_MAX_SEATS + MBUS_DOOR_SEATS, $capacity));
    $left = $capacity;
    /* Front row: the front-left pair is the driver's cell, so only the right pair can hold seats. */
    $row = [false, false, false, false];
    for ($i = 2; $i < 4 && $left > 0; $i++) { $row[$i] = true; $left--; }
    $layout = [$row];
    /* The back row is a bench of 5 seats (no aisle), so set those 5 aside when the bus is big enough. */
    $bench = $capacity >= 9;
    if ($bench) $left -= 5;
    while ($left > 0) {
        $row = [];
        for ($i = 0; $i < 4; $i++) {
            $row[] = $left > 0;
            if ($left > 0) $left--;
        }
        $layout[] = $row;
    }
    if ($bench) $layout[] = [true, true, true, true, true];
    return $layout;
}

/**
 * Layout for a bus whose driver entered their real number of seats. It gets the 2 seats the door will replace
 * (30 seats entered = 32 in the layout), so removing the door pair in My Bus leaves exactly the real count.
 */
function mbus_seat_layout_with_door_room(int $realSeats): array
{
    return mbus_seat_layout_default(max(1, min(MBUS_MAX_SEATS, $realSeats)) + MBUS_DOOR_SEATS);
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
    /* Legacy buses stored four seats in row 1 before the driver cell was part of the layout. Normalize their display
       to the current design: Driver, seats 1 and 2, then seats 3 onward. This keeps each seat visible exactly once. */
    if (isset($rows[0]) && $rows[0][0] !== null && $rows[0][1] !== null && $rows[0][2] !== null && $rows[0][3] !== null) {
        $seatCount = mbus_seat_layout_count($layout);
        $legacy = [[null, null, $seatCount >= 1 ? 1 : null, $seatCount >= 2 ? 2 : null]];
        $next = 3;
        while ($next <= $seatCount) {
            $row = [];
            for ($i = 0; $i < 4; $i++) $row[] = $next <= $seatCount ? $next++ : null;
            $legacy[] = $row;
        }
        $rows = $legacy;
    }
    return $rows;
}
