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
