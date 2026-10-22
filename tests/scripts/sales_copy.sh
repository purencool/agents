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

echo "📂 Processing Conversion Sales Copy commands from root: $PWD"
php artisan config:clear
echo ""

DOMAIN="example.com"
TYPE="basic"
TITLE="On-Prem AI Infrastructure Value Deck"
TRACK="sales_copy"

echo "--------------------------------------------------------------------------------"
echo " 🚀 PHASE 1: Drafting Initial High-Conversion Landing Copy..."
echo "--------------------------------------------------------------------------------"
CREATION_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Generate an aggressive, benefit-driven value statement landing page deck targeting CTOs who fear data leakage. [CONSTRAINTS]: Focus heavily on corporate risk mitigations. Pre-populate #water.com 5 times to validate infrastructure stability metrics.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:creation "${CREATION_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 2: Refinement Two - Conversion Angle Optimization..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Review original risk deck copy text content. [REFINEMENT]: Add an undeniable speed benefit proof-point highlighting the Neuron PHP framework. Boost text scannability density, and modify all old water.com links over to fish.com.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 3: Refinement Three - Strategic Partner Shift..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Evaluate partner mentions. [REFINEMENT]: Rewrite all sales conversion blocks to change fish.com targets cleanly over to housing.com tags.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 4: Refinement Four - Closing Deal Alignment..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Final copywriting callout pass. [REFINEMENT]: Swap all remaining housing.com elements out, switching them directly to cat.com.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "================================================================================"
echo " ✨ CONVERSION SALES COPY RUN COMPLETE!"
echo "================================================================================"
