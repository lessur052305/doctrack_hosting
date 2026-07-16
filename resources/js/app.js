import './echo';

// Fade-in on load for a subtle, professional page-transition feel.
document.addEventListener('DOMContentLoaded', () => {
    document.body.style.opacity = 0;
    requestAnimationFrame(() => {
        document.body.style.transition = 'opacity 150ms ease';
        document.body.style.opacity = 1;
    });

    // Approver dashboard SLA countdown ticker.
    const countdownEls = document.querySelectorAll('[data-countdown]');
    if (countdownEls.length) {
        const tick = () => {
            const now = Math.floor(Date.now() / 1000);
            countdownEls.forEach((el) => {
                const target = parseInt(el.dataset.countdown, 10);
                if (!target) return;
                const diff = target - now;
                if (diff <= 0) {
                    el.textContent = 'Overdue';
                    el.classList.add('text-rejected-700');
                    return;
                }
                const h = Math.floor(diff / 3600);
                const m = Math.floor((diff % 3600) / 60);
                el.textContent = `${h}h ${m}m remaining`;
                if (diff < 3600) el.classList.add('text-rejected-700');
            });
        };
        tick();
        setInterval(tick, 30000);
    }

    // Notification bell — live unread count/list, present on every page.
    // Instant via Reverb (see startLiveChannel below); the slow poll is
    // just a safety net in case the WebSocket connection is down. Swaps
    // skip while the dropdown is currently open (the user is actively
    // reading it), preserving whatever they're looking at instead of
    // reshuffling it mid-read.
    const bell = document.getElementById('notification-bell');
    if (bell) {
        const bellOpts = {
            refreshUrl: bell.dataset.refreshUrl,
            target: bell,
            isBusy: () => bell.open,
        };
        startLiveChannel(`user.${bell.dataset.userId}`, '.notification.created', bellOpts);
        startLivePoll({ ...bellOpts, pollUrl: bell.dataset.pollUrl, minDelay: 45, maxDelay: 75 });
    }
});

/**
 * Fetches `opts.refreshUrl`, swaps the result into `opts.target`, and runs
 * `opts.onSwap`. Shared by both trigger mechanisms below — a poll cycle
 * that noticed something changed, or an instant WebSocket push — so a
 * live update behaves identically no matter which one triggered it.
 *
 * @param {Object} opts
 * @param {string} opts.refreshUrl - returns an HTML fragment to swap into `target`
 * @param {Element} opts.target - element whose innerHTML gets replaced
 * @param {Function} [opts.isBusy] - return true to skip this update (e.g. user mid-input); caller decides whether to retry
 * @param {Function} [opts.onSwap] - called after a successful swap, e.g. to re-apply a client-side filter
 * @param {boolean} [opts.preserveQueryString] - forward the current page's ?query to refreshUrl (for filtered lists)
 * @param {*} [signalData] - passed through to onSwap (e.g. the poll payload that triggered this)
 */
function applyLiveRefresh(opts, signalData) {
    if (opts.isBusy && opts.isBusy()) return;

    const url = opts.preserveQueryString ? opts.refreshUrl + window.location.search : opts.refreshUrl;

    fetch(url, { headers: { Accept: 'text/html' } })
        .then((res) => (res.ok ? res.text() : Promise.reject(res)))
        .then((html) => {
            opts.target.innerHTML = html;
            if (opts.onSwap) opts.onSwap(signalData);
        })
        .catch(() => {});
}

/**
 * Real-time, primary update mechanism: subscribes to a private Reverb
 * channel and applies a live refresh the instant the given event fires —
 * no polling delay. Used for everything that can change from another
 * user's action (a new document routed to an approver, a status change,
 * a new notification) so it shows up genuinely live, not on the next
 * check.
 *
 * @param {string} channelName - without the leading "private-" (Echo adds it)
 * @param {string} eventName - e.g. '.document.status-changed' (leading dot = no namespace prefix)
 * @param {Object} opts - same shape as applyLiveRefresh's opts
 * @param {Function} [opts.filter] - (eventData) => bool; skip the refresh entirely
 *   if this returns false. For a channel that carries events for more than
 *   one thing (e.g. an originator's channel covers ALL of their documents),
 *   this is how a single-document page ignores events about other documents.
 */
function startLiveChannel(channelName, eventName, opts) {
    if (!window.Echo) return;
    window.Echo.private(channelName).listen(eventName, (data) => {
        if (opts.filter && !opts.filter(data)) return;
        applyLiveRefresh(opts, data);
    });
}
window.startLiveChannel = startLiveChannel;

/**
 * Fallback safety net, not the primary mechanism — mirrors this app's own
 * SLA architecture (EscalateAssignmentJob fires instantly via the queue,
 * with a slow periodic sweep behind it in case that ever misses). If the
 * WebSocket connection drops, this still eventually catches up instead of
 * silently going stale forever.
 *
 * @param {Object} opts - same shape as applyLiveRefresh's opts, plus:
 * @param {string} opts.pollUrl - returns JSON to compare against the last-seen value
 * @param {number} [opts.minDelay] - seconds, default 45
 * @param {number} [opts.maxDelay] - seconds, default 75
 */
function startLivePoll(opts) {
    let lastSignal = null; // null = "not established yet", first poll just primes it, never swaps
    const minDelay = opts.minDelay ?? 45;
    const maxDelay = opts.maxDelay ?? 75;

    const scheduleNext = () => setTimeout(poll, (minDelay + Math.random() * (maxDelay - minDelay)) * 1000);

    const poll = () => {
        fetch(opts.pollUrl, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => {
                const signal = JSON.stringify(data);
                if (lastSignal === null) {
                    lastSignal = signal; // establish baseline on first successful poll, don't swap
                    return;
                }
                if (signal === lastSignal) return;
                if (opts.isBusy && opts.isBusy()) return; // leave lastSignal stale so we retry next cycle

                applyLiveRefresh(opts, data);
                lastSignal = signal;
            })
            .catch(() => {})
            .finally(scheduleNext);
    };

    scheduleNext();
}
window.startLivePoll = startLivePoll;
