<?php
require_once 'includes/db_connect.php'; // Connects and starts session

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? ''); // Can be username or email
    $password = $_POST['password'] ?? '';

    if (empty($username)) $errors[] = 'Username or Email is required.';
    if (empty($password)) $errors[] = 'Password is required.';

    if (empty($errors)) {
        try {
            // Check if input looks like an email
            $isEmail = filter_var($username, FILTER_VALIDATE_EMAIL);
            $sql = "SELECT id, username, password_hash FROM users WHERE ";
            if ($isEmail) {
                $sql .= "email = :login_identifier";
            } else {
                $sql .= "username = :login_identifier";
            }
            $sql .= " LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':login_identifier' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                // Password is correct, start session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                // Redirect to the main page or dashboard
                header('Location: index.php'); // Redirect back to index, which will now show logged-in state
                exit;
            } else {
                // Invalid credentials
                $errors[] = 'Invalid username/email or password.';
                $_SESSION['login_errors'] = $errors; // Store errors in session to display on index.php
                header('Location: index.php'); // Redirect back to index page
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Login failed due to a database error.';
            // Log error: error_log("Login error: " . $e->getMessage());
             $_SESSION['login_errors'] = $errors;
             header('Location: index.php');
             exit;
        }
    } else {
         $_SESSION['login_errors'] = $errors; // Store validation errors
         header('Location: index.php'); // Redirect back to index page
         exit;
    }
} else {
    // If accessed directly via GET, just redirect to index
    header('Location: index.php');
    exit;
}
?>