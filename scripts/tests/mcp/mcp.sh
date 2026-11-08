#!/bin/bash
# Test all search tools and driver variations via pnc:mcp

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

cd "$ROOT"

ARTISAN="php artisan pnc:mcp"
TEST_INPUT="Laravel 12 features"
PASS=0
FAIL=0
TOTAL=0

run_test() {
    local label="$1"
    shift
    local cmd="$@"
    TOTAL=$((TOTAL + 1))

    local output
    output=$(eval "$cmd" 2>&1)
    local exit_code=$?

    if [ $exit_code -eq 0 ]; then
        echo "[PASS] $label"
        PASS=$((PASS + 1))
    else
        echo "[FAIL] $label"
        echo " $(echo "$output" | grep -E '(error|Error|FAIL|⚠|❌)' | head -1)"
        FAIL=$((FAIL + 1))
    fi
}

# ═══════════════════════════════════════════════
# Get available search drivers
# ═══════════════════════════════════════════════
echo "========================================"
echo "  MCP Search Tool Test Suite"
echo "========================================"
echo ""

# Get the MCP server path from .env (MCP_SERVER=...)
MCP_SERVER=$(grep "^MCP_SERVER=" .env 2>/dev/null | cut -d'=' -f2-)
MCP_SERVER=$(echo "$MCP_SERVER" | sed "s/^[\"']//;s/[\"']$//")
if [ -z "$MCP_SERVER" ]; then
    echo "ERROR: MCP_SERVER not set in .env"
    exit 1
fi

# Resolve to absolute path if relative
if [[ "$MCP_SERVER" != /* ]]; then
    MCP_SERVER="$ROOT/$MCP_SERVER"
fi

echo "MCP Server: $MCP_SERVER"
echo ""

# Get drivers from the MCP server
DRIVERS_OUTPUT=$(php "$MCP_SERVER" drivers 2>/dev/null)
DRIVERS=$(echo "$DRIVERS_OUTPUT" | php -r '
    $d = json_decode(file_get_contents("php://stdin"), true);
    if (isset($d["available"])) {
        foreach ($d["available"] as $a) echo $a . "\n";
    }
' 2>/dev/null)

DEFAULT_DRIVER=$(echo "$DRIVERS_OUTPUT" | php -r '
    $d = json_decode(file_get_contents("php://stdin"), true);
    echo $d["default"] ?? "search";
' 2>/dev/null)

echo "Default driver: $DEFAULT_DRIVER"
echo "Available drivers: $(echo "$DRIVERS" | tr '\n' ' ')"
echo ""

# ═══════════════════════════════════════════════
# Test search with each driver
# ═══════════════════════════════════════════════
echo "--- Search (all drivers) ---"
echo ""

while IFS= read -r driver; do
    [ -z "$driver" ] && continue
    run_test "search --driver=$driver" \
        "$ARTISAN --tool=search --input=\"$TEST_INPUT\" --driver=$driver"
done <<< "$DRIVERS"

run_test "search (default)" \
    "$ARTISAN --tool=search --input=\"$TEST_INPUT\""

echo ""

# ═══════════════════════════════════════════════
# Test other search/scrape tools
# ═══════════════════════════════════════════════
echo "--- Web Fetch / Scrape ---"
echo ""

run_test "web-fetch" \
    "$ARTISAN --tool=web-fetch --input=\"https://example.com\""

run_test "http GET" \
    "$ARTISAN --tool=http --input=\"GET https://httpbin.org/get\""

run_test "firecrawl" \
    "$ARTISAN --tool=firecrawl --input=\"https://example.com\""

echo ""

# ═══════════════════════════════════════════════
# Test with --save
# ═══════════════════════════════════════════════
echo "--- Save to file ---"
echo ""

SAVE_DIR="$ROOT/storage/app/mcp-test"
mkdir -p "$SAVE_DIR"

run_test "search --save" \
    "$ARTISAN --tool=search --input=\"$TEST_INPUT\" --save=$SAVE_DIR/search.json"

run_test "web-fetch --save" \
    "$ARTISAN --tool=web-fetch --input=\"https://example.com\" --save=$SAVE_DIR/page.html"

echo ""
echo "  Saved files:"
if [ -d "$SAVE_DIR" ]; then
    find "$SAVE_DIR" -type f | sort | while read f; do
        echo "    $f"
    done
fi

echo ""

# ═══════════════════════════════════════════════
# Full validation
# ═══════════════════════════════════════════════
echo "--- Full validation (--all) ---"
echo ""

#$ARTISAN --all

echo ""

# ═══════════════════════════════════════════════
# SUMMARY
# ═══════════════════════════════════════════════
echo "========================================"
echo "  Summary: $PASS passed, $FAIL failed, $TOTAL total"
echo "========================================"

# Cleanup
rm -rf "$SAVE_DIR" 2>/dev/null

exit $FAIL
