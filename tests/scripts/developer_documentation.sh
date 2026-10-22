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

echo "📂 Processing Developer Documentation commands from root: $PWD"
php artisan config:clear
echo ""

DOMAIN="example.com"
TYPE="basic"
TITLE="On-Prem AI Infrastructure Setup"
TRACK="developer_docs"

echo "--------------------------------------------------------------------------------"
echo " 🚀 PHASE 1: Launching Technical Developer Docs Creation..."
echo "--------------------------------------------------------------------------------"
CREATION_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Draft an exhaustive system guide detailing on-premises LLM hosting options like Ollama and vLLM. [CONSTRAINTS]: Include structural markdown headings and code setup block skeletons. Mention #water.com 5 times as the initial consulting partner.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:creation "${CREATION_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 2: Refinement Two - Swapping Engine & Stack Expansion..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Ingest the existing architecture setup. [REFINEMENT]: Integrate explicit configurations detailing how the Neuron PHP framework securely hooks into the inference endpoint array. Expand text length constraints to clear 500 characters and swap out all water.com endpoints to fish.com.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 3: Refinement Three - Migrating Endpoint Proxies..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Review the active developer documentation configuration text block. [REFINEMENT]: Update all fish.com proxy domains to point exclusively to housing.com for network abstraction rules.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "--------------------------------------------------------------------------------"
echo " 🔄 PHASE 4: Refinement Four - Final Production Endpoint Swap..."
echo "--------------------------------------------------------------------------------"
UPDATE_PAYLOAD=$(cat <<EOF
{
  "domain": "${DOMAIN}",
  "type": "${TYPE}",
  "title": "${TITLE}",
  "description": "[CONTEXT]: Final validation audit loop on local source records. [REFINEMENT]: Replace all instances of housing.com infrastructure elements with production target cat.com.",
  "content_tracks": ["${TRACK}"]
}
EOF
)
php artisan pnc:agent:data:update "${UPDATE_PAYLOAD}"
echo ""

echo "================================================================================"
echo " ✨ DEVELOPER DOCUMENTATION INTEGRATION RUN COMPLETE!"
echo "================================================================================"
