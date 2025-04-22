<?php
require_once 'includes/db_connect.php';
require_once 'includes/auth_check.php'; // Ensure user is logged in
require_once 'includes/functions.php'; // For Gemini and Python calls

$errors = [];
$success = '';
$generated_description = '';
$match_results = []; // To store potential matches

// Ensure upload directory exists and is writable (redundant check is okay)
if (!is_dir(UPLOAD_PATH)) {
    if (!mkdir(UPLOAD_PATH, 0755, true)) { // Create recursively with appropriate permissions
         // Log critical error and stop execution
         error_log("CRITICAL ERROR: Failed to create upload directory at " . UPLOAD_PATH . ". Please check parent directory permissions.");
         die("ERROR: System configuration issue. Failed to create upload directory. Please contact administrator.");
    }
    error_log("INFO: Created upload directory: " . UPLOAD_PATH);
} elseif (!is_writable(UPLOAD_PATH)) {
     // Log critical error and stop execution
     error_log("CRITICAL ERROR: Upload directory " . UPLOAD_PATH . " is not writable by the web server user (" . get_current_user() . "). Check permissions.");
     die("ERROR: System configuration issue. Upload directory is not writable. Please contact administrator.");
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['item_image'])) {
    // --- Get Form Data ---
    $category = trim($_POST['category'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $condition = trim($_POST['item_condition'] ?? '');
    $location = trim($_POST['location_lost'] ?? '');
    $userId = $_SESSION['user_id']; // Assumes user ID is stored in session upon login

    // --- Basic Validation ---
    if (empty($category)) $errors[] = 'Category is required.';
    if (empty($location)) $errors[] = 'Location lost is required.';
    // Add more validation as needed (e.g., check length, format)

    // --- Image Upload Handling ---
    $imageFile = $_FILES['item_image'];
    $imageFileName = null; // Will store the unique filename if upload is successful
    $imageFilePath = null; // Will store the full path to the uploaded file

    if (isset($imageFile['error']) && $imageFile['error'] === UPLOAD_ERR_OK) {
        $maxFileSize = 5 * 1024 * 1024; // 5 MB limit
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        // Check MIME type more reliably
        $fileType = mime_content_type($imageFile['tmp_name']);

        if ($imageFile['size'] > $maxFileSize) {
            $errors[] = 'Image file is too large (Max 5MB allowed).';
            error_log("Upload Error: File size exceeded limit (" . $imageFile['size'] . " > " . $maxFileSize . ")");
        } elseif (!in_array($fileType, $allowedMimeTypes)) {
            $errors[] = 'Invalid image file type. Allowed types: JPG, PNG, GIF, WEBP. Detected: ' . htmlspecialchars($fileType);
            error_log("Upload Error: Invalid MIME type: " . $fileType . " for file " . $imageFile['name']);
        } else {
            // Generate unique filename to prevent overwrites and naming conflicts
            $extension = pathinfo($imageFile['name'], PATHINFO_EXTENSION);
            if (!$extension) $extension = 'jpg'; // Default extension if missing? Risky. Better rely on MIME type check.
            $imageFileName = 'lost_' . uniqid('', true) . '.' . strtolower($extension);
            $imageFilePath = UPLOAD_PATH . $imageFileName; // Construct the full destination path

            // Move the uploaded file from the temporary location to the final destination
            if (move_uploaded_file($imageFile['tmp_name'], $imageFilePath)) {
                error_log("Upload Success: File '" . $imageFile['name'] . "' uploaded and saved as '" . $imageFileName . "'");
                // Optionally set permissions (though directory permissions are usually enough)
                // chmod($imageFilePath, 0644);
            } else {
                $errors[] = 'Failed to save uploaded image. Server error.';
                error_log("Upload Error: move_uploaded_file failed for " . $imageFile['tmp_name'] . " to " . $imageFilePath . ". Check directory permissions and PHP config.");
                $imageFileName = null; // Reset filename on failure
                $imageFilePath = null;
            }
        }
    } elseif (isset($imageFile['error']) && $imageFile['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Image is required (can be a reference image).';
    } elseif (isset($imageFile['error'])) {
        // Handle other specific upload errors
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE directive specified in HTML form.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
        ];
        $errorCode = $imageFile['error'];
        $errorMessage = $uploadErrors[$errorCode] ?? 'Unknown upload error code: ' . $errorCode;
        $errors[] = 'Error uploading image: ' . $errorMessage;
        error_log("Upload Error: Code " . $errorCode . " - " . $errorMessage);
    } else {
         $errors[] = 'An unexpected issue occurred with the image upload.';
         error_log("Upload Error: Unexpected state, \$_FILES['item_image'] structure incorrect or error key missing.");
    }

    // --- Generate AI Description, Save, and Trigger Matching ---
    if (empty($errors) && $imageFilePath && $imageFileName) {
        // 1. Generate Description for the Lost Item
        error_log("Generating AI description for lost item image: " . $imageFilePath);
        $generated_description = generate_image_description($imageFilePath); // Function from functions.php

        if (strpos($generated_description, 'Error:') === 0) {
            // Handle error from Gemini API call
            $errors[] = "Failed to generate AI description: " . htmlspecialchars(substr($generated_description, 6)); // Remove "Error:" prefix for user display
            error_log("AI Description Error: " . $generated_description . " for file " . $imageFilePath);
             // Clean up uploaded image if description fails? Optional.
             // if (file_exists($imageFilePath)) { unlink($imageFilePath); }
             // error_log("Cleaned up image {$imageFileName} due to description failure.");
        } else {
            error_log("AI Description generated successfully for lost item image: " . $imageFileName . " (Length: " . strlen($generated_description) . ")");
            // 2. Store the Lost Item in Database
            $lostItemId = null;
            try {
                $pdo->beginTransaction(); // Start transaction

                $stmt = $pdo->prepare(
                    "INSERT INTO lost_items
                        (user_id, category, color, brand, item_condition, location_lost, image_filename, ai_description, status)
                    VALUES
                        (:user_id, :category, :color, :brand, :item_condition, :location, :image_filename, :ai_description, 'searching')"
                );
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
                $lostItemId = $pdo->lastInsertId(); // Get the ID of the newly inserted lost item
                $pdo->commit(); // Commit transaction
                $success = 'Lost item reported successfully! Checking for matches...';
                error_log("Lost item saved to database successfully. ID: " . $lostItemId);

            } catch (PDOException $e) {
                $pdo->rollBack(); // Roll back transaction on error
                $errors[] = 'Database error saving lost item. Please try again.';
                error_log("Database Error (Lost Item Insert): " . $e->getMessage());
                 // Clean up uploaded image if DB insert fails
                 if (file_exists($imageFilePath)) {
                    unlink($imageFilePath);
                    error_log("Cleaned up image {$imageFileName} due to DB insert failure.");
                 }
                 $lostItemId = null; // Ensure ID is null on failure
            }

            // =====================================================
            // 3. If Lost Item Saved, Proceed to Matching
            // =====================================================
            if ($lostItemId && empty($errors)) {
                error_log("Starting match check for Lost Item ID: {$lostItemId}"); // Log start of check
                try {
                    // Fetch all 'available' found items from the database
                    $stmt_found = $pdo->prepare("SELECT id, user_id, image_filename, ai_description FROM found_items WHERE status = 'available'");
                    $stmt_found->execute();
                    $available_found_items = $stmt_found->fetchAll(PDO::FETCH_ASSOC);

                    $found_items_count = count($available_found_items);
                    error_log("Matching: Found {$found_items_count} 'available' items in found_items table."); // Log count

                    if ($found_items_count > 0) {
                        $text_similarity_threshold = 0.50; // 50%
                        $image_similarity_threshold = 75; // 75%
                        error_log("Matching: Using Text Threshold >= {$text_similarity_threshold}, Image Threshold >= {$image_similarity_threshold}%");

                        foreach ($available_found_items as $found_item) {
                            $current_found_id = $found_item['id'];
                            error_log("-----------------------------------------------------"); // Separator for clarity
                            error_log("Matching: Comparing Lost ID {$lostItemId} with Found ID {$current_found_id}");

                            // Avoid matching user's own items if desired (uncomment if needed)
                            // if ($found_item['user_id'] == $userId) {
                            //     error_log("Matching: Found ID {$current_found_id} belongs to the same user (ID: {$userId}). Skipping.");
                            //     continue;
                            // }

                            // Ensure descriptions are not empty before comparing
                            if (empty($generated_description) || empty($found_item['ai_description'])) {
                                error_log("Matching: Found ID {$current_found_id} - One or both descriptions are empty. Skipping text comparison.");
                                continue; // Skip this found item
                            }

                            // a. Text Comparison
                            $text_sim = compare_text_similarity($generated_description, $found_item['ai_description']);
                            // Log the result clearly BEFORE the check
                            error_log("Matching: Found ID {$current_found_id} - Text Similarity Result: {$text_sim}");

                            if ($text_sim >= $text_similarity_threshold) {
                                error_log("Matching: Found ID {$current_found_id} - Text threshold MET ({$text_sim} >= {$text_similarity_threshold}). Proceeding to image comparison."); // Log progress

                                // Ensure filenames are valid before proceeding
                                $found_image_filename = $found_item['image_filename']; // Filename from DB
                                $lost_image_filename = $imageFileName; // Filename of the item just uploaded

                                if (empty($lost_image_filename) || empty($found_image_filename)) {
                                     error_log("Matching: Found ID {$current_found_id} - One or both image filenames are missing/empty (Lost: '{$lost_image_filename}', Found: '{$found_image_filename}'). Skipping image comparison.");
                                     continue; // Skip image comparison for this item
                                }

                                // b. Image Comparison (only if text matches enough)
                                // Pass JUST the filenames to the comparison function.
                                // The function compare_image_similarity will construct absolute paths using UPLOAD_PATH.
                                $image_sim = compare_image_similarity(
                                    $lost_image_filename,    // Just the filename
                                    $generated_description,
                                    $found_image_filename,   // Just the filename
                                    $found_item['ai_description']
                                );
                                // Log the result clearly BEFORE the check
                                error_log("Matching: Found ID {$current_found_id} - Image Similarity Result: {$image_sim}%");

                                if ($image_sim >= $image_similarity_threshold) {
                                    // *** MATCH FOUND ***
                                    error_log("!!! MATCH FOUND !!! Lost ID {$lostItemId} <> Found ID {$current_found_id}. Text Similarity: {$text_sim}, Image Similarity: {$image_sim}%"); // Explicit log
                                    $match_results[] = [
                                        'found_item_id' => $current_found_id,
                                        'text_similarity' => round($text_sim * 100), // Convert to percentage for display
                                        'image_similarity' => $image_sim,
                                        'found_image_filename' => $found_image_filename // For display in HTML
                                    ];

                                    // Update statuses (Example) - Consider doing this AFTER the loop if collecting multiple matches
                                    // Or perhaps better in a separate process or user action confirmation step.
                                    try {
                                        // Set lost item status to indicate a match has been found
                                        $pdo->prepare("UPDATE lost_items SET status = 'match_found' WHERE id = ?")->execute([$lostItemId]);
                                         // Optionally update found item status here too (e.g., 'pending_claim')
                                         // $pdo->prepare("UPDATE found_items SET status = 'pending_claim' WHERE id = ?")->execute([$current_found_id]);
                                         error_log("Matching: Updated status for Lost ID {$lostItemId} to 'match_found'. (Found ID {$current_found_id} status unchanged for now).");
                                    } catch (PDOException $e) {
                                        error_log("Matching Error: Failed to update status for Lost ID {$lostItemId} after match found: " . $e->getMessage());
                                        // Continue processing other matches even if status update fails
                                    }

                                    // TODO: Implement Real Notification System
                                    // - Save the match details persistently (e.g., in `potential_matches` table)
                                    //   $matchStmt = $pdo->prepare("INSERT INTO potential_matches (lost_item_id, found_item_id, text_similarity, image_similarity, match_status) VALUES (?, ?, ?, ?, 'pending') ON DUPLICATE KEY UPDATE text_similarity=VALUES(text_similarity), image_similarity=VALUES(image_similarity), notified_at=NOW()");
                                    //   $matchStmt->execute([$lostItemId, $current_found_id, $text_sim, $image_sim]);
                                    // - Trigger an email or in-app notification to the user who lost the item ($userId)
                                    //   and the user who found the item ($found_item['user_id']).

                                    // Optional: break after first strong match if you only want one notification at a time
                                    // break;
                                } else {
                                     error_log("Matching: Found ID {$current_found_id} - Image threshold NOT MET ({$image_sim}% < {$image_similarity_threshold}%)");
                                }
                            } else {
                                error_log("Matching: Found ID {$current_found_id} - Text threshold NOT MET ({$text_sim} < {$text_similarity_threshold}). Skipping image comparison.");
                            }
                             error_log("-----------------------------------------------------"); // Separator
                        } // end foreach available_found_items

                         // Update success message based on whether matches were found
                         if (empty($match_results)) {
                             $success .= " No immediate matches found based on current criteria.";
                             error_log("Matching: Finished loop for Lost ID {$lostItemId}. No matches met both thresholds.");
                         } else {
                             $success .= " Potential match(es) found!";
                             error_log("Matching: Finished loop for Lost ID {$lostItemId}. Found " . count($match_results) . " potential match(es).");
                         }

                    } else {
                         // No 'available' found items existed in the DB to compare against
                         $success .= " No available items currently in the system to compare against."; // More specific message
                         error_log("Matching: No 'available' found items to process for Lost ID {$lostItemId}.");
                    }

                } catch (PDOException $e) {
                    // Catch errors during the fetching or processing of found items
                    $errors[] = "Error during matching process. Please report this issue.";
                    error_log("Matching PDOException: Error during matching process for Lost ID {$lostItemId}: " . $e->getMessage());
                }
            } elseif ($lostItemId === null && empty($errors)) {
                 // This case means DB save failed, message already in $errors
                 error_log("Matching: Skipped because lost item ID was not obtained (database save likely failed earlier).");
            } // end if lost item saved & no errors

        } // end if description generated successfully

    } elseif (empty($errors) && (!$imageFilePath || !$imageFileName)) {
        // This should ideally not happen if upload validation is correct, but good to catch
        $errors[] = "Image processing failed before description/matching stage.";
        error_log("Error: Image processing failed unexpectedly before description/matching. Filepath: '{$imageFilePath}', Filename: '{$imageFileName}'");
    } else {
        // Initial validation or upload errors occurred, $errors array already populated.
         error_log("Skipping description generation and matching due to initial errors: " . implode("; ", $errors));
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Report Lost Item - Lost & Found</title>
    <link rel="stylesheet" href="css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    <h1>Report a Lost Item</h1>
    <p><a href="index.php">< Back to Dashboard</a></p>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <strong>Please fix the following errors:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">
            <p><?php echo htmlspecialchars($success); ?></p>
            <?php // Display generated description only if successful and no critical errors occurred after generation
            if ($generated_description && strpos($generated_description, 'Error:') !== 0 && empty($errors)): ?>
                <h3>Generated Description for Your Lost Item:</h3>
                <p><?php echo nl2br(htmlspecialchars($generated_description)); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

     <?php if (!empty($match_results)): ?>
        <div class="matches">
            <h2>Potential Matches Found!</h2>
            <p>We found the following items reported by others that might be yours based on description and image similarity. Please review carefully.</p>
            <?php foreach ($match_results as $match): ?>
            <div class="match-item" style="border: 1px solid #ccc; margin-bottom: 1em; padding: 1em; overflow: hidden;">
                 <img src="<?php echo htmlspecialchars(UPLOAD_DIR . $match['found_image_filename']); ?>" alt="Found Item Image" style="max-width: 150px; max-height: 150px; float: left; margin-right: 15px; border: 1px solid #eee;">
                 <div style="overflow: hidden;">
                     <p><strong>Found Item ID:</strong> <?php echo $match['found_item_id']; ?> (For reference)</p>
                     <p><strong>Text Similarity:</strong> <?php echo $match['text_similarity']; ?>%</p>
                     <p><strong>Image Similarity:</strong> <?php echo $match['image_similarity']; ?>%</p>
                     <p style="margin-top: 10px;"><small>If you believe this is your item, please contact the site administrator or use the contact feature (if available) to arrange verification, referencing Found Item ID <?php echo $match['found_item_id']; ?>.</small></p>
                     <?php // TODO: Add a button/link to initiate contact/claim process, passing $match['found_item_id'] ?>
                     <!-- <a href="claim_item.php?found_id=<?php echo $match['found_item_id']; ?>&lost_id=<?php echo $lostItemId; ?>">Claim this Item</a> -->
                 </div>
                 <div style="clear: both;"></div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


    <form action="upload_lost.php" method="post" enctype="multipart/form-data">
        <div>
             <label for="item_image">Image of Item (or similar reference)*:</label>
             <input type="file" id="item_image" name="item_image" accept="image/jpeg,image/png,image/gif,image/webp" required>
             <small>Max 5MB. Allowed types: JPG, PNG, GIF, WEBP.</small>
        </div>
         <div>
            <label for="category">Category*:</label>
            <input type="text" id="category" name="category" required value="<?php echo isset($_POST['category']) ? htmlspecialchars($_POST['category']) : ''; ?>">
        </div>
        <div>
            <label for="color">Color:</label>
            <input type="text" id="color" name="color" value="<?php echo isset($_POST['color']) ? htmlspecialchars($_POST['color']) : ''; ?>">
        </div>
        <div>
            <label for="brand">Brand:</label>
            <input type="text" id="brand" name="brand" value="<?php echo isset($_POST['brand']) ? htmlspecialchars($_POST['brand']) : ''; ?>">
        </div>
         <div>
            <label for="item_condition">Condition (if known):</label>
             <select id="item_condition" name="item_condition">
                <option value="" <?php echo (!isset($_POST['item_condition']) || $_POST['item_condition'] == '') ? 'selected' : ''; ?>>-- Select --</option>
                <option value="new" <?php echo (isset($_POST['item_condition']) && $_POST['item_condition'] == 'new') ? 'selected' : ''; ?>>New</option>
                <option value="like_new" <?php echo (isset($_POST['item_condition']) && $_POST['item_condition'] == 'like_new') ? 'selected' : ''; ?>>Like New</option>
                <option value="good" <?php echo (isset($_POST['item_condition']) && $_POST['item_condition'] == 'good') ? 'selected' : ''; ?>>Good</option>
                <option value="fair" <?php echo (isset($_POST['item_condition']) && $_POST['item_condition'] == 'fair') ? 'selected' : ''; ?>>Fair</option>
                <option value="poor" <?php echo (isset($_POST['item_condition']) && $_POST['item_condition'] == 'poor') ? 'selected' : ''; ?>>Poor</option>
                <option value="unknown" <?php echo (isset($_POST['item_condition']) && $_POST['item_condition'] == 'unknown') ? 'selected' : ''; ?>>Unknown</option>
            </select>
        </div>
        <div>
            <label for="location_lost">Location Lost*:</label>
            <textarea id="location_lost" name="location_lost" rows="3" required><?php echo isset($_POST['location_lost']) ? htmlspecialchars($_POST['location_lost']) : ''; ?></textarea>
        </div>
        <button type="submit">Report Lost Item & Search for Matches</button>
    </form>

</body>
</html>