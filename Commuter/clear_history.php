<?php
session_start();
include "../config/db_connect.php";
include "../Includes/csrf.php";

/* CHECK REQUEST METHOD */
if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo "unauthorized";
    exit();
}
if($_SERVER['REQUEST_METHOD'] === 'POST'){

    /* CSRF CHECK */
    if(!csrf_validate_token()){
        echo "csrf_error";
        exit();
    }

    /* CHECK IF MAY ID */
    if(isset($_POST['reservation_id']) && is_scalar($_POST['reservation_id'])){

        $id = (int)$_POST['reservation_id'];

        /* Delete only this commuter's historical reservation. */
        $result = mbus_db_prepare($conn, "
            DELETE FROM reservation
            WHERE reservation_id=? AND user_id=? AND status IN ('Boarded', 'Cancelled')
        ", [$id, $_SESSION['user_id']]);

        echo $result !== false ? "ok" : "delete_error";

    }else{
        echo "no_id";
    }

}else{
    echo "invalid_request";
}
