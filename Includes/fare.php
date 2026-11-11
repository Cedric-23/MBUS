<?php
/* Fare table, passenger type and discount rules shared by reserve + payment. */

const MBUS_RESERVATION_FEE = 10.00;
const MBUS_DISCOUNT_RATE = 0.20;

function mbus_fare_table(): array {
    return [
    "SABANG"=>14.00,"ANVAYA"=>20.38,"MABAYO"=>30.28,"MINANGA"=>30.28,"MORONG GATE"=>41.06,
    "GROUP 6"=>41.06,"IDESS"=>41.06,"APPAREL"=>51.84,"GATE 2"=>51.84,"TRIBOA"=>54.70,
    "TOWER"=>54.70,"KORYO"=>65.26,"TECHNO"=>65.26,"UA"=>65.26,"NSD"=>69.66,"PETRON"=>69.66,
    "INDUSTRIAL"=>69.66,"HARBOR POINT"=>75.50,"MAIN GATE"=>75.50,"MORONG TERMINAL"=>75.50,
    "BINARITAN"=>75.50,"POBLACION"=>75.50,"HILLTOP"=>75.50
    ];
}

/* Passenger type = latest approved, unexpired verification; otherwise Regular. */
function mbus_passenger_type($conn, int $user_id): string {
    $q = mbus_db_prepare($conn, "
        SELECT verification_type FROM commuter_verification
        WHERE user_id = ? AND verification_status = 'Approved'
        AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE)
        ORDER BY reviewed_at DESC NULLS LAST, verification_id DESC LIMIT 1
    ", [$user_id]);
    $row = $q === false ? false : mbus_db_fetch_assoc($q);
    return $row && !empty($row['verification_type']) ? $row['verification_type'] : 'Regular';
}

const MBUS_MIN_FARE = 14.00;
const MBUS_PASSENGER_TYPES = ['Regular', 'Student', 'Senior', 'PWD'];

/* Distance-fare position of each stop measured from the Morong end of the route.
   The Morong-side stops sit at 0; the table value is the fare from there. */
function mbus_stop_positions(): array {
    $pos = mbus_fare_table();
    foreach (['MORONG TERMINAL', 'BINARITAN', 'POBLACION', 'HILLTOP'] as $s) { $pos[$s] = 0.0; }
    return $pos;
}

/* One seat's base fare. Works in both directions (Morong->Harbor and back),
   never below the minimum fare, 0 only when pickup/destination are unknown or equal. */
function mbus_seat_fare(string $pickup, string $destination): float {
    $p = mbus_stop_positions();
    $pickup = strtoupper(trim($pickup)); $destination = strtoupper(trim($destination));
    if ($pickup === $destination || !isset($p[$pickup], $p[$destination])) return 0.0;
    return round(max(MBUS_MIN_FARE, abs($p[$destination] - $p[$pickup])), 2);
}

/* Per-seat lines: base fare, 20% discount for Student/Senior/PWD, plus reservation fee.
   $types maps reservation_id => passenger type (missing = Regular). */
function mbus_quote_lines(array $reservations, array $types = []): array {
    $lines = []; $total = 0.0;
    foreach (array_values($reservations) as $r) {
        $type = $types[$r['reservation_id']] ?? 'Regular';
        if (!in_array($type, MBUS_PASSENGER_TYPES, true)) $type = 'Regular';
        $base = mbus_seat_fare((string)$r['pickup_location'], (string)$r['destination']);
        $discount = $type === 'Regular' ? 0.0 : round($base * MBUS_DISCOUNT_RATE, 2);
        $final = round($base - $discount + MBUS_RESERVATION_FEE, 2);
        $lines[] = ['reservation_id' => (int)$r['reservation_id'], 'seat' => $r['seat_number'], 'type' => $type,
                    'base' => $base, 'discount' => $discount, 'fee' => MBUS_RESERVATION_FEE, 'final' => $final];
        $total += $final;
    }
    return ['lines' => $lines, 'total' => round($total, 2)];
}

function mbus_quote_total(array $reservations, string $passenger_type): float {
    $types = [];
    foreach (array_values($reservations) as $i => $r) { $types[$r['reservation_id']] = $i === 0 ? $passenger_type : 'Regular'; }
    return mbus_quote_lines($reservations, $types)['total'];
}
