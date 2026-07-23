<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — DocTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-primary-950 font-sans">
<div class="min-h-full flex items-center justify-center px-4 relative overflow-hidden">
    {{-- Soft ambient glow behind the card — restrained, not a flashy hero,
         just enough depth so the dark background doesn't read as flat. --}}
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_60%_50%_at_50%_0%,theme(colors.primary.700/0.35),transparent)]"></div>

    <div class="w-full max-w-sm relative">
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-primary-400 to-primary-600 mx-auto flex items-center justify-center font-bold text-white text-2xl shadow-elevated ring-1 ring-white/20">D</div>
            <h1 class="mt-5 text-xl font-semibold text-white tracking-tight">Document Classification &amp; Tracking</h1>
            <p class="text-sm text-primary-300 mt-1.5">UJF Corporation — Internal System</p>
        </div>

        <div class="bg-white rounded-2xl shadow-elevated p-8 ring-1 ring-black/5">
            @if(session('status'))
                <div class="mb-5 rounded-xl bg-approved-50 border border-approved-500/25 text-approved-700 px-4 py-3 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            @if(session('login_retry_after'))
                <div id="login-throttle-message" data-retry-after="{{ session('login_retry_after') }}"
                    class="mb-5 rounded-xl bg-rejected-50 border border-rejected-500/25 text-rejected-700 px-4 py-3 text-sm">
                    Too many login attempts. Try again in <span id="login-throttle-seconds" class="font-semibold">{{ session('login_retry_after') }}</span> second(s).
                </div>
            @elseif(str_contains($errors->first(), 'attempt'))
                <div class="mb-5 rounded-xl bg-processing-50 border border-processing-500/25 text-processing-700 px-4 py-3 text-sm">
                    {{ $errors->first() }}
                </div>
            @elseif($errors->any())
                <div class="mb-5 rounded-xl bg-rejected-50 border border-rejected-500/25 text-rejected-700 px-4 py-3 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium text-surface-700 mb-1.5">Email</label>
                    {{-- type="text", not "email" — deliberately: browser-native
                         email validation intercepts an invalid value BEFORE the
                         form ever submits, showing its own small tooltip instead
                         of this app's error banner — easy to miss and
                         inconsistent with every other validation error here.
                         Letting the server's own 'email' rule catch it instead
                         guarantees one consistent, visible error every time. --}}
                    <input id="email" name="email" type="text" inputmode="email" required autofocus value="{{ old('email') }}"
                        class="w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm px-3.5 py-2.5">
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="password" class="block text-sm font-medium text-surface-700">Password</label>
                        <a href="{{ route('password.request') }}" class="text-xs font-medium text-primary-700 hover:underline">Forgot password?</a>
                    </div>
                    <input id="password" name="password" type="password" required
                        class="w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm px-3.5 py-2.5">
                </div>
                <button type="submit" id="login-submit"
                    class="w-full bg-gradient-to-b from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white font-medium text-sm py-2.5 rounded-lg shadow-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed">
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
