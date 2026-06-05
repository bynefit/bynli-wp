(function () {
    const ready = (fn) => (document.readyState !== 'loading')
        ? fn()
        : document.addEventListener('DOMContentLoaded', fn);

    function flash(btn, label) {
        const orig = btn.textContent;
        btn.textContent = label;
        btn.classList.add('bcn-copied');
        setTimeout(() => { btn.textContent = orig; btn.classList.remove('bcn-copied'); }, 1400);
    }

    async function copyText(text, btn) {
        try {
            await navigator.clipboard.writeText(text);
            flash(btn, 'Copied');
        } catch (e) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            flash(btn, 'Copied');
        }
    }

    ready(() => {
        document.querySelectorAll('.bcn-toggle-reveal').forEach((btn) => {
            const targetId = btn.getAttribute('data-target');
            if (!targetId) return;
            const input = document.getElementById(targetId);
            if (!input) return;
            btn.addEventListener('click', () => {
                const shown = input.type === 'text';
                input.type = shown ? 'password' : 'text';
                const icon = btn.querySelector('.dashicons');
                if (icon) {
                    icon.classList.toggle('dashicons-visibility', shown);
                    icon.classList.toggle('dashicons-hidden',     !shown);
                }
                btn.setAttribute('aria-label', shown ? 'Show key' : 'Hide key');
            });
        });

        document.querySelectorAll('.bcn-copy').forEach((btn) => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                const literal  = btn.getAttribute('data-text');
                if (literal !== null) { copyText(literal, btn); return; }
                if (!targetId) return;
                const input = document.getElementById(targetId);
                if (input) copyText(input.value || input.textContent || '', btn);
            });
        });

        const keyInput = document.getElementById('bcn_key');
        const keyValidity = document.getElementById('bcn-key-validity');
        if (keyInput && keyValidity) {
            const re = /^bynli_sh_[0-9a-f]{32}$/;
            const validate = () => {
                const v = keyInput.value.trim();
                if (v === '') {
                    keyValidity.textContent = '';
                    keyValidity.classList.remove('bcn-v-ok', 'bcn-v-err');
                    return;
                }
                if (re.test(v)) {
                    keyValidity.textContent = 'Format looks valid';
                    keyValidity.classList.add('bcn-v-ok');
                    keyValidity.classList.remove('bcn-v-err');
                } else {
                    keyValidity.textContent = 'Expected: bynli_sh_ + 32 hex characters';
                    keyValidity.classList.add('bcn-v-err');
                    keyValidity.classList.remove('bcn-v-ok');
                }
            };
            keyInput.addEventListener('input', validate);
            validate();
        }
    });
})();
