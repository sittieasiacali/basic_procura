import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const artifacts = path.join(root, 'storage/app/private');
mkdirSync(artifacts, { recursive: true });
const chrome = [process.env.CHROME_BIN, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((candidate) => candidate && existsSync(candidate));
assert.ok(chrome, 'Set CHROME_BIN to a Chrome or Edge executable.');
const profile = mkdtempSync(path.join(artifacts, 'supplier-picker-chrome-'));
const suppliers = [
    { id: 1, name: 'Acme Office Supplies', contact_person: 'Ana Cruz', email: 'ana@acme.test', phone: '555-1001', address: 'Quezon City', tax_id: 'TIN-123', quoted_amount: 1234.5, attachments: [{ id: 101, name: 'Previous quotation.pdf', url: '/attachments/101/view' }], warranty: 'Two years', vat_treatment: 'INCLUSIVE', shipping_fee: '150.00', quotation_url: 'https://acme.test/quote', delivery_location: 'Office', bank_details: 'BDO — Acme account 123456' },
    { id: 2, name: 'Metro Technology', contact_person: 'Ben Santos', email: 'sales@metro.test', phone: '555-1002', address: 'Makati', tax_id: 'TIN-456', bank_details: 'BPI — Metro account 654321' },
    { id: 3, name: 'New Partner', contact_person: '', bank_details: null },
    { id: 4, name: '<img src=x onerror=alert(1)>', contact_person: '<script>alert(1)</script>', bank_details: null },
];
const markup = `<form><script type="application/json" data-supplier-directory>${JSON.stringify(suppliers).replaceAll('<', '\\u003c')}</script>
<div data-quotation-row class="quotation-entry-card"><h2>Quotation 1</h2><div class="supplier-picker" data-supplier-picker>
<label data-supplier-label>Supplier *</label><div class="supplier-picker-control"><input class="form-control" data-supplier-search name="quotations[0][supplier_name]" autocomplete="off" role="combobox" aria-autocomplete="list"><input type="hidden" data-supplier-id name="quotations[0][supplier_id]"><button type="button" data-supplier-toggle class="supplier-picker-toggle" aria-label="Show saved suppliers">⌄</button></div>
<div data-supplier-options class="supplier-picker-menu" role="listbox" hidden></div><div data-supplier-status class="form-text" aria-live="polite"></div></div>
<label>Amount</label><input type="number" name="quotations[0][quoted_amount]"><div data-reused-attachments></div><label>Warranty</label><input class="form-control" name="quotations[0][warranty]"><label>VAT type</label><select name="quotations[0][vat_treatment]"><option value="EXEMPT">Non-Vat</option><option value="EXCLUSIVE">VAT</option><option value="INCLUSIVE">VAT inclusive</option></select><label>Shipping</label><input type="number" step=".01" name="quotations[0][shipping_fee]"><input name="quotations[0][quotation_url]"><select name="quotations[0][delivery_location]"><option value="">Choose location</option><option value="Office">Office</option></select><label>Supplier Bank Details *</label><textarea class="form-control" name="quotations[0][bank_details]"></textarea></div></form>`;
const checks = String.raw`
let assertions = 0;
function check(condition, message) { assertions++; if (!condition) throw new Error(message); }
const field = document.querySelector('[data-supplier-search]');
const picker = field.closest('[data-supplier-picker]');
const bank = document.querySelector('[name$="[bank_details]"]');
const hidden = document.querySelector('[data-supplier-id]');
const menu = document.querySelector('[data-supplier-options]');
const warranty = document.querySelector('[name$="[warranty]"]');
function type(input, value) { input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); }
function key(input, value) { input.dispatchEvent(new KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true })); }
try {
    field.focus();
    await new Promise((resolve) => setTimeout(resolve, 100));
    field.focus();
    check(field.getAttribute('aria-expanded') === 'true', 'Focus opens dropdown');
    check(document.querySelector('[data-supplier-label]').htmlFor === field.id, 'Input has an associated label');
    type(field, 'Acme');
    check(menu.querySelectorAll('[role="option"]').length === 2, 'Search matches supplier names and offers a new name');
    key(field, 'ArrowDown');
    check(field.getAttribute('aria-activedescendant') === menu.querySelector('[role="option"]').id, 'Keyboard highlight is accessible');
    key(field, 'Enter');
    check(hidden.value === '1' && field.value === 'Acme Office Supplies', 'Keyboard selects exact record');
    check(bank.value === suppliers[0].bank_details, 'Selection fills bank details');
    check(Number(document.querySelector('[name$="[quoted_amount]"]').value) === 1234.5, 'Previous quotation amount is filled');
    check(document.querySelector('[name$="[reused_attachment_ids][]"]').value === '101', 'Previous quotation file is selected for reuse');
    check(warranty.value === 'Two years', 'Previous quotation warranty is filled');
    check(document.querySelector('[name$="[vat_treatment]"]').value === 'INCLUSIVE', 'Previous VAT type is filled');
    check(document.querySelector('[name$="[shipping_fee]"]').value === '150.00', 'Previous shipping is filled');
    check(document.querySelector('[name$="[delivery_location]"]').value === 'Office', 'Previous delivery location is filled');
    check(document.querySelector('[data-supplier-profile]') === null, 'Unrelated saved supplier panel is removed');
    check(menu.hidden, 'Selection closes dropdown');
    type(field, 'Metro');
    check(!hidden.value && !bank.value && !warranty.value, 'Typing a different supplier clears stale automatic details');
    menu.querySelector('[role="option"]').click();
    check(hidden.value === '2' && bank.value === suppliers[1].bank_details, 'Mouse selection fills a different supplier');
    type(field, 'New Partner'); key(field, 'Enter');
    check(hidden.value === '3' && !bank.value, 'Selecting a supplier with no bank clears the previous bank');
    check(picker.querySelector('[data-supplier-status]').textContent.includes('No saved bank details'), 'Missing details are explained');
    type(field, 'Custom vendor'); key(field, 'Enter');
    check(!hidden.value && field.value === 'Custom vendor', 'New suppliers remain supported');
    type(field, 'Acme'); key(field, 'ArrowDown'); key(field, 'Enter');
    bank.value = 'Manual quotation-specific bank instructions';
    type(field, 'Another custom vendor');
    check(bank.value === 'Manual quotation-specific bank instructions', 'Unsubmitted manual bank edits survive typing a new name');
    type(field, '<img');
    check(menu.querySelector('img, script') === null, 'Supplier names and contact details render as text');
    key(field, 'Escape');
    check(menu.hidden && field.getAttribute('aria-expanded') === 'false', 'Escape closes dropdown');
    const firstRow = field.closest('[data-quotation-row]');
    const secondRow = firstRow.cloneNode(true);
    secondRow.querySelectorAll('input, textarea, select').forEach((input) => { if (input.tagName === 'SELECT') input.selectedIndex = 0; else input.value = ''; input.name = input.name.replace('[0]', '[1]'); });
    document.querySelector('form').append(secondRow);
    await new Promise((resolve) => setTimeout(resolve, 0));
    const secondField = secondRow.querySelector('[data-supplier-search]');
    check(secondField.id !== field.id, 'Cloned rows get unique accessible IDs');
    check(!secondRow.querySelector('[name$="[warranty]"]').value, 'Cloned quotation defaults start empty');
    secondField.focus(); type(secondField, 'Metro'); key(secondField, 'ArrowDown'); key(secondField, 'Enter');
    check(secondRow.querySelector('[data-supplier-id]').value === '2', 'Cloned rows support selection');
    check(secondRow.querySelector('textarea').value === suppliers[1].bank_details, 'Cloned rows auto-fill bank details');
    check(bank.value === 'Manual quotation-specific bank instructions', 'Selecting the second row does not change the first');
    secondRow.remove();
    type(field, 'Acme'); key(field, 'ArrowDown'); key(field, 'Enter');
    picker.querySelector('[data-supplier-toggle]').click();
    check(!menu.hidden && menu.querySelectorAll('[role="option"]').length === suppliers.length, 'Toggle shows all saved suppliers');
    document.getElementById('test-result').textContent = JSON.stringify({ passed: true, assertions });
} catch (error) { document.getElementById('test-result').textContent = JSON.stringify({ passed: false, error: error.message, assertions }); }
`;
const source = readFileSync(path.join(root, 'resources/js/supplier-picker.js'), 'utf8');
const css = readFileSync(path.join(root, 'resources/css/supplier-picker.css'), 'utf8');
const html = `<!doctype html><meta charset="utf-8"><title>Supplier picker browser check</title><style>body{font-family:Arial,sans-serif;background:#f5f6fc;color:#160d76;padding:32px}.quotation-entry-card{background:white;border:1px solid #d8dcf0;border-radius:16px;padding:24px;max-width:1050px;margin:auto}.supplier-picker{max-width:340px;margin-bottom:20px}label{display:block;font-size:13px;margin:14px 0 8px}.form-control{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cfd3e6;border-radius:7px;font:14px Arial}textarea{min-height:80px}.form-text{font-size:12px;color:#65708b;margin-top:6px}h2{font-size:18px}h3{font-size:14px}#test-result{margin:24px;text-align:center}${css}</style>${markup}<pre id="test-result">Pending</pre><script type="module">const suppliers = ${JSON.stringify(suppliers).replaceAll('<', '\\u003c')};${source}
${checks}</script>`;
const fixture = path.join(artifacts, 'supplier-picker-browser.html');
writeFileSync(fixture, html);
try {
    const result = spawnSync(chrome, ['--headless', '--disable-gpu', '--in-process-gpu', '--no-sandbox', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-extensions', `--user-data-dir=${profile}`, '--window-size=1400,1000', '--virtual-time-budget=6000', '--dump-dom', `--screenshot=${path.join(artifacts, 'supplier-picker-browser.png')}`, pathToFileURL(fixture).href], { encoding: 'utf8', windowsHide: true, timeout: 30000, maxBuffer: 5 * 1024 * 1024 });
    const match = result.stdout?.match(/<pre id="test-result">(.*?)<\/pre>/s);
    assert.ok(match, `Browser did not report a result: ${result.error?.message || result.stderr?.slice(-1000)}`);
    const summary = JSON.parse(match[1].replaceAll('&quot;', '"').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>'));
    assert.equal(summary.passed, true, summary.error);
    console.log(`Supplier picker: ${summary.assertions} browser assertions passed.`);
} finally {
    // Delete only the unique test profile created beneath the artifact directory.
    assert.ok(path.resolve(profile).startsWith(path.resolve(artifacts) + path.sep));
    rmSync(profile, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
}
