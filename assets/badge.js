(function () {
    'use strict';

    var HOLD_MS = 4000;
    var LIFT_MAX = 400;
    var SHEET_TIMEOUT_MS = 8000;
    var RECHECK_MS = [1500, 4000];

    function init(bar) {
        var holdTimer = null;
        var lastPointer = '';
        var base = Math.max(0, Math.min(LIFT_MAX, parseInt(bar.getAttribute('data-lift'), 10) || 0));

        function setLift(px) {
            if (px > 0) {
                bar.style.setProperty('--bnf-pb-lift', px + 'px');
            } else {
                bar.style.removeProperty('--bnf-pb-lift');
            }
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

        function blockingHeight() {
            var r = bar.getBoundingClientRect();
            if (!r.width || !r.height) { return 0; }
            var xs = [r.left + 1, r.left + r.width / 2, r.right - 1];
            var ys = [r.top + 1, r.top + r.height / 2, r.bottom - 1];
            var tallest = 0;
            for (var a = 0; a < xs.length; a++) {
                for (var b = 0; b < ys.length; b++) {
                    var stack = document.elementsFromPoint(xs[a], ys[b]);
                    for (var i = 0; i < stack.length; i++) {
                        var el = stack[i];
                        if (el === bar || bar.contains(el) || el === document.body || el === document.documentElement) { continue; }
                        if (window.getComputedStyle(el).position !== 'fixed') { continue; }
                        var h = Math.ceil(el.getBoundingClientRect().height) + 8;
                        if (base + h > LIFT_MAX) { continue; }
                        if (h > tallest) { tallest = h; }
                        break;
                    }
                }
            }
            return tallest;
        }

        function avoidOverlap() {
            setLift(base);
            var h = blockingHeight();
            if (h > 0) { setLift(base + h); }
        }

        setLift(base);
        avoidOverlap();
        for (var i = 0; i < RECHECK_MS.length; i++) {
            setTimeout(avoidOverlap, RECHECK_MS[i]);
        }
        window.addEventListener('resize', avoidOverlap);
        window.addEventListener('orientationchange', avoidOverlap);
    }

    function start() {
        var tpl = document.getElementById('bynli-connect-badge-tpl');
        if (!tpl || !tpl.content) { return; }
        var href = tpl.getAttribute('data-style');
        if (!href) { return; }

        var done = false;
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.id = 'bynli-connect-badge-css';
        link.href = href;

        function reveal() {
            if (done) { return; }
            done = true;
            clearTimeout(timer);
            document.body.appendChild(tpl.content.cloneNode(true));
            var bar = document.getElementById('bynli-connect-badge');
            if (bar) { init(bar); }
        }

        function giveUp() {
            if (done) { return; }
            done = true;
            if (link.parentNode) { link.parentNode.removeChild(link); }
        }

        var timer = setTimeout(giveUp, SHEET_TIMEOUT_MS);
        link.addEventListener('load', reveal);
        link.addEventListener('error', function () { clearTimeout(timer); giveUp(); });
        document.head.appendChild(link);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
