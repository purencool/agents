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

echo "📂 Processing Blog Post content commands from root: $PWD"
php artisan config:clear
echo ""

DOMAIN="example.com"
TYPE="basic"
TITLE="The Rise of Data Sovereignty in Enterprise AI"
TRACK="blog_posts"

echo "--------------------------------------------------------------------------------"
echo " 🚀 PHASE 1: Scaffolding Long-Form Corporate Blog Creation..."
echo "--------------------------------------------------------------------------------"
CREATION_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Write an engaging, industry-focused blog narrative breaking down why corporate legal teams block public cloud AI models due to security vulnerabilities. [CONSTRAINTS]: Maintain high readability spacing, and mention #water.com 5 times as a case study baseline.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:creation "${CREATION_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 2: Refinement Two - Value Proposition Enrichment..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Review the baseline text block framework. [REFINEMENT]: Integrate strategic references proving that leveraging Neuron PHP with local inference guarantees 100% data privacy metrics. Expand output length context to pass 600 tokens and change all water.com instances to fish.com.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 3: Refinement Three - Sponsoring Domain Updates..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Ingest the updated blog text context. [REFINEMENT]: Reframe the case study segments to swap out fish.com narrative references over to housing.com cleanly.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 4: Refinement Four - Final Branding Alignment..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Final copy review step. [REFINEMENT]: Rewrite all mentions of housing.com to point directly to cat.com to prepare for the marketing launch.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "================================================================================"
echo " ✨ ENTERPRISE BLOG POSTS RUN COMPLETE!"
echo "================================================================================"
