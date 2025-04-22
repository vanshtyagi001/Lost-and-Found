<?php
require_once 'includes/db_connect.php';
require_once 'includes/auth_check.php'; // Ensure user is logged in
require_once 'includes/functions.php'; // For generate_image_description

$errors = [];
$success = '';
$generated_description = '';

// Ensure upload directory exists and is writable
if (!is_dir(UPLOAD_PATH)) {
    if (!mkdir(UPLOAD_PATH, 0755, true)) { // Create recursively with appropriate permissions
         die("ERROR: Failed to create upload directory. Please check permissions.");
    }
} elseif (!is_writable(UPLOAD_PATH)) {
     die("ERROR: Upload directory is not writable by the web server.");
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['item_image'])) {
    // --- Get Form Data ---
    $category = trim($_POST['category'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $condition = trim($_POST['item_condition'] ?? ''); // Use item_condition
    $location = trim($_POST['location_found'] ?? '');
    $userId = $_SESSION['user_id'];

    // --- Basic Validation ---
    if (empty($category)) $errors[] = 'Category is required.';
    if (empty($location)) $errors[] = 'Location found is required.';
    // Add more validation as needed

    // --- Image Upload Handling ---
    $imageFile = $_FILES['item_image'];
    $imageFileName = null;
    $imageFilePath = null; // Full path for processing

    if ($imageFile['error'] === UPLOAD_ERR_OK) {
        $maxFileSize = 5 * 1024 * 1024; // 5 MB
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = mime_content_type($imageFile['tmp_name']);

        if ($imageFile['size'] > $maxFileSize) {
            $errors[] = 'Image file is too large (Max 5MB).';
        } elseif (!in_array($fileType, $allowedTypes)) {
            $errors[] = 'Invalid image file type (Allowed: JPG, PNG, GIF, WEBP).';
        } else {
            // Generate unique filename
            $extension = pathinfo($imageFile['name'], PATHINFO_EXTENSION);
            $imageFileName = uniqid('found_', true) . '.' . strtolower($extension);
            $imageFilePath = UPLOAD_PATH . $imageFileName; // Full destination path

            if (!move_uploaded_file($imageFile['tmp_name'], $imageFilePath)) {
                $errors[] = 'Failed to upload image. Check server permissions.';
                $imageFileName = null; // Reset on failure
                $imageFilePath = null;
            }
        }
    } elseif ($imageFile['error'] !== UPLOAD_ERR_NO_FILE) {
        // Handle other upload errors
        $errors[] = 'Error uploading image. Code: ' . $imageFile['error'];
    } else {
        $errors[] = 'Image is required.';
    }

    // --- Generate AI Description & Save to DB ---
    if (empty($errors) && $imageFilePath) {
        // Call Gemini function
        $generated_description = generate_image_description($imageFilePath);

        if (strpos($generated_description, 'Error:') === 0) {
            // Handle error from Gemini API
            $errors[] = $generated_description; // Display API error to user (or log it)
        } else {
            // Store in database
            try {
                $stmt = $pdo->prepare("INSERT INTO found_items (user_id, category, color, brand, item_condition, location_found, image_filename, ai_description, status) VALUES (:user_id, :category, :color, :brand, :item_condition, :location, :image_filename, :ai_description, 'available')");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':category' => $category,
                    ':color' => $color,
                    ':brand' => $brand,
                    ':item_condition' => $condition,
                    ':location' => $location,
                    ':image_filename' => $imageFileName, // Store filename only
                    ':ai_description' => $generated_description,
                ]);
                $success = 'Found item reported successfully!';
                // Clear form fields potentially or redirect
            } catch (PDOException $e) {
                $errors[] = 'Database error saving item.';
                 // Log error: error_log("Found item insert error: " . $e->getMessage());
                 // Optional: Delete uploaded image if DB insert fails
                 if (file_exists($imageFilePath)) { unlink($imageFilePath); }
            }
        }
    } elseif (empty($errors) && !$imageFilePath) {
        // This case should ideally not happen if image validation is correct
        $errors[] = "Image processing failed before description generation.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Report Found Item</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <h1>Report a Found Item</h1>
    <p><a href="index.php">< Back to Dashboard</a></p>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <?php foreach ($errors as $error): ?>
                <p><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">
            <p><?php echo htmlspecialchars($success); ?></p>
             <?php if ($generated_description && strpos($generated_description, 'Error:') !== 0): ?>
                <h3>Generated Description:</h3>
                <p><?php echo nl2br(htmlspecialchars($generated_description)); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form action="upload_found.php" method="post" enctype="multipart/form-data">
        <div>
            <label for="item_image">Image of Item*:</label>
            <input type="file" id="item_image" name="item_image" accept="image/*" required>
        </div>
        <div>
            <label for="category">Category*:</label>
            <input type="text" id="category" name="category" required>
        </div>
        <div>
            <label for="color">Color:</label>
            <input type="text" id="color" name="color">
        </div>
        <div>
            <label for="brand">Brand:</label>
            <input type="text" id="brand" name="brand">
        </div>
         <div>
            <label for="item_condition">Condition:</label>
             <select id="item_condition" name="item_condition">
                <option value="">-- Select --</option>
                <option value="new">New</option>
                <option value="like_new">Like New</option>
                <option value="good">Good</option>
                <option value="fair">Fair</option>
                <option value="poor">Poor</option>
            </select>
        </div>
        <div>
            <label for="location_found">Location Found*:</label>
            <textarea id="location_found" name="location_found" rows="3" required></textarea>
        </div>
        <button type="submit">Report Found Item</button>
    </form>

</body>
</html>