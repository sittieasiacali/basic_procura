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
const profile = mkdtempSync(path.join(artifacts, 'budget-contributions-chrome-'));

const source = readFileSync(path.join(root, 'resources/js/budget-contributions.js'), 'utf8').replace('export function', 'function');
const row = (index, amount, campaign = '') => `<div data-source-account-row><select name="source_accounts[${index}][campaign_id]" data-source-campaign required><option value="">Choose account</option><option value="1" ${campaign === '1' ? 'selected' : ''}>AIA 1</option><option value="2">AIA 2</option></select><input name="source_accounts[${index}][amount]" data-source-amount type="number" min="0.01" step="0.01" required value="${amount}"><button type="button" data-remove-source-account>Remove</button></div>`;
const markup = `<form novalidate><section data-budget-contributions data-total="100.03"><div data-source-account-rows>${row(0, '100.03', '1')}</div><template data-source-account-template>${row('__INDEX__', '')}</template><button type="button" data-add-source-account>Add source account</button><p data-source-account-feedback></p></section></form>`;
const checks = String.raw`
let assertions = 0;
const check = (condition, message) => { assertions++; if (!condition) throw new Error(message); };
try {
 initializeBudgetContributions();
 const section = document.querySelector('section');
 const form = document.querySelector('form');
 const amounts = () => [...section.querySelectorAll('[data-source-amount]')];
 const selects = () => [...section.querySelectorAll('[data-source-campaign]')];
 const set = (input, value) => { input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); };
 check(form.checkValidity(), 'Single-account default covers the PO total');
 check(section.querySelector('[data-remove-source-account]').disabled, 'At least one source account remains');
 set(amounts()[0], '60.00');
 check(!form.checkValidity(), 'Partial funding is blocked');
 check(section.querySelector('[data-source-account-feedback]').textContent.includes('40.03 left'), 'Remaining contribution is visible');
 section.querySelector('[data-add-source-account]').click();
 check(amounts().length === 2, 'A second contribution row is added');
 check(amounts()[1].value === '40.03', 'Remaining budget defaults into the added row');
 check(!form.checkValidity(), 'A campaign must be selected for every contribution');
 set(selects()[1], '2');
 check(form.checkValidity(), 'Two distinct accounts can jointly fund the PO');
 check(new FormData(form).get('source_accounts[1][amount]') === '40.03', 'Submitted contribution values are preserved');
 set(selects()[1], '1');
 check(!form.checkValidity(), 'Duplicate campaign accounts are rejected');
 set(selects()[1], '2'); set(amounts()[1], '41.03');
 check(!form.checkValidity(), 'Overfunding is blocked');
 const submission = new Event('submit', { cancelable: true }); form.dispatchEvent(submission);
 check(submission.defaultPrevented, 'Incorrect contributions block submission even with novalidate');
 set(amounts()[1], '40.03');
 check(form.checkValidity(), 'Correcting the total clears the error');
 section.querySelectorAll('[data-remove-source-account]')[1].click();
 check(amounts().length === 1 && !form.checkValidity(), 'Removing an account recalculates the balance');
 section.querySelector('[data-add-source-account]').click();
 check(amounts()[1].name === 'source_accounts[2][amount]', 'Adding after removing does not reuse submitted field names');
 document.getElementById('test-result').textContent = JSON.stringify({ passed: true, assertions });
} catch (error) { document.getElementById('test-result').textContent = JSON.stringify({ passed: false, assertions, error: error.message }); }
`;
const html = `<!doctype html><meta charset="utf-8"><title>Budget contributions check</title>${markup}<pre id="test-result">Pending</pre><script>${source}\n${checks}</script>`;
const fixture = path.join(artifacts, 'budget-contributions-browser.html');
writeFileSync(fixture, html);
try {
    const result = spawnSync(chrome, ['--headless', '--disable-gpu', '--in-process-gpu', '--no-sandbox', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-extensions', `--user-data-dir=${profile}`, '--window-size=1400,1000', '--virtual-time-budget=6000', '--dump-dom', `--screenshot=${path.join(artifacts, 'budget-contributions-browser.png')}`, pathToFileURL(fixture).href], { encoding: 'utf8', windowsHide: true, timeout: 30000, maxBuffer: 5 * 1024 * 1024 });
    const match = result.stdout?.match(/<pre id="test-result">(.*?)<\/pre>/s);
    assert.ok(match, `Browser did not report a result: ${result.error?.message || result.stderr?.slice(-1000)}`);
    const summary = JSON.parse(match[1].replaceAll('&quot;', '"').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>'));
    assert.equal(summary.passed, true, summary.error);
    console.log(`Budget source contributions: ${summary.assertions} browser assertions passed.`);
} finally {
    // Delete only the unique test profile created beneath the artifact directory.
    assert.ok(path.resolve(profile).startsWith(path.resolve(artifacts) + path.sep));
    rmSync(profile, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
}
