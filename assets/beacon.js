(function () {
    'use strict';

    var cfg = window.BynliConnectBeacon;
    if (!cfg || typeof cfg.endpoint !== 'string' || typeof cfg.slug !== 'string') { return; }
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true) { return; }
    if (!/^[a-z0-9-]{3,100}$/.test(cfg.slug)) { return; }

    var ID = /^[a-z0-9][a-z0-9_-]{0,39}$/;
    var path = (window.location.pathname || '/').slice(0, 191);

    function send(url) {
        try { new Image().src = url; } catch (e) {}
    }

    function base(endpoint) {
        return endpoint + '?slug=' + encodeURIComponent(cfg.slug) + '&path=' + encodeURIComponent(path);
    }

    send(base(cfg.endpoint));

    var eventEndpoint = typeof cfg.eventEndpoint === 'string' ? cfg.eventEndpoint : '';
    if (!eventEndpoint) { return; }

    function event(kind, name, target) {
        if (!ID.test(name)) { return; }
        var url = base(eventEndpoint) + '&e=' + kind + '&n=' + encodeURIComponent(name);
        if (target && ID.test(target)) { url += '&t=' + encodeURIComponent(target); }
        send(url);
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        var cta = t.closest('[data-beacon]');
        if (cta) { event('cta', cta.getAttribute('data-beacon') || ''); }
        var act = t.closest('[data-beacon-action]');
        if (act) { event('action', act.getAttribute('data-beacon-action') || '', act.getAttribute('data-beacon-target') || ''); }
    }, true);

    document.addEventListener('submit', function (e) {
        var f = e.target && e.target.getAttribute ? e.target.getAttribute('data-beacon-form') : '';
        if (f) { event('form', f); }
    }, true);

    if (!window.bynefitBeacon) {
        window.bynefitBeacon = {
            action: function (name, target) { event('action', String(name || ''), target ? String(target) : ''); },
            cta: function (name) { event('cta', String(name || '')); }
        };
    }
})();
