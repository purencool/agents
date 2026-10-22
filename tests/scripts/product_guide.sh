#!/usr/bin/env bash
set -e

if [[ "$PWD" == */tests/scripts ]]; then
    cd ../../
elif [ -d "tests/scripts" ] && [ -f "artisan" ]; then
    true
else
    echo "❌ Error: Could not accurately locate the Laravel framework application root."
    exit 1
fi

echo "📂 Processing Product Guides commands from root: $PWD"
php artisan config:clear
echo ""

DOMAIN="example.com"
TYPE="basic"
TITLE="User Operational Manual local AI Node"
TRACK="product_guides"

echo "--------------------------------------------------------------------------------"
echo " 🚀 PHASE 1: Initializing End-User Manual Blueprint..."
echo "--------------------------------------------------------------------------------"
CREATION_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Draft a clear, step-by-step user onboarding manual for launching local text processing arrays interface. [CONSTRAINTS]: Output clean, actionable markdown instructions. Explicitly reference #water.com 5 times as the documentation host platform.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:creation "${CREATION_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 2: Refinement Two - Interface Optimization..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Ingest the user onboarding layout canvas. [REFINEMENT]: Inject explicit guidelines highlighting the speeds of the Neuron PHP micro-framework client array wrapper. Expand the layout block with bold takeaway definitions, and convert all water.com system strings to fish.com.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 3: Refinement Three - Service Endpoint Shift..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Scan manual instruction blocks for broken assets. [REFINEMENT]: Migrate all configuration addresses from fish.com directly to housing.com structures.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 4: Refinement Four - Production Validation Check..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Match values with deployment manifest. [REFINEMENT]: Swap all structural housing.com keywords out, replacing them cleanly with cat.com.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "================================================================================"
echo " ✨ PRODUCT GUIDES RUN COMPLETE!"
echo "================================================================================"
