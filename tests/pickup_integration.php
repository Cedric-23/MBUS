<?php
/** LOCAL ONLY: requires an explicitly named isolated mbus_pickup_test database.
 * Schema: supabase/01_schema.sql then supabase/10_live_pickup.sql.
 * MBUS_PICKUP_TEST_DSN='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test' php tests/pickup_integration.php
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../Includes/pickup.php';
$dsn = getenv('MBUS_PICKUP_TEST_DSN') ?: '';
if ($dsn !== 'pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test') {
    throw new RuntimeException('Refusing to test outside the explicit local mbus_pickup_test database.');
}
$pdo = new PDO($dsn, 'ubuntu', null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'mbus_pickup_test') throw new RuntimeException('Unsafe database');
$pdo->exec("SET TIME ZONE 'Asia/Manila'");
$pdo->exec('TRUNCATE pickup_requests, reservation, payment, schedule, buses, routes, operator_bus_assignments, users RESTART IDENTITY CASCADE');
$pdo->exec("INSERT INTO users(user_id,full_name,email,password,user_type) VALUES
    (901,'Rider One','r1@test.invalid','test','Commuter'),
    (902,'Operator One','o1@test.invalid','test','Operator'),
    (903,'Operator Two','o2@test.invalid','test','Operator'),
    (904,'Administrator','a@test.invalid','test','Admin'),
    (905,'Rider Two','r2@test.invalid','test','Commuter')");
$pdo->exec("INSERT INTO routes(route_id,origin,destination,fare) VALUES(1,'MORONG TERMINAL','SBMA',75.50)");
$pdo->exec("INSERT INTO buses(bus_id,bus_number,bus_type,capacity) VALUES(11,'B11','Regular',4),(12,'B12','Regular',4)");
$pdo->exec("INSERT INTO operator_bus_assignments(operator_id,bus_id) VALUES(902,11),(903,12)");
$now = time();
$stmt = $pdo->prepare('INSERT INTO schedule(schedule_id,bus_id,route_id,departure_time,arrival_time,schedule_status,trip_status) VALUES(?,?,?,?,?,?,?)');
foreach ([
    [9001,11,600,3600,'Active','Scheduled'],
    [9002,11,3600,7200,'Active','Scheduled'],
    [9003,11,-86400,-82800,'Active','Departed'],
    [9004,11,600,3600,'Cancelled','Cancelled'],
    [9005,12,-300,3600,'Active','Departed'],
] as [$id,$bus,$dep,$arr,$status,$trip]) {
    $stmt->execute([$id,$bus,1,date('Y-m-d H:i:s',$now+$dep),date('Y-m-d H:i:s',$now+$arr),$status,$trip]);
}
function check(bool $pass, string $label): void { if (!$pass) throw new RuntimeException($label); echo "PASS $label\n"; }
function rejected(callable $call, int $status, string $label): void {
    try { $call(); } catch (MbusPickupException $e) { check($e->httpStatus === $status, $label); return; }
    throw new RuntimeException("Expected rejection: $label");
}
function waitData(int $schedule): array { return ['action'=>'wait','schedule_id'=>$schedule,'latitude'=>14.676,'longitude'=>120.27,'pickup_label'=>'Main Gate stop','destination'=>'MAIN GATE']; }
$feed = mbus_pickup_feed($pdo, 'commuter', 901);
check($feed['ready'] && count($feed['schedules']) === 3, 'feed includes active trips, excludes stale/cancelled, optional coordinates absent');
check(count(mbus_pickup_feed($pdo,'operator',902)['schedules']) === 2, 'operator schedule scope with duplicate assignments');
rejected(fn() => mbus_pickup_mutate($pdo,'commuter',901,waitData(9002)), 409, 'reservation-open trip rejects waiting');
rejected(fn() => mbus_pickup_mutate($pdo,'commuter',901,waitData(9003)), 409, 'stale departed trip rejects waiting');
rejected(fn() => mbus_pickup_mutate($pdo,'commuter',901,waitData(9004)), 409, 'cancelled trip rejects waiting');
$created = mbus_pickup_mutate($pdo,'commuter',901,waitData(9001));
$id = $created['request']['request_id'];
check($created['request']['status'] === 'Waiting', 'wait creates request inside cutoff');
check((int)$pdo->query('SELECT COUNT(*) FROM reservation')->fetchColumn() === 0, 'pickup does not create reservations');
check(count(mbus_pickup_feed($pdo,'operator',902)['requests']) === 1, 'assigned operator sees rider');
check(count(mbus_pickup_feed($pdo,'operator',903)['requests']) === 0, 'unassigned operator cannot see rider');
check(count(mbus_pickup_feed($pdo,'admin',904)['requests']) === 1, 'admin sees all waiting riders');
check(count(mbus_pickup_feed($pdo,'commuter',905)['requests']) === 0, 'commuter cannot see another rider');
check(!isset(mbus_pickup_feed($pdo,'commuter',901)['requests'][0]['full_name']), 'commuter feed omits staff name field');
rejected(fn() => mbus_pickup_mutate($pdo,'commuter',905,['action'=>'cancel','request_id'=>$id]),409,'rider cannot cancel another rider');
rejected(fn() => mbus_pickup_mutate($pdo,'operator',903,['action'=>'picked_up','request_id'=>$id]),404,'operator cannot complete another bus pickup');
rejected(fn() => mbus_pickup_mutate($pdo,'commuter',901,['action'=>'picked_up','request_id'=>$id]),403,'commuter cannot act as staff');
$updated = mbus_pickup_mutate($pdo,'commuter',901,waitData(9001));
$newId = $updated['request']['request_id'];
check($newId !== $id && (int)$pdo->query('SELECT COUNT(*) FROM pickup_requests')->fetchColumn() === 1, 'explicit update replaces one current request with fresh identity');
rejected(fn() => mbus_pickup_mutate($pdo,'commuter',901,['action'=>'cancel','request_id'=>$id]),409,'stale actions cannot cancel replacement request');
$done = mbus_pickup_mutate($pdo,'operator',902,['action'=>'picked_up','request_id'=>$newId]);
check($done['request']['status'] === 'Picked up' && !mbus_pickup_feed($pdo,'admin',904)['requests'], 'picked up disappears from waiting queue');
$newId = mbus_pickup_mutate($pdo,'commuter',901,waitData(9001))['request']['request_id'];
$cancelled = mbus_pickup_mutate($pdo,'commuter',901,['action'=>'cancel','request_id'=>$newId]);
check($cancelled['request']['status'] === 'Cancelled', 'rider cancels own request');
mbus_pickup_mutate($pdo,'commuter',901,waitData(9001));
$pdo->exec("UPDATE pickup_requests SET created_at=NOW()-INTERVAL '31 minutes',expires_at=NOW()-INTERVAL '1 minute'");
$expiryBefore = $pdo->query('SELECT expires_at FROM pickup_requests')->fetchColumn();
check(!mbus_pickup_feed($pdo,'admin',904)['requests'], 'expired request excluded for staff');
check(mbus_pickup_feed($pdo,'commuter',901)['requests'][0]['status'] === 'Expired', 'commuter sees expired status');
check($pdo->query('SELECT expires_at FROM pickup_requests')->fetchColumn() === $expiryBefore, 'GET polling does not renew expiry');
mbus_pickup_mutate($pdo,'commuter',901,waitData(9001));
$pdo->exec("UPDATE schedule SET trip_status='Arrived' WHERE schedule_id=9001");
check(!mbus_pickup_feed($pdo,'admin',904)['requests'], 'arrived trip excluded for staff');
check(mbus_pickup_feed($pdo,'commuter',901)['requests'][0]['status'] === 'Expired', 'terminal trip request no longer waiting for commuter');
$valid = ['csrf_token'=>'test','action'=>'wait','schedule_id'=>'9005','latitude'=>'14.6','longitude'=>'120.2','pickup_label'=>'Gate','destination'=>'MAIN GATE'];
foreach (['latitude'=>['14.6'], 'longitude'=>'NaN', 'schedule_id'=>'0', 'csrf_token'=>['x'], 'destination'=>"BAD\nVALUE"] as $key=>$value) {
    rejected(fn() => mbus_pickup_validate_form(array_replace($valid,[$key=>$value]),'commuter'),400,"invalid form $key rejected");
}
check(!$pdo->query("SELECT has_table_privilege('anon','pickup_requests','SELECT')")->fetchColumn(), 'anonymous Supabase client has no table access');
$pdo->exec('ALTER TABLE pickup_requests RENAME TO pickup_requests_missing_test');
try {
    check(!mbus_pickup_feed($pdo,'admin',904)['ready'], 'missing migration returns ready false');
    rejected(fn()=>mbus_pickup_mutate($pdo,'commuter',901,waitData(9005)),503,'missing migration blocks mutation');
} finally { $pdo->exec('ALTER TABLE pickup_requests_missing_test RENAME TO pickup_requests'); }
echo "Pickup integration checks passed on isolated local PostgreSQL only.\n";
