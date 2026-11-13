import assert from 'node:assert/strict';
globalThis.document = { querySelector: () => null };
const { createUnreadTracker, playNotificationTone, stopNotificationTone, initializeNotificationSound } = await import('../../resources/js/notification-sound.js');
const tracker = createUnreadTracker();
const entry = id => ({ id, created_at: '2026-11-13T10:00:00Z' });
assert.equal(tracker([entry('old')]), false);
assert.equal(tracker([entry('old'), entry('new')]), true);
assert.equal(tracker([entry('new')]), false);
function audio(state = 'running') {
    return {
        state, currentTime: 10, destination: {}, oscillators: [],
        resume() { this.state = 'running'; return Promise.resolve(); },
        createOscillator() {
            const node = { frequency: {}, connect() {}, disconnect() {}, start(at) { this.started = at; }, stop(at) { this.stopped = at; } };
            this.oscillators.push(node);
            return node;
        },
        createGain() { return { connect() {}, disconnect() {}, gain: { setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {} } }; },
    };
}
for (const tone of ['chime', 'bell', 'pulse', 'marimba', 'sparkle', 'doorbell', 'melody', 'sonar']) {
    const context = audio();
    assert.equal(playNotificationTone(context, tone), true);
    assert.ok(Math.abs(Math.max(...context.oscillators.map(node => node.stopped)) - 14.02) < .00001, `${tone} lasts four seconds`);
    stopNotificationTone(context);
    assert.ok(context.oscillators.every(node => node.stopped === 10));
}
assert.equal(playNotificationTone(audio('suspended')), false);
function control() {
    return { handlers: new Map(), addEventListener(name, fn) { this.handlers.set(name, fn); }, setAttribute() {} };
}
const enable = control(), test = control(), panel = control(), status = control();
const events = new Map(), gestures = new Map(), saved = new Map();
const contexts = [];
globalThis.CustomEvent = class extends Event { constructor(type, options) { super(type); this.detail = options.detail; } };
globalThis.localStorage = { getItem: key => saved.get(key) ?? null, setItem: (key, value) => saved.set(key, value) };
globalThis.document = {
    hidden: false,
    querySelector: selector => ({
        'meta[name="notification-sound-user"]': { content: '993' },
        '[data-campaign-notification-alert]': {},
        '[data-notification-sound-enable]': enable,
        '[data-notification-sound-test]': test,
        '[data-campaign-alert-setup]': panel,
        '[data-notification-sound-status]': status,
    })[selector] ?? null,
    addEventListener: (name, fn) => gestures.set(name, fn),
};
globalThis.window = {
    AudioContext: class { constructor() { const context = audio('suspended'); contexts.push(context); return context; } },
    addEventListener: (name, fn) => events.set(name, fn),
    dispatchEvent: event => events.get(event.type)?.(event),
};
initializeNotificationSound();
assert.equal(panel.hidden, false, 'First-time setup is available');
gestures.get('pointerdown')();
assert.equal(contexts.length, 0, 'No audio before confirmation');
events.get('procura:alert-consent')({ detail: { enabled: true, preview: true } });
await Promise.resolve();
await Promise.resolve();
assert.equal(panel.hidden, true, 'Confirmed setup disappears');
const sampleCount = contexts[0].oscillators.length;
assert.ok(sampleCount > 0);
await test.handlers.get('click')();
assert.equal(contexts[0].oscillators.length, sampleCount, 'Confirmation sample plays only once');
saved.set('asi-procura:campaign-alerts:993', JSON.stringify({ decision: 'enabled' }));
initializeNotificationSound();
assert.equal(panel.hidden, true, 'Returning page hides activation setup immediately');
assert.equal(enable.hidden, true, 'No repeat activate or mute action');
gestures.get('touchend')();
await Promise.resolve();
assert.equal(contexts[1].state, 'running', 'Normal interaction silently activates audio');
assert.equal(contexts[1].oscillators.length, 0, 'Returning does not replay sample');
events.get('procura:notification-summary')({ detail: { recent_unread: [] } });
events.get('procura:notification-summary')({ detail: { recent_unread: [entry('real')] } });
assert.ok(contexts[1].oscillators.length > 0, 'New notifications still ring');
console.log('Sound checks passed: unread deduplication, eight four-second tones, consent, one-time sample, silent return activation and real alerts.');
