#!/usr/bin/env bash

# Exit immediately if any sub-script fails unexpectedly
set -e

# Capture the script's home location so it executes perfectly from anywhere
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Track the master global stopwatch timer
GLOBAL_START_TIME=$(date +%s)

# Clear terminal screen output for pristine logging readability
clear

echo "================================================================================"
echo "PNC ENTERPRISE AGENT ENGINE: FULL PIPELINE TESTING SUITE"
echo "================================================================================"
echo " Execution Directory: $PWD"
echo " Started At: $(date)"
echo "============ ===================================================================="
echo ""

# Explicitly list out the child testing tracks exactly matching your filenames
test_scripts=(
    "blog_posts.sh"
    "developer_documentation.sh"
    "product_guide.sh"
    "sales_copy.sh"
    "social_media.sh"
)

for script in "${test_scripts[@]}"; do
    echo "================================================================================"
    echo " QUEUING COMPONENT: [ $script ]"
    echo "================================================================================"

    # Verify the child script exists and is executable before spinning threads
    if [ -f "$script" ]; then
        chmod +x "$script"

        TRACK_START_TIME=$(date +%s)

        # Execute the child shell script directly in the current terminal output matrix
        ./"$script"

        TRACK_END_TIME=$(date +%s)
        TRACK_DURATION=$((TRACK_END_TIME - TRACK_START_TIME))

        echo " Component [ $script ] finished cleanly in ${TRACK_DURATION}s."
    else
        echo "❌ Critical Error: Target script asset [ $script ] not found on disk!"
        exit 1
    fi
    echo ""
done

GLOBAL_END_TIME=$(date +%s)
GLOBAL_DURATION=$((GLOBAL_END_TIME - GLOBAL_START_TIME))

echo "================================================================================"
echo " GLOBAL SUITE METRICS: WORKFLOWS PASSED CLEANLY"
echo "================================================================================"
echo " Total processing duration across all 5 tracks: ${GLOBAL_DURATION}s"
echo " Version history snapshot stacks are fully populated on disk."
echo "================================================================================"
