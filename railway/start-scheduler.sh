#!/usr/bin/env sh
# Railway custom start command for the SCHEDULER service.
#
# Runs the two SLA sweeps registered in bootstrap/app.php's withSchedule()
# (workflow:check-parallel-slas and sla:check, both every 5 minutes) plus
# the daily backup — these are the safety nets that escalate a breached
# assignment to Admin and auto-approve after the 12-hour grace window, even
# if nothing else ever triggers them. Without a scheduler running
# somewhere, SLA escalation silently never happens.
#
# `schedule:work` is Laravel's own long-running loop (sleeps, wakes once a
# minute, runs whatever's due) — no external cron needed, which matters on
# Railway since there's no system crontab to hook into the way a VPS would
# have.
set -e

php artisan config:clear

exec php artisan schedule:work
