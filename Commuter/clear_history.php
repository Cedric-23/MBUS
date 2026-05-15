<?php
include "../config/db_connect.php";

/* CHECK REQUEST METHOD */
if($_SERVER['REQUEST_METHOD'] === 'POST'){

    /* CHECK IF MAY ID */
    if(isset($_POST['reservation_id'])){

        $id = $_POST['reservation_id'];

        /* DELETE FROM DATABASE */
        $delete = mbus_db_query($conn,"
        DELETE FROM reservation
        WHERE reservation_id='$id'
        ");

        if($delete){
            echo "ok";
        }else{
            echo "error";
        }

    }else{
        echo "no_id";
    }

}else{
    echo "invalid_request";
}
?>