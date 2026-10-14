/**
 * DevDome Activity Log screen behaviour (vanilla JS, no build step, no inline scripts).
 *
 * The suite blocks (Admin Cleaner ac-admin.js, 1:1 with the al- prefix):
 * 1. DESIGN.md 24: the database banner printed after the page moves to its top.
 * 2. DESIGN.md 3: WAI-ARIA tabs, every panel already in the page, instant switching, hash deep links.
 * 3. DESIGN.md 22.3: result banners are flashes; their URL flags are stripped so a reload does not repeat them.
 * 4. The shared .dd-dd dropdown (mouse + keyboard), with a change event on its hidden input.
 * 5. DESIGN.md 23: typed-word confirmation for form[data-al-typed="WORD"].
 * 6. DESIGN.md 16.1: "N changes not saved" next to Save while the form differs from what is stored.
 * 7. The Activity list: REST-fed rows built with createElement + textContent (no row value is ever markup),
 *    superseded requests abort, the URL carries the filters, the pager is the suite pager (21.8).
 */
(function () {
    'use strict';

    var app = document.querySelector('.dd-app');
    if (!app) { return; }

    var guardBanner = document.getElementById('devdacti-guard-banner');
    if (guardBanner) { app.insertBefore(guardBanner, app.firstChild); }

    /* ------------------------------ flash banners (22.3) ------------------------------ */

    var FLAGS = ['al_saved', 'al_failed', 'al_audit_failed', 'al_purged', 'al_purge_unconfirmed', 'al_purge_error', 'al_repaired', 'al_repair_unconfirmed', 'al_invalid', 'al_error', 'al_why', 'al_confirm'];
    function clearFlash() {
        Array.prototype.slice.call(document.querySelectorAll('.dd-banner[data-al-flash]')).forEach(function (b) { b.parentNode.removeChild(b); });
        Array.prototype.slice.call(document.querySelectorAll('.dd-footer .dd-saved')).forEach(function (b) { b.hidden = true; }); // "Saved." is a flash too (16.1)
    }
    // A focused info icon (DESIGN.md 21.6) shows its box; Escape hides it again.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('dd-tip')) { document.activeElement.blur(); }
    });
    (function stripFlags() {
        if (!(window.history && history.replaceState) || !window.URL) { return; }
        var url = new URL(location.href), changed = false;
        FLAGS.forEach(function (k) { if (url.searchParams.has(k)) { url.searchParams.delete(k); changed = true; } });
        if (changed) { history.replaceState(null, '', url.pathname + url.search + url.hash); }
    })();
    document.addEventListener('submit', function () { clearFlash(); }, true);

    /* ------------------------------ tabs (3) ------------------------------ */

    var tabs = Array.prototype.slice.call(app.querySelectorAll('.dd-tab[data-dd-tab]'));
    var panels = Array.prototype.slice.call(app.querySelectorAll('.dd-tabpanel[data-dd-panel]'));
    var ALIAS = { dashboard: 'overview', log: 'activity' }; // the first builds named the tabs differently; old deep links still land

    function show(name, moveFocus) {
        name = ALIAS[name] || name;
        var known = panels.some(function (p) { return p.getAttribute('data-dd-panel') === name; });
        if (!known) { return; }
        var current = null;
        panels.forEach(function (p) { if (p.classList.contains('is-active')) { current = p.getAttribute('data-dd-panel'); } });
        if (current !== null && current !== name) { clearFlash(); }
        panels.forEach(function (p) {
            var on = p.getAttribute('data-dd-panel') === name;
            p.classList.toggle('is-active', on);
            if (on) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', 'hidden'); }
        });
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-dd-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.setAttribute('tabindex', on ? '0' : '-1');
            if (on && moveFocus) { t.focus(); }
        });
        app.setAttribute('data-al-tab', name); // the header shows the export icon on the Activity tab only
    }

    tabs.forEach(function (t, idx) {
        t.addEventListener('click', function (e) {
            e.preventDefault();
            var name = t.getAttribute('data-dd-tab');
            show(name, false);
            t.blur();
            if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
        });
        t.addEventListener('keydown', function (e) {
            var target = null;
            if ('ArrowRight' === e.key || 'ArrowDown' === e.key) { target = tabs[(idx + 1) % tabs.length]; }
            else if ('ArrowLeft' === e.key || 'ArrowUp' === e.key) { target = tabs[(idx - 1 + tabs.length) % tabs.length]; }
            else if ('Home' === e.key) { target = tabs[0]; }
            else if ('End' === e.key) { target = tabs[tabs.length - 1]; }
            else if (' ' === e.key || 'Enter' === e.key) { target = t; }
            if (!target) { return; }
            e.preventDefault();
            var name = target.getAttribute('data-dd-tab');
            show(name, true);
            if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
        });
    });

    window.addEventListener('hashchange', function () {
        var h = (location.hash || '').replace('#', '');
        if (h) { show(h, false); }
    });
    var hash = (location.hash || '').replace('#', '');
    if (hash) { show(hash, false); }

    /* ------------------ custom dropdowns (.dd-dd, the shared component, Admin Cleaner 1:1) ------------------ */
    // Options are read live, so a panel filled later (the user list from REST) works without re-binding.

    var dds = Array.prototype.slice.call(app.querySelectorAll('.dd-dd'));
    function ddCloseAll(except) {
        dds.forEach(function (d) {
            if (d !== except) {
                d.classList.remove('is-open');
                var t = d.querySelector('.dd-dd-trigger');
                if (t) { t.setAttribute('aria-expanded', 'false'); }
            }
        });
    }
    function ddApplyValue(dd, value, fire) {
        var input = dd.querySelector('input[type="hidden"]'), label = dd.querySelector('.dd-dd-label'), chosen = null;
        Array.prototype.forEach.call(dd.querySelectorAll('.dd-dd-opt'), function (o) {
            var sel = (o.getAttribute('data-value') === String(value));
            o.classList.toggle('is-selected', sel);
            o.setAttribute('aria-selected', sel ? 'true' : 'false');
            if (sel) { chosen = o; }
        });
        if (!chosen) { return false; }
        if (input) { input.value = String(value); }
        if (label) { label.textContent = chosen.textContent; }
        var trig = dd.querySelector('.dd-dd-trigger');
        if (trig && dd.getAttribute('data-label')) { trig.setAttribute('aria-label', dd.getAttribute('data-label') + ': ' + chosen.textContent); }
        if (fire && input) { input.dispatchEvent(new Event('change', { bubbles: true })); }
        return true;
    }
    dds.forEach(function (dd) {
        var trigger = dd.querySelector('.dd-dd-trigger');
        var panel = dd.querySelector('.dd-dd-panel');
        if (!trigger || !panel) { return; }
        function ddSetOpen(open) { if (open && dd.classList.contains('is-disabled')) { return; } ddCloseAll(dd); dd.classList.toggle('is-open', open); trigger.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        function ddOpts() { return Array.prototype.slice.call(dd.querySelectorAll('.dd-dd-opt')); }
        function ddFocusOpt(i) { var o = ddOpts(); if (!o.length) { return; } i = Math.max(0, Math.min(o.length - 1, i)); o[i].focus(); }
        function ddChoose(opt) { if (opt.classList.contains('is-disabled')) { return; } ddApplyValue(dd, opt.getAttribute('data-value'), true); ddSetOpen(false); trigger.focus(); }
        trigger.addEventListener('click', function (e) { e.stopPropagation(); ddSetOpen(!dd.classList.contains('is-open')); });
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault(); ddSetOpen(true);
                var o = ddOpts(), sel = -1;
                o.forEach(function (x, i) { if (sel < 0 && x.classList.contains('is-selected')) { sel = i; } });
                ddFocusOpt(sel >= 0 ? sel : 0);
            } else if (e.key === 'Escape') { ddSetOpen(false); }
        });
        panel.addEventListener('click', function (e) {
            var opt = e.target.closest ? e.target.closest('.dd-dd-opt') : null;
            if (!opt) { return; }
            e.stopPropagation();
            ddChoose(opt);
        });
        panel.addEventListener('keydown', function (e) {
            var opt = e.target.closest ? e.target.closest('.dd-dd-opt') : null;
            if (!opt) { return; }
            var idx = ddOpts().indexOf(opt);
            if (e.key === 'ArrowDown') { e.preventDefault(); ddFocusOpt(idx + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); ddFocusOpt(idx - 1); }
            else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ddChoose(opt); }
            else if (e.key === 'Escape' || e.key === 'Tab') { ddSetOpen(false); if (e.key === 'Escape') { e.preventDefault(); trigger.focus(); } }
        });
    });
    document.addEventListener('click', function () { ddCloseAll(null); });
    window.devdactiSetSelect = function (name, value, fire) {
        var dd = app.querySelector('.dd-dd[data-name="' + name + '"]');
        return dd ? ddApplyValue(dd, value, !!fire) : false;
    };

    /* ------------- typed-word confirmation (DESIGN.md 23, Admin Cleaner 1:1) ------------- */

    Array.prototype.slice.call(app.querySelectorAll('form[data-al-typed]')).forEach(function (form) {
        var word = String(form.getAttribute('data-al-typed') || '').toUpperCase();
        var open = form.querySelector('[data-al-typed-open]');
        var panel = form.querySelector('.al-typed-confirm');
        var input = form.querySelector('.al-typed-input');
        var submit = form.querySelector('button[type="submit"]');
        var cancel = form.querySelector('[data-al-typed-cancel]');
        if (!word || !open || !panel || !input || !submit) { return; }
        function ok() { return input.value.trim().toUpperCase() === word; }
        function reset() { panel.setAttribute('hidden', 'hidden'); open.style.display = ''; input.value = ''; submit.disabled = true; }
        open.addEventListener('click', function () {
            clearFlash();
            panel.removeAttribute('hidden'); open.style.display = 'none';
            input.value = ''; submit.disabled = true;
            setTimeout(function () { input.focus(); }, 0);
        });
        input.addEventListener('input', function () { submit.disabled = !ok(); });
        if (cancel) { cancel.addEventListener('click', function () { reset(); open.focus(); }); }
        form.addEventListener('submit', function (e) {
            if (!ok()) { e.preventDefault(); return; }
            var h = form.querySelector('input[name="al_word"]');
            if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'al_word'; form.appendChild(h); }
            h.value = input.value.trim(); // the server checks the word too
            submit.disabled = true;
        });
        form.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hasAttribute('hidden')) { e.preventDefault(); reset(); open.focus(); } });
    });

    /* ------------- pending-changes counter (DESIGN.md 16.1, Admin Cleaner 1:1) ------------- */

    var forms = Array.prototype.slice.call(document.querySelectorAll('form')).filter(function (f) { return f.querySelector('.dd-footer .dd-pending'); });
    forms.forEach(function (form) {
        var slot = form.querySelector('.dd-footer .dd-pending');
        var saved = form.querySelector('.dd-footer .dd-saved');
        var submitting = false;
        function fields() {
            return Array.prototype.slice.call(form.elements).filter(function (el) {
                if (!el.name || /^(submit|button)$/.test(el.type)) { return false; }
                // A hidden input counts only when it is a dropdown's value (the shared .dd-dd keeps its choice there).
                if (el.type === 'hidden' && !(el.closest && el.closest('.dd-dd'))) { return false; }
                return el.name.indexOf('_alsn') !== 0 && el.name !== '_wp_http_referer';
            });
        }
        function value(el) {
            if (el.type === 'checkbox' || el.type === 'radio') { return el.checked ? '1' : '0'; }
            return String(el.value);
        }
        var initial = {};
        fields().forEach(function (el, i) { initial[el.name + '#' + i] = el.hasAttribute('data-al-saved') ? el.getAttribute('data-al-saved') : value(el); }); // a rejected proposal is compared against what is STORED
        function count() {
            var n = 0;
            fields().forEach(function (el, i) { var k = el.name + '#' + i; if (initial[k] !== undefined && initial[k] !== value(el)) { n++; } });
            return n;
        }
        function paint() {
            var n = count();
            if (n > 0) {
                slot.textContent = n === 1 ? slot.getAttribute('data-one') : slot.getAttribute('data-many').replace('%d', String(n));
                slot.hidden = false;
                if (saved) { saved.hidden = true; }
            } else {
                slot.hidden = true;
                slot.textContent = '';
            }
        }
        form.addEventListener('change', paint);
        form.addEventListener('input', paint);
        form.addEventListener('submit', function () { submitting = true; });
        if (form.hasAttribute('data-al-proposal')) { paint(); }
        window.addEventListener('beforeunload', function (e) {
            if (!submitting && count() > 0) { e.preventDefault(); e.returnValue = ''; }
        });
    });

    /* ------------------------------ the Activity list ------------------------------ */

    var CFG = window.DevdActi || {};
    var I18N = CFG.i18n || {};
    var root = document.getElementById('al-log');
    if (!root || !CFG.root) { return; }

    var rowsBody = document.getElementById('al-rows');
    var tableEl = document.getElementById('al-table');
    var emptyEl = document.getElementById('al-empty');
    var errorEl = document.getElementById('al-error');
    var statusEl = document.getElementById('al-status');
    var pagerEl = document.getElementById('al-pager');
    var fromInput = document.getElementById('al-from');
    var toInput = document.getElementById('al-to');
    var searchInput = document.getElementById('al-search');
    var applyBtn = document.getElementById('al-apply');
    var exportLink = document.getElementById('al-export');
    var userDd = app.querySelector('.dd-dd[data-name="al_user"]');

    var FILTER_KEYS = ['user', 'group', 'action', 'object_type', 'severity', 'from', 'to', 'search'];
    var DD_KEYS = { user: 'al_user', group: 'al_group', action: 'al_action', object_type: 'al_object_type', severity: 'al_severity' };
    var ACTION_GROUPS = CFG.actionGroups || {}; // action => group
    /** Group and Action never contradict each other: an action belongs to one group. */
    function reconcileGroupAction() {
        if (state.action && ACTION_GROUPS[state.action] && state.group !== ACTION_GROUPS[state.action]) { state.group = ACTION_GROUPS[state.action]; }
    }
    var PER_CHOICES = [10, 20, 50, 100]; // the server clamps per_page at 100 (devdacti_query_limits)
    var MAX_PAGE = 1000; // the server refuses a page past this (devdacti_query_limits)
    var DEFAULTS = { page: 1, per: parseInt(CFG.perPage, 10) || 20, user: 0, group: '', action: '', object_type: '', severity: '', from: '', to: '', search: '' };
    var state = {};
    var pages = 1;
    var seq = 0;
    var inFlight = null;
    var detailId = 0;
    var hasRows = false;

    function resetState() {
        Object.keys(DEFAULTS).forEach(function (k) { state[k] = DEFAULTS[k]; });
    }
    resetState();

    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== '') { n.textContent = text; } return n; }
    function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }
    function panelActive() { var p = root.closest ? root.closest('.dd-tabpanel') : null; return !p || p.classList.contains('is-active'); }

    /** printf-style formatting: %1$s / %1$d or bare %s / %d, so a translation can reorder the pieces. */
    function format(tpl, args) {
        var next = 0;
        return String(tpl).replace(/%(?:(\d+)\$)?([sd])/g, function (match, pos, type) {
            var i = pos ? (parseInt(pos, 10) - 1) : next++;
            if (i < 0 || i >= args.length) { return match; }
            return ('d' === type) ? String(parseInt(args[i], 10) || 0) : String(args[i]);
        });
    }

    /** Machine values (actor_type, channel, severity, outcome) become the words the server supplied. */
    function label(v) {
        var value = (v === null || typeof v === 'undefined') ? '' : String(v);
        return (I18N.labels && I18N.labels[value]) ? I18N.labels[value] : value;
    }

    /* ---- URL <-> state, so reload, Back and a pasted link all show the same view. ---- */

    function readUrl() {
        var params = new URLSearchParams(window.location.search);
        FILTER_KEYS.forEach(function (k) {
            var v = params.get('al_' + k);
            if (v === null) { return; }
            state[k] = (k === 'user') ? (parseInt(v, 10) || 0) : v;
        });
        var p = parseInt(params.get('al_page'), 10);
        if (p > 0) { state.page = Math.min(p, MAX_PAGE); }
        var per = parseInt(params.get('al_per'), 10);
        if (PER_CHOICES.indexOf(per) !== -1) { state.per = per; }
        reconcileGroupAction(); // a restored URL carrying a mismatched pair follows the action
    }

    function writeUrl(push) {
        if (!window.history || !history.replaceState) { return; }
        var params = new URLSearchParams(window.location.search);
        FILTER_KEYS.forEach(function (k) {
            var v = state[k];
            if (v === '' || v === 0) { params.delete('al_' + k); } else { params.set('al_' + k, v); }
        });
        if (state.page > 1) { params.set('al_page', state.page); } else { params.delete('al_page'); }
        if (state.per !== DEFAULTS.per) { params.set('al_per', state.per); } else { params.delete('al_per'); }
        var q = params.toString();
        var url = window.location.pathname + (q ? '?' + q : '') + (window.location.hash || '');
        // A new filter set or page is a new view with its own history entry; the first sync only REPLACES.
        if (push && history.pushState) {
            history.pushState(null, '', url);
        } else {
            history.replaceState(null, '', url);
        }
    }

    /** A user id from the URL that the loaded list does not carry gets its own option, so the filter is never shown as "All users". */
    function ensureUserOption(id) {
        if (!userDd || !id) { return; }
        var panel = userDd.querySelector('.dd-dd-panel');
        if (!panel || panel.querySelector('.dd-dd-opt[data-value="' + id + '"]')) { return; }
        var opt = el('div', 'dd-dd-opt', format(I18N.userById || 'User #%d', [id]));
        opt.setAttribute('role', 'option');
        opt.setAttribute('tabindex', '-1');
        opt.setAttribute('aria-selected', 'false');
        opt.setAttribute('data-value', String(id));
        panel.appendChild(opt);
    }

    function syncControls() {
        ensureUserOption(state.user);
        Object.keys(DD_KEYS).forEach(function (k) { window.devdactiSetSelect(DD_KEYS[k], String(state[k]), false); });
        if (fromInput) { fromInput.value = state.from; }
        if (toInput) { toInput.value = state.to; }
        if (searchInput) { searchInput.value = state.search; }
    }

    function query() {
        var q = ['page=' + state.page, 'per_page=' + state.per];
        FILTER_KEYS.forEach(function (k) {
            var v = state[k];
            if (v !== '' && v !== 0) { q.push(k + '=' + encodeURIComponent(v)); }
        });
        return q.join('&');
    }

    function api(path, signal) {
        // Plain-permalink sites: root is already '?rest_route=/...', so the sub-path's own '?' must become '&'.
        var url = CFG.root + (CFG.root.indexOf('?') !== -1 ? path.replace('?', '&') : path);
        return fetch(url, {
            method: 'GET',
            headers: { 'X-WP-Nonce': CFG.nonce },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: signal
        }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, status: r.status, data: j }; });
        });
    }

    // The CSV export in the header honours the ACTIVE filters.
    function updateExportLink() {
        if (!exportLink) { return; }
        var base = exportLink.getAttribute('data-base') || exportLink.href;
        var url;
        try { url = new URL(base, window.location.origin); } catch (e) { return; }
        FILTER_KEYS.forEach(function (k) {
            url.searchParams.delete(k);
            var v = state[k];
            if (v !== '' && v !== 0) { url.searchParams.set(k, v); }
        });
        exportLink.href = url.toString();
    }

    /* ---- rows ---- */

    function badge(severity) {
        var cls = { critical: 'al-badge-bad', warning: 'al-badge-warn', notice: 'al-badge-info', info: 'al-badge-muted' }[severity] || 'al-badge-muted';
        return el('span', 'al-badge ' + cls, label(severity));
    }

    function fact(list, k, v) {
        var li = document.createElement('li');
        li.appendChild(el('span', 'al-fact-k', k));
        li.appendChild(el('span', 'al-fact-v', (v === '' || v === null || typeof v === 'undefined') ? (I18N.none || 'none') : String(v)));
        list.appendChild(li);
    }

    function buildRow(r) {
        var frag = document.createDocumentFragment();
        var id = 'al-detail-' + (++detailId);

        var tr = document.createElement('tr');
        tr.className = 'al-row';
        tr.appendChild(el('td', 'al-col-pill', r.created_display || r.created_at || ''));
        var tdSev = el('td', 'al-col-pill');
        tdSev.appendChild(badge(r.severity));
        tr.appendChild(tdSev);
        tr.appendChild(el('td', 'al-col-pill', r.user_login || label(r.actor_type)));
        tr.appendChild(el('td', 'al-col-pill', r.ip || ''));

        // The wide column last (21.2: narrow pill columns first): the event name, then the facts stacked under it (19).
        var tdEvent = document.createElement('td');
        var title = el('span', 'al-title', r.action_label || r.action || '');
        if ((r.counter || 1) > 1) {
            title.appendChild(document.createTextNode(' '));
            title.appendChild(el('span', 'al-grouptag', format(I18N.times || '%d times', [r.counter])));
        }
        tdEvent.appendChild(title);
        if (r.object_title) {
            var meta = el('span', 'al-meta');
            meta.appendChild(el('b', '', (I18N.subject || 'Subject') + ':'));
            meta.appendChild(document.createTextNode(' ' + r.object_title));
            tdEvent.appendChild(meta);
        }
        if (r.details) {
            var det = el('span', 'al-meta');
            det.appendChild(el('b', '', (I18N.details || 'Details') + ':'));
            det.appendChild(document.createTextNode(' ' + r.details));
            tdEvent.appendChild(det);
        }
        tr.appendChild(tdEvent);

        // A real button with expanded/controls state, never a clickable row.
        var tdToggle = el('td', 'al-col-exp');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'al-toggle';
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-controls', id);
        btn.setAttribute('aria-label', I18N.showDetails || 'Show details');
        var chev = el('span', 'dashicons dashicons-arrow-down-alt2');
        chev.setAttribute('aria-hidden', 'true');
        btn.appendChild(chev);
        tdToggle.appendChild(btn);
        tr.appendChild(tdToggle);

        // Detail panel (21.3): starts under the wide column, one fact per line, full values.
        var trDetails = document.createElement('tr');
        trDetails.className = 'al-detail';
        trDetails.id = id;
        trDetails.hidden = true;
        var tdPad = document.createElement('td');
        tdPad.colSpan = 4;
        var tdDetails = document.createElement('td');
        tdDetails.colSpan = 2;
        var facts = el('ul', 'al-facts');
        fact(facts, I18N.group || 'Group', r.group || '');
        fact(facts, I18N.objectType || 'Object type', r.object_type || '');
        fact(facts, I18N.objectId || 'Object ID', String(r.object_id || 0));
        fact(facts, I18N.role || 'Role', r.user_role || '');
        fact(facts, I18N.actorType || 'Actor', label(r.actor_type));
        fact(facts, I18N.channel || 'Request channel', label(r.channel));
        fact(facts, I18N.outcome || 'Outcome', label(r.outcome));
        if ((r.counter || 1) > 1) {
            fact(facts, I18N.firstSeen || 'First seen', r.first_seen ? r.first_seen + ' UTC' : ''); // stored in UTC; the row time is site-local
            fact(facts, I18N.lastSeen || 'Last seen', r.last_seen ? r.last_seen + ' UTC' : '');
        }
        if (r.peer_ip && r.peer_ip !== r.ip) {
            fact(facts, I18N.peerIp || 'Connection address', r.peer_ip);
        }
        fact(facts, I18N.requestId || 'Request ID', r.request_id || '');
        tdDetails.appendChild(facts);
        trDetails.appendChild(tdPad);
        trDetails.appendChild(tdDetails);

        btn.addEventListener('click', function () {
            var open = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
            btn.setAttribute('aria-label', open ? (I18N.showDetails || 'Show details') : (I18N.hideDetails || 'Hide details'));
            chev.className = open ? 'dashicons dashicons-arrow-down-alt2' : 'dashicons dashicons-arrow-up-alt2';
            trDetails.hidden = open;
        });

        frag.appendChild(tr);
        frag.appendChild(trDetails);
        return frag;
    }

    /* ---- the pager (21.8, Link Monitor renderPager 1:1) ---- */

    function renderPager(data) {
        if (!pagerEl) { return; }
        clear(pagerEl);
        var total = parseInt(data.total, 10) || 0;
        var occurrences = parseInt(data.occurrences, 10) || total;
        if (!total) { return; }
        var first = (state.page - 1) * state.per + 1;
        var last = Math.min(total, state.page * state.per);
        var countText = format(I18N.ofTotal || '%1$s-%2$s of %3$s', [first, last, total]);
        if (occurrences !== total) { countText += ' (' + format(I18N.occurrences || '%s occurrences', [occurrences]) + ')'; }
        pagerEl.appendChild(el('span', 'al-pg-count', countText));
        var right = el('div', 'al-pg-right');

        var perWrap = el('div', 'al-pg-per');
        perWrap.appendChild(el('span', '', I18N.perPage || 'Show per page:'));
        var jump = el('div', 'page-jump-wrap');
        var jb = el('button', 'page-btn page-jump-btn', state.per + ' ▾');
        jb.type = 'button';
        jb.setAttribute('aria-haspopup', 'true');
        jb.setAttribute('aria-expanded', 'false');
        var menu = el('div', 'page-jump-menu hidden');
        PER_CHOICES.forEach(function (n) {
            var it = el('button', 'pjm-item' + (n === state.per ? ' active' : ''), String(n));
            it.type = 'button';
            it.addEventListener('click', function () { menu.classList.add('hidden'); state.per = n; state.page = 1; fetchEvents(true); });
            menu.appendChild(it);
        });
        jb.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = menu.classList.contains('hidden');
            menu.classList.toggle('hidden', !open);
            jb.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        jump.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menu.classList.contains('hidden')) { e.preventDefault(); menu.classList.add('hidden'); jb.setAttribute('aria-expanded', 'false'); jb.focus(); } });
        pagerEl.alJump = { menu: menu, jb: jb };
        jump.appendChild(jb);
        jump.appendChild(menu);
        perWrap.appendChild(jump);
        right.appendChild(perWrap);

        var btns = el('div', 'al-pg-btns');
        var mk = function (text, target, disabled, active, name) {
            var b = el('button', 'page-btn' + (active ? ' active' : ''), text);
            b.type = 'button';
            b.disabled = !!disabled;
            b.setAttribute('aria-label', name);
            if (active) { b.setAttribute('aria-current', 'page'); }
            if (!disabled && !active) { b.addEventListener('click', function () { state.page = target; fetchEvents(true); }); }
            return b;
        };
        btns.appendChild(mk('‹', state.page - 1, state.page <= 1, false, I18N.prevPage || 'Previous page'));
        var prev = 0;
        for (var p = 1; p <= pages; p++) {
            if (p !== 1 && p !== pages && Math.abs(p - state.page) > 1) { continue; }
            if (p - prev > 1) { btns.appendChild(el('span', 'page-gap', '…')); }
            btns.appendChild(mk(String(p), p, false, p === state.page, format(I18N.pageN || 'Page %d', [p])));
            prev = p;
        }
        btns.appendChild(mk('›', state.page + 1, state.page >= pages, false, I18N.nextPage || 'Next page'));
        right.appendChild(btns);
        pagerEl.appendChild(right);
    }
    document.addEventListener('click', function () { var m = pagerEl && pagerEl.alJump; if (m) { m.menu.classList.add('hidden'); m.jb.setAttribute('aria-expanded', 'false'); } });

    /* ---- the four screen states: busy, empty, error, results ---- */

    // The first load says "Loading events..." in the empty slot; after the first paint the current rows stay on screen,
    // dimmed, until the new rows arrive (DESIGN.md 19: no "Loading..." after the first paint).
    function setBusy(busy) {
        if (tableEl) { tableEl.setAttribute('aria-busy', busy ? 'true' : 'false'); }
        if (rowsBody) { rowsBody.classList.toggle('is-loading', busy && hasRows); }
        if (busy && !hasRows) { setEmpty(I18N.loading || 'Loading events...'); }
    }

    function setEmpty(msg) {
        if (!emptyEl) { return; }
        emptyEl.textContent = msg || '';
        emptyEl.style.display = msg ? 'block' : 'none';
    }

    function setStatus(msg) {
        if (statusEl) { statusEl.textContent = msg || ''; }
    }

    /** A failed request looks NOTHING like an empty result. */
    function showError(message) {
        if (!errorEl) { return; }
        clear(errorEl);
        errorEl.style.display = 'flex';
        errorEl.appendChild(document.createTextNode(message));
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'dd-btn dd-btn-sm';
        retry.textContent = I18N.retry || 'Try Again';
        retry.addEventListener('click', function () { fetchEvents(false); });
        errorEl.appendChild(retry);
        // DESIGN.md 26: every error carries "Report this error" (the shared core's runtime handles the states).
        if (typeof window.devdcorev1ReportError === 'function') {
            var wrap = el('span', 'ddc-report');
            var rep = el('button', 'ddc-report-btn', I18N.report || 'Report this error');
            rep.type = 'button';
            rep.addEventListener('click', function () { window.devdcorev1ReportError({ plugin: 'devdome-activity-log', version: CFG.version || '', error: 'Activity list could not be read: ' + message, screen: 'activity' }, rep); });
            wrap.appendChild(rep);
            errorEl.appendChild(wrap);
        }
        hasRows = false;
        setBusy(false);
        setStatus(message); // the live region says it too (the alert container is announced on its own)
        setEmpty('');
        clear(rowsBody);
        if (pagerEl) { clear(pagerEl); }
        if (retry.focus && panelActive()) { retry.focus(); }
    }

    function clearError() {
        if (errorEl) {
            errorEl.style.display = 'none';
            clear(errorEl);
        }
    }

    function render(data) {
        clear(rowsBody);
        var rows = data.rows || [];
        rows.forEach(function (r) { rowsBody.appendChild(buildRow(r)); });
        hasRows = rows.length > 0;
        setBusy(false);
        setEmpty(rows.length ? '' : (I18N.noResults || 'No events matched this filter.'));
        pages = Math.min(MAX_PAGE, Math.max(1, parseInt(data.pages, 10) || 1));
        var total = parseInt(data.total, 10) || 0;
        if (state.page > pages && total > 0) {
            // The list shrank under a stale page number (a purge, a filter from the URL): land on the last page that exists.
            state.page = pages;
            fetchEvents(false);
            return;
        }
        renderPager(data);
        setStatus(format(I18N.loaded || '%d events shown.', [rows.length]));
    }

    function fetchEvents(push) {
        // Supersede, never suppress: a filter change while a request is in flight aborts it.
        if (inFlight) { inFlight.abort(); }
        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        inFlight = controller;
        var mine = ++seq;

        clearError();
        setStatus(I18N.loading || 'Loading events...');
        setBusy(true);
        updateExportLink();
        writeUrl(push);

        api('events?' + query(), controller ? controller.signal : undefined).then(function (res) {
            if (mine !== seq) { return; }
            inFlight = null;
            if (!res.ok) {
                var msg = (res.data && res.data.message) ? res.data.message : (I18N.error || 'The activity log could not be read.');
                showError(msg);
                return;
            }
            render(res.data || { rows: [], total: 0, pages: 1 });
        }).catch(function (err) {
            if (err && err.name === 'AbortError') { return; }
            if (mine !== seq) { return; }
            inFlight = null;
            showError(I18N.error || 'The activity log could not be read.');
        });
    }

    function loadFilters() {
        if (!userDd) { return; }
        var panel = userDd.querySelector('.dd-dd-panel');
        function facetFailed() {
            if (!panel || panel.querySelector('.al-users-failed')) { return; }
            var note = el('div', 'dd-dd-opt is-disabled al-users-failed', I18N.usersFailed || 'The user list could not be loaded.');
            note.setAttribute('role', 'option');
            note.setAttribute('aria-disabled', 'true');
            note.setAttribute('data-value', '__failed');
            panel.appendChild(note);
            ensureUserOption(state.user);
            window.devdactiSetSelect('al_user', String(state.user), false);
        }
        api('filters').then(function (res) {
            if (!res.ok || !res.data || !res.data.users || !panel) { facetFailed(); return; }
            res.data.users.forEach(function (u) {
                var existing = panel.querySelector('.dd-dd-opt[data-value="' + String(u.id) + '"]');
                if (existing) { existing.textContent = u.login; return; } // the fallback option made from the URL
                var opt = el('div', 'dd-dd-opt', u.login);
                opt.setAttribute('role', 'option');
                opt.setAttribute('tabindex', '-1');
                opt.setAttribute('aria-selected', 'false');
                opt.setAttribute('data-value', String(u.id));
                panel.appendChild(opt);
            });
            if (res.data.users_truncated) {
                var note = el('div', 'dd-dd-opt is-disabled', I18N.usersPartial || 'Showing the 200 most recent users.');
                note.setAttribute('role', 'option');
                note.setAttribute('aria-disabled', 'true');
                note.setAttribute('data-value', '__more');
                panel.appendChild(note);
            }
            ensureUserOption(state.user);
            window.devdactiSetSelect('al_user', String(state.user), false);
        }).catch(function () { facetFailed(); });
    }

    function applyInputs() {
        clearFlash();
        Object.keys(DD_KEYS).forEach(function (k) {
            var input = app.querySelector('.dd-dd[data-name="' + DD_KEYS[k] + '"] input[type="hidden"]');
            var v = input ? input.value : '';
            state[k] = (k === 'user') ? (parseInt(v, 10) || 0) : v;
        });
        state.from = fromInput ? fromInput.value : '';
        state.to = toInput ? toInput.value : '';
        state.search = searchInput ? searchInput.value.trim() : '';
        state.page = 1;
        fetchEvents(true);
    }

    Object.keys(DD_KEYS).forEach(function (k) {
        var input = app.querySelector('.dd-dd[data-name="' + DD_KEYS[k] + '"] input[type="hidden"]');
        if (!input) { return; }
        input.addEventListener('change', function () {
            // A new group clears the action (it may not belong to the group); a new action selects its own group.
            if (k === 'group') { window.devdactiSetSelect('al_action', '', false); }
            if (k === 'action' && input.value && ACTION_GROUPS[input.value]) { window.devdactiSetSelect('al_group', ACTION_GROUPS[input.value], false); }
            applyInputs();
        });
    });
    if (applyBtn) { applyBtn.addEventListener('click', applyInputs); }
    if (searchInput) {
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); applyInputs(); }
        });
    }
    [fromInput, toInput].forEach(function (inp) {
        if (inp) { inp.addEventListener('change', applyInputs); }
    });

    // Back and Forward restore the view they point at: the URL is the whole state.
    window.addEventListener('popstate', function () {
        var h = (location.hash || '').replace('#', '');
        var t = new URLSearchParams(location.search).get('al_tab') || '';
        show(h || t || 'overview', false); // the tab the restored URL points at, then its filters
        resetState();
        readUrl();
        syncControls();
        fetchEvents(false);
    });

    readUrl();
    syncControls();
    loadFilters();
    fetchEvents(false);
})();
