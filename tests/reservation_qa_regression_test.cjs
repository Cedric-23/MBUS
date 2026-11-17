const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');

const reserveJs = read('Assets/js/commuter_reserve.js');
const reservePhp = read('Commuter/reserve.php');
const payment = read('Commuter/payment.php');
const reservations = read('Commuter/my_reservations.php');
const cancel = read('Commuter/cancel_reservation.php');
const ticketData = read('Includes/ticket_checkout_data.php');
const ticketPage = read('Commuter/ticket.php');
const ticketPdf = read('Commuter/download_ticket.php');
const db = read('config/db_connect.php');
const migration = read('supabase/17_reservation_expiry.sql');
const allExpirySources = [
  reservePhp, payment, reservations, read('Commuter/schedule.php'), read('Commuter/commuter_dashboard.php'),
  read('api/get_seat_availability.php'), read('api/get_calendar_events.php'), read('Operator/passengers.php'), read('Includes/pickup.php')
].join('\n');

assert.match(reserveJs, /reservationMode === "solo" && !destination\.value/, 'multi mode must not require the hidden shared destination');
assert.match(reserveJs, /Choose a destination for every selected seat/);
assert.match(reservePhp, /\$destination_by_seat\[\$seat_no\]/, 'server must retain the destination per seat');
assert.match(reservePhp, /created_at,expires_at,status/);
assert.match(reserveJs, /reservationMode === "multi" && selectedSeats\.some\(seat => !seatDestinations\[seat\]\)/, 'multi booking must require a destination for every seat');
assert.match(migration, /ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ/);
assert.match(migration, /reservation_pending_expiry_idx/);
assert.match(db, /ADD COLUMN IF NOT EXISTS ' \. \$col/);
assert.doesNotMatch(allExpirySources, /(?:r\.|rv\.|reservation\.)?created_at\s*[<>]=?\s*NOW\(\)\s*-\s*INTERVAL\s*'10 minutes'/, 'all pending hold checks must use the persisted expiry timestamp');
assert.match(payment, /strtotime\(\(string\)\$r\['expires_at'\]\)/);
assert.match(payment, /expires_at = NOW\(\) \+ INTERVAL '15 minutes'/);
assert.match(reservations, /expires_at <= NOW\(\)/);
assert.match(reservations, /meta name="csrf-token"/);
assert.match(cancel, /csrf_validate_token\(\)/);
assert.match(cancel, /UPDATE reservation SET status = 'Cancelled'/);
assert.match(cancel, /UPDATE paymongo_checkout SET status = 'Cancelled'/);
assert.match(cancel, /fetchAll\(PDO::FETCH_COLUMN\)/, 'endpoint must verify at least one row changed before reporting success');
assert.match(ticketData, /WHERE ticket_code = \? AND user_id = \? AND schedule_id = \? AND status = 'Paid'/);
assert.match(ticketData, /reservation_ids/);
assert.match(ticketPage, /foreach \(\$reservations as \$reservation\)/, 'screen ticket must show each seat route');
assert.match(ticketPage, /\$fareLines\[\(int\)\$reservation\['reservation_id'\]\]/, 'screen ticket must use the matching checkout fare line');
assert.match(ticketPdf, /foreach \(\$reservations as \$reservation\)/, 'PDF ticket must show each seat route');
assert.match(read('Commuter/history.php'), /ticket\.php\?reservation_id=/, 'history tickets must identify the selected reservation');
const history = read('Commuter/history.php');
const tripReservations = read('Commuter/my_reservations.php');
const tripStatusApi = read('api/commuter_trip_status.php');
assert.match(tripReservations, /schedule\.trip_status/, 'the passenger Trips view must read current operator trip status');
assert.match(tripReservations, /trip-completion-confirmation/, 'the Trips view must render a clear completion confirmation');
assert.match(tripReservations, /refreshTripCompletion/, 'the Trips view should detect arrival without a manual refresh');
assert.match(tripStatusApi, /\$_SESSION\['user_type'\].*commuter/s, 'only commuter sessions may poll passenger trip completion');
assert.match(tripStatusApi, /reservation\.user_id = \?/, 'trip-status polling must be restricted to the signed-in passenger');
assert.match(tripStatusApi, /reservation\.status = \\'Boarded\\'/, 'trip completion status should only be returned for boarded reservations');
assert.match(tripStatusApi, /trip_status.*Arrived/s, 'Arrived should be reported as a completed trip');
assert.match(history, /schedule\.trip_status/, 'trip history must retain the completion confirmation');

console.log('PASS: group destinations, shared reservation expiry, safe cancellation, and transaction-scoped seat tickets');
