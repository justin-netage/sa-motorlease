/**
 * SA Motorlease — Custom Vehicle Filter (frontend)
 *
 * Instant AJAX filtering over the pa_* attribute facets, dependent Make→Model,
 * a dual monthly-price slider, sorting, "load more" pagination, shareable URL
 * state and a mobile filter toggle. No jQuery / no external deps.
 */
(function () {
    'use strict';

    if (typeof window.SA_VF === 'undefined') return;
    var CFG = window.SA_VF;

    var root = document.querySelector('.sa-vf');
    if (!root) return;

    var form     = root.querySelector('.sa-vf-form');
    var grid     = root.querySelector('.sa-vf__grid');
    var count    = root.querySelector('.sa-vf__count');
    var sortSel  = root.querySelector('.sa-vf__sort');
    var pager    = root.querySelector('.sa-vf__pager');
    var loading  = root.querySelector('.sa-vf__loading');
    var modelSel = form.querySelector('[data-facet="model"]');
    var makeSel  = form.querySelector('[data-facet="make"]');

    var state = { page: 1, busy: false };

    /* ---------------------------------------------------------------- utils */

    function fmtR(n) {
        return Number(n).toLocaleString('en-ZA');
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var ctx = this, a = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, a); }, ms);
        };
    }

    /** Collect the current filter values into a flat object. */
    function collect() {
        var data = {};
        form.querySelectorAll('.sa-vf-select').forEach(function (s) {
            if (s.hasAttribute('data-region-nav')) return; // navigator, not a filter
            if (s.name && s.value) data[s.name] = s.value;
        });
        var pmin = form.querySelector('[name="price_min"]');
        var pmax = form.querySelector('[name="price_max"]');
        if (pmin && pmin.value) data.price_min = pmin.value;
        if (pmax && pmax.value) data.price_max = pmax.value;
        var hs = form.querySelector('[name="hide_sold"]');
        if (hs && hs.checked) data.hide_sold = '1';
        if (sortSel && sortSel.value) data.sort = sortSel.value;
        return data;
    }

    /** Reflect current filters into the URL (shareable, back-button friendly). */
    function syncUrl(data) {
        var qs = new URLSearchParams();
        Object.keys(data).forEach(function (k) {
            // Skip defaults to keep the URL tidy.
            if (k === 'sort' && data[k] === 'featured') return;
            if (k === 'price_min' && Number(data[k]) <= CFG.price.min) return;
            if (k === 'price_max' && Number(data[k]) >= CFG.price.max) return;
            qs.set(k, data[k]);
        });
        var url = window.location.pathname + (qs.toString() ? '?' + qs.toString() : '');
        window.history.replaceState(null, '', url);
    }

    /* --------------------------------------------------------------- request */

    var results = root.querySelector('.sa-vf__results');

    function request(scrollAfter) {
        if (state.busy) return;
        state.busy = true;
        if (loading) loading.hidden = false;
        if (results) results.classList.add('is-loading');

        var data = collect();
        syncUrl(data);
        data.page = state.page;
        data.action = 'sa_vf_query';
        data.nonce = CFG.nonce;
        // Location scope is fixed for the page — send it, but keep it out of the URL.
        if (CFG.category) data.category = CFG.category;

        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.set(k, data[k]); });

        fetch(CFG.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) throw new Error('bad response');
            var d = res.data;
            grid.innerHTML = d.html;
            if (count) count.textContent = d.showing;
            state.page = d.page;
            renderPager(d.page, d.pages);
            applyAvailability(d.available);
            if (scrollAfter) scrollToResults();
        })
        .catch(function () {
            grid.innerHTML = '<div class="sa-vf-empty">Something went wrong loading vehicles. Please try again.</div>';
        })
        .finally(function () {
            state.busy = false;
            if (loading) loading.hidden = true;
            if (results) results.classList.remove('is-loading');
        });
    }

    /* Which page numbers to show: first, last, current ±1, with gaps. */
    function pageList(page, pages) {
        var out = [], last = 0;
        for (var i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || (i >= page - 1 && i <= page + 1)) {
                if (last && i - last > 1) out.push('…');
                out.push(i);
                last = i;
            }
        }
        return out;
    }

    function renderPager(page, pages) {
        pager.setAttribute('data-page', page);
        pager.setAttribute('data-pages', pages);
        pager.innerHTML = '';
        if (pages <= 1) return;

        var nav = document.createElement('div');
        nav.className = 'sa-vf-pagination';

        nav.appendChild(pageBtn('‹', page - 1, page === 1, false, 'Previous page'));
        pageList(page, pages).forEach(function (item) {
            if (item === '…') {
                var gap = document.createElement('span');
                gap.className = 'sa-vf-page sa-vf-page--gap';
                gap.textContent = '…';
                nav.appendChild(gap);
            } else {
                nav.appendChild(pageBtn(item, item, false, item === page, 'Page ' + item));
            }
        });
        nav.appendChild(pageBtn('›', page + 1, page === pages, false, 'Next page'));

        pager.appendChild(nav);
    }

    function pageBtn(label, target, disabled, current, aria) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'sa-vf-page' + (current ? ' is-current' : '');
        b.textContent = label;
        b.setAttribute('aria-label', aria);
        if (current) b.setAttribute('aria-current', 'page');
        if (disabled) {
            b.disabled = true;
        } else if (!current) {
            b.addEventListener('click', function () { goToPage(target); });
        }
        return b;
    }

    function goToPage(n) {
        state.page = Math.max(1, n);
        request(true); // scroll after loading
    }

    function scrollToResults() {
        var results = root.querySelector('.sa-vf__results');
        if (!results) return;
        // Clear the site's sticky header (read the CSS var, fall back to 96px).
        var offset = 96;
        var raw = getComputedStyle(root).getPropertyValue('--sa-vf-sticky-top');
        if (raw) {
            var n = parseInt(raw, 10);
            if (!isNaN(n)) offset = n + 16;
        }
        var top = results.getBoundingClientRect().top + window.pageYOffset - offset;
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    }

    /* Re-run from page 1 (any filter change). No scroll — user is at the top. */
    var applyFilters = debounce(function () {
        state.page = 1;
        request(false);
    }, 250);

    /* ------------------------------------------------- available options */

    /**
     * Hide the options in each facet dropdown that can't produce a result given
     * the other active filters. `map` is { facetKey: [allowedValues...] } from
     * the server. The currently-selected value is always kept visible.
     */
    function applyAvailability(map) {
        if (!map) return;
        form.querySelectorAll('.sa-vf-select[data-facet]').forEach(function (sel) {
            var key = sel.getAttribute('data-facet');
            var allowed = map[key];
            if (!allowed) return; // no data for this facet → leave untouched
            var set = {};
            allowed.forEach(function (v) { set[v] = 1; });
            Array.prototype.forEach.call(sel.options, function (opt) {
                if (opt.value === '') return; // keep the placeholder
                var ok = !!set[opt.value] || opt.value === sel.value;
                opt.hidden = !ok;
                opt.disabled = !ok;
            });
        });
    }

    /* --------------------------------------------------------- price slider */

    function initRange() {
        var wrap = root.querySelector('.sa-vf-range');
        if (!wrap) return;
        var minInput = wrap.querySelector('.sa-vf-range__min');
        var maxInput = wrap.querySelector('.sa-vf-range__max');
        var fill     = wrap.querySelector('.sa-vf-range__fill');
        var minVal   = root.querySelector('.sa-vf-range__minval');
        var maxVal   = root.querySelector('.sa-vf-range__maxval');
        var hidMin   = form.querySelector('[name="price_min"]');
        var hidMax   = form.querySelector('[name="price_max"]');
        var lo = Number(wrap.getAttribute('data-min'));
        var hi = Number(wrap.getAttribute('data-max'));
        var span = Math.max(1, hi - lo);

        function paint() {
            var a = Number(minInput.value);
            var b = Number(maxInput.value);
            if (a > b - 1) { // keep a gap so handles don't cross
                if (this === maxInput) { a = b; minInput.value = a; }
                else { b = a; maxInput.value = b; }
            }
            var left  = ((a - lo) / span) * 100;
            var right = ((b - lo) / span) * 100;
            fill.style.left  = left + '%';
            fill.style.width = (right - left) + '%';
            minVal.textContent = fmtR(a);
            maxVal.textContent = fmtR(b);
            hidMin.value = a;
            hidMax.value = b;
        }

        minInput.addEventListener('input', paint);
        maxInput.addEventListener('input', paint);
        minInput.addEventListener('change', applyFilters);
        maxInput.addEventListener('change', applyFilters);
        paint();
    }

    /* --------------------------------------------------------------- events */

    form.querySelectorAll('.sa-vf-select').forEach(function (s) {
        if (s.hasAttribute('data-region-nav')) {
            // Category page: the Region dropdown navigates to another location
            // archive instead of filtering in place.
            s.addEventListener('change', function () {
                if (s.value) window.location.href = s.value;
            });
            return;
        }
        s.addEventListener('change', applyFilters);
    });

    var hideSold = form.querySelector('[name="hide_sold"]');
    if (hideSold) hideSold.addEventListener('change', applyFilters);
    if (sortSel) sortSel.addEventListener('change', applyFilters);

    var filterBtn = form.querySelector('.sa-vf-btn--filter');
    if (filterBtn) filterBtn.addEventListener('click', function () {
        state.page = 1;
        request(false);
    });

    var clearBtn = form.querySelector('.sa-vf-btn--clear');
    if (clearBtn) clearBtn.addEventListener('click', function () {
        form.querySelectorAll('.sa-vf-select').forEach(function (s) {
            if (s.hasAttribute('data-region-nav')) return; // keep current location
            s.value = '';
        });
        if (hideSold) hideSold.checked = false;
        if (sortSel) sortSel.value = 'featured';
        // reset price handles
        var minInput = root.querySelector('.sa-vf-range__min');
        var maxInput = root.querySelector('.sa-vf-range__max');
        if (minInput && maxInput) {
            minInput.value = CFG.price.min;
            maxInput.value = CFG.price.max;
            minInput.dispatchEvent(new Event('input'));
        }
        state.page = 1;
        request(false);
    });

    var toggle = root.querySelector('.sa-vf-toggle');
    var sidebar = root.querySelector('.sa-vf__sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            var open = sidebar.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    /* ----------------------------------------------------------------- boot */
    applyAvailability(CFG.available);
    initRange();
    // Render numbered pagination from the server-seeded state.
    renderPager(
        parseInt(pager.getAttribute('data-page'), 10) || 1,
        parseInt(pager.getAttribute('data-pages'), 10) || 1
    );
})();


/* ===========================================================================
 * Featured listings slider — draggable + prev/next arrows.
 * Independent of the filter above so it also works on featured-only pages.
 * ======================================================================== */
(function () {
    'use strict';

    function initFeatured(el) {
        var track = el.querySelector('.sa-vf-featured__track');
        if (!track || track.dataset.saInit) return;
        track.dataset.saInit = '1';

        var prev = el.querySelector('.sa-vf-featured__nav--prev');
        var next = el.querySelector('.sa-vf-featured__nav--next');

        function page() {
            // Scroll by roughly one viewport-width of cards.
            return Math.max(240, Math.round(track.clientWidth * 0.9));
        }

        function update() {
            var max = track.scrollWidth - track.clientWidth - 2;
            var hasOverflow = max > 0;
            if (prev) prev.disabled = !hasOverflow || track.scrollLeft <= 0;
            if (next) next.disabled = !hasOverflow || track.scrollLeft >= max;
        }

        if (prev) prev.addEventListener('click', function () {
            track.scrollBy({ left: -page(), behavior: 'smooth' });
        });
        if (next) next.addEventListener('click', function () {
            track.scrollBy({ left: page(), behavior: 'smooth' });
        });
        track.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);

        // Pointer drag-to-scroll.
        var down = false, startX = 0, startScroll = 0, moved = false;
        track.addEventListener('pointerdown', function (e) {
            if (e.button !== undefined && e.button !== 0) return;
            down = true; moved = false;
            startX = e.clientX; startScroll = track.scrollLeft;
        });
        track.addEventListener('pointermove', function (e) {
            if (!down) return;
            var dx = e.clientX - startX;
            if (Math.abs(dx) > 4) {
                if (!moved) track.classList.add('is-dragging');
                moved = true;
            }
            track.scrollLeft = startScroll - dx;
        });
        function end() {
            if (!down) return;
            down = false;
            // Drop the dragging class next frame so the click-suppressor can read `moved`.
            setTimeout(function () { track.classList.remove('is-dragging'); }, 0);
        }
        track.addEventListener('pointerup', end);
        track.addEventListener('pointercancel', end);
        track.addEventListener('pointerleave', end);

        // Suppress the card link click that would fire at the end of a drag.
        track.addEventListener('click', function (e) {
            if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; }
        }, true);

        update();
    }

    function boot() {
        var list = document.querySelectorAll('.sa-vf-featured');
        for (var i = 0; i < list.length; i++) initFeatured(list[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
