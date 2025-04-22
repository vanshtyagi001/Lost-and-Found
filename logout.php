<?php
session_start(); // Ensure session is active
session_unset(); // Unset all session variables
session_destroy(); // Destroy the session

header('Location: index.php'); // Redirect to login page
exit;
?>