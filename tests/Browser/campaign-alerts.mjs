import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

globalThis.document = { querySelector: () => null };
globalThis.window = {};
const source = readFileSync(new URL('../../resources/js/campaign-alerts.js', import.meta.url), 'utf8').replace("import '../css/campaign-alerts.css';", '');
const { initializeCampaignAlerts, vibrateCampaignAlert } = await import('data:text/javascript,' + encodeURIComponent(source));

function element() {
    return {
        handlers: new Map(), textContent: '', checked: false, disabled: false,
        addEventListener(event, callback) { this.handlers.set(event, callback); },
        removeAttribute(name) { delete this[name]; },
        setAttribute(name, value) { this[name] = value; },
    };
}
function setup(supported = true, vibrateEnabled = true, decision = 'enabled', auto = true, completed = false) {
    const toggles = [element()], statuses = [element()], test = element();
    const title = element(), message = element(), dismiss = element(), open = element();
    const parts = { '[data-campaign-alert-title]': title, '[data-campaign-alert-message]': message, '[data-campaign-alert-dismiss]': dismiss, '[data-campaign-alert-open]': open };
    const dialog = element();
    dialog.open = false;
    dialog.shows = 0;
    dialog.querySelector = selector => parts[selector];
    dialog.showModal = () => { dialog.open = true; dialog.shows++; };
    dialog.close = () => { dialog.open = false; };
    const consent = element(), accept = element(), decline = element(), support = element();
    consent.open = false;
    consent.shows = 0;
    consent.hasAttribute = () => auto;
    consent.showModal = () => { consent.open = true; consent.shows++; };
    consent.close = () => { consent.open = false; };
    consent.querySelector = selector => selector === '[data-campaign-consent-enable]' ? accept
        : selector === '[data-campaign-consent-decline]' ? decline : support;
    const root = {
        hidden: false,
        querySelector: selector => selector === '[data-campaign-notification-alert]' ? dialog
            : selector === '[data-campaign-alert-consent]' ? consent : { content: '42' },
        querySelectorAll: selector => selector === '[data-campaign-vibrate]' ? toggles : selector === '[data-campaign-vibration-status]' ? statuses : [test],
    };
    const handlers = new Map(), emitted = [], vibrations = [];
    const browser = {
        addEventListener: (type, callback) => handlers.set(type, callback),
        dispatchEvent: event => { emitted.push(event.type); handlers.get(event.type)?.(event); },
    };
    const device = supported ? { vibrate: value => { vibrations.push(value); return true; } } : {};
    let saved;
    const storage = { getItem: key => key.includes('alert-test') ? (completed ? '1' : null) : JSON.stringify({ decision, vibrate: vibrateEnabled }), setItem: (key, value) => { saved = JSON.parse(value); } };
    initializeCampaignAlerts(root, browser, device, storage);
    return { root, dialog, consent, accept, decline, support, title, message, dismiss, open, toggles, statuses, test, device, vibrations, emitted, handlers, saved: () => saved };
}
const alert = setup();
alert.handlers.get('procura:new-notification')({ detail: { title: 'REQ-42', message: '<script>unsafe</script>', url: '/chats?request=42' } });
assert.equal(alert.dialog.open, true);
assert.equal(alert.title.textContent, 'REQ-42');
assert.equal(alert.message.textContent, '<script>unsafe</script>', 'Message is assigned as text');
assert.equal(alert.open.href, '/chats?request=42');
assert.deepEqual(alert.vibrations, [[200, 100, 200]]);
alert.handlers.get('procura:new-notification')({ detail: { title: 'REQ-43', message: 'Another update', url: '/chats?request=43' } });
assert.equal(alert.dialog.shows, 1, 'Update an existing alert without opening stacked dialogs');
alert.dismiss.handlers.get('click')();
assert.equal(alert.dialog.open, false);
assert.equal(alert.vibrations.at(-1), 0);
assert.ok(alert.emitted.includes('procura:stop-notification-alert'));
alert.test.handlers.get('click')();
assert.ok(!alert.emitted.includes('procura:test-notification-alert'));
assert.equal(alert.test.hidden, true, 'Test controls are removed after confirmation');
alert.toggles[0].checked = false;
alert.toggles[0].handlers.get('change')();
assert.equal(alert.toggles[0].checked, true, 'Confirmed vibration cannot be muted');
assert.equal(alert.toggles[0].disabled, true);

const unsupported = setup(false);
assert.equal(unsupported.toggles[0].disabled, true);
assert.ok(unsupported.statuses[0].textContent.includes('unavailable'));
unsupported.handlers.get('procura:new-notification')({ detail: { title: 'iPhone update' } });
assert.equal(unsupported.dialog.open, true, 'Unsupported vibration does not block the confirmation message');
assert.equal(vibrateCampaignAlert({}), false);
assert.equal(vibrateCampaignAlert({ vibrate: () => { throw Error('blocked'); } }), false);
const background = setup();
background.root.hidden = true;
background.test.handlers.get('click')();
assert.equal(background.dialog.open, false);
assert.equal(background.vibrations.length, 0);
console.log('Campaign alerts passed: message, conversation link, dismissal, vibration opt-in, unsupported phones and background suppression.');
const pending = setup(true, true, null);
assert.equal(pending.consent.open, true, 'First visit opens confirmation');
assert.equal(pending.toggles[0].checked, false, 'Old vibration preference does not bypass confirmation');
assert.equal(pending.vibrations.length, 0, 'Opening confirmation is silent and does not vibrate');
pending.handlers.get('procura:new-notification')({ detail: { title: 'REQ-44' } });
assert.equal(pending.dialog.open, false, 'Incoming alerts wait for the decision');
pending.accept.handlers.get('click')();
assert.equal(pending.consent.open, false);
assert.deepEqual(pending.saved(), { decision: 'enabled', vibrate: true });
assert.ok(pending.emitted.includes('procura:alert-consent'));
assert.deepEqual(pending.vibrations.at(-1), [200, 100, 200]);
const later = setup(true, false, null);
later.decline.handlers.get('click')();
assert.deepEqual(later.saved(), { decision: 'declined', vibrate: false });
assert.equal(later.consent.open, false);
assert.ok(later.vibrations.every(value => value === 0), 'Not now does not start vibration');
const declined = setup(true, false, 'declined');
assert.equal(declined.consent.open, false, 'Not now is remembered after reload');
declined.handlers.get('procura:request-alert-consent')();
assert.equal(declined.consent.open, true, 'Enable alerts can reopen the confirmation');
const accepted = setup(true, true, 'enabled');
assert.equal(accepted.consent.open, false, 'Accepted confirmation does not repeat');
const iphone = setup(false, false, null);
assert.ok(iphone.support.textContent.includes('does not support website vibration'));
iphone.accept.handlers.get('click')();
assert.deepEqual(iphone.saved(), { decision: 'enabled', vibrate: false });
assert.equal(iphone.vibrations.length, 0);
const settings = setup(true, false, null, false);
assert.equal(settings.consent.open, false, 'Automatic confirmation is limited to Chats');
console.log('First-visit confirmation passed: silence until consent, Enable alerts, Not now, saved choices and unsupported vibration.');
const synced = setup(true, true, 'enabled');
synced.handlers.get('storage')({ key: 'asi-procura:campaign-alerts:42', newValue: JSON.stringify({ decision: 'enabled', vibrate: false }) });
assert.ok(!synced.emitted.includes('procura:alert-consent'), 'Changing vibration in another tab does not turn sound back on');

const oneTest = setup();
oneTest.handlers.get('procura:alert-test-completed')();
assert.equal(oneTest.test.hidden, true);
const eventsBeforeRepeat = oneTest.emitted.length;
oneTest.test.handlers.get('click')();
assert.equal(oneTest.emitted.length, eventsBeforeRepeat, 'The combined test cannot be replayed');
const restoredTest = setup(true, true, 'enabled', true, true);
assert.equal(restoredTest.test.hidden, true, 'Completed test stays hidden after reload');