#!/usr/bin/env bash

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$SCRIPT_DIR"
while [ ! -f "$ROOT/artisan" ]; do
    ROOT="$(cd "$ROOT/.." && pwd)"
done
cd "$ROOT"

CONFIG="${SCRIPT_DIR}/social_media.json"
CONFIG_UPDATE="${SCRIPT_DIR}/social_media_update.json"
TITLE="${1:-Test Document}"
DEBUG=""

if [[ "$2" == "--debug" ]]; then
    DEBUG="1"
fi

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
    #strace php artisan pnc:agent:data:creation "$(cat "$CONFIG")"
    php artisan pnc:agent:data:creation "$(cat "$CONFIG")"
fi

echo "--------------------------------------------------------------------------------"
echo " Triggering Refinery Update Command over Historical Content..."
echo "--------------------------------------------------------------------------------"

#php artisan pnc:agent:data:update "$(catCONFIG_UPDATE)"

echo "================================================================================"
echo " SYSTEM INTEGRATION VALIDATION TEST RUN COMPLETE!"
echo "================================================================================"
