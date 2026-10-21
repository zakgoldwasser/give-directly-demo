import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import { initFeedback, setBusy } from '../../resources/js/modules/feedback.js';
import { initUpload } from '../../resources/js/modules/upload.js';

// A small DOM adapter keeps event/state tests independent of a browser or test dependency.
class Element extends EventTarget {
    attributes = new Map();
    childNodes = [];
    dataset = {};
    textContent = '';
    target = '';
    classList = { add() {}, remove() {} };
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); }
    replaceChildren(...children) { this.childNodes = children; }
    prepend(child) { this.childNodes.unshift(child); }
}

let timers;
beforeEach(() => {
    globalThis.window = new EventTarget();
    window.location = new URL('https://demo.test/try');
    timers = new Map();
    window.setTimeout = (callback) => { timers.set(callback, callback); return callback; };
    window.clearTimeout = (timer) => timers.delete(timer);
    globalThis.document = { createElement: () => new Element(), querySelector: () => null, querySelectorAll: () => [] };
});

function setup({ links = [], forms = [], controls = [] } = {}) {
    const status = new Element();
    initFeedback({
        querySelector: () => status,
        querySelectorAll: (selector) => {
            if (selector === '[data-busy-form]') return forms;
            if (selector.startsWith('a[href]')) return links;
            return controls;
        },
    });
    return status;
}

function click(link, properties = {}) {
    const event = new Event('click', { cancelable: true });
    Object.assign(event, { button: 0, ...properties });
    link.dispatchEvent(event);
    return event;
}

function makeLink(href) {
    const link = new Element();
    link.href = href;
    link.replaceChildren(new Element());
    return link;
}

test('native submissions proceed once and restore controls and their original contents on Back', () => {
    const button = new Element();
    button.disabled = false;
    const originalLabel = new Element();
    button.replaceChildren(originalLabel);
    const alreadyDisabled = new Element();
    alreadyDisabled.disabled = true;
    const form = new Element();
    form.dataset.busyForm = 'Confirming match…';
    const status = setup({ forms: [form], controls: [button, alreadyDisabled] });
    const submit = () => {
        const event = new Event('submit', { cancelable: true });
        event.submitter = button;
        form.dispatchEvent(event);
        return event;
    };

    assert.equal(submit().defaultPrevented, false);
    assert.equal(button.disabled, true);
    assert.equal(button.getAttribute('aria-busy'), 'true');
    assert.equal(status.textContent, 'Confirming match…');
    assert.equal(submit().defaultPrevented, true);
    window.dispatchEvent(new Event('pageshow'));
    assert.equal(button.disabled, false);
    assert.equal(alreadyDisabled.disabled, true);
    assert.equal(button.childNodes[0], originalLabel);
    assert.equal(button.getAttribute('aria-busy'), null);
    assert.equal(submit().defaultPrevented, false);
    window.dispatchEvent(new Event('pageshow'));
});

test('cancelled submissions do not lock the page', () => {
    const form = new Element();
    form.addEventListener('submit', (event) => event.preventDefault());
    const status = setup({ forms: [form] });
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    assert.equal(status.textContent, '');
});

test('navigation shows opening feedback, preserves native navigation, and blocks repeat clicks', () => {
    const link = makeLink('https://demo.test/try/history');
    const status = setup({ links: [link] });
    assert.equal(click(link).defaultPrevented, false);
    assert.equal(status.textContent, 'Opening…');
    assert.equal(link.getAttribute('aria-busy'), 'true');
    assert.equal(click(link).defaultPrevented, true);
    window.dispatchEvent(new Event('pageshow'));
    assert.equal(link.getAttribute('aria-disabled'), null);
});

test('anchors, external destinations, new tabs, and modified clicks keep their normal behavior', () => {
    const anchor = makeLink('https://demo.test/try#reports');
    const external = makeLink('https://example.test/');
    const newTab = makeLink('https://demo.test/try/history');
    newTab.target = '_blank';
    const modified = makeLink('https://demo.test/try/history');
    setup({ links: [anchor, external, newTab, modified] });
    for (const link of [anchor, external, newTab]) {
        assert.equal(click(link).defaultPrevented, false);
        assert.equal(link.getAttribute('aria-busy'), null);
    }
    for (const properties of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { button: 1 }]) {
        assert.equal(click(modified, properties).defaultPrevented, false);
        assert.equal(modified.getAttribute('aria-busy'), null);
    }
});

test('downloads get temporary requested feedback without a navigation spinner or completion claim', () => {
    const link = makeLink('https://demo.test/fixtures/reference/donations');
    link.setAttribute('data-download-feedback', '');
    const originalChildren = [...link.childNodes];
    const status = setup({ links: [link] });
    assert.equal(click(link).defaultPrevented, false);
    assert.equal(link.getAttribute('aria-busy'), 'false');
    assert.equal(link.childNodes.length, 1);
    assert.equal(link.childNodes[0].textContent, 'Download requested');
    assert.match(status.textContent, /requested/);
    for (const callback of timers.values()) callback();
    assert.deepEqual(link.childNodes, originalChildren);
    assert.equal(link.getAttribute('aria-disabled'), null);
});

test('changing an upload from uploading to opening preserves its original state for recovery', () => {
    const button = new Element();
    button.disabled = false;
    button.setAttribute('aria-label', 'Load report');
    const originalLabel = new Element();
    button.replaceChildren(originalLabel);
    const restore = setBusy(button, 'Uploading…');
    setBusy(button, 'Opening import…');
    assert.equal(button.disabled, true);
    assert.equal(button.getAttribute('aria-label'), 'Opening import…');
    restore();
    assert.equal(button.disabled, false);
    assert.equal(button.getAttribute('aria-label'), 'Load report');
    assert.equal(button.childNodes[0], originalLabel);
});

function uploadFixture() {
    const elements = new Map(['drop-zone', 'file-input', 'file-label', 'upload-error', 'upload-progress', 'upload-submit']
        .map((key) => [`[data-${key}]`, new Element()]));
    const button = elements.get('[data-upload-submit]');
    button.disabled = false;
    elements.get('[data-file-input]').files = [{ name: 'donations.csv', size: 20 }];
    const form = new Element();
    form.dataset.uploadUrl = 'https://demo.test/try/imports';
    form.querySelector = (selector) => elements.get(selector);
    globalThis.FormData = class { constructor(source) { this.source = source; } };
    initUpload({ querySelectorAll: () => [form] });
    setup();
    return { form, button, error: elements.get('[data-upload-error]') };
}

for (const status of [200, 409]) {
    test(`upload response ${status} keeps the button busy through navigation and rejects resubmission`, async () => {
        const { form, button } = uploadFixture();
        let requests = 0;
        const destinations = [];
        window.location.assign = (url) => destinations.push(url);
        globalThis.fetch = async () => {
            requests++;
            return { status, ok: status === 200, json: async () => ({ data: { redirect: '/try/imports/1' } }) };
        };
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await new Promise(setImmediate);
        assert.deepEqual(destinations, ['/try/imports/1']);
        assert.equal(button.disabled, true);
        assert.equal(button.getAttribute('aria-label'), 'Opening import…');
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        assert.equal(requests, 1);
        window.dispatchEvent(new Event('pageshow'));
        assert.equal(button.disabled, false);
    });
}

test('upload failure restores the button and permits a retry', async () => {
    const { form, button, error } = uploadFixture();
    const sampleButton = new Element();
    sampleButton.disabled = false;
    const otherUploadButton = new Element();
    otherUploadButton.disabled = false;
    document.querySelectorAll = () => [button, sampleButton, otherUploadButton];
    let requests = 0;
    globalThis.fetch = async () => {
        requests++;
        return { status: 422, ok: false, json: async () => ({ error: { message: 'Invalid CSV' } }) };
    };
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    assert.equal(sampleButton.disabled, true);
    assert.equal(otherUploadButton.disabled, true);
    await new Promise(setImmediate);
    assert.equal(button.disabled, false);
    assert.equal(sampleButton.disabled, false);
    assert.equal(otherUploadButton.disabled, false);
    assert.equal(error.textContent, 'Invalid CSV');
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await new Promise(setImmediate);
    assert.equal(requests, 2);
});
