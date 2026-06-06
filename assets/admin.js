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
            if (v === '') {
                out.textContent = '';
                out.removeAttribute('data-state');
                return;
            }
            if (re.test(v)) {
                out.textContent = 'Format looks valid';
                out.setAttribute('data-state', 'ok');
            } else {
                out.textContent = 'Expected: bynli_sh_ + 32 hex characters';
                out.setAttribute('data-state', 'err');
            }
        };
        input.addEventListener('input', validate);
        validate();
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
            btn.innerHTML = '<span class="dashicons dashicons-update"></span> Sending…';
            if (statusEl) {
                statusEl.textContent = '';
                statusEl.setAttribute('data-state', 'run');
            }

            const fd = new FormData();
            fd.append('action', 'bynli_connect_heartbeat');
            fd.append('_wpnonce', cfg.nonce);

            let body;
            try {
                const res = await fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd,
                });
                body = await res.json();
            } catch (err) {
                body = { success: false, data: { message: 'Network error.' } };
            }

            btn.removeAttribute('aria-busy');
            btn.disabled = false;
            btn.innerHTML = origLabel;

            if (body && body.success) {
                if (statusEl) {
                    statusEl.textContent = body.data && body.data.message
                        ? body.data.message
                        : 'Heartbeat OK.';
                    statusEl.setAttribute('data-state', 'ok');
                }
                if (body.data && body.data.last_at_human) {
                    const lastVal = document.querySelector('[data-bcn="last-report"]');
                    if (lastVal) {
                        lastVal.textContent = body.data.last_at_human;
                        lastVal.setAttribute('data-state', 'ok');
                    }
                }
                const pill = document.querySelector('[data-bcn="status-pill"]');
                if (pill) {
                    pill.setAttribute('data-state', 'on');
                    const lbl = pill.querySelector('.bcn-status-label');
                    if (lbl) lbl.textContent = 'Connected';
                }
            } else {
                if (statusEl) {
                    statusEl.textContent = (body && body.data && body.data.message)
                        ? body.data.message
                        : 'Heartbeat failed.';
                    statusEl.setAttribute('data-state', 'err');
                }
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

    function wireAjaxForms() {
        const forms = document.querySelectorAll('form.bcn-ajax-form');
        if (!forms.length) return;
        if (!cfg || !cfg.ajaxUrl) return;

        forms.forEach((form) => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const action = form.getAttribute('data-bcn-action') || '';
                if (!action) return;

                const fb = form.querySelector('[data-role="feedback"]');
                const submit = form.querySelector('[data-role="submit"]');
                const origLabel = submit ? submit.textContent : '';

                if (fb) {
                    fb.hidden = true;
                    fb.textContent = '';
                    fb.className = 'bcn-form-feedback';
                }
                if (submit) {
                    submit.disabled = true;
                    submit.textContent = 'Sending…';
                }

                const fd = new FormData(form);
                fd.append('action', action);

                let res, body;
                try {
                    res = await fetch(cfg.ajaxUrl, {
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
                    if (submit) {
                        submit.disabled = false;
                        submit.textContent = origLabel;
                    }
                    return;
                }

                if (body && body.success) {
                    const detailUrl = body.data && body.data.detail_url;
                    if (detailUrl) {
                        window.location.href = detailUrl;
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
                        const inputName = ({ subject: 'ticket_subject', body: 'ticket_body' })[field];
                        if (inputName) {
                            const el = form.querySelector('[name="' + inputName + '"]');
                            if (el && typeof el.focus === 'function') el.focus();
                        }
                    }
                }

                if (submit) {
                    submit.disabled = false;
                    submit.textContent = origLabel;
                }
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
    });
})();
