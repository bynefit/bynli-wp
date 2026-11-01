const fs = require('fs');
const path = require('path');

function el(opts) {
    opts = opts || {};
    const handlers = {};
    const classes = new Set((opts.className || '').split(' ').filter(Boolean));
    const self = {
        attrs: Object.assign({}, opts.attrs || {}),
        dataset: Object.assign({}, opts.dataset || {}),
        hidden: !!opts.hidden,
        textContent: opts.text || '',
        children: [],
        subs: opts.subs || {},
        lists: opts.lists || {},
        focused: false,
        tabIndex: 0,
        disabled: false,
        innerHTML: '',
        classList: {
            add(c) { classes.add(c); },
            remove(c) { classes.delete(c); },
            contains(c) { return classes.has(c); },
            toggle(c, on) { if (on) { classes.add(c); } else { classes.delete(c); } },
        },
        get className() { return Array.from(classes).join(' '); },
        set className(v) { classes.clear(); String(v).split(' ').filter(Boolean).forEach((c) => classes.add(c)); },
        setAttribute(k, v) { self.attrs[k] = String(v); },
        getAttribute(k) { return k in self.attrs ? self.attrs[k] : null; },
        removeAttribute(k) { delete self.attrs[k]; },
        hasAttribute(k) { return k in self.attrs; },
        addEventListener(type, fn) { (handlers[type] = handlers[type] || []).push(fn); },
        removeEventListener(type, fn) { handlers[type] = (handlers[type] || []).filter((h) => h !== fn); },
        fire(type, ev) { (handlers[type] || []).slice().forEach((fn) => fn(ev || {})); },
        listenerCount(type) { return (handlers[type] || []).length; },
        querySelector(sel) { return self.subs[sel] || null; },
        querySelectorAll(sel) { return self.lists[sel] || []; },
        appendChild(c) { self.children.push(c); if (c.isTick) { self.tick = true; } },
        focus() { self.focused = true; },
        get offsetWidth() { return 1; },
    };
    return self;
}

const sr = (cls) => el({ className: cls });
function step(name, next) {
    const title = el({ className: 'bcn-onramp-title', text: name });
    const n = el({ className: 'bcn-onramp-n', text: '2' });
    const s = el({ className: 'bcn-onramp-step' + (next ? ' is-next' : ''), attrs: next ? { 'aria-current': 'step' } : {}, subs: { '.bcn-onramp-n': n, '.bcn-onramp-title': title } });
    s.title = title;
    s.mark = n;
    return s;
}

const steps = [step('Save your key', false), step('Send a test heartbeat', true), step('First daily report', false)];
steps[0].classList.add('is-done');
const onrampList = el({ lists: { '.bcn-onramp-step': steps } });
const cta = el({ attrs: { 'data-go': 'connection' } });

const dot = el();
const status = el({ text: 'NOT VERIFIED' });
const sub = el({ text: 'Key saved' });
const moment = el({ hidden: true });
const hero = el({
    className: 'bcn-hero',
    attrs: { 'data-state': 'off' },
    subs: { '[data-bcn="uplink-status"]': status, '[data-bcn="uplink-sub"]': sub, '[data-bcn="uplink-moment"]': moment },
});
const lastVal = el({ text: 'never' });
const lastTile = el({ attrs: { 'data-state': 'warn' }, subs: { '.bcn-tile-value': lastVal } });
const tileVal = el({ text: 'Unverified' });
const tileIco = el({ className: 'dashicons dashicons-warning' });
const tile = el({ attrs: { 'data-state': 'warn' }, subs: { '.bcn-tile-value': tileVal, '.dashicons': tileIco } });
const pillLabel = el({ text: 'Not verified' });
const pill = el({ attrs: { 'data-state': 'off' }, subs: { '.bcn-signal-label': pillLabel } });

const seeUplink = el({ hidden: true, className: 'bcn-note-link', attrs: { 'data-go': 'overview', href: '/x' } });
const note = el({ subs: { '[data-role="see-uplink"]': seeUplink, '[data-role="ico"]': el(), '[data-role="msg"]': el() } });
const btn = el({ attrs: {} });
btn.innerHTML = 'Send';

const overviewPanel = el({ className: 'bcn-panel', attrs: { 'data-panel': 'overview', hidden: '' } });
const connectionPanel = el({ className: 'bcn-panel active', attrs: { 'data-panel': 'connection' } });
const panels = [overviewPanel, connectionPanel];
const navLink = el({ className: 'bcn-nav-item', attrs: { 'data-go': 'overview' } });

const singles = {
    '#bcn-heartbeat-btn': btn,
    '#bcn-heartbeat-status': note,
    '[data-bcn="signal"]': pill,
    '[data-bcn="uplink-hero"]': hero,
    '[data-bcn="tile-connection"]': tile,
    '[data-bcn="tile-last"]': lastTile,
    '[data-bcn="onramp"]': onrampList,
    '[data-bcn="onramp-cta"]': cta,
    '.bcn-rail': el(),
    '.bcn-panel.active': null,
    '[data-bcn="uplink-hero"].is-first-connect': null,
};
const lists = {
    '.bcn-panel': panels,
    '.bcn-nav-item': [navLink],
    'a[data-go]': [navLink, seeUplink, cta],
};

global.document = {
    readyState: 'complete',
    getElementById(id) { return singles['#' + id] || null; },
    querySelector(sel) {
        if (sel === '.bcn-panel.active') { return panels.find((p) => p.classList.contains('active')) || null; }
        if (sel === '[data-bcn="uplink-hero"].is-first-connect') { return hero.classList.contains('is-first-connect') ? hero : null; }
        return singles[sel] || null;
    },
    querySelectorAll(sel) { return lists[sel] || []; },
    createElement() { const e = el(); return e; },
    addEventListener() {},
};
let scenarioIdx = 0;
const responses = JSON.parse(process.argv[2] || '[]');
global.window = {
    BynliConnect: { ajaxUrl: '/ajax', nonce: 'n' },
    matchMedia() { return { matches: false }; },
    addEventListener() {},
    history: { pushState() {} },
    location: { origin: 'http://x', href: 'http://x/?section=connection' },
    performance: { now: () => Date.now() },
};
global.FormData = class { append() {} };
global.fetch = function () {
    const body = responses[scenarioIdx++];
    return Promise.resolve({ json: () => Promise.resolve(body) });
};
global.URL = URL;

const origCreate = global.document.createElement;
global.document.createElement = function (tag) {
    const e = origCreate();
    if (tag === 'span') { e.isTick = true; }
    return e;
};

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'admin.js'), 'utf8');
new Function(src)();

(async () => {
    const out = {};
    const click = async () => {
        btn.fire('click', { preventDefault() {} });
        await new Promise((r) => setTimeout(r, 20));
    };
    await click();
    out.afterFirst = {
        heroState: hero.getAttribute('data-state'),
        status: status.textContent,
        sub: sub.textContent,
        momentHidden: moment.hidden,
        celebrating: hero.classList.contains('is-first-connect'),
        tile: tileVal.textContent,
        tileState: tile.getAttribute('data-state'),
        lastTile: lastVal.textContent,
        lastTileState: lastTile.getAttribute('data-state'),
        pill: pillLabel.textContent,
        linkHidden: seeUplink.hidden,
        ctaHidden: cta.hidden,
        stepTwoDone: steps[1].classList.contains('is-done'),
        stepTwoCurrent: steps[1].hasAttribute('aria-current'),
        stepThreeCurrent: steps[2].getAttribute('aria-current'),
        stepThreeNext: steps[2].classList.contains('is-next'),
        stepTwoSr: steps[1].title.children.length,
    };
    hero.fire('animationend', { animationName: 'bcn-uplink-wash' });
    out.afterOtherAnimation = hero.classList.contains('is-first-connect');
    hero.fire('animationend', { animationName: 'bcn-uplink-ping' });
    out.afterPing = hero.classList.contains('is-first-connect');
    out.listenersLeft = hero.listenerCount('animationend');

    await click();
    out.afterSecond = {
        celebrating: hero.classList.contains('is-first-connect'),
        linkHidden: seeUplink.hidden,
    };

    seeUplink.fire('click', { preventDefault() {} });
    out.afterLink = {
        overviewActive: overviewPanel.classList.contains('active'),
        connectionActive: connectionPanel.classList.contains('active'),
        overviewFocused: overviewPanel.focused,
        navFocused: navLink.focused,
    };
    overviewPanel.focused = false;
    navLink.fire('click', { preventDefault() {} });
    out.afterNav = { overviewFocused: overviewPanel.focused };
    console.log(JSON.stringify(out));
})();
