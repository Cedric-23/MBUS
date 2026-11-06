<?php
require_once __DIR__ . '/../config/app.php';
session_start();
require_once __DIR__ . '/../Includes/csrf.php';
require_once __DIR__ . '/../Includes/bus_tracking.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
function mbus_tracking_json(array $body,int $status=200): never { $GLOBALS['mbus_tracking_sent']=true; http_response_code($status); echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
$userInput=$_SESSION['user_id']??null; $roleInput=$_SESSION['user_type']??''; $role=is_string($roleInput)?strtolower($roleInput):'';
try{$userId=mbus_pickup_positive_id($userInput);}catch(Throwable $e){mbus_tracking_json(['ok'=>false,'message'=>'Please sign in to use live tracking.'],401);}
if(!in_array($role,['commuter','operator','admin'],true)) mbus_tracking_json(['ok'=>false,'message'=>'Your account cannot access live tracking.'],403);
$method=$_SERVER['REQUEST_METHOD']??'GET'; if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');mbus_tracking_json(['ok'=>false,'message'=>'Use GET or POST for this endpoint.'],405);}
$data=null; if($method==='POST'){try{$data=mbus_tracking_form($_POST,$role);}catch(MbusPickupException $e){mbus_tracking_json(['ok'=>false,'message'=>$e->getMessage()],$e->httpStatus);} if(!csrf_validate_token())mbus_tracking_json(['ok'=>false,'message'=>'Security validation failed. Refresh and try again.'],403);}
$token=csrf_get_token(); $chosen=null;
if($method==='GET' && isset($_GET['schedule_id'])){try{$chosen=mbus_pickup_positive_id($_GET['schedule_id']);}catch(MbusPickupException $e){mbus_tracking_json(['ok'=>false,'message'=>'Invalid schedule ID.'],400);}}
session_write_close(); $level=ob_get_level(); ob_start();
register_shutdown_function(static function()use($level){if(empty($GLOBALS['mbus_tracking_sent'])){while(ob_get_level()>$level)ob_end_clean();http_response_code(503);echo json_encode(['ok'=>false,'ready'=>false,'message'=>'Live tracking is temporarily unavailable. Check the database connection and apply supabase/11_bus_live_tracking.sql.']);}});
try{require_once __DIR__.'/../config/db_connect.php'; if($method==='GET')$response=mbus_tracking_feed($conn->pdo,$role,$userId,$chosen);else $response=mbus_tracking_mutate($conn->pdo,$role,$userId,$data); $response['csrf_token']=$token; if($method==='POST')$response['server_time']=date(DATE_ATOM); ob_end_clean(); mbus_tracking_json($response);
}catch(MbusPickupException $e){ob_end_clean();mbus_tracking_json(['ok'=>false,'message'=>$e->getMessage()],$e->httpStatus);}catch(Throwable $e){ob_end_clean();error_log('Live tracking API: '.$e->getMessage());$missing=$e instanceof PDOException&&(string)$e->getCode()==='42P01';mbus_tracking_json(['ok'=>false,'ready'=>false,'message'=>$missing?'Live tracking is not configured. Apply supabase/11_bus_live_tracking.sql after the required migrations.':'Live tracking is temporarily unavailable. Please refresh and try again.'],503);}
