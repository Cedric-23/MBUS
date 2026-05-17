<?php
// Activity Log Helper Function
// Call this function to log important system actions

function log_activity($conn, $user_id, $action, $description = '') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $user_id = mbus_db_escape($conn, $user_id);
    $action = mbus_db_escape($conn, $action);
    $description = mbus_db_escape($conn, $description);
    $ip_address = mbus_db_escape($conn, $ip_address);
    $user_agent = mbus_db_escape($conn, $user_agent);
    
    mbus_db_query($conn,"
        INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
        VALUES ('$user_id', '$action', '$description', '$ip_address', '$user_agent')
    ");
}

// Common action types
define('ACTION_LOGIN', 'Login');
define('ACTION_LOGOUT', 'Logout');
define('ACTION_REGISTER', 'Register');
define('ACTION_RESERVATION_CREATE', 'Reservation Created');
define('ACTION_RESERVATION_CANCEL', 'Reservation Cancelled');
define('ACTION_PAYMENT_SUCCESS', 'Payment Successful');
define('ACTION_PAYMENT_FAILED', 'Payment Failed');
define('ACTION_PROFILE_UPDATE', 'Profile Updated');
define('ACTION_PASSWORD_CHANGE', 'Password Changed');
define('ACTION_SCHEDULE_VIEW', 'Schedule Viewed');
define('ACTION_SCHEDULE_CREATE', 'Schedule Created');
define('ACTION_SCHEDULE_UPDATE', 'Schedule Updated');
define('ACTION_SCHEDULE_DELETE', 'Schedule Deleted');
define('ACTION_ROUTE_CREATE', 'Route Created');
define('ACTION_ROUTE_UPDATE', 'Route Updated');
define('ACTION_ROUTE_DELETE', 'Route Deleted');
define('ACTION_BUS_CREATE', 'Bus Created');
define('ACTION_BUS_UPDATE', 'Bus Updated');
define('ACTION_BUS_DELETE', 'Bus Deleted');
define('ACTION_OPERATOR_APPROVE', 'Operator Approved');
define('ACTION_OPERATOR_REJECT', 'Operator Rejected');
define('ACTION_VERIFICATION_SUBMIT', 'Verification Submitted');
define('ACTION_VERIFICATION_APPROVE', 'Verification Approved');
define('ACTION_VERIFICATION_REJECT', 'Verification Rejected');
?>
