<?php
/** LOCAL ONLY. Requires migrations 01, 10 and 11.
 * MBUS_TRACKING_TEST_DSN='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test' php tests/tracking_integration.php
 */
require_once __DIR__.'/../config/app.php'; require_once __DIR__.'/../Includes/bus_tracking.php';
$dsn=getenv('MBUS_TRACKING_TEST_DSN')?:'';
if($dsn!=='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test') throw new RuntimeException('Refusing to test outside explicit local mbus_pickup_test database.');
$pdo=new PDO($dsn,'ubuntu',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->query('SELECT current_database()')->fetchColumn()!=='mbus_pickup_test')throw new RuntimeException('Unsafe database');
function tcheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
// Fixtures use dedicated IDs and are removed in finally, avoiding interference with other tests.
try {
	 $pdo->exec("INSERT INTO users(user_id,full_name,email,password,user_type) VALUES (9801,'Tracking Rider','trackr@test.invalid','x','Commuter'),(9802,'Tracking Operator','tracko@test.invalid','x','Operator'),(9803,'Other Operator','trackx@test.invalid','x','Operator'),(9804,'Unreserved Rider','tracku@test.invalid','x','Commuter') ON CONFLICT(user_id) DO NOTHING");
 $pdo->exec("INSERT INTO routes(route_id,origin,destination,fare) VALUES(9801,'Origin','Destination',1) ON CONFLICT(route_id) DO NOTHING");
 $pdo->exec("INSERT INTO buses(bus_id,bus_number,bus_type,capacity) VALUES(9801,'T9801','Regular',20),(9802,'T9802','Regular',20) ON CONFLICT(bus_id) DO NOTHING");
 $pdo->exec("INSERT INTO operator_bus_assignments(operator_id,bus_id) VALUES(9802,9801),(9803,9802) ON CONFLICT DO NOTHING");
	 $today = date('Y-m-d'); // Schedule values are local wall-clock times (Asia/Manila), not DB UTC.
	 $pdo->exec("INSERT INTO schedule(schedule_id,bus_id,route_id,departure_time,arrival_time,schedule_status,trip_status) VALUES(9801,9801,9801,'$today 12:00:00','$today 13:00:00','Active','Boarding'),(9802,9802,9801,'$today 12:00:00','$today 13:00:00','Active','Departed'),(9803,9801,9801,'" . date('Y-m-d', strtotime('-1 day')) . " 12:00:00','" . date('Y-m-d', strtotime('-1 day')) . " 13:00:00','Active','Departed') ON CONFLICT(schedule_id) DO NOTHING");
 $pdo->exec("INSERT INTO reservation(user_id,schedule_id,status,destination,ticket_code) VALUES(9801,9801,'Paid','Destination','TRACK9801') ON CONFLICT DO NOTHING");
 tcheck(mbus_tracking_schedule($pdo,9801,'operator',9802)!==null,'assigned operator can access active schedule');
 tcheck(mbus_tracking_schedule($pdo,9801,'operator',9803)===null,'unassigned operator cannot access schedule');
	 tcheck(mbus_tracking_schedule($pdo,9801,'commuter',9801)!==null,'authenticated commuter can track a boarding trip');
	 tcheck(mbus_tracking_schedule($pdo,9802,'commuter',9804)!==null,'unreserved commuter can check bus and mark waiting');
	 tcheck(mbus_tracking_schedule($pdo,9803,'commuter',9801)===null,'previous-day stale trip is excluded');
	 $choices = mbus_tracking_feed($pdo,'commuter',9804,null)['schedules'];
	 $choiceIds = array_map('intval', array_column($choices, 'schedule_id'));
	 tcheck(in_array(9801,$choiceIds,true) && in_array(9802,$choiceIds,true) && !in_array(9803,$choiceIds,true),'commuter receives every current-day trip and excludes the stale trip');
 mbus_tracking_mutate($pdo,'operator',9802,['action'=>'update_location','schedule_id'=>9801,'latitude'=>14.6,'longitude'=>120.2,'accuracy'=>12.5]);
 $feed=mbus_tracking_feed($pdo,'commuter',9801,9801);
	 tcheck($feed['active']&&$feed['location']['latitude']===14.6,'commuter sees assigned active bus GPS');
	 mbus_tracking_mutate($pdo,'commuter',9804,['action'=>'mark_waiting','schedule_id'=>9802,'latitude'=>14.61,'longitude'=>120.21,'pickup_label'=>'North Gate']);
 $feed=mbus_tracking_feed($pdo,'operator',9802,9801);
	 tcheck(count($feed['requests'])===0,'operator sees waiters only for selected assigned trip');
	 $feed = mbus_tracking_feed($pdo,'operator',9803,9802);
	 tcheck(count($feed['requests'])===1&&$feed['requests'][0]['name']==='Unreserved Rider','assigned operator sees unreserved waiting commuter');
	 tcheck(count(mbus_tracking_feed($pdo,'operator',9802,9802)['requests'])===0,'unassigned operator cannot see waiter');
	 tcheck(count(mbus_tracking_feed($pdo,'commuter',9804,9802)['requests'])===1,'commuter sees own waiting status');
		 mbus_tracking_mutate($pdo,'commuter',9804,['action'=>'cancel_waiting','schedule_id'=>9802]);
		 tcheck(mbus_tracking_feed($pdo,'commuter',9804,9802)['waiting']['status']==='Cancelled','commuter can cancel own waiting signal');
		 mbus_tracking_mutate($pdo,'commuter',9804,['action'=>'mark_waiting','schedule_id'=>9801,'latitude'=>14.61,'longitude'=>120.21,'pickup_label'=>'North Gate']);
		 $blockedDifferentBus = false;
		 try { mbus_tracking_mutate($pdo,'commuter',9804,['action'=>'mark_waiting','schedule_id'=>9802,'latitude'=>14.62,'longitude'=>120.22,'pickup_label'=>'Other Gate']); }
		 catch (MbusPickupException $e) { $blockedDifferentBus = $e->httpStatus === 409; }
		 $retainedSchedule = (int)$pdo->query('SELECT schedule_id FROM public.pickup_requests WHERE user_id=9804')->fetchColumn();
		 tcheck($blockedDifferentBus && $retainedSchedule===9801,'marking a different bus cannot silently replace a valid waiting request');
		 mbus_tracking_mutate($pdo,'commuter',9804,['action'=>'cancel_waiting','schedule_id'=>9801]);
		 mbus_tracking_mutate($pdo,'commuter',9804,['action'=>'mark_waiting','schedule_id'=>9802,'latitude'=>14.61,'longitude'=>120.21,'pickup_label'=>'North Gate']);
		 $pdo->exec("UPDATE public.schedule SET trip_status='Arrived' WHERE schedule_id=9802");
		 $endedWait = mbus_tracking_feed($pdo,'commuter',9804,null)['my_request'];
		 tcheck($endedWait['status']==='Waiting' && $endedWait['bus_number']==='T9802' && $endedWait['origin']==='Origin' && $endedWait['destination']==='Destination','waiting board retains bus and route details after the trip ends');
		 mbus_tracking_mutate($pdo,'commuter',9804,['action'=>'cancel_waiting','schedule_id'=>9802]);
		 $cancelledAfterArrival = $pdo->query("SELECT status FROM public.pickup_requests WHERE user_id=9804 AND schedule_id=9802")->fetchColumn();
		 tcheck($cancelledAfterArrival==='Cancelled','commuter can cancel a still-valid waiting request after the bus trip ends');
 tcheck(!$pdo->query("SELECT has_table_privilege('anon','bus_live_tracking','SELECT')")->fetchColumn(),'anon role has no GPS table access');
 tcheck(!$pdo->query("SELECT has_table_privilege('authenticated','bus_live_tracking','SELECT')")->fetchColumn(),'authenticated role has no GPS table access');
 echo "Tracking integration checks passed on isolated local PostgreSQL only.\n";
} finally {
	 $pdo->exec('DELETE FROM public.bus_live_tracking WHERE schedule_id IN (9801,9802,9803)');
	 $pdo->exec('DELETE FROM public.pickup_requests WHERE user_id IN (9801,9804)');
	 $pdo->exec('DELETE FROM public.reservation WHERE user_id IN (9801,9804) AND schedule_id IN (9801,9802,9803)');
	 $pdo->exec('DELETE FROM public.schedule WHERE schedule_id IN (9801,9802,9803)');
 $pdo->exec('DELETE FROM public.operator_bus_assignments WHERE operator_id IN (9802,9803) OR bus_id IN (9801,9802)');
 $pdo->exec('DELETE FROM public.buses WHERE bus_id IN (9801,9802)');
 $pdo->exec('DELETE FROM public.routes WHERE route_id=9801');
	 $pdo->exec('DELETE FROM public.users WHERE user_id IN (9801,9802,9803,9804)');
}
