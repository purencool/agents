#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$SCRIPT_DIR"

declare -i count=0

while IFS= read -r -d '' file; do
    # Skip if already has the license header
    if head -n 10 "$file" | grep -q '@license     GNU General Public License'; then
        continue
    fi

    # Only process files that start with <?php
    first_line=$(head -n 1 "$file")
    if [[ "$first_line" != "<?php"* ]]; then
        continue
    fi

    tmp=$(mktemp)
    {
        cat <<'HEADER'
<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

HEADER

        # Rest of file from line 2 onward, skipping leading blank lines,
        # then removing any existing declare(strict_types=1); line
        tail -n +2 "$file" \
            | sed '/./,$!d' \
            | grep -v '^declare(strict_types=1);$'
    } > "$tmp"

    mv "$tmp" "$file"
    count+=1
    echo "  Fixed: $file"
done < <(find app tests -type f -name '*.php' -print0)

echo ""
echo "Done. Fixed $count file(s)."
