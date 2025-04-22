<?php
// Ensure session is started (might be started already by db_connect.php)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login page if the user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); // Or your login page path
    exit;
}

// You can also fetch user details here if needed frequently
// $current_user_id = $_SESSION['user_id'];
// $current_username = $_SESSION['username'];
?>