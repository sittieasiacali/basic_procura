import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/procura-chat.js', import.meta.url), 'utf8');
function extract(start, end) {
    const first = source.indexOf(start), last = source.indexOf(end, first);
    assert.ok(first >= 0 && last > first);
    return source.slice(first, last);
}
function element(text = '') {
    return {
        textContent: text, children: [], value: '', handlers: new Map(),
        append(...children) { this.children.push(...children); },
        replaceChildren() { this.children = []; this.scrollTop = 0; },
        addEventListener(type, callback) { this.handlers.set(type, callback); },
        click() { this.handlers.get('click')?.(); },
        showModal() { this.open = true; },
    };
}
const elements = new Map();
const $ = id => { if (!elements.has(id)) elements.set(id, element()); return elements.get(id); };
let action;
const baseContext = { $, node: (tag, text) => element(text), showDecision: type => { action = type; } };
const renderActions = vm.runInNewContext(extract('    function renderActions(', '    function renderMessages(') + '\nrenderActions;', baseContext);
renderActions({ permissions: { approve_om: true, send: true }, request: { status: 'FOR_OM_APPROVAL' } });
assert.deepEqual($('actions').children.map(button => button.textContent), ['Approve', 'Reject']);
$('actions').children[1].click();
assert.equal(action, 'om-reject');
renderActions({ permissions: { send: false }, request: { status: 'REJECTED' } });
assert.equal($('actions').children.length, 0);
assert.equal($('compose').hidden, true);
assert.ok($('readonly').textContent.includes('Processing has stopped'));

const decisions = { $, root: { querySelector: () => null }, selected: 42, decision: null };
const showDecision = vm.runInNewContext(extract('    function showDecision(', "    $('cancel').addEventListener") + '\nshowDecision;', decisions);
showDecision('om-reject');
assert.equal($('remarks').required, true);
assert.equal($('required').textContent, '(required)');
assert.ok($('decision-title').textContent.includes('stop processing'));
assert.equal($('decision').open, true);
assert.equal(decisions.decision.id, 42);

const listContext = {
    $, node: (tag, text, className) => Object.assign(element(text), { className }), base: '/chat-data/requests', selected: null,
    label: text => String(text || '').replaceAll('_', ' ').toLowerCase().replace(/^\w/, c => c.toUpperCase()),
    filter: 'all', scope: 'all', listGeneration: 0, listViewKey: '', page: 1, lastPage: 1, URLSearchParams, open() {},
    api: async () => ({ last_page: 1, data: [{ id: 42, request_number: 'REQ-42', campaign: 'IT Department', requestor: 'Sample Requestor', status: 'FOR_OM_APPROVAL', title: 'Equipment' }] }),
};
const loadList = vm.runInNewContext(extract('    async function loadList(', '    function fileLink(') + '\nloadList;', listContext);
await loadList();
const metadata = $('list').children[0].children[1].children.map(child => child.textContent).join(' ');
assert.ok(metadata.includes('REQ-42') && metadata.includes('Campaign: IT Department') && metadata.includes('Requestor: Sample Requestor'));
const badges = $('list').children[0].children[1].children;
assert.equal(badges.find(child => child.className === 'pc-campaign-badge').textContent, 'Campaign: IT Department');
assert.equal(badges.find(child => child.className?.includes('pc-status-badge')).textContent, 'For OM approval');
$('list').scrollTop = 120;
await loadList();
assert.equal($('list').scrollTop, 120, 'Polling keeps the current scroll position');
listContext.scope = 'department';
await loadList();
assert.equal($('list').scrollTop, 0, 'Changing request scope starts at the top');
for (const [status, tone] of [['FOR_OM_APPROVAL', 'pending'], ['FOR_IT_SCREENING', 'progress'], ['COMPLETED', 'success'], ['REJECTED', 'closed']]) {
    listContext.api = async () => ({ last_page: 1, data: [{ id: 42, request_number: 'REQ-42', status }] });
    await loadList();
    const row = $('list').children[0].children[1].children;
    assert.ok(row.some(child => child.className === 'pc-status-badge pc-status-' + tone));
    assert.ok(row.some(child => child.textContent === 'Campaign: Not assigned'));
}
console.log('Procura Chat revision passed: OM decisions, closed conversations, campaign badges, requestor identity and status tones.');