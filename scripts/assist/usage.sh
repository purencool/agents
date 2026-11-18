#!/usr/bin/env bash
# scripts/usage.sh
# Scans app/ for classes and shows usage relationships across the codebase.
# Usage: ./scripts/usage.sh [--unused|--used|--all] [--json]
#        (default: --all)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$SCRIPT_DIR"

# ─── Args ────────────────────────────────────────────────────────────────────

MODE="--all"
JSON_OUTPUT=0

for arg in "$@"; do
    case "$arg" in
        --unused|--used|--all) MODE="$arg" ;;
        --json) JSON_OUTPUT=1 ;;
        --help|-h)
            cat <<'EOF'
Usage: ./scripts/usage.sh [MODE] [OPTIONS]

Modes:
  --all      Show both used and unused classes (default)
  --used     Show only classes referenced elsewhere
  --unused   Show only classes with no references

Options:
  --json     Output as JSON array
  -h, --help Show this help
EOF
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            echo "Run with --help for usage." >&2
            exit 1
            ;;
    esac
done

# ─── Scan ────────────────────────────────────────────────────────────────────

declare -a results=()
declare -i used_count=0 unused_count=0 total=0

# Build a combined search corpus for faster grep (one pass instead of N)
SEARCH_PATHS=("$SCRIPT_DIR/app" "$SCRIPT_DIR/tests" "$SCRIPT_DIR/config" "$SCRIPT_DIR/routes")
SEARCH_PATHS=("${SEARCH_PATHS[@]/#/$SCRIPT_DIR/}")

# Filter to only existing paths
SEARCH_PATHS=()
for p in "$SCRIPT_DIR/app" "$SCRIPT_DIR/tests" "$SCRIPT_DIR/config" "$SCRIPT_DIR/routes"; do
    [[ -d "$p" ]] && SEARCH_PATHS+=("$p")
done

while IFS= read -r -d '' file; do
    class_name="$(basename "${file%.php}")"

    # Skip interfaces and traits
    if head -30 "$file" | grep -qE '^[[:space:]]*(interface|trait)[[:space:]]'; then
        continue
    fi

    total=$((total + 1))

    # Search for references to this class name (as a word boundary match)
    # Catches: use statements, type hints, ::class, new, extends, implements, etc.
    matches=$(grep -rn --include="*.php" \
        -wE "${class_name}" \
        "${SEARCH_PATHS[@]}" 2>/dev/null \
        | grep -vF "$file" || true)

    if [[ -n "$matches" ]]; then
        used_count=$((used_count + 1))

        if [[ "$MODE" == "--all" || "$MODE" == "--used" ]]; then
            rel_path="${file#"$SCRIPT_DIR"/}"
            results+=("\"USED|$rel_path|$matches\"")
        fi
    else
        unused_count=$((unused_count + 1))

        if [[ "$MODE" == "--all" || "$MODE" == "--unused" ]]; then
            rel_path="${file#"$SCRIPT_DIR"/}"
            results+=("\"UNUSED|$rel_path|\"")
        fi
    fi
done < <(find "$SCRIPT_DIR/app" -name "*.php" -type f \
    ! -path "*/migrations/*" \
    ! -path "*/seeders/*" \
    ! -path "*/config/*" \
    -print0)

# ─── Output ──────────────────────────────────────────────────────────────────

if [[ $JSON_OUTPUT -eq 1 ]]; then
    # JSON output
    echo "["
    first=1
    for entry in "${results[@]}"; do
        IFS='|' read -r status path matches <<< "$entry"
        (( first )) || echo ","
        first=0
        if [[ "$status" == "USED" ]]; then
            # Build a JSON array of match lines
            json_matches="[]"
            if [[ -n "$matches" ]]; then
                json_matches="["
                mfirst=1
                while IFS= read -r line; do
                    (( mfirst )) || json_matches+=","
                    mfirst=0
                    escaped=$(printf '%s' "$line" | sed 's/"/\\"/g')
                    json_matches+="\"$escaped\""
                done <<< "$matches"
                json_matches+="]"
            fi
            printf "  {\"status\":\"used\",\"file\":\"%s\",\"references\":%s}" "$path" "$json_matches"
        else
            printf "  {\"status\":\"unused\",\"file\":\"%s\",\"references\":[]}" "$path"
        fi
    done
    echo ""
    echo "]"
    echo ""
    echo "# $unused_count unused, $used_count used (of $total)" >&2
else
    # Human-readable output
    if [[ ${#results[@]} -eq 0 ]]; then
        echo "No classes found."
        exit 0
    fi

    for entry in "${results[@]}"; do
        IFS='|' read -r status path matches <<< "$entry"
        if [[ "$status" == "USED" ]]; then
            echo "✓ $path"
            echo "$matches" | sed 's/^/    ← /'
            echo ""
        else
            echo "✗ $path  (unused)"
            echo ""
        fi
    done

    echo "=========================================="
    echo " $unused_count unused, $used_count used (of $total)"
    echo "=========================================="
fi
