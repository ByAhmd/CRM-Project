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

        // The observer exists for content that arrives during the entrance
        // window (lazy widgets); it retires with the choreography.
        let debounce;
        const observer = new MutationObserver(() => {
            clearTimeout(debounce);
            debounce = setTimeout(countUp, 150);
        });
        observer.observe(document.body, { childList: true, subtree: true });

        // Entrances play on ARRIVAL only. Once the first choreography has
        // finished (longest chain: the auth card at 0.18s + 0.6s; rows at
        // 0.3s + 0.4s), the page is settled: the crm-settled class switches
        // every entrance rule off (theme.css scopes them to
        // html:not(.crm-settled)) and the count-up observer disconnects, so
        // a Livewire search, sort or refresh renders its data INSTANTLY —
        // replaying the entrance on updates is how motion briefly made a
        // fast system feel slow.
        window.setTimeout(() => {
            document.documentElement.classList.add('crm-settled');
            observer.disconnect();
            clearTimeout(debounce);
        }, 900);
    })();
</script>
