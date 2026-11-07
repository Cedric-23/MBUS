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

function mbus_seat_fare(string $pickup, string $destination): float {
    $t = mbus_fare_table();
    if (!isset($t[$pickup], $t[$destination])) return 0.0;
    return max(0.0, $t[$destination] - $t[$pickup]);
}

/* The account holder's own seat (first seat) gets the discount. */
function mbus_quote_total(array $reservations, string $passenger_type): float {
    $total = 0.0;
    foreach (array_values($reservations) as $i => $r) {
        $fare = mbus_seat_fare((string)$r['pickup_location'], (string)$r['destination']);
        if ($i === 0 && $passenger_type !== 'Regular') $fare -= $fare * MBUS_DISCOUNT_RATE;
        $total += round($fare, 2) + MBUS_RESERVATION_FEE;
    }
    return round($total, 2);
}
