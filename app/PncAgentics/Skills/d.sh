#!/bin/bash

# Check if a filename was provided as an argument
if [ -z "$1" ]; then
    echo "Error: No filename provided."
    echo "Usage: ./delete.sh <filename>"
    exit 1
fi

FILENAME="$1"

echo "Searching for and deleting all instances of '$FILENAME'..."

# Recursively find and delete the file, outputting the paths of deleted files (-v)
find . -type f -name "$FILENAME" -exec rm -v {} +

echo "Done."
