import sys

def simple_word_overlap_similarity(text1, text2):
    """
    Calculates similarity based on the proportion of shared words (Jaccard Index).
    Case-insensitive, splits on whitespace.
    Returns a score between 0.0 (no overlap) and 1.0 (identical word sets).
    """
    if not isinstance(text1, str) or not isinstance(text2, str):
        return 0.0 # Handle potential non-string inputs gracefully

    words1 = set(text1.lower().split())
    words2 = set(text2.lower().split())

    intersection = words1.intersection(words2)
    union = words1.union(words2)

    if not union: # Handle case where both texts are empty or have no words
        return 1.0 if not words1 and not words2 else 0.0

    similarity = len(intersection) / len(union)
    return similarity

if __name__ == "__main__":
    if len(sys.argv) != 3:
        # Output 0 or an error code if arguments are incorrect
        print("0.0")
        sys.exit(1) # Exit with error status

    text_a = sys.argv[1]
    text_b = sys.argv[2]

    similarity_score = simple_word_overlap_similarity(text_a, text_b)
    # Print ONLY the similarity score, formatted as needed (e.g., float to 4 decimal places)
    print(f"{similarity_score:.4f}")