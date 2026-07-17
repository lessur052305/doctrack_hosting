#!/usr/bin/env sh
# Railway custom start command for the REVERB (WebSocket) service.
#
# This is what makes every dashboard, the notification bell, and the
# document tracking page update live instead of falling back to the
# 45-75s poll (see resources/js/app.js's startLiveChannel/startLivePoll).
# Locally this is `docuwise-reverb.service` (see deploy/); on Railway it
# needs its own exposed service since it's a long-lived WebSocket server,
# not a request/response HTTP process like the main app.
#
# --host=0.0.0.0 so it accepts connections from Railway's edge proxy, not
# just localhost. --port uses Railway's own injected $PORT rather than the
# REVERB_SERVER_PORT default (8080) — Railway assigns which port your
# public domain actually proxies to, and it isn't always 8080.
set -e

exec php artisan reverb:start --host=0.0.0.0 --port="${PORT:-8080}"
