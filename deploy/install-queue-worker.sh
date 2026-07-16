#!/usr/bin/env bash
# Installs the persistent queue worker as a systemd --user service.
#
# Why this exists: EscalateAssignmentJob is dispatched with a delay set to
# each assignment's sla_expires_at (see WorkflowService::assignStage()) —
# that's what makes SLA breach detection instant instead of poll-based. But
# a delayed job only ever fires if *something* is running `queue:work`
# continuously. Without this service, breach detection silently falls back
# to the 5-minute safety-net sweep in bootstrap/app.php (workflow:check-
# parallel-slas), which still works but is no longer "real-time."
#
# No root required — systemd --user units live entirely under the
# invoking user's own session.
set -euo pipefail

PROJECT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BINARY="$(command -v php)"
UNIT_DIR="$HOME/.config/systemd/user"
UNIT_NAME="docuwise-queue-worker.service"

if [ -z "$PHP_BINARY" ]; then
    echo "Could not find 'php' on PATH — install PHP first." >&2
    exit 1
fi

mkdir -p "$UNIT_DIR"

sed \
    -e "s|__PROJECT_PATH__|$PROJECT_PATH|g" \
    -e "s|__PHP_BINARY__|$PHP_BINARY|g" \
    "$PROJECT_PATH/deploy/docuwise-queue-worker.service.template" \
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
