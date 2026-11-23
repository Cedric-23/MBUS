<?php
$staffPortal = rtrim((string)(getenv('STAFF_PORTAL_URL') ?: 'StaffPortal'), '/');
header('Location: ' . $staffPortal . '/register.php?type=driver');
exit();
