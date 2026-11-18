#!/usr/bin/env bash
# declare.sh — Ensure all PHP files in app/ and tests/ start with declare(strict_types=1);
# Usage: bash ./scripts/declare.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$SCRIPT_DIR"

declare -i count=0

while IFS= read -r -d '' file; do
    # Skip if already has strict_types in the first 5 lines
    if head -n 5 "$file" | grep -q 'declare(strict_types=1);'; then
        continue
    fi

    # Only process files that start with <?php
    first_line=$(head -n 1 "$file")
    if [[ "$first_line" != "<?php"* ]]; then
        continue
    fi

    # Insert declare(strict_types=1); after the <?php line
    # Use a temp file to avoid in-place issues
    tmp=$(mktemp)
    {
        echo '<?php'
        echo ''
        echo 'declare(strict_types=1);'
        # Rest of file starting from line 2, skipping any blank lines
        tail -n +2 "$file" | sed '/./,$!d'
    } > "$tmp"

    mv "$tmp" "$file"
    count+=1
    echo "  Fixed: $file"
done < <(find app tests -type f -name '*.php' -print0)

echo ""
echo "Done. Fixed $count file(s)."
