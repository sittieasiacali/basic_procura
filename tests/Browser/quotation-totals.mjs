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
const profile = mkdtempSync(path.join(artifacts, 'quotation-totals-chrome-'));

const markup = `<form data-quotation-form><button id="add-quotation" type="button">Add quotation</button><div id="quotation-entry-body"><input type="radio" name="quotation_mode" value="manual" checked><input type="radio" name="quotation_mode" value="bulk"><select name="priority_level"><option value="">Select priority</option><option value="HIGH">High</option></select><div id="quotation-rows"><div data-quotation-row><span data-quotation-number>1</span><button type="button" data-remove-quotation>Remove</button><div class="quote-manual-fields"><input name="quotations[0][quoted_amount]" type="number"></div><select name="quotations[0][vat_treatment]"><option value="EXEMPT">Non-Vat</option><option value="EXCLUSIVE">VAT</option><option value="INCLUSIVE">VAT inclusive</option></select><input type="number" name="quotations[0][shipping_fee]"><div class="quote-bulk-fields"><button type="button" data-add-quoted-item>Add Item</button><div data-quoted-items><div data-quoted-item><select name="quotations[0][items][0][item_id]"><option value="1">Laptop</option></select><input class="bulk-item-quantity" name="quotations[0][items][0][quantity]" value="1"><input class="bulk-item-price" name="quotations[0][items][0][unit_price]" value="0"><output data-item-total>0.00</output><button type="button" data-remove-quoted-item>Clear</button></div></div><span data-bulk-total></span></div><span data-quote-items-total></span><span data-quote-vat></span><span data-quote-shipping></span><span data-quote-total></span></div></div><div id="quotation-empty-state"></div><input data-confirm-without-quotation><div data-quotation-errors><ul data-quotation-error-list></ul></div><button data-submit-quotations type="submit">Proceed</button></div></form>`;
const view = readFileSync(path.join(root, 'resources/views/purchase-requests/show.blade.php'), 'utf8');
const start = view.lastIndexOf('    (() => {', view.indexOf('const quotationTemplate'));
const end = view.indexOf('    (() => {', view.indexOf('        syncQuotationMode();\n    })();', start));
const source = view.slice(start, end);
const checks = String.raw`
let assertions = 0;
const check = (condition, message) => { assertions++; if (!condition) throw new Error(message); };
const row = document.querySelector('[data-quotation-row]');
const output = key => row.querySelector('[data-'+key+']').textContent;
function set(selector, value, event = 'input') { const input = row.querySelector(selector); input.value = value; input.dispatchEvent(new Event(event, { bubbles: true })); }
try {
 check(output('quote-total') === '0.00', 'Initial total is zero');
 set('[name$="[quoted_amount]"]', '1000');
 set('[name$="[shipping_fee]"]', '75.5');
 check(output('quote-total') === '1,075.50', 'Manual total includes shipping');
 set('[name$="[vat_treatment]"]', 'EXCLUSIVE', 'change');
 check(output('quote-total') === '1,195.50', 'Exclusive VAT applies only to items');
 check(output('quote-vat') === '120.00', 'VAT displayed separately');
 set('[name$="[vat_treatment]"]', 'INCLUSIVE', 'change');
 set('[name$="[quoted_amount]"]', '1120', 'change');
 check(output('quote-items-total') === '1,000.00', 'VAT inclusive item net is shown');
 check(output('quote-total') === '1,195.50', 'Inclusive VAT is not counted twice');
 const mode = document.querySelector('[value="bulk"]'); mode.checked = true; mode.dispatchEvent(new Event('change'));
 set('[name$="[vat_treatment]"]', 'EXCLUSIVE', 'change');
 set('.bulk-item-quantity', '2'); set('.bulk-item-price', '100');
 check(output('item-total') === '200.00', 'Quantity times price shown per item');
 check(output('quote-total') === '299.50', 'Bulk total includes VAT and shipping');
 row.querySelector('[data-add-quoted-item]').click();
 const second = row.querySelectorAll('[data-quoted-item]')[1];
 second.querySelector('.bulk-item-price').value = '50'; second.querySelector('.bulk-item-price').dispatchEvent(new Event('input', { bubbles: true }));
 check(output('quote-total') === '355.50', 'Shipping is added once across multiple items');
 check(second.querySelector('[data-item-total]').textContent === '50.00', 'New item has its own live total');
 second.querySelector('[data-remove-quoted-item]').click();
 check(output('quote-total') === '299.50', 'Removing item refreshes total');
 document.getElementById('add-quotation').click();
 const secondQuote = document.querySelectorAll('[data-quotation-row]')[1];
 check(secondQuote.querySelector('[data-quote-total]').textContent === '0.00', 'New supplier quotation starts with zero total');
 check(output('quote-total') === '299.50', 'Cloning does not alter first quotation');
 const form = document.querySelector('form');
 const submission = new Event('submit', { cancelable: true }); form.dispatchEvent(submission);
 check(submission.defaultPrevented, 'Missing priority blocks client handoff');
 document.getElementById('test-result').textContent = JSON.stringify({ passed: true, assertions });
} catch (error) { document.getElementById('test-result').textContent = JSON.stringify({ passed: false, assertions, error: error.message }); }
`;
const html = `<!doctype html><meta charset="utf-8"><title>Quotation totals check</title>${markup}<pre id="test-result">Pending</pre><script>${source}\n${checks}</script>`;
const fixture = path.join(artifacts, 'quotation-totals-browser.html');
writeFileSync(fixture, html);
try {
    const result = spawnSync(chrome, ['--headless', '--disable-gpu', '--in-process-gpu', '--no-sandbox', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-extensions', `--user-data-dir=${profile}`, '--window-size=1400,1000', '--virtual-time-budget=6000', '--dump-dom', `--screenshot=${path.join(artifacts, 'quotation-totals-browser.png')}`, pathToFileURL(fixture).href], { encoding: 'utf8', windowsHide: true, timeout: 30000, maxBuffer: 5 * 1024 * 1024 });
    const match = result.stdout?.match(/<pre id="test-result">(.*?)<\/pre>/s);
    assert.ok(match, `Browser did not report a result: ${result.error?.message || result.stderr?.slice(-1000)}`);
    const summary = JSON.parse(match[1].replaceAll('&quot;', '"').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>'));
    assert.equal(summary.passed, true, summary.error);
    console.log(`Quotation totals: ${summary.assertions} browser assertions passed.`);
} finally {
    // Delete only the unique test profile created beneath the artifact directory.
    assert.ok(path.resolve(profile).startsWith(path.resolve(artifacts) + path.sep));
    rmSync(profile, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
}
