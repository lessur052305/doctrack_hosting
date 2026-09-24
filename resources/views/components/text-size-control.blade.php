{{--
    Per-device text size control (Feature: readability on small/laptop
    screens). Three fixed steps — Normal/Large/Larger — applied via a
    data-text-size attribute on <html> (see resources/css/app.css) and
    remembered in this browser's own localStorage, not the user's
    account: there's no reliable way to detect that a screen is
    physically small, so this is a manual per-device choice instead of
    something the system infers automatically. The default (already
    scaled up from the browser's own 16px baseline — see app.css) applies
    everywhere with no action needed; this menu is only for pushing it
    further on a particularly cramped screen.

    Click-handling and localStorage persistence live in app.js — this
    just renders the trigger + menu. data-popover already gets click-
    outside-to-close for free (see app.js's existing delegated listener
    for every [data-popover] <details>, shared with the notification
    bell and other header popovers).
--}}
<details class="relative" data-popover id="text-size-control">
    <summary class="list-none cursor-pointer inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-surface-100 transition-colors text-surface-500 font-semibold text-sm" aria-label="Text size" title="Text size">
        Aa
    </summary>
    <div class="absolute right-0 mt-2 w-44 bg-white rounded-xl shadow-elevated border border-surface-200/80 z-30 py-1.5">
        <p class="px-3.5 py-1.5 text-xs font-semibold text-surface-400 uppercase tracking-wide">Text Size</p>
        <button type="button" data-text-size-option="normal" class="w-full text-left px-3.5 py-2 text-sm text-surface-700 hover:bg-surface-50 flex items-center justify-between">
            Normal
            <svg data-text-size-check class="hidden w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </button>
        <button type="button" data-text-size-option="large" class="w-full text-left px-3.5 py-2 text-sm text-surface-700 hover:bg-surface-50 flex items-center justify-between">
            Large
            <svg data-text-size-check class="hidden w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </button>
        <button type="button" data-text-size-option="larger" class="w-full text-left px-3.5 py-2 text-sm text-surface-700 hover:bg-surface-50 flex items-center justify-between">
            Larger
            <svg data-text-size-check class="hidden w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </button>
    </div>
</details>
