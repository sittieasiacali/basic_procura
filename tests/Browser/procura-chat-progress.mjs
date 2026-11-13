import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const root = new URL('../../', import.meta.url);
const globalSource = readFileSync(new URL('resources/js/procurement-revisions.js', root), 'utf8').split('// Refresh the exact unread count')[0];
const chatSource = readFileSync(new URL('resources/js/procura-chat.js', root), 'utf8');
const apiSource = chatSource.slice(chatSource.indexOf('    async function api('), chatSource.indexOf('    const post ='));
const events = new Map();
const elements = new Map();
let progress;
const pending = [];
const context = {
    Headers, Request, Date, queueMicrotask,
    setInterval() { return 1; }, clearInterval() {},
    addEventListener(type, callback) { events.set(type, callback); },
    document: {
        body: { append(element) { progress = element; } },
        createElement() {
            return {
                hidden: true, style: {}, setAttribute() {},
                querySelector(selector) {
                    if (!elements.has(selector)) elements.set(selector, { textContent: '' });
                    return elements.get(selector);
                },
            };
        },
        addEventListener(type, callback) { events.set(type, callback); },
    },
    fetch(...args) { return new Promise((resolve, reject) => pending.push({ args, resolve, reject })); },
    csrf: 'test-token', stopConversation() {},
};
context.window = context;
vm.createContext(context);
vm.runInContext(globalSource, context);
const api = vm.runInContext(apiSource + '\napi;', context);
const complete = () => pending.shift().resolve({ ok: true, json: async () => ({ read: true }) });

// Exercise the actual chat API through the shared global fetch wrapper.
for (let poll = 0; poll < 5; poll++) {
    const reading = api('/chat-data/42/read', { method: 'POST', body: '{}' });
    assert.equal(pending[0].args[1].headers['X-Procura-Progress'], 'silent');
    assert.equal(progress.hidden, true, 'Read polling must never flash the saving overlay');
    complete(); await reading;
}
const sending = api('/chat-data/42/messages', { method: 'POST', body: 'message' });
assert.equal(progress.hidden, true);
complete(); await sending;

// Other forms retain their loading feedback, even when a silent chat request overlaps.
const normal = context.fetch('/requests/save', { method: 'POST' });
assert.equal(progress.hidden, false);
const reading = api('/chat-data/42/read', { method: 'POST' });
assert.equal(progress.hidden, false);
pending.pop().resolve({ ok: true, json: async () => ({}) });
await reading;
assert.equal(progress.hidden, false);
complete(); await normal;
assert.equal(progress.hidden, true);

// Request objects and network failures must also respect quiet mode.
const quiet = context.fetch(new Request('https://procura.test/chat/read', { method: 'POST', headers: { 'X-Procura-Progress': 'silent' } }));
assert.equal(progress.hidden, true);
pending.shift().reject(new Error('Offline'));
await assert.rejects(quiet, /Offline/);
assert.equal(progress.hidden, true);

// Native chat form events do not start the global popup.
events.get('submit')({ defaultPrevented: false, target: { target: '', closest: () => ({}), querySelector: () => null } });
await Promise.resolve();
assert.equal(progress.hidden, true);
events.get('submit')({ defaultPrevented: false, target: { target: '', closest: () => null, querySelector: () => null } });
await Promise.resolve();
assert.equal(progress.hidden, false);
events.get('pagehide')();
assert.equal(progress.hidden, true);
console.log('Chat progress checks passed: silent read polling and sends, overlapping saves, Request headers, failures and form events.');
