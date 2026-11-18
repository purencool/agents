#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$SCRIPT_DIR"

APP_DIR="${1:-$SCRIPT_DIR/app}"
TESTS_DIR="${2:-$SCRIPT_DIR/tests}"

if [[ ! -d "$APP_DIR" ]]; then
    echo "Error: '$APP_DIR' not found." >&2
    exit 1
fi

declare -A TEST_FILES
while IFS= read -r -d '' test_file; do
    basename_no_ext="$(basename "${test_file%.php}")"
    TEST_FILES["$basename_no_ext"]=1
done < <(find "$TESTS_DIR" -name "*.php" -type f -print0)

untested=()
total=0

while IFS= read -r -d '' file; do
    class_name="$(basename "${file%.php}")"

    if head -20 "$file" | grep -qE '^[[:space:]]*(interface|trait)[[:space:]]'; then
        continue
    fi

    total=$((total + 1))

    if [[ -z "${TEST_FILES[$class_name]:-}" && -z "${TEST_FILES[${class_name}Test]:-}" ]]; then
        untested+=("$file")
    fi
done < <(find "$APP_DIR" -name "*.php" -type f ! -path "*/migrations/*" ! -path "*/seeders/*" ! -path "*/config/*" -print0)

echo "=========================================="
echo " Untested Classes"
echo "=========================================="
echo ""

if [[ ${#untested[@]} -eq 0 ]]; then
    echo "All $total classes have tests."
else
    printf "%-4s %-60s\n" "#" "File"
    printf "%-4s %-60s\n" "---" "----"
    for i in "${!untested[@]}"; do
        printf "%-4s %-60s\n" "$((i + 1))" "${untested[$i]#"$SCRIPT_DIR"/}"
    done
    echo ""
    echo "Total: ${#untested[@]} / $total classes untested"
    echo "Coverage: $(( (total - ${#untested[@]}) * 100 / total ))%"
fi
