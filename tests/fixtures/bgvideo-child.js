const fs = require('fs');
const path = require('path');

function node(classes) {
    return {
        classes: classes,
        children: [],
        parent: null,
        paused: true,
        dataset: {},
        attrs: {},
        appendChild(child) {
            child.parent = this;
            this.children.push(child);
        },
        closest(selector) {
            const wanted = selector.split(',').map((s) => s.trim().replace(/^\./, ''));
            for (let n = this.parent; n; n = n.parent) {
                if (wanted.some((w) => n.classes.indexOf(w) !== -1)) {
                    return n;
                }
            }
            return null;
        },
        setAttribute(k, v) {
            this.attrs[k] = v;
        },
        addEventListener() {},
        play() {
            this.paused = false;
            return Promise.resolve();
        },
        pause() {
            this.paused = true;
        },
    };
}

const shellClass = process.argv[2];
const shell = node([shellClass]);
const video = node([]);
shell.appendChild(video);

global.document = {
    querySelectorAll(sel) {
        return sel === 'video[data-bynefit-bgvideo]' ? [video] : [];
    },
    createElement() {
        return node([]);
    },
};
global.window = {
    matchMedia() {
        return { matches: process.argv[3] === 'motion' };
    },
};

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'blocks.js'), 'utf8');
new Function(src)();

const toggle = shell.children.find((c) => c !== video);
console.log(JSON.stringify({
    hasToggle: !!toggle,
    toggleClass: toggle ? toggle.className : null,
    label: toggle ? toggle.attrs['aria-label'] : null,
    playing: !video.paused,
}));
