#!/usr/bin/env bash
# Installs the Reverb WebSocket server as a systemd --user service.
#
# Why this exists: real-time dashboard/notification updates (approver
# queue, originator submissions, admin overview, the notification bell —
# see resources/js/app.js's startLiveChannel()) push over a WebSocket
# connection to this server. Without it running continuously, the
# frontend silently falls back to the slow (45-75s) poll in the same file
# — still correct, just no longer instant.
#
# No root required — systemd --user units live entirely under the
# invoking user's own session.
set -euo pipefail

PROJECT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BINARY="$(command -v php)"
UNIT_DIR="$HOME/.config/systemd/user"
UNIT_NAME="docuwise-reverb.service"

if [ -z "$PHP_BINARY" ]; then
    echo "Could not find 'php' on PATH — install PHP first." >&2
    exit 1
fi

if ! grep -q "^BROADCAST_CONNECTION=reverb" "$PROJECT_PATH/.env" 2>/dev/null; then
    echo "Warning: .env doesn't have BROADCAST_CONNECTION=reverb set — live" >&2
    echo "updates won't actually use this server until that's added." >&2
fi

mkdir -p "$UNIT_DIR"

sed \
    -e "s|__PROJECT_PATH__|$PROJECT_PATH|g" \
    -e "s|__PHP_BINARY__|$PHP_BINARY|g" \
    "$PROJECT_PATH/deploy/docuwise-reverb.service.template" \
    > "$UNIT_DIR/$UNIT_NAME"

systemctl --user daemon-reload
systemctl --user enable --now "$UNIT_NAME"

echo ""
echo "Installed and started: $UNIT_NAME"
systemctl --user status "$UNIT_NAME" --no-pager || true

echo ""
echo "IMPORTANT: by default this service only runs while you are logged in."
echo "To keep it running after logout / across reboots (recommended for a"
echo "demo or production server), enable lingering for this user (needs sudo, one-time):"
echo ""
echo "    sudo loginctl enable-linger $USER"
echo ""
echo "Verify later with:  systemctl --user status $UNIT_NAME"
