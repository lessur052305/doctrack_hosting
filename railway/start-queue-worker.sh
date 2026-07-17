#!/usr/bin/env sh
# Railway custom start command for the QUEUE WORKER service.
#
# Why this needs its own Railway service: QUEUE_CONNECTION=database means
# jobs (queued mail — DocumentAssignedMail, DocumentDecisionMail,
# SlaEscalationMail — and EscalateAssignmentJob's delayed SLA-breach
# dispatch) sit in the `jobs` table until something runs `queue:work`
# continuously. Without this process running, delayed jobs never fire and
# queued mail never sends — locally this is `docuwise-queue-worker.service`
# (see deploy/), on Railway it's this second service instead.
#
# Same repo, same build as the web service — only the start command
# differs. In the Railway dashboard: New Service → same GitHub repo →
# Settings → Deploy → Custom Start Command → point it at this script.
set -e

exec php artisan queue:work --sleep=1 --tries=3 --max-time=3600
