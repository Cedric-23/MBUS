<?php

session_start();

if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != 'admin'){

    header("Location: ../login.php");
    exit();

}

include("../config/db_connect.php");

/* =========================
   FETCH ROUTES
========================= */

$routes = mbus_db_query($conn, "
    SELECT *
    FROM routes
    ORDER BY origin ASC
");

/* =========================
   ADD SCHEDULE
========================= */

if(isset($_POST['add_schedule'])){

    $bus_id = mbus_db_escape($conn, $_POST['bus_id']);

    $route_id = mbus_db_escape($conn, $_POST['route_id']);

    $departure = $_POST['departure_date'] . ' ' . $_POST['departure_time'];

    $arrival = $_POST['arrival_date'] . ' ' . $_POST['arrival_time'];

    $status = "Active";

    $sql = "INSERT INTO schedule
            (
                bus_id,
                route_id,
                departure_time,
                arrival_time,
                schedule_status
            )

            VALUES
            (
                '$bus_id',
                '$route_id',
                '$departure',
                '$arrival',
                '$status'
            )";

    if(mbus_db_query($conn, $sql)){

        echo "<script>

                alert('Schedule Added Successfully');

                window.location='schedules.php';

              </script>";

    } else {

        echo mbus_db_error($conn);

    }

}

?>

<!DOCTYPE html>
<html>

<head>

    <title>Add Schedule</title>

    <style>

        body{

            font-family: Arial;
            background: #f4f4f4;
            margin: 0;
            padding: 40px;

        }

        .box{

            width: 550px;
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin: auto;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);

        }

        h2{

            text-align: center;
            margin-bottom: 30px;
            color: #1e3a5f;

        }

        label{

            display: block;
            margin-bottom: 8px;
            font-weight: bold;

        }

        input,
        select{

            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;

        }

        .row{

            display: flex;
            gap: 15px;

        }

        .column{

            flex: 1;

        }

        button{

            width: 100%;
            padding: 14px;
            background: #1e3a5f;
            color: white;
            border: none;
            cursor: pointer;
            border-radius: 6px;
            font-size: 16px;

        }

        button:hover{

            background: #16304d;

        }

    </style>

</head>

<body>

    <div class="box">

        <h2>Add Schedule</h2>

        <form method="POST">

            <label>Bus ID</label>

            <input type="number"
                   name="bus_id"
                   required>

            <label>Select Route</label>

            <select name="route_id" required>

                <option value="">

                    Select Route

                </option>

                <?php while($route = mbus_db_fetch_assoc($routes)) { ?>

                    <option value="<?php echo $route['route_id']; ?>">

                        <?php echo $route['origin']; ?>

                        →

                        <?php echo $route['destination']; ?>

                    </option>

                <?php } ?>

            </select>

            <div class="row">

                <div class="column">

                    <label>Departure Date</label>

                    <input type="date"
                           name="departure_date"
                           required>

                </div>

                <div class="column">

                    <label>Departure Time</label>

                    <input type="time"
                           name="departure_time"
                           required>

                </div>

            </div>

            <div class="row">

                <div class="column">

                    <label>Arrival Date</label>

                    <input type="date"
                           name="arrival_date"
                           required>

                </div>

                <div class="column">

                    <label>Arrival Time</label>

                    <input type="time"
                           name="arrival_time"
                           required>

                </div>

            </div>

            <button type="submit"
                    name="add_schedule">

                Add Schedule

            </button>

        </form>

    </div>

</body>

</html>
```
