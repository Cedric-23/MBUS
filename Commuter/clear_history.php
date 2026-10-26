<?php
include "../config/db_connect.php";
include "../Includes/csrf.php";

/* CHECK REQUEST METHOD */
if($_SERVER['REQUEST_METHOD'] === 'POST'){

    /* CSRF CHECK */
    if(!csrf_validate_token()){
        echo "csrf_error";
        exit();
    }

    /* CHECK IF MAY ID */
    if(isset($_POST['reservation_id'])){

        $id = mbus_db_escape($conn, $_POST['reservation_id']);

        /* DELETE FROM DATABASE */
        mbus_db_prepare($conn, "
            DELETE FROM reservation
            WHERE reservation_id=?
        ", [$id]);

        echo "ok";

    }else{
        echo "no_id";
    }

}else{
    echo "invalid_request";
}
