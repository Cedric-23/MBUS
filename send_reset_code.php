<?php
// Kept as a compatibility route for old bookmarks. Code delivery now happens
// only through the CSRF-protected POST actions in forgot_password.php and verify_code.php.
session_start();
header('Location: ' . (isset($_SESSION['reset_email']) ? 'verify_code.php' : 'forgot_password.php'), true, 303);
exit();
