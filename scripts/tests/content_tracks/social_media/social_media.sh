#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$SCRIPT_DIR"

# Walk up to project root (guard against infinite loop)
while [ ! -f "$ROOT/artisan" ] && [ "$ROOT" != "/" ]; do
    ROOT="$(cd "$ROOT/.." && pwd)"
done
[ -f "$ROOT/artisan" ] || { echo "Error: could not find artisan"; exit 1; }
cd "$ROOT"

CONFIG="${SCRIPT_DIR}/social_media.json"
CONFIG_UPDATE="${SCRIPT_DIR}/social_media_update.json"

TITLE=""
DEBUG=""
for arg in "$@"; do
    case "$arg" in
        --debug) DEBUG="on" ;;
        *)       TITLE="$arg" ;;
    esac
done
TITLE="${TITLE:-Test Document}"

echo "========================================"
echo "  Agent Data Creation"
echo "========================================"
echo " Config:  $CONFIG"
echo " Title:   $TITLE"
echo " Debug:   ${DEBUG:-off}"
echo "========================================"
echo ""
# ─── Run ───
if [ -n "$DEBUG" ]; then
    php -dxdebug.mode=debug \
        -dxdebug.start_with_request=yes \
        -dxdebug.client_host=127.0.0.1 \
        -dxdebug.client_port=9003 \
        artisan pnc:agent:data:creation "$(cat "$CONFIG")"
else
    php artisan pnc:agent:data:creation "$(cat "$CONFIG")"
fi

echo "--------------------------------------------------------------------------------"
echo " Triggering Refinery Update Command over Historical Content..."
echo "--------------------------------------------------------------------------------"

# php artisan pnc:agent:data:update "$(cat "$CONFIG_UPDATE")"

echo "================================================================================"
echo " SYSTEM INTEGRATION VALIDATION TEST RUN COMPLETE!"
echo "================================================================================"
