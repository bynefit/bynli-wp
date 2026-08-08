(function () {
    var vids = document.querySelectorAll('video[data-bynefit-bgvideo]');
    if (!vids.length) {
        return;
    }
    var motionOk = !window.matchMedia || window.matchMedia('(prefers-reduced-motion: no-preference)').matches;

    Array.prototype.forEach.call(vids, function (v) {
        var shell = v.closest ? v.closest('.bynefit-section-shell') : null;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bynefit-bgvideo-toggle';

        function sync() {
            var paused = v.paused;
            btn.dataset.state = paused ? 'paused' : 'playing';
            btn.setAttribute('aria-label', paused ? 'Play background video' : 'Pause background video');
        }

        btn.addEventListener('click', function () {
            if (v.paused) {
                var p = v.play();
                if (p && p.catch) { p.catch(function () {}); }
            } else {
                v.pause();
            }
            sync();
        });

        if (shell) {
            shell.appendChild(btn);
        }

        if (motionOk) {
            var pr = v.play();
            if (pr && pr.catch) { pr.catch(function () {}); }
        }
        sync();
    });
})();

(function () {
    var roots = document.querySelectorAll('[data-bynefit-tabs]');
    Array.prototype.forEach.call(roots, function (root) {
        var tabs = root.querySelectorAll('.bynefit-tabs__tab');
        var panels = root.querySelectorAll('.bynefit-tabs__panel');
        if (!tabs.length || tabs.length !== panels.length) { return; }
        root.classList.add('is-enhanced');

        function select(idx) {
            for (var i = 0; i < tabs.length; i++) {
                var on = i === idx;
                tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
                tabs[i].setAttribute('tabindex', on ? '0' : '-1');
                if (on) { panels[i].removeAttribute('hidden'); } else { panels[i].setAttribute('hidden', ''); }
            }
        }

        Array.prototype.forEach.call(tabs, function (tab, i) {
            tab.addEventListener('click', function () { select(i); tab.focus(); });
            tab.addEventListener('keydown', function (e) {
                var n = null;
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { n = (i + 1) % tabs.length; }
                else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { n = (i - 1 + tabs.length) % tabs.length; }
                else if (e.key === 'Home') { n = 0; }
                else if (e.key === 'End') { n = tabs.length - 1; }
                if (n !== null) { e.preventDefault(); select(n); tabs[n].focus(); }
            });
        });
        select(0);
    });
})();

(function () {
    var roots = document.querySelectorAll('[data-bynefit-carousel]');
    var motionOk = !window.matchMedia || window.matchMedia('(prefers-reduced-motion: no-preference)').matches;
    Array.prototype.forEach.call(roots, function (root) {
        var slides = root.querySelectorAll('.bynefit-carousel__slide');
        if (slides.length < 2) { return; }
        var dots = root.querySelectorAll('.bynefit-carousel__dot');
        var prev = root.querySelector('.bynefit-carousel__prev');
        var next = root.querySelector('.bynefit-carousel__next');
        var track = root.querySelector('.bynefit-carousel__track');
        var toggle = root.querySelector('.bynefit-carousel__toggle');
        var autoplay = motionOk && root.getAttribute('data-autoplay') === '1';
        var idx = 0, timer = null, userPaused = false;
        root.classList.add('is-enhanced');
        if (toggle && !autoplay) { toggle.hidden = true; }

        function show(n) {
            idx = (n + slides.length) % slides.length;
            for (var i = 0; i < slides.length; i++) {
                var on = i === idx;
                if (on) { slides[i].removeAttribute('hidden'); } else { slides[i].setAttribute('hidden', ''); }
                if (dots[i]) { dots[i].setAttribute('aria-current', on ? 'true' : 'false'); }
            }
        }
        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
            if (track) { track.setAttribute('aria-live', 'polite'); }
        }
        function start() {
            if (!autoplay || userPaused || timer) { return; }
            timer = setInterval(function () { show(idx + 1); }, 6000);
            if (track) { track.setAttribute('aria-live', 'off'); }
        }

        if (prev) { prev.addEventListener('click', function () { show(idx - 1); stop(); start(); }); }
        if (next) { next.addEventListener('click', function () { show(idx + 1); stop(); start(); }); }
        Array.prototype.forEach.call(dots, function (d, i) { d.addEventListener('click', function () { show(i); stop(); start(); }); });

        show(0);
        if (autoplay) {
            if (toggle) {
                toggle.addEventListener('click', function () {
                    if (timer) {
                        userPaused = true; stop();
                        toggle.setAttribute('aria-pressed', 'true');
                        toggle.setAttribute('aria-label', 'Play the carousel');
                    } else {
                        userPaused = false; start();
                        toggle.setAttribute('aria-pressed', 'false');
                        toggle.setAttribute('aria-label', 'Pause the carousel');
                    }
                });
            }
            start();
            root.addEventListener('mouseenter', stop);
            root.addEventListener('mouseleave', start);
            root.addEventListener('focusin', stop);
            root.addEventListener('focusout', function (e) {
                if (!root.contains(e.relatedTarget)) { start(); }
            });
        }
    });
})();
