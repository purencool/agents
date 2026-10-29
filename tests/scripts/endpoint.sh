#!/usr/bin/env bash

# Automatically resolve the real absolute folder path where this script resides
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Target the relative location of the .env file based on the script's actual directory
ENV_PATH="${SCRIPT_DIR}/../../.env"

echo " Parsing configuration maps from .env file..."

# Read and scrub values dynamically using standard character class matches
AI_API_KEY=$(grep -E "^AI_API_KEY=" "$ENV_PATH" | cut -d'=' -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^["'\'']//' -e 's/["'\'']$//')
AI_BASE_URL=$(grep -E "^AI_BASE_URL=" "$ENV_PATH" | cut -d'=' -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^["'\'']//' -e 's/["'\'']$//')
AI_MODEL=$(grep -E "^AI_MODEL=" "$ENV_PATH" | cut -d'=' -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^["'\'']//' -e 's/["'\'']$//')

# Validate that critical parameters were populated
if [ -z "$AI_API_KEY" ] || [ -z "$AI_BASE_URL" ] || [ -z "$AI_MODEL" ]; then
    echo "❌ Error: One or more required variables (AI_API_KEY, AI_BASE_URL, AI_MODEL) are missing from your .env file."
    exit 1
fi

# Standardise the URL trailing endpoint mapping
AI_BASE_URL="${AI_BASE_URL%/}"
ENDPOINT="${AI_BASE_URL}/chat/completions"

echo " • Target Model: $AI_MODEL"
echo " • API Endpoint: $ENDPOINT"
echo "================================================================================"
echo " Dispatching ping test request to third-party LLM service container..."

# Execute the cURL network transits payload mapping
response=$(curl -s -w "\n💡 HTTP Status Code: %{http_code}\n" -X POST "$ENDPOINT" \
  -H "Authorization: Bearer $AI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "'"$AI_MODEL"'",
    "messages": [
      {"role": "user", "content": "ping"}
    ],
    "max_tokens": 10,
    "temperature": 0.0
  }')

echo "================================================================================"
echo -e " Server Transits Output Payload:\n"
echo "$response"
