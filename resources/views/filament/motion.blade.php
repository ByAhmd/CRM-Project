{{--
    «روح» — the KPI count-up (A-9, amended 2026-09-21).

    The motion layer's one scripted behaviour: dashboard stat values roll from
    zero to their figure in under a second, eased like a gauge. Everything
    else about the layer is CSS in resources/css/filament/admin/theme.css.

    Rendered on every panel page through a render hook in AdminPanelProvider.
    No user-facing text lives here — the script only re-renders text the
    server already localised, so there is nothing to translate.

    Honesty rules the script follows:
    - prefers-reduced-motion stops it entirely; the true value just appears.
    - Only whole numbers animate (plain or comma-grouped). A decimal such as
      «66.7%» never animates: rounding its intermediate frames would show
      figures that were never true.
    - The animation always ends on the exact server-rendered text, so the
      final frame is the source of truth, not a reconstruction.
    - A value is animated once (data-crm-counted); Livewire re-renders that
      replace a value animate the new value, driven by a debounced observer.
--}}
<script>
    (() => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const countUp = () => {
            document.querySelectorAll('.fi-wi-stats-overview-stat-value:not([data-crm-counted])').forEach((el) => {
                el.dataset.crmCounted = '1';

                const raw = el.textContent || '';
                const match = raw.match(/\d{1,3}(?:,\d{3})+|\d+/);

                if (! match) {
                    return;
                }

                const numeral = match[0];

                // A decimal point right after the match means the figure is
                // fractional - it appears without animation.
                if (raw.slice(raw.indexOf(numeral) + numeral.length).startsWith('.')) {
                    return;
                }

                const target = parseInt(numeral.replace(/,/g, ''), 10);

                if (! target || target > 100000000) {
                    return;
                }

                const grouped = numeral.includes(',');
                const render = (n) => raw.replace(numeral, grouped ? n.toLocaleString('en-US') : String(n));
                const started = performance.now();

                const frame = (now) => {
                    const progress = Math.min(1, (now - started) / 900);
                    const eased = 1 - Math.pow(1 - progress, 3);

                    el.textContent = progress < 1 ? render(Math.round(target * eased)) : raw;

                    if (progress < 1) {
                        requestAnimationFrame(frame);
                    }
                };

                requestAnimationFrame(frame);
            });
        };

        countUp();

        let debounce;
        new MutationObserver(() => {
            clearTimeout(debounce);
            debounce = setTimeout(countUp, 150);
        }).observe(document.body, { childList: true, subtree: true });
    })();
</script>
