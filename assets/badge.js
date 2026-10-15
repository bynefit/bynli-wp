(function () {
    'use strict';

    var HOLD_MS = 4000;
    var LIFT_MAX = 400;

    function init(bar) {
        var holdTimer = null;
        var lastPointer = '';

        var lift = parseInt(bar.getAttribute('data-lift'), 10);
        if (lift > 0 && lift <= LIFT_MAX) {
            bar.style.setProperty('--bnf-pb-lift', lift + 'px');
        }

        function outsideTap(ev) {
            if (ev.target && bar.contains(ev.target)) { return; }
            closeHeld();
        }

        function closeHeld() {
            if (holdTimer) { clearTimeout(holdTimer); holdTimer = null; }
            bar.classList.remove('bnf-pb-open');
            document.removeEventListener('pointerdown', outsideTap, true);
        }

        function openHeld() {
            bar.classList.add('bnf-pb-open');
            if (holdTimer) { clearTimeout(holdTimer); }
            holdTimer = setTimeout(closeHeld, HOLD_MS);
            document.addEventListener('pointerdown', outsideTap, true);
        }

        function forgetPointer() { lastPointer = ''; }

        bar.addEventListener('pointerdown', function (ev) {
            lastPointer = ev && ev.pointerType ? ev.pointerType : 'touch';
            if (lastPointer === 'touch') {
                bar.classList.add('bnf-pb-touch');
            } else {
                bar.classList.remove('bnf-pb-touch');
            }
        });
        bar.addEventListener('touchstart', function () {
            lastPointer = 'touch';
            bar.classList.add('bnf-pb-touch');
        });
        bar.addEventListener('pointercancel', forgetPointer);
        bar.addEventListener('touchcancel', forgetPointer);
        bar.addEventListener('blur', forgetPointer);
        bar.addEventListener('click', function (ev) {
            var viaTouch = lastPointer === 'touch' && ev.detail !== 0;
            lastPointer = '';
            if (!viaTouch || bar.classList.contains('bnf-pb-open')) { return; }
            ev.preventDefault();
            openHeld();
        });

        function avoidOverlap() {
            var r = bar.getBoundingClientRect();
            if (!r.width || !r.height) { return; }
            var stack = document.elementsFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            var base = parseInt(bar.getAttribute('data-lift'), 10) || 0;
            for (var i = 0; i < stack.length; i++) {
                var el = stack[i];
                if (el === bar || bar.contains(el) || el === document.body || el === document.documentElement) { continue; }
                if (window.getComputedStyle(el).position !== 'fixed') { continue; }
                var extra = Math.ceil(el.getBoundingClientRect().height) + 8;
                if (extra > 0 && base + extra <= LIFT_MAX) {
                    bar.style.setProperty('--bnf-pb-lift', (base + extra) + 'px');
                }
                return;
            }
        }

        function settle() {
            var link = document.getElementById('bynli-connect-badge-css');
            if ((!link || !link.sheet) && bar.parentNode) {
                bar.parentNode.removeChild(bar);
                return;
            }
            avoidOverlap();
        }

        if (document.readyState === 'complete') {
            settle();
        } else {
            window.addEventListener('load', settle);
        }
    }

    function start() {
        var bar = document.getElementById('bynli-connect-badge');
        if (bar) { init(bar); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
