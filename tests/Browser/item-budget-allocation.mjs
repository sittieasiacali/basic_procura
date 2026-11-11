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
const profile = mkdtempSync(path.join(artifacts, 'item-budget-chrome-'));

const source = readFileSync(path.join(root, 'resources/js/item-budget-allocation.js'), 'utf8').replace('export function', 'function');
const row = value => `<tr data-item-budget-row><td>Office item</td><td><input type="number" min="0" step="0.01" data-item-budget-input value="${value}"></td><td data-item-shipping></td><td data-item-total></td></tr>`;
const markup = `<form><section data-item-budget-allocation data-total="100.03" data-shipping="0.03"><table>${row(20)}${row(80)}</table><p data-item-budget-feedback></p></section></form>`;
const checks = String.raw`
let assertions = 0;
const check = (condition, message) => { assertions++; if (!condition) throw new Error(message); };
try {
 initializeItemBudgetAllocations();
 const section = document.querySelector('section');
 const inputs = [...section.querySelectorAll('input')];
 const outputs = key => [...section.querySelectorAll('[data-'+key+']')].map(el => Number(el.textContent.replace(/[^\d.-]/g, '')));
 const change = (index, value) => { inputs[index].value=value; inputs[index].dispatchEvent(new Event('input')); };
 check(JSON.stringify(outputs('item-shipping')) === '[0.01,0.02]', 'Shipping follows each allocated item amount');
 check(JSON.stringify(outputs('item-total')) === '[20.01,80.02]', 'Item totals include shipping');
 check(inputs[0].checkValidity(), 'Matching allocations are valid');
 change(0, 10);
 check(!inputs[0].checkValidity(), 'Under-allocation blocks submission');
 const submission = new Event('submit', { cancelable: true });
 section.closest('form').dispatchEvent(submission);
 check(submission.defaultPrevented, 'Allocation errors block even forms that use custom validation');
 check(section.querySelector('[data-item-budget-feedback]').textContent.includes('10.00 left'), 'Remaining budget is visible');
 change(0, 30);
 check(!inputs[0].checkValidity(), 'Over-allocation blocks submission');
 check(section.querySelector('[data-item-budget-feedback]').textContent.includes('10.00 over'), 'Excess budget is visible');
 change(1, 70);
 check(inputs[0].checkValidity(), 'Correcting the allocations clears validation');
 check(JSON.stringify(outputs('item-total')) === '[30.01,70.02]', 'Totals update immediately after corrections');
 change(0, 0); change(1, 0);
 check(JSON.stringify(outputs('item-shipping')) === '[0.02,0.01]', 'Zero weights distribute shipping cents predictably');
 check(!inputs[0].checkValidity(), 'Blank allocations cannot bypass the budget total');
 section.dataset.total='0.03';
 // A shipping-only budget still accounts for the full amount.
 section.removeAttribute('data-initialized');
 initializeItemBudgetAllocations();
 check(inputs[0].checkValidity(), 'Shipping-only allocations allow zero item amounts');
 check(outputs('item-total').reduce((sum, value) => sum+value, 0) === 0.03, 'Shipping-only totals preserve every cent');
 document.getElementById('test-result').textContent = JSON.stringify({ passed: true, assertions });
} catch (error) { document.getElementById('test-result').textContent = JSON.stringify({ passed: false, assertions, error: error.message }); }
`;
const html = `<!doctype html><meta charset="utf-8"><title>Item budget allocation check</title>${markup}<pre id="test-result">Pending</pre><script>${source}\n${checks}</script>`;
const fixture = path.join(artifacts, 'item-budget-browser.html');
writeFileSync(fixture, html);
try {
    const result = spawnSync(chrome, ['--headless', '--disable-gpu', '--in-process-gpu', '--no-sandbox', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-extensions', `--user-data-dir=${profile}`, '--window-size=1400,1000', '--virtual-time-budget=6000', '--dump-dom', `--screenshot=${path.join(artifacts, 'item-budget-browser.png')}`, pathToFileURL(fixture).href], { encoding: 'utf8', windowsHide: true, timeout: 30000, maxBuffer: 5 * 1024 * 1024 });
    const match = result.stdout?.match(/<pre id="test-result">(.*?)<\/pre>/s);
    assert.ok(match, `Browser did not report a result: ${result.error?.message || result.stderr?.slice(-1000)}`);
    const summary = JSON.parse(match[1].replaceAll('&quot;', '"').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>'));
    assert.equal(summary.passed, true, summary.error);
    console.log(`Item budget allocations: ${summary.assertions} browser assertions passed.`);
} finally {
    // Delete only the unique test profile created beneath the artifact directory.
    assert.ok(path.resolve(profile).startsWith(path.resolve(artifacts) + path.sep));
    rmSync(profile, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
}
