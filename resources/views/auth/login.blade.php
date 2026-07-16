<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — DocTrack</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-primary-900 font-sans">
<div class="min-h-full flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-8">
            <div class="w-12 h-12 rounded-xl bg-primary-500 mx-auto flex items-center justify-center font-bold text-white text-xl">D</div>
            <h1 class="mt-4 text-xl font-semibold text-white">Document Classification &amp; Tracking</h1>
            <p class="text-sm text-primary-300 mt-1">UJF Corporation — Internal System</p>
        </div>

        <div class="bg-white rounded-2xl shadow-card p-8">
            @if(session('login_retry_after'))
                <div id="login-throttle-message" data-retry-after="{{ session('login_retry_after') }}"
                    class="mb-4 rounded-lg bg-rejected-50 border border-rejected-500/30 text-rejected-700 px-4 py-3 text-sm">
                    Too many login attempts. Try again in <span id="login-throttle-seconds">{{ session('login_retry_after') }}</span> second(s).
                </div>
            @elseif(str_contains($errors->first(), 'attempt'))
                <div class="mb-4 rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-sm">
                    {{ $errors->first() }}
                </div>
            @elseif($errors->any())
                <div class="mb-4 rounded-lg bg-rejected-50 border border-rejected-500/30 text-rejected-700 px-4 py-3 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="username" class="block text-sm font-medium text-surface-700 mb-1">Username</label>
                    <input id="username" name="username" type="text" required autofocus value="{{ old('username') }}"
                        class="w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm px-3 py-2.5">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-surface-700 mb-1">Password</label>
                    <input id="password" name="password" type="password" required
                        class="w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm px-3 py-2.5">
                </div>
                <button type="submit" id="login-submit"
                    class="w-full bg-primary-700 hover:bg-primary-800 text-white font-medium text-sm py-2.5 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    Sign In
                </button>
            </form>
        </div>
        <p class="text-center text-xs text-primary-400 mt-6">Access is role-restricted. Contact your Administrator for an account.</p>
    </div>
</div>
<script>
    // Live-ticking countdown for the login lockout message (AuthController's
    // per-username+IP throttle). The server only sends the seconds remaining
    // as of the response — without this, that number would sit frozen and
    // visibly wrong for however long the user actually waits.
    (function () {
        const box = document.getElementById('login-throttle-message');
        if (!box) return;

        let remaining = parseInt(box.dataset.retryAfter, 10);
        const secondsEl = document.getElementById('login-throttle-seconds');
        const submitBtn = document.getElementById('login-submit');
        submitBtn.disabled = true;

        const tick = () => {
            remaining -= 1;
            if (remaining <= 0) {
                box.textContent = 'You can try again now.';
                box.classList.remove('bg-rejected-50', 'border-rejected-500/30', 'text-rejected-700');
                box.classList.add('bg-approved-50', 'border-approved-500/30', 'text-approved-700');
                submitBtn.disabled = false;
                clearInterval(timer);
                return;
            }
            secondsEl.textContent = remaining;
        };

        const timer = setInterval(tick, 1000);
    })();
</script>
</body>
</html>
