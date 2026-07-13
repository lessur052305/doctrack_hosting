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
});
