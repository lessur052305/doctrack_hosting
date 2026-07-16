// Reverb speaks the Pusher protocol, so Echo is configured with the
// 'reverb' broadcaster (a thin preset over the same client) rather than a
// third-party Pusher account — this is Laravel's own first-party WebSocket
// server, no external SaaS dependency.
import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Private channels (every channel in routes/channels.php is private) POST
// to /broadcasting/auth to prove the visitor is who they claim before
// Reverb allows the subscription. That route sits behind Laravel's normal
// CSRF protection like any other POST, so the request needs the token —
// without this `auth.headers` block, the browser's subscription silently
// fails 419 and no live update (dashboards OR the notification bell) ever
// arrives, since there's no `window.axios` configured anywhere in this
// app to inject the header automatically the way a stock Laravel
// bootstrap.js would.
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

// wsHost deliberately does NOT come from VITE_REVERB_HOST. That value gets
// compiled into the JS bundle once, at build time — so "localhost" means
// something different depending on which device loads the page. On the
// same machine the server runs on, "localhost" correctly means itself; on
// a phone or any other device on the network, "localhost" in ITS browser
// means the phone, not the server, so the WebSocket connection silently
// fails and everything falls back to the slow poll instead. Using
// window.location.hostname instead means it always resolves to whatever
// address the browser actually used to reach this page — 127.0.0.1,
// localhost, or a LAN IP like 192.168.1.9 — which is always correct
// regardless of device. The Reverb server itself already listens on
// 0.0.0.0 (every interface, see deploy/docuwise-reverb.service.template),
// so it's reachable at all of those addresses already; only the client
// was hardcoded to just one of them.
const wsHost = window.location.hostname;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    auth: {
        headers: {
            'X-CSRF-TOKEN': csrfToken,
        },
    },
});
