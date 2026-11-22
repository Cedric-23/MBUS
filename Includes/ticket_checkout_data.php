<?php
/**
 * Resolve paid tickets to the exact checkout and reservation set that was charged.
 *
 * One trip can have several paid checkouts for the same rider (for example seat 3 booked on its own,
 * then seats 4 and 5 booked together). mbus_ticket_checkouts() returns every one of them, newest first;
 * mbus_ticket_checkout_data() keeps the old "newest only" behaviour for callers that need a single ticket.
 */
function mbus_ticket_checkouts($conn, int $userId, ?int $scheduleId = null, ?int $reservationId = null, int $limit = 0): array
{
    if ($userId <= 0 || (($scheduleId ?? 0) <= 0 && ($reservationId ?? 0) <= 0)) {
        return [];
    }

    if (($reservationId ?? 0) > 0) {
        $targetQuery = mbus_db_prepare($conn, "SELECT schedule_id FROM reservation
            WHERE reservation_id = ? AND user_id = ? AND status IN ('Paid', 'Boarded')",
            [$reservationId, $userId]);
        $target = $targetQuery === false ? false : mbus_db_fetch_assoc($targetQuery);
        if (!$target) {
            return [];
        }
        $targetSchedule = (int)$target['schedule_id'];
        if (($scheduleId ?? 0) > 0 && $scheduleId !== $targetSchedule) {
            return [];
        }
        $scheduleId = $targetSchedule;
    }

    $paymentsQuery = mbus_db_prepare($conn, "SELECT p.payment_id, p.reservation_id, p.amount,
            p.payment_method, p.payment_reference, p.payment_status, p.ticket_code, p.payment_date
        FROM payment p
        JOIN reservation r ON r.reservation_id = p.reservation_id
        WHERE r.user_id = ? AND r.schedule_id = ?
          AND r.status IN ('Paid', 'Boarded') AND p.payment_status = 'Paid'
        ORDER BY p.payment_id DESC", [$userId, $scheduleId]);
    if ($paymentsQuery === false) {
        return [];
    }

    $tickets = [];
    $seen = [];
    while ($paymentRow = mbus_db_fetch_assoc($paymentsQuery)) {
        $checkout = false;
        if (!empty($paymentRow['ticket_code'])) {
            $checkoutQuery = mbus_db_prepare($conn, "SELECT reservation_ids, lines, amount, payment_ref,
                    paid_at, ticket_code
                FROM paymongo_checkout
                WHERE ticket_code = ? AND user_id = ? AND schedule_id = ? AND status = 'Paid'
                ORDER BY paid_at DESC LIMIT 1",
                [$paymentRow['ticket_code'], $userId, $scheduleId]);
            $checkout = $checkoutQuery === false ? false : mbus_db_fetch_assoc($checkoutQuery);
        }

        $ids = [];
        if ($checkout && !empty($checkout['reservation_ids'])) {
            $decoded = json_decode((string)$checkout['reservation_ids'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $id) {
                    if (is_scalar($id) && (int)$id > 0) {
                        $ids[] = (int)$id;
                    }
                }
            }
            $ids = array_values(array_unique($ids));
        }

        if (!$ids) {
            // Older paid records predate checkout-to-ticket linkage; never combine
            // their unrelated reservations under one payment row.
            $ids = [(int)$paymentRow['reservation_id']];
            $checkout = false;
        }
        if (($reservationId ?? 0) > 0 && !in_array($reservationId, $ids, true)) {
            continue;
        }

        // Every payment row of one checkout points at the same set of seats: list that ticket once.
        $key = $ids;
        sort($key);
        $key = implode(',', $key);
        if (isset($seen[$key])) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $reservationQuery = mbus_db_prepare($conn, "SELECT reservation_id, schedule_id, seat_number,
                pickup_location, destination, status, passenger_type, fare_amount, discount_amount
            FROM reservation
            WHERE user_id = ? AND schedule_id = ? AND reservation_id IN ($placeholders)
              AND status IN ('Paid', 'Boarded')
            ORDER BY reservation_id ASC",
            array_merge([$userId, $scheduleId], $ids));
        if ($reservationQuery === false || mbus_db_num_rows($reservationQuery) !== count($ids)) {
            continue;
        }

        $reservations = [];
        while ($reservationRow = mbus_db_fetch_assoc($reservationQuery)) {
            $reservations[] = $reservationRow;
        }

        $lines = [];
        if ($checkout && !empty($checkout['lines'])) {
            $decodedLines = json_decode((string)$checkout['lines'], true);
            if (is_array($decodedLines)) {
                foreach ($decodedLines as $line) {
                    if (is_array($line) && in_array((int)($line['reservation_id'] ?? 0), $ids, true)) {
                        $lines[(int)$line['reservation_id']] = $line;
                    }
                }
            }
        }

        $ticket = $paymentRow;
        if ($checkout) {
            $ticket['amount'] = $checkout['amount'];
            $ticket['payment_reference'] = $checkout['payment_ref'] ?: $paymentRow['payment_reference'];
            $ticket['payment_date'] = $checkout['paid_at'] ?: $paymentRow['payment_date'];
            $ticket['ticket_code'] = $checkout['ticket_code'] ?: $paymentRow['ticket_code'];
        }

        $seen[$key] = true;
        $tickets[] = [
            'schedule_id' => (int)$scheduleId,
            'reservations' => $reservations,
            'payment' => $ticket,
            'lines' => $lines,
        ];
        if ($limit > 0 && count($tickets) >= $limit) {
            break;
        }
    }

    return $tickets;
}

/** The newest paid ticket of a trip (or the ticket that contains one given reservation). */
function mbus_ticket_checkout_data($conn, int $userId, ?int $scheduleId = null, ?int $reservationId = null): ?array
{
    $all = mbus_ticket_checkouts($conn, $userId, $scheduleId, $reservationId, 1);
    return $all[0] ?? null;
}
