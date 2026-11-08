#!/usr/bin/env bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_PATH="${SCRIPT_DIR}/../../../.env"

echo " Parsing configuration maps from .env file..."

AI_API_KEY=$(grep -E "^AI_API_KEY=" "$ENV_PATH" | cut -d'=' -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^["'"'"']//' -e 's/["'"'"']$//')
AI_BASE_URL=$(grep -E "^AI_BASE_URL=" "$ENV_PATH" | cut -d'=' -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^["'"'"']//' -e 's/["'"'"']$//')
AI_MODEL=$(grep -E "^AI_MODEL=" "$ENV_PATH" | cut -d'=' -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^["'"'"']//' -e 's/["'"'"']$//')

if [ -z "$AI_API_KEY" ] || [ -z "$AI_BASE_URL" ] || [ -z "$AI_MODEL" ]; then
    echo "Error: One or more required variables (AI_API_KEY, AI_BASE_URL, AI_MODEL) are missing from your .env file."
    exit 1
fi

AI_BASE_URL="${AI_BASE_URL%/}"
ENDPOINT="${AI_BASE_URL}/chat/completions"

QUESTION="${1:-What is the capital of Australia? Answer in one sentence.}"

echo " Target Model: $AI_MODEL"
echo " API Endpoint: $ENDPOINT"
echo " Question: $QUESTION"
echo "================================================================================"
echo " Dispatching request to LLM service..."
echo "================================================================================"

# Build JSON body safely using php to handle escaping
PAYLOAD=$(php -r '
    $question = $argv[1];
    $model = $argv[2];
    echo json_encode([
        "model" => $model,
        "messages" => [
            ["role" => "system", "content" => "You are a helpful assistant. Answer concisely."],
            ["role" => "user", "content" => $question],
        ],
        "max_tokens" => 512,
        "temperature" => 0.3,
    ]);
' "$QUESTION" "$AI_MODEL")

response=$(curl -s -w "\n\nHTTP Status: %{http_code}" -X POST "$ENDPOINT" \
  -H "Authorization: Bearer $AI_API_KEY" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD")

echo ""
echo "================================================================================"
echo " Response:"
echo "================================================================================"
echo "$response"

echo ""
echo " Answer:"
echo "$response" | head -1 | php -r '
    $d = json_decode(trim(file_get_contents("php://stdin")), true);
    echo $d["choices"][0]["message"]["content"] ?? "No answer found.\n";
'
