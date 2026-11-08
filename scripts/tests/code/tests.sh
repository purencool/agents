#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
cd "$PROJECT_ROOT"

TEST_DIR="${1:-tests}"

echo "========================================"
echo "  PhpUnit Test Suite"
echo "========================================"
echo ""

if [ -d "$TEST_DIR" ]; then
    vendor/bin/phpunit "$TEST_DIR"
else
    echo "Error: Test directory [$TEST_DIR] not found."
    exit 1
fi
