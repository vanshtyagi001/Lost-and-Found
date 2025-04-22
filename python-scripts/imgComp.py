import google.generativeai as genai
import os
from PIL import Image
import sys
import argparse
import logging # Import logging

# --- Configuration ---
# WARNING: Storing API keys directly in code is insecure! Consider environment variables.
API_KEY = "AIzaSyDiSqdPU5NB_H3qeX95fOZE1ZhXl5E2rx8" # Replace if needed
MODEL_NAME = "gemini-1.5-flash" # Use 1.5 Flash as it's generally available and good for this

# Define a log file path - Ensure this path is WRITABLE by the web server user (e.g., www-data)
# Common places are /tmp or a dedicated logs directory if permissions are set correctly.
LOG_FILE = '/tmp/imgcomp_debug.log' # Example path, adjust if needed & ensure permissions

# --- Setup Logging ---
log_configured = False
try:
    # Attempt to set up logging configuration
    log_dir = os.path.dirname(LOG_FILE)
    if log_dir and not os.path.exists(log_dir):
        os.makedirs(log_dir, exist_ok=True) # Create log directory if it doesn't exist

    logging.basicConfig(filename=LOG_FILE,
                        level=logging.INFO, # Log INFO level and above (INFO, WARNING, ERROR, CRITICAL)
                        format='%(asctime)s - %(levelname)s - [imgComp.py] - %(message)s',
                        filemode='a') # Append mode ('w' for overwrite)
    logging.info("--- Logging Initialized ---")
    log_configured = True
except Exception as log_e:
    # If logging setup fails, print error to stderr so PHP might capture it via 2>&1
    print(f"CRITICAL - imgComp.py: Failed to configure logging to {LOG_FILE}: {log_e}", file=sys.stderr)
    # Script will still try to run, but without file logging.

# Helper function to log messages safely even if logging failed
def safe_log(level, message):
    if log_configured:
        if level == 'info':
            logging.info(message)
        elif level == 'warning':
            logging.warning(message)
        elif level == 'error':
            logging.error(message)
        elif level == 'critical':
            logging.critical(message)
    else:
        # Fallback to stderr if logging isn't working
        print(f"{level.upper()} - imgComp.py: {message}", file=sys.stderr)

# --- End Configuration ---

def get_similarity_percentage(lost_img_path, lost_desc, found_img_path, found_desc):
    """
    Compares a lost item (image + description) with a found item
    (image + description) using Gemini and returns ONLY the similarity percentage.
    Logs detailed steps and errors.
    """
    safe_log('info', "--- get_similarity_percentage function started ---")
    safe_log('info', f"Received Args: lost_img='{lost_img_path}', found_img='{found_img_path}'")
    safe_log('info', f"Received Args: lost_desc='{lost_desc[:100]}...', found_desc='{found_desc[:100]}...'") # Log truncated descriptions

    if not API_KEY:
        safe_log('error', "API_KEY is not configured in the script.")
        print("0") # Output 0 to PHP to indicate failure
        sys.exit(1)

    try:
        safe_log('info', "Configuring Google Generative AI...")
        genai.configure(api_key=API_KEY)
        safe_log('info', "GenAI configured successfully.")
    except Exception as e:
        safe_log('error', f"Failed to configure GenAI: {e}")
        print("0")
        sys.exit(1)

    # Load images
    lost_img = None
    found_img = None
    try:
        safe_log('info', f"Attempting to load lost image from: {lost_img_path}")
        lost_img = Image.open(lost_img_path)
        safe_log('info', f"Lost image loaded successfully. Format: {lost_img.format}, Size: {lost_img.size}")

        safe_log('info', f"Attempting to load found image from: {found_img_path}")
        found_img = Image.open(found_img_path)
        safe_log('info', f"Found image loaded successfully. Format: {found_img.format}, Size: {found_img.size}")

    except FileNotFoundError as e:
        safe_log('error', f"Error loading image - File Not Found: {e}")
        print("0")
        sys.exit(1)
    except Exception as e:
        # Catch other potential PIL errors (corrupt file, etc.)
        safe_log('error', f"Error opening image file (PIL Error): {e}")
        print("0")
        sys.exit(1)

    # Prepare the prompt for Gemini
    prompt = f"""
Analyze the following lost item and found item details.
Lost Item:
Description: {lost_desc}
Image 1: [Attached Lost Item Image]

Found Item:
Description: {found_desc}
Image 2: [Attached Found Item Image]

Carefully compare the visual details in both images and the information in both descriptions. Determine the likelihood that these are the exact same item.
Respond with ONLY a single integer between 0 and 100 representing the similarity percentage. Do not include '%', explanations, context, or any other text. Just the number.
"""
    safe_log('info', "Prompt prepared for Gemini API call.")
    # Optionally log the full prompt if needed for debugging, but be mindful of length/secrets
    # safe_log('debug', f"Full Prompt: {prompt}") # Change level to DEBUG if using levels

    # Create the model instance and make the API call
    try:
        safe_log('info', f"Instantiating Gemini model: {MODEL_NAME}")
        model = genai.GenerativeModel(MODEL_NAME)

        # Prepare the content for the API call (text prompt + images)
        content = [
            prompt,    # The main instruction text
            lost_img,  # First image
            found_img  # Second image
        ]
        safe_log('info', "Content list (prompt + images) prepared.")

        safe_log('info', "Calling Gemini API (model.generate_content)...")
        # Optional: Add safety settings if needed
        # response = model.generate_content(content, safety_settings=...)
        response = model.generate_content(content)
        safe_log('info', "Received response from Gemini API.")

        # --- Process Response ---
        # Log the raw response text for debugging before parsing
        try:
            raw_text = response.text
            safe_log('info', f"Gemini raw response text: '{raw_text}'")
        except Exception as e:
             safe_log('warning', f"Could not access response.text directly: {e}")
             raw_text = "[Error accessing response text]" # Placeholder

        # Check for prompt feedback (indicates potential issues like safety blocks)
        if hasattr(response, 'prompt_feedback') and response.prompt_feedback:
             feedback_str = str(response.prompt_feedback)
             safe_log('warning', f"Gemini Prompt Feedback received: {feedback_str}")
             # Decide if feedback indicates a failure (e.g., blocked content)
             if "BLOCK_REASON_SAFETY" in feedback_str or "BLOCK_REASON_OTHER" in feedback_str:
                 safe_log('error', "Content generation blocked due to safety or other reasons.")
                 print("0") # Indicate failure
                 sys.exit(1)

        # Attempt to parse the percentage
        try:
            percentage = int(raw_text.strip())
            safe_log('info', f"Successfully parsed percentage: {percentage}")
            print(percentage) # <<< THIS IS THE SCRIPT'S SUCCESSFUL OUTPUT TO PHP
            safe_log('info', "--- get_similarity_percentage function finished successfully ---")

        except ValueError:
            # This happens if the response text wasn't a simple integer
            safe_log('error', f"ValueError: Gemini response was not a valid integer: '{raw_text.strip()}'")
            print("0") # Indicate failure
            sys.exit(1)
        except AttributeError:
             # This might happen if the response object structure is unexpected (e.g., error response)
             safe_log('error', "AttributeError: Could not extract text from Gemini response object.")
             print("0") # Indicate failure
             sys.exit(1)
        except Exception as parse_e:
             # Catch any other unexpected errors during parsing
             safe_log('error', f"Unexpected error parsing Gemini response: {parse_e}")
             print("0")
             sys.exit(1)

    except Exception as e:
        # Catch errors during API call itself (network issues, authentication, etc.)
        safe_log('critical', f"An error occurred during the Gemini API call or processing: {e}")
        print("0") # Indicate failure
        sys.exit(1) # Exit on API error


if __name__ == "__main__":
    safe_log('info', f"imgComp.py script started directly with args: {sys.argv}")

    # Use argparse for robust command-line argument parsing
    parser = argparse.ArgumentParser(description='Compare Lost and Found items using Gemini.')
    parser.add_argument('lost_img_path', type=str, help='Path to the lost item image')
    parser.add_argument('lost_desc', type=str, help='Description of the lost item')
    parser.add_argument('found_img_path', type=str, help='Path to the found item image')
    parser.add_argument('found_desc', type=str, help='Description of the found item')

    try:
        args = parser.parse_args()
        safe_log('info', "Command line arguments parsed successfully.")
    except SystemExit:
         safe_log('error', "Failed to parse command line arguments.")
         # Argparse handles printing errors and exiting, but we log it too.
         # No need to print "0" here as argparse likely exited already.
         raise # Re-raise the SystemExit to ensure script stops

    # Basic check if image files exist before calling the main function
    valid_paths = True
    if not os.path.exists(args.lost_img_path):
        safe_log('error', f"Input Error: Lost image file not found at path: {args.lost_img_path}")
        valid_paths = False
    if not os.path.exists(args.found_img_path):
        safe_log('error', f"Input Error: Found image file not found at path: {args.found_img_path}")
        valid_paths = False

    if not valid_paths:
        print("0") # Output 0 if files are missing
        sys.exit(1)
    else:
         safe_log('info',"Input image file paths verified.")
         # Call the main comparison function
         get_similarity_percentage(
             args.lost_img_path,
             args.lost_desc,
             args.found_img_path,
             args.found_desc
         )