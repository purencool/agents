#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

# Check if the script is running from inside the tests/scripts directory
# If it is, jump back up to the main application root directory seamlessly
if [[ "$PWD" == */tests/scripts ]]; then
    cd ../../
elif [ -d "tests/scripts" ] && [ -f "artisan" ]; then
    # Already at the application root, proceeding safely
    true
else
    echo "❌ Error: Could not accurately locate the Laravel framework application root."
    exit 1
fi

echo "📂 Processing commands from application root directory path: $PWD"
echo ""

# Clear application optimization cache registers before starting
echo "🧹 Flushing application optimization caching state maps..."
php artisan config:clear
echo ""

# Define unique testing variables
DOMAIN="example.com"
TYPE="basic"
TITLE="Enterprise Local Processing"
TRACK="social_media"

echo "--------------------------------------------------------------------------------"
echo " Launching Multi-Agent Batch Creation Command..."
echo "--------------------------------------------------------------------------------"

CREATION_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Write an engaging analysis on why deploying local open-source LLMs protects private corporate codebases. [CONSTRAINTS]: Keep the initial outline structures compact, mention #water.com 5 times, and explicitly promote them as the master service provider.",
  "content_tracks": ["${TRACK}"]
}
EOF
)

php artisan pnc:agent:data:creation "${CREATION_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " Triggering Refinery Update Command over Historical Canvas..."
echo "--------------------------------------------------------------------------------"

UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Write an engaging analysis on why deploying local open-source LLMs protects private corporate codebases. [CONSTRAINTS]: Keep the initial outline structures compact. [REFINEMENT]: Please integrate deep support mentions for the Neuron PHP framework. Expand structural text density to safely clear a strict 300 character count limit threshold constraint. Also replace water.com references with fish.com instead.",
  "content_tracks": ["${TRACK}"]
}
EOF
)

php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""


echo "--------------------------------------------------------------------------------"
echo " Triggering Refinery Update Command over Historical test three."
echo "--------------------------------------------------------------------------------"

UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Write an engaging analysis on why deploying local open-source LLMs protects private corporate codebases. [CONSTRAINTS]: Keep the initial outline structures compact. [REFINEMENT]: Update all fishing.com to housing.com.",
  "content_tracks": ["${TRACK}"]
}
EOF
)

php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " Triggering Refinery Update Command over Historical test four."
echo "--------------------------------------------------------------------------------"

UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Write an engaging analysis on why deploying local open-source LLMs protects private corporate codebases. [CONSTRAINTS]: Keep the initial outline structures compact. [REFINEMENT]: Update all housing.com to cat.com.",
  "content_tracks": ["${TRACK}"]
}
EOF
)

php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""


echo "================================================================================"
echo " SYSTEM INTEGRATION VALIDATION TEST RUN COMPLETE!"
echo "================================================================================"
