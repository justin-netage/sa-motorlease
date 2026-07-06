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
            if (s.value) data[s.name] = s.value;
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

    function request(scrollAfter) {
        if (state.busy) return;
        state.busy = true;
        if (loading) loading.hidden = false;

        var data = collect();
        syncUrl(data);
        data.page = state.page;
        data.action = 'sa_vf_query';
        data.nonce = CFG.nonce;

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
            if (scrollAfter) scrollToResults();
        })
        .catch(function () {
            grid.innerHTML = '<div class="sa-vf-empty">Something went wrong loading vehicles. Please try again.</div>';
        })
        .finally(function () {
            state.busy = false;
            if (loading) loading.hidden = true;
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
        var top = results.getBoundingClientRect().top + window.pageYOffset - 20;
        window.scrollTo({ top: top, behavior: 'smooth' });
    }

    /* Re-run from page 1 (any filter change). No scroll — user is at the top. */
    var applyFilters = debounce(function () {
        state.page = 1;
        request(false);
    }, 250);

    /* ----------------------------------------------------- dependent models */

    /** Snapshot the full model <option> list so we can restore it. */
    var allModelOptions = modelSel
        ? Array.prototype.map.call(modelSel.options, function (o) {
            return { value: o.value, text: o.textContent.trim() };
        })
        : [];

    function updateModels() {
        if (!modelSel || !makeSel) return;
        var make = makeSel.value;
        var keep = modelSel.value;
        var allowed = (make && CFG.models[make]) ? CFG.models[make] : null;

        modelSel.innerHTML = '';
        allModelOptions.forEach(function (opt) {
            if (opt.value === '') {
                add(opt); // placeholder
            } else if (!allowed || allowed[opt.value]) {
                add(opt);
            }
        });
        // Restore prior selection if still valid.
        if (keep && modelSel.querySelector('option[value="' + cssEsc(keep) + '"]')) {
            modelSel.value = keep;
        } else {
            modelSel.value = '';
        }

        function add(opt) {
            var o = document.createElement('option');
            o.value = opt.value;
            o.textContent = opt.text;
            modelSel.appendChild(o);
        }
    }

    function cssEsc(s) {
        return (window.CSS && CSS.escape) ? CSS.escape(s) : s.replace(/"/g, '\\"');
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
        s.addEventListener('change', function () {
            if (s === makeSel) updateModels();
            applyFilters();
        });
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
        form.querySelectorAll('.sa-vf-select').forEach(function (s) { s.value = ''; });
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
        updateModels();
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
    updateModels();
    initRange();
    // Render numbered pagination from the server-seeded state.
    renderPager(
        parseInt(pager.getAttribute('data-page'), 10) || 1,
        parseInt(pager.getAttribute('data-pages'), 10) || 1
    );
})();
