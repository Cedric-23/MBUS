<?php
/* Driver home: the driver's bus (with its conductor), what is scheduled today, and every upcoming schedule.
 *
 * Data model (no schema change): operator_bus_assignments links a driver to a bus, and
 * conductor_bus_assignments links that bus to its conductor (at most one per bus). A trip's conductor is
 * therefore the conductor of the trip's bus.
 */

function mbus_dh_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mbus_dh_bus_label($busNumber): string
{
    return ctype_digit((string)$busNumber) ? 'Bus ' . $busNumber : (string)$busNumber;
}

/** Loads this driver's buses and upcoming trips. Never throws: a failed query just yields empty lists. */
function mbus_driver_home_data($conn, int $driverId, array $bounds): array
{
    $buses = [];
    $bq = mbus_db_prepare($conn, "
        SELECT b.bus_id, b.bus_number, b.bus_type, b.capacity,
               (b.bus_photo_b64 IS NOT NULL AND b.bus_photo_b64 <> '') AS has_photo,
               cu.full_name AS conductor_name, cu.phone_number AS conductor_phone
        FROM buses b
        JOIN operator_bus_assignments oba ON oba.bus_id = b.bus_id
        LEFT JOIN conductor_bus_assignments cba ON cba.bus_id = b.bus_id
        LEFT JOIN users cu ON cu.user_id = cba.conductor_id
        WHERE oba.operator_id = ?
        ORDER BY b.bus_number", [$driverId]);
    while ($bq !== false && ($r = mbus_db_fetch_assoc($bq))) {
        $r['has_photo'] = filter_var($r['has_photo'], FILTER_VALIDATE_BOOLEAN);
        $buses[] = $r;
    }

    /* Everything from the start of today onward, soonest first. */
    $trips = [];
    $tq = mbus_db_prepare($conn, "
        SELECT s.schedule_id, s.departure_time, s.arrival_time, s.trip_status,
               b.bus_id, b.bus_number, b.capacity, rt.origin, rt.destination,
               cu.full_name AS conductor_name,
               (SELECT COUNT(*) FROM reservation r WHERE r.schedule_id = s.schedule_id AND r.status IN ('Paid','Confirmed','Boarded')) AS booked,
               (SELECT COUNT(*) FROM reservation r WHERE r.schedule_id = s.schedule_id AND r.status = 'Boarded') AS boarded
        FROM schedule s
        JOIN buses b ON b.bus_id = s.bus_id
        JOIN routes rt ON rt.route_id = s.route_id
        JOIN operator_bus_assignments oba ON oba.bus_id = s.bus_id
        LEFT JOIN conductor_bus_assignments cba ON cba.bus_id = s.bus_id
        LEFT JOIN users cu ON cu.user_id = cba.conductor_id
        WHERE oba.operator_id = ?
          AND s.schedule_status = 'Active'
          AND s.trip_status IN ('Scheduled','Boarding','Departed')
          AND s.departure_time >= ?
        ORDER BY s.departure_time ASC
        LIMIT 60", [$driverId, $bounds['today']]);
    while ($tq !== false && ($r = mbus_db_fetch_assoc($tq))) $trips[] = $r;

    $today = array_values(array_filter($trips, fn($t) => $t['departure_time'] < $bounds['tomorrow']));
    // Running trips first, then boarding, then by departure time (stable for equal ranks).
    $rank = ['Departed' => 0, 'Boarding' => 1, 'Scheduled' => 2];
    usort($today, fn($a, $b) => [$rank[$a['trip_status']] ?? 3, $a['departure_time']] <=> [$rank[$b['trip_status']] ?? 3, $b['departure_time']]);
    $later = array_values(array_filter($trips, fn($t) => $t['departure_time'] >= $bounds['tomorrow']));

    return ['buses' => $buses, 'trips' => $trips, 'today' => $today, 'later' => $later];
}

function mbus_dh_conductor_html(?string $name, bool $short = false): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '<span class="dh-nocond"><i class="fa fa-user-slash" aria-hidden="true"></i> No conductor yet</span>';
    }
    return '<span class="dh-cond"><i class="fa fa-user-tie" aria-hidden="true"></i> ' . mbus_dh_h($short ? explode(' ', $name)[0] : $name) . '</span>';
}

function mbus_dh_state(string $status): array
{
    return [
        'Departed'  => ['On the road', 'is-road'],
        'Boarding'  => ['Boarding now', 'is-board'],
        'Scheduled' => ['Up next', 'is-next'],
    ][$status] ?? ['Up next', 'is-next'];
}

function mbus_dh_day_label(string $departure, string $todayYmd): string
{
    $d = date('Y-m-d', strtotime($departure));
    if ($d === $todayYmd) return 'Today';
    if ($d === date('Y-m-d', strtotime($todayYmd . ' +1 day'))) return 'Tomorrow';
    return date('D, M j', strtotime($departure));
}

/** The highlighted "scheduled today" card (or a calm "nothing today" card). */
function mbus_driver_home_today_html(array $d, string $todayYmd): string
{
    $today = $d['today'];
    if (!$today) {
        $next = $d['later'][0] ?? null;
        $line = $next
            ? 'Next trip: <strong>' . mbus_dh_h(mbus_dh_day_label($next['departure_time'], $todayYmd) . ' at ' . date('g:i A', strtotime($next['departure_time']))) . '</strong> · ' . mbus_dh_h($next['origin'] . ' to ' . $next['destination'])
            : 'Nothing is scheduled for your bus yet.';
        return '<section class="dh-today is-none" aria-label="Today"><span class="dh-pill is-quiet">Today</span>'
            . '<h2>No trip scheduled today</h2><p>' . $line . '</p></section>';
    }

    $t = $today[0];
    [$stateText, $stateClass] = mbus_dh_state($t['trip_status']);
    $booked = (int)$t['booked'];
    $out  = '<section class="dh-today" aria-label="Scheduled today">';
    $out .= '<div class="dh-today-top"><span class="dh-pill">Scheduled today</span><span class="dh-state ' . $stateClass . '">' . mbus_dh_h($stateText) . '</span></div>';
    $out .= '<div class="dh-today-main"><div class="dh-today-time">' . date('g:i', strtotime($t['departure_time'])) . '<small>' . date('A', strtotime($t['departure_time'])) . '</small></div>';
    $out .= '<div class="dh-today-route"><strong>' . mbus_dh_h($t['origin']) . '</strong><span aria-hidden="true">to</span><strong>' . mbus_dh_h($t['destination']) . '</strong></div></div>';
    $out .= '<div class="dh-today-meta"><span><i class="fa fa-bus-simple" aria-hidden="true"></i> ' . mbus_dh_h(mbus_dh_bus_label($t['bus_number'])) . '</span>'
        . mbus_dh_conductor_html($t['conductor_name'])
        . '<span><i class="fa fa-users" aria-hidden="true"></i> ' . (int)$t['boarded'] . ' of ' . $booked . ' boarded</span></div>';

    if (count($today) > 1) {
        $out .= '<div class="dh-today-more"><h3>Also today</h3><ul>';
        foreach (array_slice($today, 1) as $x) {
            [$sText] = mbus_dh_state($x['trip_status']);
            $out .= '<li><b>' . date('g:i A', strtotime($x['departure_time'])) . '</b><span>' . mbus_dh_h($x['origin'] . ' to ' . $x['destination']) . '</span><em>' . mbus_dh_h($sText) . '</em></li>';
        }
        $out .= '</ul></div>';
    }
    return $out . '</section>';
}

/** The driver's bus card(s), each with its conductor. */
function mbus_driver_home_buses_html(array $d, string $root = '../'): string
{
    $out = '<section class="home-section" aria-labelledby="dh-bus-h"><div class="home-section-head"><h2 id="dh-bus-h">'
        . (count($d['buses']) === 1 ? 'Your bus' : 'Your buses') . '</h2><a href="my_bus.php">My Bus</a></div>';
    if (!$d['buses']) {
        return $out . '<p class="attn is-clear"><span><strong>No bus assigned yet</strong><small>An admin assigns your bus once your application is approved.</small></span></p></section>';
    }
    $out .= '<ul class="dh-buses">';
    foreach ($d['buses'] as $b) {
        $img = $b['has_photo']
            ? '<span class="dh-bus-img" style="background-image:url(' . mbus_dh_h($root . 'Operator/bus_photo.php?bus_id=' . (int)$b['bus_id']) . ')"></span>'
            : '<span class="dh-bus-img"><i class="fa fa-bus" aria-hidden="true"></i></span>';
        $phone = trim((string)($b['conductor_phone'] ?? ''));
        $out .= '<li>' . $img . '<div class="dh-bus-body"><strong>' . mbus_dh_h(mbus_dh_bus_label($b['bus_number'])) . '</strong>'
            . '<small>' . mbus_dh_h(trim($b['bus_type'] . ' · ' . (int)$b['capacity'] . ' seats', ' ·')) . '</small>'
            . '<div class="dh-crew">' . mbus_dh_conductor_html($b['conductor_name'])
            . ($phone !== '' && trim((string)$b['conductor_name']) !== '' ? ' <a class="dh-tel" href="tel:' . mbus_dh_h(preg_replace('/[^0-9+]/', '', $phone)) . '">' . mbus_dh_h($phone) . '</a>' : '')
            . '</div></div></li>';
    }
    return $out . '</ul></section>';
}

/** Every upcoming schedule, grouped by day; today's group is highlighted. */
function mbus_driver_home_schedule_html(array $d, string $todayYmd): string
{
    $out = '<section class="home-section" aria-labelledby="dh-sched-h"><div class="home-section-head"><h2 id="dh-sched-h">All schedules</h2></div>';
    if (!$d['trips']) {
        return $out . '<p class="attn is-clear"><span><strong>No upcoming schedules</strong><small>Trips set up for your bus will show here.</small></span></p></section>';
    }
    $groups = [];
    foreach ($d['trips'] as $t) $groups[date('Y-m-d', strtotime($t['departure_time']))][] = $t;
    ksort($groups);
    foreach ($groups as $ymd => $rows) {
        $isToday = $ymd === $todayYmd;
        $out .= '<div class="dh-day' . ($isToday ? ' is-today' : '') . '"><h3>' . mbus_dh_h(mbus_dh_day_label($rows[0]['departure_time'], $todayYmd))
            . ($isToday ? ' <span class="dh-pill">Scheduled today</span>' : '') . '</h3><ul class="dh-sched">';
        foreach ($rows as $t) {
            $full = (int)$t['booked'] >= (int)$t['capacity'];
            [$sText, $sClass] = mbus_dh_state($t['trip_status']);
            $out .= '<li><span class="dh-time">' . date('g:i', strtotime($t['departure_time'])) . '<small>' . date('A', strtotime($t['departure_time'])) . '</small></span>'
                . '<span class="dh-body"><strong>' . mbus_dh_h($t['origin'] . ' to ' . $t['destination']) . '</strong>'
                . '<small>' . mbus_dh_h(mbus_dh_bus_label($t['bus_number'])) . ' · ' . mbus_dh_conductor_html($t['conductor_name']) . ($isToday ? ' · <i class="dh-state-text ' . $sClass . '">' . mbus_dh_h($sText) . '</i>' : '') . '</small></span>'
                . '<span class="seat-chip ' . ($full ? 'full' : 'ok') . '">' . (int)$t['booked'] . '/' . (int)$t['capacity'] . '</span></li>';
        }
        $out .= '</ul></div>';
    }
    return $out . '</section>';
}
