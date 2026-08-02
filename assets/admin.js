(function () {
    'use strict';

    const ready = (fn) => (document.readyState !== 'loading')
        ? fn()
        : document.addEventListener('DOMContentLoaded', fn);

    const cfg = (typeof window.BynliConnect !== 'undefined') ? window.BynliConnect : null;

    function flashCopy(btn) {
        const orig = btn.textContent;
        btn.textContent = 'Copied';
        btn.classList.add('bcn-copied');
        setTimeout(() => {
            btn.textContent = orig;
            btn.classList.remove('bcn-copied');
        }, 1400);
    }

    async function copyText(text, btn) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
            } else {
                throw new Error('clipboard-api-unavailable');
            }
        } catch (e) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e2) { /* swallow */ }
            ta.remove();
        }
        if (btn) flashCopy(btn);
    }

    function wireRevealToggles() {
        document.querySelectorAll('.bcn-toggle-reveal').forEach((btn) => {
            const targetId = btn.getAttribute('data-target');
            if (!targetId) return;
            const input = document.getElementById(targetId);
            if (!input) return;
            btn.addEventListener('click', () => {
                const showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                const icon = btn.querySelector('.dashicons');
                if (icon) {
                    icon.classList.toggle('dashicons-visibility', showing);
                    icon.classList.toggle('dashicons-hidden',     !showing);
                }
                btn.setAttribute('aria-label', showing ? 'Show key' : 'Hide key');
                btn.setAttribute('aria-pressed', String(!showing));
            });
        });
    }

    function wireCopyButtons() {
        document.querySelectorAll('.bcn-copy, .bcn-sc-copy').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const literal = btn.getAttribute('data-text');
                if (literal !== null) { copyText(literal, btn); return; }
                const targetId = btn.getAttribute('data-target');
                if (!targetId) return;
                const input = document.getElementById(targetId);
                if (input) copyText(input.value || input.textContent || '', btn);
            });
        });
    }

    function wireKeyValidation() {
        const input = document.getElementById('bcn_key');
        const out   = document.getElementById('bcn-key-validity');
        if (!input || !out) return;
        const re = /^bynli_sh_[0-9a-f]{32}$/;
        const validate = () => {
            const v = (input.value || '').trim();
            out.classList.remove('ok', 'err');
            if (v === '') { out.textContent = ''; return; }
            if (re.test(v)) {
                out.textContent = 'Format looks valid';
                out.classList.add('ok');
            } else {
                out.textContent = 'Expected: bynli_sh_ + 32 hex characters';
                out.classList.add('err');
            }
        };
        input.addEventListener('input', validate);
        validate();
    }

    function setNote(el, state, ico, msg) {
        if (!el) return;
        el.hidden = false;
        el.className = 'bcn-note ' + state;
        const icoEl = el.querySelector('[data-role="ico"]');
        const msgEl = el.querySelector('[data-role="msg"]');
        if (icoEl) icoEl.className = 'dashicons ' + ico;
        if (msgEl) msgEl.textContent = msg;
        else el.textContent = msg;
    }

    function wireHeartbeat() {
        const btn = document.getElementById('bcn-heartbeat-btn');
        if (!btn || !cfg || !cfg.ajaxUrl) return;
        const statusEl = document.getElementById('bcn-heartbeat-status');
        const origLabel = btn.innerHTML;

        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (btn.getAttribute('aria-disabled') === 'true' || btn.disabled) return;

            btn.setAttribute('aria-busy', 'true');
            btn.disabled = true;
            btn.innerHTML = '<span class="dashicons dashicons-update bcn-spin"></span> Sending…';
            setNote(statusEl, 'is-run', 'dashicons-update bcn-spin', 'Sending a signed heartbeat to Bynefit…');

            const t0 = (window.performance && performance.now) ? performance.now() : Date.now();
            const fd = new FormData();
            fd.append('action', 'bynli_connect_heartbeat');
            fd.append('_wpnonce', cfg.nonce);

            let body;
            try {
                const res = await fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
                body = await res.json();
            } catch (err) {
                body = { success: false, data: { message: 'Network error — could not reach WordPress.' } };
            }
            const t1 = (window.performance && performance.now) ? performance.now() : Date.now();
            const rtt = Math.max(0, Math.round(t1 - t0));

            btn.removeAttribute('aria-busy');
            btn.disabled = false;
            btn.innerHTML = origLabel;

            const data = (body && body.data) || {};
            if (body && body.success) {
                const code = data.status ? ' · ' + data.status : '';
                setNote(statusEl, 'is-ok', 'dashicons-yes-alt',
                    (data.message || 'Heartbeat OK. Bynefit received the ping.') + code + ' · ' + rtt + 'ms round-trip');
                // Reflect the now-verified state in the topbar + any last-report readout.
                const pill = document.querySelector('[data-bcn="signal"]');
                if (pill) {
                    pill.setAttribute('data-state', 'on');
                    const lbl = pill.querySelector('.bcn-signal-label');
                    if (lbl) lbl.textContent = 'Connected';
                }
            } else {
                setNote(statusEl, 'is-err', 'dashicons-warning',
                    (data.message || 'Heartbeat failed.') + ' · ' + rtt + 'ms');
            }
        });
    }

    function wireDisconnect() {
        const btn = document.getElementById('bcn-disconnect-btn');
        if (!btn) return;
        btn.addEventListener('click', (e) => {
            const ok = window.confirm(
                'Disconnect this site from Bynli? The API key will be cleared.\n\n' +
                'This will NOT revoke the key on Bynli’s side — visit /dash/sites/host-keys to do that.'
            );
            if (!ok) e.preventDefault();
        });
    }

    const BCN_ON_SUCCESS = {
        reply(form, data) {
            if (!data || !data.message_html) return false;
            const foot = form.closest('.bcn-thread-foot');
            if (!foot || !foot.parentNode) return false;
            const tmp = document.createElement('div');
            tmp.innerHTML = String(data.message_html).trim();
            const article = tmp.firstElementChild;
            if (!article) return false;
            foot.parentNode.insertBefore(article, foot);
            const ta = form.querySelector('textarea[name="reply_body"]');
            if (ta) ta.value = '';
            if (typeof article.scrollIntoView === 'function') {
                article.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return true;
        },
        resolve(form, data) {
            if (!data || !data.foot_html) return false;
            const foot = form.closest('.bcn-thread-foot');
            if (!foot) return false;
            foot.innerHTML = String(data.foot_html);
            return true;
        },
    };

    const BCN_FIELD_MAP = { subject: 'ticket_subject', body: 'ticket_body' };

    function wireAjaxForms() {
        const forms = document.querySelectorAll('form.bcn-ajax-form');
        if (!forms.length) return;
        if (!cfg || !cfg.ajaxUrl) return;

        forms.forEach((form) => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const confirmMsg = form.getAttribute('data-bcn-confirm');
                if (confirmMsg && !window.confirm(confirmMsg)) return;

                const action = form.getAttribute('data-bcn-action') || '';
                if (!action) return;

                const fb = form.querySelector('[data-role="feedback"]');
                const submit = form.querySelector('[data-role="submit"]');
                const origMarkup = submit ? submit.innerHTML : '';

                if (fb) {
                    fb.hidden = true;
                    fb.textContent = '';
                    fb.className = 'bcn-form-feedback';
                }
                if (submit) {
                    submit.disabled = true;
                    submit.setAttribute('aria-busy', 'true');
                    submit.innerHTML = '<span class="dashicons dashicons-update bcn-spin" aria-hidden="true"></span> Sending…';
                }

                const restoreSubmit = () => {
                    if (submit) {
                        submit.disabled = false;
                        submit.removeAttribute('aria-busy');
                        submit.innerHTML = origMarkup;
                    }
                };

                const fd = new FormData(form);
                fd.append('action', action);

                let body;
                try {
                    const res = await fetch(cfg.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd,
                    });
                    body = await res.json().catch(() => null);
                } catch (err) {
                    if (fb) {
                        fb.hidden = false;
                        fb.classList.add('is-err');
                        fb.textContent = 'Network error — please try again.';
                    }
                    restoreSubmit();
                    return;
                }

                if (body && body.success) {
                    const data = body.data || {};
                    const onSuccess = form.getAttribute('data-bcn-on-success');
                    if (onSuccess && typeof BCN_ON_SUCCESS[onSuccess] === 'function') {
                        const handled = BCN_ON_SUCCESS[onSuccess](form, data);
                        if (handled) {
                            if (fb) {
                                fb.hidden = false;
                                fb.classList.add('is-ok');
                                fb.textContent = 'Sent.';
                                setTimeout(() => {
                                    fb.hidden = true;
                                    fb.textContent = '';
                                    fb.className = 'bcn-form-feedback';
                                }, 1800);
                            }
                            restoreSubmit();
                            return;
                        }
                    }
                    if (data.detail_url) {
                        window.location.href = data.detail_url;
                        return;
                    }
                    if (fb) {
                        fb.hidden = false;
                        fb.classList.add('is-ok');
                        fb.textContent = 'Sent.';
                    }
                    form.reset();
                } else {
                    const msg = (body && body.data && body.data.message)
                        ? body.data.message
                        : 'Request failed. Please try again.';
                    if (fb) {
                        fb.hidden = false;
                        fb.classList.add('is-err');
                        fb.textContent = msg;
                    }
                    const field = body && body.data && body.data.field;
                    if (field) {
                        const inputName = BCN_FIELD_MAP[field] || field;
                        const el = form.querySelector('[name="' + inputName + '"]');
                        if (el && typeof el.focus === 'function') el.focus();
                    }
                }

                restoreSubmit();
            });
        });
    }

    // ── Relay console (#29): client-side panel switching + theme toggle ──

    function showPanel(section) {
        const panels = document.querySelectorAll('.bcn-panel');
        if (!panels.length) return false;
        let matched = false;
        panels.forEach((p) => {
            const on = (p.getAttribute('data-panel') === section);
            p.classList.toggle('active', on);
            if (on) { p.removeAttribute('hidden'); matched = true; }
            else    { p.setAttribute('hidden', ''); }
        });
        if (!matched) return false;
        document.querySelectorAll('.bcn-nav-item').forEach((a) => {
            const on = (a.getAttribute('data-go') === section);
            a.classList.toggle('active', on);
            if (on) a.setAttribute('aria-current', 'page');
            else    a.removeAttribute('aria-current');
        });
        // A sparkline in a display:none panel measures 0px wide; render it now
        // that its panel is visible.
        try { wireSparklines(); } catch (e) { /* ignore */ }
        return true;
    }

    function wirePanels() {
        const rail = document.querySelector('.bcn-rail');
        if (!rail) return;
        // Enhance the deep-link anchors: switch client-side, keep the URL in
        // sync (so refresh/share lands on the same section), no reload. The
        // server already rendered every panel + the ?section= fallback works
        // with JS off, so this is pure progressive enhancement.
        document.querySelectorAll('.bcn-nav-item[data-go]').forEach((a) => {
            a.addEventListener('click', (ev) => {
                // Server-rendered surfaces (tickets: remote API call) must do a
                // real navigation so the server renders fresh content.
                if (a.hasAttribute('data-server')) return;
                const section = a.getAttribute('data-go');
                if (!section) return;
                if (!showPanel(section)) return; // fall back to navigation
                ev.preventDefault();
                try {
                    const url = new URL(a.href, window.location.origin);
                    window.history.pushState({ bcnSection: section }, '', url);
                } catch (e) { /* history unsupported — leave URL as is */ }
            });
        });
        window.addEventListener('popstate', () => {
            try {
                const s = new URL(window.location.href).searchParams.get('section');
                if (s) showPanel(s);
            } catch (e) { /* ignore */ }
        });
    }

    // ── 7-day heartbeat sparkline (deterministic, drawn from report history) ──

    const clamp01 = (v) => Math.max(0, Math.min(1, Number(v) || 0));

    function buildSpark(el) {
        let series;
        try { series = JSON.parse(el.getAttribute('data-series') || '[]'); } catch (e) { series = []; }
        if (!Array.isArray(series)) series = [];

        const rect = el.getBoundingClientRect();
        const W = Math.round(rect.width);
        const H = Math.round(rect.height) || 52;
        if (W < 4) return; // panel hidden — re-rendered when it becomes visible

        const NS  = 'http://www.w3.org/2000/svg';
        const pad = 4;
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);

        el.textContent = '';

        if (series.length < 2) {
            // Not enough history yet — flat baseline instead of a fake line.
            const base = document.createElementNS(NS, 'line');
            base.setAttribute('x1', 0); base.setAttribute('y1', (H / 2).toFixed(1));
            base.setAttribute('x2', W); base.setAttribute('y2', (H / 2).toFixed(1));
            base.setAttribute('class', 'bcn-spark-base');
            svg.appendChild(base);
            el.appendChild(svg);
            return;
        }

        const n = series.length;
        const stepX = W / (n - 1);
        const yOf = (v) => H - pad - clamp01(v) * (H - pad * 2);
        const coords = series.map((v, i) => [ +(i * stepX).toFixed(2), +yOf(v).toFixed(2) ]);

        const linePath = coords.map((c, i) => (i ? 'L' : 'M') + c[0] + ' ' + c[1]).join(' ');
        const areaPath = 'M0 ' + H + ' L' + coords.map((c) => c[0] + ' ' + c[1]).join(' L') + ' L' + W + ' ' + H + ' Z';

        const area = document.createElementNS(NS, 'path');
        area.setAttribute('d', areaPath); area.setAttribute('class', 'bcn-spark-area');
        const line = document.createElementNS(NS, 'path');
        line.setAttribute('d', linePath); line.setAttribute('class', 'bcn-spark-line');
        line.setAttribute('vector-effect', 'non-scaling-stroke');
        svg.appendChild(area); svg.appendChild(line);

        const last = coords[coords.length - 1];
        const dot = document.createElementNS(NS, 'circle');
        dot.setAttribute('cx', last[0]); dot.setAttribute('cy', last[1]); dot.setAttribute('r', 2.6);
        dot.setAttribute('class', 'bcn-spark-dot' + (el.getAttribute('data-ok') === '0' ? ' down' : ''));
        svg.appendChild(dot);

        el.appendChild(svg);
    }

    function wireSparklines() {
        document.querySelectorAll('.bcn-spark[data-series]').forEach(buildSpark);
    }

    // ── Site visibility select → save via AJAX (mirrors the theme toggle) ──
    function wireVisibility() {
        const sel = document.querySelector('[data-bcn-visibility]');
        if (!sel || !cfg || !cfg.ajaxUrl) return;
        const hintEl   = document.querySelector('[data-role="vis-hint"]');
        const statusEl = document.querySelector('[data-role="vis-status"]');
        const HINTS = {
            live:         'Your site is public — normal behavior.',
            coming_soon:  'Logged-out visitors see a branded holding page (503).',
            members_only: 'Logged-out visitors are sent to sign in first.',
        };
        const warnEl = document.querySelector('[data-role="vis-warn"]');
        sel.addEventListener('change', async () => {
            const mode = sel.value;
            if (hintEl && HINTS[mode]) hintEl.textContent = HINTS[mode];
            if (warnEl) warnEl.hidden = (mode === 'live');
            setNote(statusEl, 'is-run', 'dashicons-update bcn-spin', 'Saving…');
            const body = new URLSearchParams();
            body.set('action', 'bynli_connect_visibility');
            body.set('_wpnonce', sel.getAttribute('data-nonce') || '');
            body.set('mode', mode);
            let data;
            try {
                const res = await fetch(cfg.ajaxUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });
                data = await res.json();
            } catch (e) { data = { success: false, data: { message: 'Network error.' } }; }
            if (data && data.success) {
                setNote(statusEl, 'is-ok', 'dashicons-yes-alt', 'Visibility saved.');
            } else {
                setNote(statusEl, 'is-err', 'dashicons-warning', (data && data.data && data.data.message) || 'Could not save.');
            }
        });
    }

    // ── Client-mode toggle → save via AJAX ──
    function wireClientMode() {
        const sel = document.querySelector('[data-bcn-client-mode]');
        if (!sel || !cfg || !cfg.ajaxUrl) return;
        const hintEl   = document.querySelector('[data-role="client-hint"]');
        const statusEl = document.querySelector('[data-role="client-status"]');
        const HINTS = {
            '0': 'Off — no role or lockdown is applied.',
            '1': 'On — the site owner (Client) sees only the Portal (pages, posts, media); the rest of wp-admin is hidden. Your admin account is unaffected.',
        };
        sel.addEventListener('change', async () => {
            const on = sel.value;
            if (hintEl && HINTS[on]) hintEl.textContent = HINTS[on];
            setNote(statusEl, 'is-run', 'dashicons-update bcn-spin', 'Saving…');
            const body = new URLSearchParams();
            body.set('action', 'bynli_connect_client_mode');
            body.set('_wpnonce', sel.getAttribute('data-nonce') || '');
            body.set('enabled', on);
            let data;
            try {
                const res = await fetch(cfg.ajaxUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });
                data = await res.json();
            } catch (e) { data = { success: false, data: { message: 'Network error.' } }; }
            if (data && data.success) {
                const mgr = document.querySelector('[data-bcn-clients]');
                if (mgr) mgr.hidden = (on !== '1');
                setNote(statusEl, 'is-ok', 'dashicons-yes-alt',
                    on === '1' ? 'Client mode on. Add or invite clients below.' : 'Client mode off.');
            } else {
                setNote(statusEl, 'is-err', 'dashicons-warning', (data && data.data && data.data.message) || 'Could not save.');
            }
        });
    }

    // ── Client roster: assign / invite / remove via AJAX ──
    function wireClientManage() {
        const box = document.querySelector('[data-bcn-clients]');
        if (!box || !cfg || !cfg.ajaxUrl) return;
        const nonce    = box.getAttribute('data-nonce') || '';
        const listEl   = box.querySelector('[data-role="client-list"]');
        const userSel  = box.querySelector('[data-role="client-user"]');
        const nameEl   = box.querySelector('[data-role="client-name"]');
        const emailEl  = box.querySelector('[data-role="client-email"]');
        const statusEl = box.querySelector('[data-role="client-manage-status"]');

        function renderClients(rows) {
            if (!listEl) return;
            listEl.textContent = '';
            if (!rows || !rows.length) {
                const li = document.createElement('li');
                li.className = 'bcn-client-empty';
                li.setAttribute('data-role', 'client-empty');
                li.textContent = 'No clients yet — add or invite one below.';
                listEl.appendChild(li);
                return;
            }
            rows.forEach((c) => {
                const li = document.createElement('li');
                li.className = 'bcn-client-item';
                li.setAttribute('data-uid', String(c.id));
                const meta = document.createElement('span');
                meta.className = 'bcn-client-meta';
                const nm = document.createElement('span');
                nm.className = 'bcn-client-name'; nm.textContent = c.name || '(no name)';
                const em = document.createElement('span');
                em.className = 'bcn-client-email'; em.textContent = c.email || '';
                meta.appendChild(nm); meta.appendChild(em);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'bcn-btn danger sm';
                btn.setAttribute('data-role', 'client-remove');
                btn.setAttribute('data-uid', String(c.id));
                btn.textContent = 'Remove';
                li.appendChild(meta); li.appendChild(btn);
                listEl.appendChild(li);
            });
        }

        function renderAssignable(rows) {
            if (!userSel) return;
            userSel.textContent = '';
            const first = document.createElement('option');
            if (!rows || !rows.length) {
                first.value = ''; first.textContent = 'No eligible users';
                userSel.appendChild(first);
                return;
            }
            first.value = ''; first.textContent = 'Choose a user…';
            userSel.appendChild(first);
            rows.forEach((a) => {
                const o = document.createElement('option');
                o.value = String(a.id);
                o.textContent = (a.name || '(no name)') + ' — ' + (a.email || '');
                userSel.appendChild(o);
            });
        }

        async function post(params, runningMsg) {
            setNote(statusEl, 'is-run', 'dashicons-update bcn-spin', runningMsg);
            const body = new URLSearchParams();
            body.set('_wpnonce', nonce);
            Object.keys(params).forEach((k) => body.set(k, params[k]));
            let data;
            try {
                const res = await fetch(cfg.ajaxUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });
                data = await res.json();
            } catch (e) { data = { success: false, data: { message: 'Network error.' } }; }
            if (data && data.success) {
                renderClients(data.data && data.data.clients);
                renderAssignable(data.data && data.data.assignable);
                return true;
            }
            setNote(statusEl, 'is-err', 'dashicons-warning', (data && data.data && data.data.message) || 'Request failed.');
            return false;
        }

        const addBtn = box.querySelector('[data-role="client-add"]');
        if (addBtn) addBtn.addEventListener('click', async () => {
            const uid = userSel && userSel.value;
            if (!uid) { setNote(statusEl, 'is-err', 'dashicons-warning', 'Choose a user first.'); return; }
            const ok = await post({ action: 'bynli_connect_client_assign', user_id: uid }, 'Assigning…');
            if (ok) setNote(statusEl, 'is-ok', 'dashicons-yes-alt', 'Client added.');
        });

        const inviteBtn = box.querySelector('[data-role="client-invite"]');
        if (inviteBtn) inviteBtn.addEventListener('click', async () => {
            const email = (emailEl && emailEl.value || '').trim();
            const name  = (nameEl && nameEl.value || '').trim();
            if (!email) { setNote(statusEl, 'is-err', 'dashicons-warning', 'Enter an email to invite.'); return; }
            const ok = await post({ action: 'bynli_connect_client_assign', email: email, name: name }, 'Inviting…');
            if (ok) {
                if (emailEl) emailEl.value = '';
                if (nameEl) nameEl.value = '';
                setNote(statusEl, 'is-ok', 'dashicons-yes-alt', 'Invite sent — they’ll get an email to set a password.');
            }
        });

        if (listEl) listEl.addEventListener('click', async (ev) => {
            const btn = ev.target.closest('[data-role="client-remove"]');
            if (!btn) return;
            const uid = btn.getAttribute('data-uid');
            if (!uid) return;
            const ok = await post({ action: 'bynli_connect_client_revoke', user_id: uid }, 'Removing…');
            if (ok) setNote(statusEl, 'is-ok', 'dashicons-yes-alt', 'Client removed.');
        });
    }

    // ── Portal: contact-Bynefit support form → ticket via AJAX ──
    function wirePortalSupport() {
        const box = document.querySelector('[data-bcn-support]');
        if (!box || !cfg || !cfg.ajaxUrl) return;
        const nonce     = box.getAttribute('data-nonce') || '';
        const subjectEl = box.querySelector('[data-role="sup-subject"]');
        const catEl     = box.querySelector('[data-role="sup-cat"]');
        const bodyEl    = box.querySelector('[data-role="sup-body"]');
        const statusEl  = box.querySelector('[data-role="sup-status"]');
        const sendBtn   = box.querySelector('[data-role="sup-send"]');
        if (!sendBtn) return;
        sendBtn.addEventListener('click', async () => {
            const subject = (subjectEl && subjectEl.value || '').trim();
            const body    = (bodyEl && bodyEl.value || '').trim();
            const category = (catEl && catEl.value) || 'general';
            if (subject.length < 3) { setNote(statusEl, 'is-err', 'dashicons-warning', 'Add a short subject first.'); return; }
            if (!body) { setNote(statusEl, 'is-err', 'dashicons-warning', 'Add a message first.'); return; }
            setNote(statusEl, 'is-run', 'dashicons-update bcn-spin', 'Sending…');
            const params = new URLSearchParams();
            params.set('action', 'bynli_connect_portal_support');
            params.set('_wpnonce', nonce);
            params.set('subject', subject);
            params.set('category', category);
            params.set('body', body);
            let data;
            try {
                const res = await fetch(cfg.ajaxUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString(),
                });
                data = await res.json();
            } catch (e) { data = { success: false, data: { message: 'Network error.' } }; }
            if (data && data.success) {
                if (subjectEl) subjectEl.value = '';
                if (bodyEl) bodyEl.value = '';
                setNote(statusEl, 'is-ok', 'dashicons-yes-alt', (data.data && data.data.message) || 'Sent.');
            } else {
                setNote(statusEl, 'is-err', 'dashicons-warning', (data && data.data && data.data.message) || 'Could not send.');
            }
        });
    }

    // ── Live form picker: load the team's real forms, click to insert the id ──

    function bcnCodeNodes(code) {
        // Build token-colored spans (mirrors sc_code_html) via DOM so values
        // are never interpolated as HTML.
        const frag = document.createDocumentFragment();
        const span = (cls, txt) => { const s = document.createElement('span'); s.className = cls; s.textContent = txt; return s; };
        const m = /^\[([a-z0-9-]+)\s*(.*)\]$/i.exec(String(code).trim());
        if (!m) { frag.appendChild(document.createTextNode(String(code))); return frag; }
        frag.appendChild(span('bcn-cb-b', '['));
        frag.appendChild(span('bcn-cb-t', m[1]));
        const re = /([a-z0-9_-]+)="([^"]*)"/gi;
        let pair;
        while ((pair = re.exec(m[2]))) {
            frag.appendChild(document.createTextNode(' '));
            frag.appendChild(span('bcn-cb-a', pair[1]));
            frag.appendChild(span('bcn-cb-p', '='));
            frag.appendChild(span('bcn-cb-v', '"' + pair[2] + '"'));
        }
        frag.appendChild(span('bcn-cb-b', ']'));
        return frag;
    }

    function insertForm(id, item) {
        const detail = item.closest('.bcn-sc-detail');
        if (!detail) return;
        const code  = '[bynli-form id="' + id + '"]';
        const block = detail.querySelector('.bcn-code-block');
        if (block) { block.textContent = ''; block.appendChild(bcnCodeNodes(code)); }
        const copy = detail.querySelector('.bcn-sc-copy');
        if (copy) copy.setAttribute('data-text', code);
        detail.querySelectorAll('.bcn-form-item').forEach((i) => i.classList.toggle('is-selected', i === item));
    }

    function renderFormList(listEl, data) {
        listEl.textContent = '';
        if (!data || !data.success) {
            const n = document.createElement('div'); n.className = 'bcn-note is-err';
            n.textContent = (data && data.data && data.data.message) || 'Could not load your forms.';
            listEl.appendChild(n);
            return;
        }
        const forms = (data.data && data.data.forms) || [];
        if (!forms.length) {
            const n = document.createElement('div'); n.className = 'bcn-note';
            n.textContent = 'No forms found for this team yet.';
            listEl.appendChild(n);
            return;
        }
        forms.forEach((f) => {
            const item = document.createElement('button');
            item.type = 'button'; item.className = 'bcn-form-item';
            const t = document.createElement('span'); t.className = 'bcn-form-item-title'; t.textContent = f.title || '(untitled)';
            const c = document.createElement('code'); c.className = 'bcn-form-item-id'; c.textContent = f.id;
            item.appendChild(t); item.appendChild(c);
            item.addEventListener('click', () => insertForm(f.id, item));
            listEl.appendChild(item);
        });
    }

    function wireFormPicker() {
        document.querySelectorAll('[data-bcn-load-forms]').forEach((btn) => {
            if (!cfg || !cfg.ajaxUrl) return;
            const listEl = btn.parentElement && btn.parentElement.querySelector('[data-role="forms-list"]');
            btn.addEventListener('click', async () => {
                const orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="dashicons dashicons-update bcn-spin"></span> Loading…';
                const body = new URLSearchParams();
                body.set('action', 'bynli_connect_forms');
                body.set('_wpnonce', btn.getAttribute('data-nonce') || '');
                let data;
                try {
                    const res = await fetch(cfg.ajaxUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString(),
                    });
                    data = await res.json();
                } catch (e) { data = { success: false, data: { message: 'Network error.' } }; }
                btn.disabled = false;
                btn.innerHTML = orig;
                if (listEl) renderFormList(listEl, data);
            });
        });
    }

    // ── Shortcode previewer: swap the detail panel for the picked shortcode ──
    function wireShortcodePicker() {
        const items = document.querySelectorAll('.bcn-sc-item[data-sc]');
        if (!items.length) return;
        items.forEach((btn) => {
            btn.addEventListener('click', () => {
                const tag = btn.getAttribute('data-sc');
                document.querySelectorAll('.bcn-sc-item').forEach((b) => {
                    const on = (b === btn);
                    b.classList.toggle('active', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                document.querySelectorAll('.bcn-sc-detail').forEach((d) => {
                    const on = (d.getAttribute('data-sc-detail') === tag);
                    d.classList.toggle('active', on);
                    if (on) d.removeAttribute('hidden');
                    else    d.setAttribute('hidden', '');
                });
            });
        });
    }

    ready(() => {
        wireRevealToggles();
        wireCopyButtons();
        wireKeyValidation();
        wireHeartbeat();
        wireDisconnect();
        wireAjaxForms();
        wirePanels();
        wireSparklines();
        wireShortcodePicker();
        wireFormPicker();
        wireVisibility();
        wireClientMode();
        wireClientManage();
        wirePortalSupport();
        let rt;
        window.addEventListener('resize', () => {
            clearTimeout(rt);
            rt = setTimeout(wireSparklines, 150);
        });
    });
})();
