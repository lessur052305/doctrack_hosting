import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import os from 'os';

/**
 * Auto-detects this machine's LAN IPv4 address (skipping loopback/internal
 * interfaces) so `server.origin`/`hmr.host` below don't need a hardcoded IP
 * that breaks the moment DHCP hands out a different one. Falls back to
 * 'localhost' if nothing is found (e.g. no network connection at all).
 */
function lanIp() {
    const nets = os.networkInterfaces();
    for (const name of Object.keys(nets)) {
        for (const net of nets[name]) {
            if (net.family === 'IPv4' && !net.internal) {
                return net.address;
            }
        }
    }
    return 'localhost';
}

const host = lanIp();

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        // 0.0.0.0, not just localhost — otherwise the dev server refuses
        // connections from any other device on the network entirely.
        host: '0.0.0.0',
        port: 5173,
        // Without strictPort, a leftover process still holding 5173 makes
        // Vite silently move to 5174/5175/... — which is exactly what
        // caused the original bug (public/hot pointed at a port nothing
        // else knew about). Fail loudly instead of drifting.
        strictPort: true,
        // The actual fix: without this, Laravel's @vite() directive writes
        // asset URLs pointing at 127.0.0.1, which only resolves to "this
        // same machine" — meaningless to any other device on the network.
        origin: `http://${host}:5173`,
        hmr: {
            host,
        },
        // Every page now requests its JS/CSS from one fixed origin
        // (http://<lan-ip>:5173, set above) regardless of which address the
        // PAGE itself was loaded from — so viewing the app via 127.0.0.1
        // makes the browser treat that asset request as cross-origin, and
        // <script type="module"> refuses to run anything without a valid
        // CORS response. `cors: true` makes Vite reflect back whatever
        // Origin the browser actually sent instead of only allowing the
        // one origin above — safe here since this is a local dev server,
        // not something exposed to the internet.
        cors: true,
    },
});
