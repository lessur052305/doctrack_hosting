{{--
    A connector between tiles that reads as a real pipeline arrow (line +
    solid arrowhead, the same technique flow-automation tools like
    n8n/Zapier use), not a unicode character — text-surface-500 for
    contrast that actually reads against a white/surface-50 card, unlike
    the earlier text-surface-300 attempt. Two SVGs, toggled by breakpoint:
    a downward arrow while tiles stack in a single column, a rightward one
    once they sit in a row — same breakpoint the tile row itself wraps at.
--}}
<div class="flex sm:flex-col items-center justify-center text-surface-500 shrink-0" aria-hidden="true">
    <svg class="sm:hidden" width="16" height="28" viewBox="0 0 16 28" fill="none" xmlns="http://www.w3.org/2000/svg">
        <line x1="8" y1="1" x2="8" y2="19" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
        <polygon points="2,17 8,27 14,17" fill="currentColor" />
    </svg>
    <svg class="hidden sm:block" width="28" height="16" viewBox="0 0 28 16" fill="none" xmlns="http://www.w3.org/2000/svg">
        <line x1="1" y1="8" x2="19" y2="8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
        <polygon points="17,2 27,8 17,14" fill="currentColor" />
    </svg>
</div>
