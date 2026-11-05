import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const directory = process.env.PROCURA_AUDIT_DIR;
if (!directory) throw new Error('Set PROCURA_AUDIT_DIR to the isolated environment.');
const setup = JSON.parse(await readFile(path.join(directory, 'source-sessions.json'), 'utf8'));
const base = 'http://127.0.0.1:18474';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=', 'base64');
const signature = (role) => ({ signature_name: setup.accounts[role].name, signature_data: `data:image/png;base64,${png.toString('base64')}` });
const clients = new Map();
const steps = [];
const state = () => JSON.parse(execFileSync('C:/xampp/php/php.exe', ['-r', `$db=new PDO('sqlite:'.$argv[1]);$result=[];foreach(['purchase_requests','supplier_quotations','purchase_orders','budget_requests','payments','goods_receipts','inventory_items'] as $table){$result[$table]=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);}echo json_encode($result,JSON_THROW_ON_ERROR);`, path.join(directory, 'flow.sqlite')], { encoding: 'utf8' }));
function cookie(role) {
    const account = setup.accounts[role];
    return clients.get(role)?.cookie ?? `${account.cookie_name}=${encodeURIComponent(account.cookie_value)}`;
}
function updateCookies(role, response) {
    const session = response.headers.getSetCookie().find(value => value.startsWith(`${setup.accounts[role].cookie_name}=`));
    if (session) clients.set(role, { ...clients.get(role), cookie: session.split(';')[0] });
}
async function get(role, route) {
    const response = await fetch(`${base}${route}`, { headers: { Cookie: cookie(role) }, redirect: 'manual' });
    updateCookies(role, response);
    const text = await response.text();
    assert.equal(response.status, 200, `GET ${role} ${route}: ${response.status} ${response.headers.get('location')}`);
    const csrf = text.match(/name="csrf-token" content="([^"]+)"/)?.[1];
    if (csrf) clients.set(role, { ...clients.get(role), csrf });
    return text;
}
function fields(form, object, prefix = '') {
    for (const [key, value] of Object.entries(object)) {
        const name = prefix ? `${prefix}[${key}]` : key;
        if (value !== null && typeof value === 'object' && !(value instanceof Blob)) fields(form, value, name);
        else form.append(name, value instanceof Blob ? value : String(value));
    }
}
async function post(role, route, data, expected = [200, 302, 303]) {
    if (!clients.get(role)?.csrf) await get(role, '/dashboard');
    const form = new FormData();
    fields(form, data);
    const response = await fetch(`${base}${route}`, { method: 'POST', body: form, redirect: 'manual', headers: { Cookie: cookie(role), 'X-CSRF-TOKEN': clients.get(role).csrf, Accept: 'application/json' } });
    updateCookies(role, response);
    const text = await response.text();
    assert.ok(expected.includes(response.status), `${role} ${route}: HTTP ${response.status} ${text.slice(0, 1500)}`);
    steps.push({ role, route, status: response.status });
    console.log(`${role}: ${route} => ${response.status}`);
    return response;
}
const requestState = (request, expected) => assert.equal(state().purchase_requests.find(row => row.id === request).status, expected);
const date = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
try {
    await get('requestor', '/purchase-requests/create');
    await post('requestor', '/purchase-requests', {
        action: 'submit', requested_team: 'IT', request_category: 'COMPUTERS',
        department_campaign: setup.campaign_name, purpose: 'Isolated HTTP audit', required_date: date, delivery_location: 'Main Office',
        ...signature('requestor'), items: [{ description: 'Audit business laptop', quantity: 2, unit: 'unit', estimated_unit_price: 50, brand_specifications: 'Audit quotation', attachment: new Blob([png], { type: 'image/png' }), picture: new Blob([png], { type: 'image/png' }) }],
    });
    const request = state().purchase_requests.at(-1).id;
    requestState(request, 'FOR_IT_SCREENING');
    await post('requestor', `/purchase-requests/${request}/submit`, signature('requestor'), [403, 422]);
    // IT requests are directly screenable; an extra claim is deliberately denied.
    await post('it-staff', `/request-flow/${request}/claim`, {}, [403]);
    await post('it-staff', `/request-flow/${request}/screening`, { decision: 'UNAVAILABLE', evidence: 'No available stock', ...signature('it-staff') });
    requestState(request, 'FOR_PROCUREMENT');
    await post('purchasing-team', `/canvassing/${request}/quotations`, {
        quotation_mode: 'manual', quotations: [{ supplier_name: 'Audit Supplier', quoted_amount: 100, vat_treatment: 'EXEMPT', delivery_lead_time: '7 days', delivery_location: 'Seneca Plaza — E. Rodriguez, Quezon City', warranty: '1 year', quotation_url: 'https://example.test/audit', bank_details: 'Audit Bank, Audit Supplier, 000000', recommended: 1 }],
    });
    requestState(request, 'FOR_SUPPLIER_APPROVAL');
    const quote = state().supplier_quotations.at(-1);
    await post('president', `/president-approvals/${request}/supplier-decision`, { quotation_id: quote.id, decision: 'APPROVE', ...signature('president') });
    requestState(request, 'FOR_BUDGET_PROCESSING');
    await post('purchasing-team', `/purchase-orders/${request}`, { expected_delivery_date: date, payable_on: date, payment_details: 'Audit transfer', account_number: '000000', account_name: 'Audit Supplier', payment_terms: '7 days', ...signature('purchasing-team') });
    for (const [role, action] of [['po-checker', 'check'], ['po-noted', 'acknowledge'], ['po-approver', 'approve']]) {
        await post(role, `/purchase-orders/${request}/${action}`, { ...signature(role), decision: 'APPROVE' });
    }
    assert.equal(state().purchase_orders.at(-1).status, 'APPROVED');
    await post('purchasing-team', '/budget', { purchase_request_id: request, campaign_id: setup.campaign_id, details: 'Audit laptop budget', requested_payment_method: 'ONLINE', bank_details: 'Audit Bank', ...signature('purchasing-team') });
    const budget = state().budget_requests.at(-1);
    await post('purchasing', '/budget/finalize', { ids: [budget.id], priority_level: 'NORMAL', ...signature('purchasing') });
    requestState(request, 'FOR_PAYMENT');
    const payment = { payment_method: 'ONLINE', account_type: 'ACA', fund_source: 'MAIN_FUND', main_fund: 'Audit', reference_number: 'AUDIT-HTTP', receiving_status: 'FOR_RECEIVING', receipts: [new Blob([png], { type: 'image/png' })] };
    await post('finance', `/payments/${request}`, { ...payment, amount: 40 });
    requestState(request, 'FOR_PAYMENT');
    await post('finance', `/payments/${request}`, { ...payment, amount: 60 });
    requestState(request, 'FOR_RECEIVING');
    await post('inventory', `/receiving/${request}`, { ...signature('inventory'), remarks: 'Audit goods received' });
    requestState(request, 'COMPLETED');
    const before = state();
    await post('inventory', `/receiving/${request}`, signature('inventory'), [403, 422]);
    const after = state();
    assert.equal(after.inventory_items.length, before.inventory_items.length);
    assert.equal(after.goods_receipts.length, before.goods_receipts.length);
    assert.equal(after.inventory_items.filter(row => row.purchase_request_id === request).length, 1);
    assert.equal(after.payments.filter(row => row.purchase_request_id === request).length, 2);
    await get('requestor', `/purchase-requests/${request}`);
    const report = { result: 'PASS', environment: 'isolated source, SQLite, real HTTP, no outbound mail', steps, request, final_status: 'COMPLETED', inventory_items: after.inventory_items.length, payments: after.payments.length };
    await writeFile(path.join(directory, 'flow-http-results.json'), JSON.stringify(report, null, 2));
    console.log('HTTP FLOW COMPLETE');
} catch (error) {
    await writeFile(path.join(directory, 'flow-http-results.json'), JSON.stringify({ result: 'FAIL', steps, error: error.message }, null, 2));
    throw error;
}
