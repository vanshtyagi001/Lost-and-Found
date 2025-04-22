<?php
// IMPORTANT SECURITY WARNING: Hardcoding API keys is very insecure.
// Use environment variables or a config file outside the web root in production.
define('GEMINI_API_KEY', 'AIzaSyDiSqdPU5NB_H3qeX95fOZE1ZhXl5E2rx8'); // Use the key provided in the prompt
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent'); // Use 1.5 Flash

// Function to call Gemini API for Image Description
function generate_image_description($imagePath) {
    // Log the attempt
    error_log("generate_image_description: Attempting to generate description for: " . $imagePath);

    if (!file_exists($imagePath) || !is_readable($imagePath)) {
        error_log("generate_image_description: File not found or not readable: " . $imagePath);
        return "Error: Image file not accessible.";
    }

    // Get image MIME type and read image data
    $imageMime = mime_content_type($imagePath);
    if ($imageMime === false || !in_array($imageMime, ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])) {
         error_log("generate_image_description: Unsupported image type: " . $imageMime . " for file " . $imagePath);
         return "Error: Unsupported image type.";
    }
    $imageData = base64_encode(file_get_contents($imagePath));
    error_log("generate_image_description: Image loaded and base64 encoded. Mime: " . $imageMime);

    $payload = json_encode([
        'contents' => [[
            'parts' => [
                ['text' => "Describe this item in detail for a lost and found system. Include visual characteristics, potential brand names if visible, condition, and any unique features. Focus on keywords useful for searching."],
                ['inline_data' => [
                    'mime_type' => $imageMime,
                    'data' => $imageData
                ]]
            ]
        ]],
        // Optional: Add generation config or safety settings if needed
    ]);

    error_log("generate_image_description: Payload prepared. Making cURL request to: " . GEMINI_API_URL);
    $ch = curl_init(GEMINI_API_URL . '?key=' . GEMINI_API_KEY);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Keep true for security
    // curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Add a timeout (e.g., 30 seconds)

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("generate_image_description: Gemini API cURL Error: " . $curlError);
        return "Error: Could not connect to description service.";
    }

    error_log("generate_image_description: Gemini API response Code: " . $httpCode);
    // Log first 500 chars of response for debugging (avoid logging too much)
    error_log("generate_image_description: Gemini API response Body (start): " . substr($response, 0, 500));

    if ($httpCode >= 200 && $httpCode < 300) {
        $result = json_decode($response, true);
        // Navigate the Gemini response structure (adjust if the model version changes structure)
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $description = trim($result['candidates'][0]['content']['parts'][0]['text']);
            error_log("generate_image_description: Success! Description length: " . strlen($description));
            return $description;
        } elseif (isset($result['promptFeedback'])) {
            $feedback = json_encode($result['promptFeedback']);
            error_log("generate_image_description: Gemini API Prompt Feedback: " . $feedback);
            return "Error: Content generation blocked or failed. Feedback: " . $feedback;
        } else {
             error_log("generate_image_description: Gemini API Unexpected Response Structure: " . $response);
             return "Error: Could not parse description from response.";
        }
    } else {
        error_log("generate_image_description: Gemini API HTTP Error: Code " . $httpCode . " - Response: " . $response);
        return "Error: Failed to generate description (Code: $httpCode).";
    }
}


// Function to execute Python script for text comparison
function compare_text_similarity($text1, $text2) {
    error_log("--- Text Comparison Start ---");
    // Paths need to be correct relative to where PHP executes
    // Using absolute paths is often more reliable
    $scriptPath = __DIR__ . '/../python_scripts/textComp.py'; // Assumes functions.php is in includes/
    $pythonExecutable = 'python3'; // Or 'python' or the full path to your python executable (use python3 if available)

    // Use escapeshellarg to safely pass arguments to the command line
    $escapedText1 = escapeshellarg($text1);
    $escapedText2 = escapeshellarg($text2);

    error_log("PHP Text Comp: Text 1 (start): " . substr($text1, 0, 100) . "...");
    error_log("PHP Text Comp: Text 2 (start): " . substr($text2, 0, 100) . "...");

    // SECURITY WARNING: Ensure the python executable path is trusted and the script is secure.
    $command = $pythonExecutable . ' ' . escapeshellarg($scriptPath) . ' ' . $escapedText1 . ' ' . $escapedText2 . ' 2>&1'; // Capture stderr too
    error_log("PHP Text Comp: Executing Command: " . $command);

    // Execute the command and capture the output
    $output = shell_exec($command);
    error_log("PHP Text Comp: Raw Output from Python: " . var_export($output, true));

    // Check for execution errors (shell_exec returns NULL on failure)
    if ($output === null) {
        error_log("PHP Text Comp Error: Failed to execute script (shell_exec returned NULL). Check PHP permissions/config/python path.");
        return 0.0; // Return low similarity on error
    }

    // Trim whitespace and convert to float
    $similarity = floatval(trim($output));
    error_log("PHP Text Comp: Parsed Similarity: " . $similarity);
    error_log("--- Text Comparison End ---");
    return $similarity; // Returns score between 0.0 and 1.0
}


// Function to execute Python script for image comparison (WITH ENHANCED LOGGING)
function compare_image_similarity($lostImgFilename, $lostDesc, $foundImgFilename, $foundDesc) {
     error_log("--- Image Comparison Start ---"); // Log start

    // Use absolute paths for reliability
    $scriptPath = __DIR__ . '/../python_scripts/imgComp.py'; // Path relative to this functions.php file
    $pythonExecutable = 'python3'; // <--- TRY 'python3' or full path like '/usr/bin/python3' if 'python' fails

    // Construct absolute paths from filenames and the defined UPLOAD_PATH
    // IMPORTANT: Ensure UPLOAD_PATH is correctly defined in db_connect.php (absolute path)
    if (!defined('UPLOAD_PATH')) {
        error_log("PHP Image Comp Error: UPLOAD_PATH is not defined!");
        return 0;
    }
    $absLostImgPath = UPLOAD_PATH . basename($lostImgFilename); // Use basename to prevent path traversal issues with filename
    $absFoundImgPath = UPLOAD_PATH . basename($foundImgFilename);

    error_log("PHP Image Comp: Using UPLOAD_PATH: " . UPLOAD_PATH);
    error_log("PHP Image Comp: Lost Image Filename (Relative): " . $lostImgFilename);
    error_log("PHP Image Comp: Found Image Filename (Relative): " . $foundImgFilename);
    error_log("PHP Image Comp: Lost Image Path (Abs): " . $absLostImgPath);
    error_log("PHP Image Comp: Found Image Path (Abs): " . $absFoundImgPath);
    error_log("PHP Image Comp: Lost Desc (start): " . substr($lostDesc, 0, 100) . "..."); // Log start of desc
    error_log("PHP Image Comp: Found Desc (start): " . substr($foundDesc, 0, 100) . "...");

    // Ensure files exist and are readable before calling script
    if (!file_exists($absLostImgPath)) {
        error_log("PHP Image Comp Error: Lost image file not found at path: {$absLostImgPath}");
        return 0; // Return 0% on file error
    }
     if (!is_readable($absLostImgPath)) {
        error_log("PHP Image Comp Error: Lost image file not readable (check permissions): {$absLostImgPath}");
        return 0;
    }
    if (!file_exists($absFoundImgPath)) {
        error_log("PHP Image Comp Error: Found image file not found at path: {$absFoundImgPath}");
        return 0; // Return 0% on file error
    }
    if (!is_readable($absFoundImgPath)) {
        error_log("PHP Image Comp Error: Found image file not readable (check permissions): {$absFoundImgPath}");
        return 0;
    }
    error_log("PHP Image Comp: Image files exist and seem readable.");

    // Use escapeshellarg for all arguments
    $escapedLostImgPath = escapeshellarg($absLostImgPath);
    $escapedLostDesc = escapeshellarg($lostDesc);
    $escapedFoundImgPath = escapeshellarg($absFoundImgPath);
    $escapedFoundDesc = escapeshellarg($foundDesc);

    // Build command
    $command = $pythonExecutable . ' ' . escapeshellarg($scriptPath) . ' ' . $escapedLostImgPath . ' ' . $escapedLostDesc . ' ' . $escapedFoundImgPath . ' ' . $escapedFoundDesc . ' 2>&1'; // Add 2>&1 to capture errors

    error_log("PHP Image Comp: Executing Command: " . $command);

    // Execute and capture output
    $output = shell_exec($command);

    error_log("PHP Image Comp: Raw Output from Python: " . var_export($output, true)); // Log raw output

    if ($output === null) {
        error_log("PHP Image Comp Error: Failed to execute script (shell_exec returned NULL). Check PHP permissions/config/python path.");
        return 0; // Return low similarity on error
    }

    // Trim and convert to integer
    $trimmed_output = trim($output);
    if (ctype_digit($trimmed_output)) { // Check if the output is purely digits
         $percentage = intval($trimmed_output);
         error_log("PHP Image Comp: Parsed Percentage: " . $percentage);
    } else {
        error_log("PHP Image Comp Warning: Python script output was not a simple integer ('{$trimmed_output}'). Returning 0.");
        $percentage = 0; // Treat non-integer output as 0% match
    }

    error_log("--- Image Comparison End ---");

    return $percentage; // Returns integer percentage 0-100
}

// --- Add other helper functions as needed ---
// E.g., function to sanitize input, function to notify users, etc.

?>