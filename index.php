<?php
require_once 'includes/db_connect.php'; // Connects and starts session

// Check for login errors passed from login.php
$login_errors = $_SESSION['login_errors'] ?? [];
unset($_SESSION['login_errors']); // Clear errors after displaying

// Check for registration success message
$registered = isset($_GET['registered']);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Lost & Found</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <h1>Smart Lost & Found</h1>

    <?php if (isset($_SESSION['user_id'])): ?>
        <!-- Logged In View -->
        <p>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</p>
        <nav>
            <ul>
                <li><a href="upload_found.php">Report a Found Item</a></li>
                <li><a href="upload_lost.php">Report a Lost Item</a></li>
                <li><a href="view_items.php">View My Items</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </nav>

        <!-- Optional: Display some dashboard info here -->
        <h2>Dashboard</h2>
        <p>Here you can manage your reported items.</p>
         <?php
            // Example: Count user's lost/found items
            try {
                $stmt_lost = $pdo->prepare("SELECT COUNT(*) FROM lost_items WHERE user_id = ?");
                $stmt_lost->execute([$_SESSION['user_id']]);
                $lost_count = $stmt_lost->fetchColumn();

                $stmt_found = $pdo->prepare("SELECT COUNT(*) FROM found_items WHERE user_id = ?");
                $stmt_found->execute([$_SESSION['user_id']]);
                $found_count = $stmt_found->fetchColumn();

                echo "<p>You have reported " . $lost_count . " lost item(s).</p>";
                echo "<p>You have reported " . $found_count . " found item(s).</p>";

            } catch (PDOException $e) {
                echo "<p class='error'>Could not retrieve item counts.</p>";
                // Log error: error_log("Dashboard count error: " . $e->getMessage());
            }
         ?>


    <?php else: ?>
        <!-- Logged Out View (Login Form) -->
        <h2>Login</h2>

        <?php if ($registered): ?>
            <p class="success">Registration successful! Please log in.</p>
        <?php endif; ?>

        <?php if (!empty($login_errors)): ?>
            <div class="errors">
                <?php foreach ($login_errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="post">
            <div>
                <label for="username">Username or Email:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div>
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Login</button>
        </form>
        <p>Don't have an account? <a href="register.php">Register here</a></p>

    <?php endif; ?>

    <script src="js/script.js"></script> <!-- Include JS if needed -->
</body>
</html>