(function () {
    'use strict';

    var cfg = window.BynliConnectBeacon;
    if (!cfg || typeof cfg.endpoint !== 'string' || typeof cfg.slug !== 'string') { return; }
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true) { return; }
    if (!/^[a-z0-9-]{3,100}$/.test(cfg.slug)) { return; }

    var path = (window.location.pathname || '/').slice(0, 191);
    var url = cfg.endpoint + '?slug=' + encodeURIComponent(cfg.slug) + '&path=' + encodeURIComponent(path);

    try { new Image().src = url; } catch (e) {}
})();
