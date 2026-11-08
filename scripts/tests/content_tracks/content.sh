#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

TARGET="${1:-all}"

declare -A TRACKS=(
    ["blog_posts"]="blog_posts/blog_posts.sh"
    ["developer"]="developer/developer_documentation.sh"
    ["product_guide"]="product_guide/product_guide.sh"
    ["sales_copy"]="sales_copy/sales_copy.sh"
    ["social_media"]="social_media/social_media.sh"
)

if [ "$TARGET" = "all" ]; then
    test_scripts=("${TRACKS[@]}")
elif [ -n "${TRACKS[$TARGET]+x}" ]; then
    test_scripts=("${TRACKS[$TARGET]}")
else
    echo "Usage: $0 {blog_posts|developer|product_guide|sales_copy|social_media|all}"
    exit 1
fi

GLOBAL_START_TIME=$(date +%s)
clear

echo "================================================================================"
echo "PNC ENTERPRISE AGENT ENGINE: PIPELINE TESTING SUITE"
echo "================================================================================"
echo " Execution Directory: $PWD"
echo " Target: $TARGET"
echo " Started At: $(date)"
echo "================================================================================"
echo ""

for script in "${test_scripts[@]}"; do
    echo "================================================================================"
    echo " QUEUING COMPONENT: [ $script ]"
    echo "================================================================================"

    if [ -f "$script" ]; then
        chmod +x "$script"
        TRACK_START_TIME=$(date +%s)
        ./"$script"
        TRACK_END_TIME=$(date +%s)
        TRACK_DURATION=$((TRACK_END_TIME - TRACK_START_TIME))
        echo " Component [ $script ] finished cleanly in ${TRACK_DURATION}s."
    else
        echo "Critical Error: Target script asset [ $script ] not found on disk!"
        exit 1
    fi
    echo ""
done

GLOBAL_END_TIME=$(date +%s)
GLOBAL_DURATION=$((GLOBAL_END_TIME - GLOBAL_START_TIME))

echo "================================================================================"
echo " GLOBAL SUITE METRICS: WORKFLOWS PASSED CLEANLY"
echo "================================================================================"
echo " Total duration ($TARGET): ${GLOBAL_DURATION}s"
echo " Version history snapshot stacks are fully populated on disk."
echo "================================================================================"
