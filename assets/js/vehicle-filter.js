/**
 * SA Motorlease — Custom Vehicle Filter (frontend)
 *
 * Client-side filtering: the whole catalogue is shipped once in window.SA_VF
 * (each vehicle carries its filter fields + pre-rendered card HTML), so every
 * filter/sort/page change is computed in the browser with NO server round-trip.
 * On a site whose WordPress bootstrap is slow, this is the difference between a
 * multi-second admin-ajax request per change and an instant one. The first page
 * is still server-rendered for SEO / no-JS; this takes over on load.
 * Facets, monthly-payment + mileage buckets, sorting, numbered pagination,
 * shareable URL state, a floating mobile button. No jQuery / no external deps.
 */
(function () {
    'use strict';

    if (typeof window.SA_VF === 'undefined') return;
    var CFG = window.SA_VF;

    var root = document.querySelector('.sa-vf');
    if (!root) return;

    var form    = root.querySelector('.sa-vf-form');
    var grid    = root.querySelector('.sa-vf__grid');
    var count   = root.querySelector('.sa-vf__count');
    var sortSel = root.querySelector('.sa-vf__sort');
    var pager   = root.querySelector('.sa-vf__pager');

    var DATA = Array.isArray(CFG.vehicles) ? CFG.vehicles : [];
    var PER  = Number(CFG.perPage) || 15;
    var CAT  = Number(CFG.category) || 0;
    var PB   = Array.isArray(CFG.priceBuckets) ? CFG.priceBuckets : [];
    var KB   = Array.isArray(CFG.kmBuckets) ? CFG.kmBuckets : [];

    // Catalogue order (as shipped) is the "Featured" fallback order.
    DATA.forEach(function (v, i) { v._i = i; });

    var state = { page: 1 };

    /* ---------------------------------------------------------- filter engine */

    function bucketByKey(list, key) {
        if (!key) return null;
        for (var i = 0; i < list.length; i++) if (list[i].key === key) return list[i];
        return null;
    }
    // Half-open [min, max); a null value (no km/price) is never in a bucket.
    function inBucket(val, b) {
        if (val == null) return false;
        var max = (b.max == null) ? Infinity : b.max;
        return val >= b.min && val < max;
    }
    function cloneArgs(a) {
        return { facets: Object.assign({}, a.facets), price: a.price, km: a.km, hideSold: a.hideSold, sort: a.sort };
    }
    function withArg(a, k, v) { var c = cloneArgs(a); c[k] = v; return c; }

    /** Read the current filter state from the form controls. */
    function collect() {
        var a = { facets: {}, price: '', km: '', hideSold: true, sort: 'featured' };
        form.querySelectorAll('.sa-vf-select[data-facet]').forEach(function (s) {
            var k = s.getAttribute('data-facet');
            if (k === 'price') { a.price = s.value; return; }
            if (k === 'km')    { a.km = s.value; return; }
            if (s.value) a.facets[k] = s.value;
        });
        // "Available Only" checked (default) hides sold; unchecking shows them.
        a.hideSold = availToggle ? !!availToggle.checked : true;
        if (sortSel && sortSel.value) a.sort = sortSel.value;
        return a;
    }

    /** Vehicles matching the args (facets ANDed, buckets half-open, sold + scope). */
    function filterData(a) {
        var pb = bucketByKey(PB, a.price), kb = bucketByKey(KB, a.km);
        var fk = Object.keys(a.facets);
        return DATA.filter(function (v) {
            if (a.hideSold && v.sold) return false;
            if (CAT && v.c.indexOf(CAT) === -1) return false;
            if (pb && !inBucket(v.price, pb)) return false;
            if (kb && !inBucket(v.km, kb)) return false;
            for (var i = 0; i < fk.length; i++) {
                var slug = a.facets[fk[i]];
                if (!slug) continue;
                var have = (v.f && v.f[fk[i]]) || [];
                if (have.indexOf(slug) === -1) return false;
            }
            return true;
        });
    }

    /** Sort a matched set: sold always last, then by the chosen key. */
    function sortData(arr, sort) {
        return arr.slice().sort(function (A, B) {
            var sa = A.sold ? 1 : 0, sb = B.sold ? 1 : 0;
            if (sa !== sb) return sa - sb;
            switch (sort) {
                case 'price_asc':  return (A.price || 0) - (B.price || 0);
                case 'price_desc': return (B.price || 0) - (A.price || 0);
                case 'year_desc':  return (B.year || 0) - (A.year || 0);
                case 'year_asc':   return (A.year || 0) - (B.year || 0);
                case 'km_asc':     return (A.km || 0) - (B.km || 0);
                case 'newest':     return B.id - A.id;
                default:           return A._i - B._i; // featured = catalogue order
            }
        });
    }

    function bucketsPresent(rows, buckets, field) {
        var set = {};
        rows.forEach(function (v) {
            var val = v[field];
            if (val == null) return;
            for (var i = 0; i < buckets.length; i++) {
                if (inBucket(val, buckets[i])) { set[buckets[i].key] = 1; break; }
            }
        });
        return Object.keys(set);
    }

    /**
     * For each facet/bucket dropdown, which values can still produce a result
     * given the OTHER active filters (its own selection excluded so it can be
     * switched). Mirrors the old server availability map.
     */
    function computeAvail(a) {
        var full = filterData(a), av = {};
        form.querySelectorAll('.sa-vf-select[data-facet]').forEach(function (sel) {
            var k = sel.getAttribute('data-facet'), rows;
            if (k === 'km') {
                rows = a.km ? filterData(withArg(a, 'km', '')) : full;
                av.km = bucketsPresent(rows, KB, 'km');
                return;
            }
            if (k === 'price') {
                rows = a.price ? filterData(withArg(a, 'price', '')) : full;
                av.price = bucketsPresent(rows, PB, 'price');
                return;
            }
            if (a.facets[k]) {
                var a2 = cloneArgs(a); delete a2.facets[k]; rows = filterData(a2);
            } else {
                rows = full;
            }
            var set = {};
            rows.forEach(function (v) { ((v.f && v.f[k]) || []).forEach(function (s) { set[s] = 1; }); });
            av[k] = Object.keys(set);
        });
        return av;
    }

    /** Reflect current filters into the URL (shareable, back-button friendly). */
    function syncUrl(a) {
        var qs = new URLSearchParams();
        Object.keys(a.facets).forEach(function (k) { if (a.facets[k]) qs.set(k, a.facets[k]); });
        if (a.price) qs.set('price', a.price);
        if (a.km) qs.set('km', a.km);
        if (!a.hideSold) qs.set('show_sold', '1');
        if (a.sort && a.sort !== 'featured') qs.set('sort', a.sort);
        var url = window.location.pathname + (qs.toString() ? '?' + qs.toString() : '');
        window.history.replaceState(null, '', url);
    }

    /* --------------------------------------------------------------- render */

    /** The whole render pass — instant, no network. */
    function render(scrollAfter) {
        var a = collect();
        syncUrl(a);

        var arr   = sortData(filterData(a), a.sort);
        var total = arr.length;
        var pages = Math.max(1, Math.ceil(total / PER));
        state.page = Math.min(Math.max(1, state.page), pages);

        var off   = (state.page - 1) * PER;
        var slice = arr.slice(off, off + PER);

        grid.innerHTML = slice.length
            ? slice.map(function (v) { return v.h; }).join('')
            : '<div class="sa-vf-empty">No vehicles match your filters. Try widening your search.</div>';

        if (count) {
            count.textContent = total
                ? ('Showing ' + (off + 1) + '–' + Math.min(off + PER, total) + ' of ' + total + ' results')
                : 'No results';
        }

        renderPager(state.page, pages);
        applyAvailability(computeAvail(a));
        setApplyCount(total);
        updateActiveCount();
        if (scrollAfter) scrollToResults();
    }

    /* Any filter change re-runs from page 1 (user is at the top; no scroll). */
    function rerun() { state.page = 1; render(false); }

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
        render(true); // scroll to results after paging
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

    /* ------------------------------------------------- available options */

    /**
     * Hide the options in each facet dropdown that can't produce a result given
     * the other active filters. `map` is { facetKey: [allowedValues...] },
     * computed client-side. The currently-selected value is always kept visible.
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

    /* --------------------------------------------- active filter count badge */

    var toggleCount = root.querySelector('.sa-vf-toggle__count');
    var applyBtn    = root.querySelector('.sa-vf__drawer-apply');

    function updateActiveCount() {
        var n = 0;
        form.querySelectorAll('.sa-vf-select[data-facet]').forEach(function (s) {
            if (s.value) n++;
        });
        // Showing sold vehicles is a deviation from the default, so it counts.
        if (availToggle && !availToggle.checked) n++;
        if (toggleCount) {
            toggleCount.textContent = n;
            toggleCount.hidden = n === 0;
        }
    }

    function setApplyCount(total) {
        if (!applyBtn || typeof total === 'undefined') return;
        applyBtn.textContent = (Number(total) === 1) ? 'Show 1 vehicle' : 'Show ' + total + ' vehicles';
    }

    /* --------------------------------------------------------------- events */

    var availToggle = form.querySelector('[name="available_only"]');

    form.querySelectorAll('.sa-vf-select').forEach(function (s) {
        if (s.hasAttribute('data-region-nav')) {
            // Category page: the Region dropdown navigates to another location
            // archive instead of filtering in place.
            s.addEventListener('change', function () {
                if (s.value) window.location.href = s.value;
            });
            return;
        }
        s.addEventListener('change', rerun);
    });

    if (availToggle) availToggle.addEventListener('change', rerun);
    if (sortSel) sortSel.addEventListener('change', rerun);

    var filterBtn = form.querySelector('.sa-vf-btn--filter');
    if (filterBtn) filterBtn.addEventListener('click', rerun);

    function clearAll() {
        form.querySelectorAll('.sa-vf-select').forEach(function (s) {
            if (s.hasAttribute('data-region-nav')) return; // keep current location
            s.value = '';
        });
        // "Available Only" is the default state.
        if (availToggle) availToggle.checked = true;
        if (sortSel) sortSel.value = 'featured';
        rerun();
    }

    root.querySelectorAll('.sa-vf-btn--clear').forEach(function (b) {
        b.addEventListener('click', clearAll);
    });

    /* --------------------------------------------------- mobile filter drawer */

    var toggle   = root.querySelector('.sa-vf-toggle');
    var sidebar  = root.querySelector('.sa-vf__sidebar');
    var closeBtn = root.querySelector('.sa-vf__drawer-close');

    function openDrawer() {
        if (!sidebar) return;
        sidebar.classList.add('is-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
        document.body.classList.add('sa-vf-open');
    }
    function closeDrawer() {
        if (!sidebar) return;
        sidebar.classList.remove('is-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('sa-vf-open');
    }

    if (toggle) toggle.addEventListener('click', function () {
        sidebar && sidebar.classList.contains('is-open') ? closeDrawer() : openDrawer();
    });
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if (applyBtn) applyBtn.addEventListener('click', function () {
        closeDrawer();
        scrollToResults();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('is-open')) closeDrawer();
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 860 && sidebar && sidebar.classList.contains('is-open')) closeDrawer();
    });

    /* ----------------------------------------------------------------- boot */
    // The form controls are pre-set from the URL by the server; render the
    // matching state client-side (identical markup, so no visible flash).
    render(false);
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

    // Exposed so the qualified carousel can init its slider after it fills.
    window.saVfInitFeatured = initFeatured;

    function boot() {
        // Skip carousels that populate themselves later (e.g. qualified).
        var list = document.querySelectorAll('.sa-vf-featured:not([data-qualified])');
        for (var i = 0; i < list.length; i++) initFeatured(list[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();


/* ===========================================================================
 * Qualified-vehicles carousel — reads the lead's rental limit and fills the
 * slider via AJAX, then boots the same drag/arrow slider.
 * ======================================================================== */
(function () {
    'use strict';

    function readLimit() {
        // URL ?rental_limit= wins (e.g. the "see all" link), else stored lead.
        try {
            var qs = new URLSearchParams(window.location.search);
            var p = qs.get('rental_limit');
            if (p) {
                var n = parseFloat(String(p).replace(/[^\d.]/g, ''));
                if (!isNaN(n) && n > 0) return n;
            }
        } catch (e) {}

        var stored = null;
        try { stored = sessionStorage.getItem('lead_qualification') || localStorage.getItem('lead_qualification'); } catch (e) {}
        if (!stored) return null;
        try {
            var lead = JSON.parse(stored);
            var raw = lead && lead.response ? lead.response.rental_limit : null;
            if (raw == null) return null;
            var v = parseFloat(String(raw).replace(/[^\d.]/g, ''));
            return (!isNaN(v) && v > 0) ? v : null;
        } catch (e) { return null; }
    }

    function initQualified(el) {
        if (el.dataset.saQInit) return;
        el.dataset.saQInit = '1';

        var track = el.querySelector('.sa-vf-featured__track');
        var msgEl = el.querySelector('.sa-vf-featured__msg');
        var cfg   = window.SA_VF_Q || {};

        function showMsg(text) {
            if (track) track.innerHTML = '';
            if (msgEl) { msgEl.textContent = text; msgEl.hidden = false; }
        }

        var limit = readLimit();
        if (limit == null) { showMsg('Complete the qualification to see vehicles within your monthly amount.'); return; }
        if (!cfg.ajax_url || !track) { showMsg(''); return; }

        var body = new URLSearchParams();
        body.set('action', 'sa_vf_qualified');
        body.set('rental_limit', limit);
        if (el.getAttribute('data-limit')) body.set('limit', el.getAttribute('data-limit'));

        fetch(cfg.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success || !res.data || !res.data.html) {
                showMsg('No vehicles are currently available within your monthly amount.');
                return;
            }
            track.innerHTML = res.data.html;
            if (msgEl) msgEl.hidden = true;
            if (typeof window.saVfInitFeatured === 'function') window.saVfInitFeatured(el);
        })
        .catch(function () { showMsg('Could not load vehicles right now. Please try again.'); });
    }

    function boot() {
        document.querySelectorAll('.sa-vf-featured[data-qualified]').forEach(initQualified);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
