<?php
$dbHost = 'localhost'; // Or your database host
$dbName = 'lnf';
$dbUser = 'root';      // Replace with your DB username
$dbPass = '';          // Replace with your DB password

// Define upload directory RELATIVE to the web server's document root
// IMPORTANT: Ensure this directory exists and is writable by the web server user (e.g., www-data, apache)
// For security, it's better if this is OUTSIDE the main web root, but that requires more setup.
define('UPLOAD_DIR', 'uploads/');
define('UPLOAD_PATH', $_SERVER['DOCUMENT_ROOT'] . '/' . basename(dirname(__DIR__)) . '/' . UPLOAD_DIR); // More robust path calculation


// Create connection using PDO (recommended)
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Don't emulate prepares (safer)
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch(PDOException $e) {
    // In production, log this error instead of displaying it
    die("ERROR: Could not connect. " . $e->getMessage());
}

// Start session management (place this early in files that need auth)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>