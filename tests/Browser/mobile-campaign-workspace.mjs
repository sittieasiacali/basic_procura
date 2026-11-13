import assert from 'node:assert/strict';

globalThis.document = { querySelector: () => null };
globalThis.window = { matchMedia: () => ({ matches: false }) };
const { initializeMobileCampaignWorkspace } = await import('../../resources/js/mobile-campaign-workspace.js');

function workspace({ mobile, allowed, campaign = true }) {
    const navigations = [];
    let onResize;
    const classes = new Set();
    const viewport = { matches: mobile, addEventListener: (event, callback) => { assert.equal(event, 'change'); onResize = callback; } };
    const root = {
        querySelector: () => campaign ? { dataset: { allowed: String(allowed), url: '/chats?request=42' } } : null,
        documentElement: { classList: { toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name) } },
    };
    initializeMobileCampaignWorkspace(root, viewport, url => navigations.push(url));
    return { navigations, classes, resize: mobile => { viewport.matches = mobile; onResize?.(); } };
}

const phone = workspace({ mobile: true, allowed: false });
assert.deepEqual(phone.navigations, ['/chats?request=42']);
const desktop = workspace({ mobile: false, allowed: false });
assert.deepEqual(desktop.navigations, []);
desktop.resize(true);
assert.deepEqual(desktop.navigations, ['/chats?request=42']);
desktop.resize(false);
assert.equal(desktop.classes.has('mobile-campaign-workspace'), false);
for (const allowed of [true, false]) {
    assert.deepEqual(workspace({ mobile: true, allowed, campaign: false }).navigations, []);
}
assert.deepEqual(workspace({ mobile: true, allowed: true }).navigations, []);
console.log('Campaign mobile routing passed: phone entry, desktop preservation, resize, allowed pages and other roles.');
