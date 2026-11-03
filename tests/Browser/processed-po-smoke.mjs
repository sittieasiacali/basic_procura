import { writeFile } from 'node:fs/promises';

const base = process.env.E2E_BASE_URL ?? 'http://localhost/basic%20procura/public';
const debug = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9334';
const target = await (await fetch(debug + '/json/new?' + encodeURIComponent(base + '/login'), { method: 'PUT' })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
const errors = [];
let sequence = 0;
await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});
socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    if (message.method === 'Runtime.consoleAPICalled' && message.params.type === 'error') errors.push(JSON.stringify(message.params.args));
    if (!pending.has(message.id)) return;
    const callbacks = pending.get(message.id);
    pending.delete(message.id);
    message.error ? callbacks.reject(new Error(message.error.message)) : callbacks.resolve(message.result);
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++sequence;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
});
const evaluate = async (expression) => {
    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
};
const waitFor = async (expression) => {
    for (let count = 0; count < 120; count++) {
        if (await evaluate(expression)) return;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('Timed out: ' + expression);
};
const assert = (condition, message) => { if (!condition) throw new Error(message); };

try {
    await send('Page.enable');
    await send('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await send('Runtime.enable');
    await send('Page.navigate', { url: base + '/login' });
    await waitFor('document.readyState === "complete"');
    if (await evaluate('!!document.querySelector("#email")')) {
        await evaluate('document.querySelector("#email").value = ' + JSON.stringify(process.env.E2E_EMAIL ?? 'purchasing@example.com'));
        await evaluate('document.querySelector("#password").value = ' + JSON.stringify(process.env.E2E_PASSWORD ?? 'Procura123!'));
        await evaluate('document.querySelector("form").requestSubmit()');
    }
    await waitFor('location.pathname.endsWith("/dashboard")');
    await send('Page.navigate', { url: base + '/request-flow?stage=purchase-order' });
    await waitFor('document.readyState === "complete" && !!document.querySelector("#purchase-order-summary")');
    const page = await evaluate('({url:location.href, title:document.querySelector("#live-queue-heading").textContent, rows:document.querySelectorAll(".processed-purchase-orders-table tbody tr").length, modals:document.querySelectorAll("#purchase-order-review-modal").length, order:document.querySelector("#live-queue-heading").compareDocumentPosition(document.querySelector("#purchase-order-summary")) & Node.DOCUMENT_POSITION_FOLLOWING, assets:[...document.querySelectorAll("script[src]")].map(s=>s.src)})');
    assert(page.rows > 0, 'No existing processed records available for browser preview verification');
    assert(page.modals === 1 && page.order, 'Section or modal placement incorrect');
    console.log('LIVE_PAGE', JSON.stringify(page));

    async function openPreview(selector) {
        await evaluate('document.querySelector(' + JSON.stringify(selector) + ').click()');
        await waitFor('!!document.querySelector("#purchase-order-review-modal.show [data-po-preview-document] .purchase-order-document") && !document.querySelector("[data-export-purchase-order]").disabled');
        await waitFor('bootstrap.Modal.getInstance(document.querySelector("#purchase-order-review-modal"))?._isTransitioning === false');
        const details = await evaluate('({reference:document.querySelector("[data-po-preview-reference]").textContent, document:!!document.querySelector("[data-po-preview-document] .po-official"), details:!!document.querySelector(".po-details-template"), active:document.querySelector("#purchase-order-review-modal .tab-pane.active").id, title:document.querySelector("[data-po-preview-document] .po-official-brand h2").textContent, total:document.querySelector("[data-po-preview-document] .po-official-totals div:last-child dd").textContent})');
        assert(details.active === 'po-print-template-pane' && details.document && details.title.trim() === 'PURCHASE ORDER', 'Official printable template not automatically selected');
        console.log('PREVIEW', JSON.stringify(details));
    }    await openPreview('.processed-purchase-order-code');
    await evaluate('window.__poPrintCount = 0; window.print = () => { window.__poPrintCount++; }');
    assert(await evaluate('document.querySelectorAll("#purchase-order-review-modal [role=tab]").length === 4'), 'Expected four independent preview tabs');
    const accountData = await evaluate('fetch(document.querySelector(".processed-purchase-order-code").dataset.poPreviewUrl, {headers:{Accept:"application/json"}}).then(r=>r.json()).then(p=>({classification:p.account_classification, bank:p.campaign_bank_account}))');
    assert(await evaluate('document.querySelector("[data-po-preview-account-type]").textContent.includes(' + JSON.stringify(accountData.classification.value ? accountData.classification.label : '') + ')'), 'Account classification is incorrect');
    assert(await evaluate('document.querySelector("[data-po-preview-campaign-bank]").textContent.includes(' + JSON.stringify(accountData.bank.accountNumber ?? '') + ')'), 'Campaign account is incorrect');
    console.log('INDEPENDENT_ACCOUNT_PREVIEWS', JSON.stringify(accountData));
    let expectedGeometry = null;
    for (const [tab, name] of [['#po-print-template-tab', 'official'], ['#po-details-template-tab', 'details'], ['#po-account-type-tab', 'account-type'], ['#po-campaign-bank-tab', 'campaign-bank']]) {
        await evaluate('document.querySelector(' + JSON.stringify(tab) + ').click()');
        if (name !== 'details') {
            const original = await evaluate('(() => { const sheet=document.querySelector("#purchase-order-review-modal .tab-pane.active .purchase-order-document"); const table=sheet.querySelector("table"); const legacy=sheet.classList.contains("po-sheet"); return {width:sheet.getBoundingClientRect().width, headers:[...table.querySelectorAll("thead tr:last-child th")].map(e=>e.textContent.trim()), rowCount:table.tBodies[0].rows.length, yellow:legacy?getComputedStyle(table.querySelector(".po-sheet-amount")).backgroundColor:null, blue:legacy?getComputedStyle(table.querySelector(".po-sheet-total")).backgroundColor:null, gray:legacy?getComputedStyle(table.querySelector(".po-sheet-column-row th")).backgroundColor:null, widths:[...table.tBodies[0].rows[0].cells].map(e=>e.getBoundingClientRect().width), signatures:[...sheet.querySelectorAll(".po-sheet-signatory,.po-official-signatures section")].map(e=>e.getBoundingClientRect().y), labels:[...sheet.querySelectorAll(".po-official-signature-label strong")].map(e=>e.textContent.trim()), hasLine:!!table.querySelector(".po-sheet-line-heading"), sourceColspan:Number(table.querySelector(".po-sheet-source-row th:last-child")?.colSpan || 0)}; })()');
            if (name === 'official') {
                assert(JSON.stringify(original.headers) === JSON.stringify(['Item Code','Description','Campaign','Qty','Unit','Rate','Amount','Total']), 'Official PO columns changed');
                assert(original.rowCount > 0 && original.widths.length === 8, 'Official PO item layout is incomplete');
                assert(JSON.stringify(original.labels) === JSON.stringify(['Prepared by:','Checked by:','Noted by:','Approved by:']), 'Official signature workflow changed');
            } else {
                assert(JSON.stringify(original.headers) === JSON.stringify(['DETAILS','REQUESTED BY','ACCOUNT TYPE','AMOUNT','Bank Details (for Deposit)']), 'Original account/bank columns changed');
                assert(original.rowCount >= 5 && original.yellow === 'rgb(255, 192, 0)' && original.blue === 'rgb(157, 195, 230)' && original.gray === 'rgb(217, 217, 217)', 'Original account/bank rows or Excel colors changed');
                if (name === 'campaign-bank') {
                    assert(!original.hasLine && original.widths.length === 5 && original.sourceColspan === 5, 'Campaign bank template must keep the original five-column layout without Line no.');
                    assert(original.signatures.length === 0, 'Campaign bank cropped template must not add signature sections.');
                } else {
                    assert(original.hasLine && original.widths.length === 6, 'Full account spreadsheet columns changed.');
                    assert(original.signatures.every((y,i,list)=>i===0 || y>list[i-1]), 'Signature arrangement is not vertical');
                }
            }
            const shot = await send('Page.captureScreenshot', {format:'png'});
            await writeFile(new URL('../../storage/app/po-'+name+'-preview.png', import.meta.url),Buffer.from(shot.data,'base64'));
            console.log('PO_TEMPLATE',name,JSON.stringify(original));
            expectedGeometry = original;
        }
        await evaluate('document.querySelector("[data-export-purchase-order]").click()');
        await waitFor('document.body.classList.contains("print-purchase-order")');
        await send('Emulation.setEmulatedMedia', { media: 'print' });
        const state = await evaluate('({root:!!document.querySelector("#purchase-order-print-root .purchase-order-document"), nav:getComputedStyle(document.querySelector("nav")).display, modalRects:document.querySelector("#purchase-order-review-modal").getClientRects().length, calls:window.__poPrintCount})');
        assert(state.root && state.nav === 'none' && state.modalRects === 0, 'Printing contains surrounding UI');
        if (name === 'official') {
            const layout = await evaluate('(() => { const root=document.querySelector("#purchase-order-print-root .po-official").getBoundingClientRect(); const brand=document.querySelector("#purchase-order-print-root .po-official-brand").getBoundingClientRect(); const footer=document.querySelector("#purchase-order-print-root .po-official-signatures").getBoundingClientRect(); return {rootTop:root.top,rootBottom:root.bottom,brandTop:brand.top,brandBottom:brand.bottom,footerTop:footer.top,footerBottom:footer.bottom}; })()');
            assert(Math.abs(layout.brandTop-layout.rootTop)<2 && Math.abs(layout.footerBottom-layout.rootBottom)<2 && layout.footerTop>layout.brandBottom, 'Official A4 content or signatures are outside the printed sheet');
            console.log('OFFICIAL_PRINT_LAYOUT', JSON.stringify(layout));
        }
        if (name === 'official') assert(await evaluate('!!document.querySelector("#purchase-order-print-root [data-po-sheet-variant=official]")'), 'Wrong official PO printed');
        if (name === 'account-type') assert(await evaluate('!!document.querySelector("#purchase-order-print-root [data-po-sheet-variant=account-type]") && !document.querySelector("#purchase-order-print-root [data-po-sheet-variant=campaign-bank]")'), 'Wrong account preview printed');
        if (name === 'campaign-bank') assert(await evaluate('!!document.querySelector("#purchase-order-print-root [data-po-sheet-variant=campaign-bank]") && !document.querySelector("#purchase-order-print-root [data-po-sheet-variant=account-type]")'), 'Wrong campaign bank preview printed');
        if(name !== 'details') {
            const printed = await evaluate('(() => { const s=document.querySelector("#purchase-order-print-root .purchase-order-document");const t=s.querySelector("table");const legacy=s.classList.contains("po-sheet"); return {width:s.getBoundingClientRect().width,widths:[...t.tBodies[0].rows[0].cells].map(e=>e.getBoundingClientRect().width),yellow:legacy?getComputedStyle(t.querySelector(".po-sheet-amount")).backgroundColor:null,blue:legacy?getComputedStyle(t.querySelector(".po-sheet-total")).backgroundColor:null}; })()');
            assert(Math.abs(printed.width-expectedGeometry.width)<1 && printed.widths.every((w,i)=>Math.abs(w-expectedGeometry.widths[i])<1), 'Preview and print proportions differ');
            if (name !== 'official') assert(printed.yellow===expectedGeometry.yellow && printed.blue===expectedGeometry.blue, 'Preview and print colors differ');
            const clip = await evaluate('(() => { const r=document.querySelector("#purchase-order-print-root .purchase-order-document").getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height,scale:1}; })()');
            const screenshot = await send('Page.captureScreenshot', {format:'png',clip,captureBeyondViewport:true});
            await writeFile(new URL('../../storage/app/po-'+name+'-print.png', import.meta.url),Buffer.from(screenshot.data,'base64'));
        }
        const pdf = await send('Page.printToPDF', { printBackground: true, preferCSSPageSize: true, displayHeaderFooter: false });
        await writeFile(new URL('../../storage/app/processed-po-' + name + '.pdf', import.meta.url), Buffer.from(pdf.data, 'base64'));
        console.log('PRINT', name, JSON.stringify({ ...state, bytes: Buffer.from(pdf.data, 'base64').length }));
        await send('Emulation.setEmulatedMedia', { media: '' });
        await evaluate('window.dispatchEvent(new Event("afterprint"))');
        assert(await evaluate('!document.body.classList.contains("print-purchase-order") && !document.querySelector("#purchase-order-print-root")'), 'Print state not restored');
    }
    await evaluate('document.querySelector("#purchase-order-review-modal .btn-close").click()');
    await waitFor('!document.querySelector("#purchase-order-review-modal.show")');
    await waitFor('!document.body.classList.contains("modal-open") && !document.querySelector(".modal-backdrop")');
    await waitFor('bootstrap.Modal.getInstance(document.querySelector("#purchase-order-review-modal"))?._isTransitioning === false');
    assert(await evaluate('location.search === "?stage=purchase-order"'), 'Closing changed page URL');

    await send('Page.reload', { ignoreCache: true });
    await waitFor('document.readyState === "complete" && !!document.querySelector(".processed-purchase-order-code")');
    await openPreview('.processed-purchase-orders-table tbody tr:first-child td:last-child button');
    await evaluate('document.querySelector("#purchase-order-review-modal .btn-close").click()');
    await waitFor('!document.querySelector("#purchase-order-review-modal.show")');
    await waitFor('!document.body.classList.contains("modal-open") && !document.querySelector(".modal-backdrop")');
    await waitFor('bootstrap.Modal.getInstance(document.querySelector("#purchase-order-review-modal"))?._isTransitioning === false');

    if (page.rows > 1) {
        await evaluate('document.querySelector(".processed-purchase-orders-table tbody tr:nth-child(2) td:last-child button").click()');
        await waitFor('!!document.querySelector("#purchase-order-review-modal.show [data-po-preview-details] .po-details-template")');
        await waitFor('bootstrap.Modal.getInstance(document.querySelector("#purchase-order-review-modal"))?._isTransitioning === false');
        await evaluate('document.querySelector("#po-print-template-tab").click()');
        const loaded = await evaluate('({error:!!document.querySelector("[data-po-preview-document] .alert-warning"), documentCount:document.querySelectorAll("[data-po-preview-document] .po-official").length, printDisabled:document.querySelector("[data-export-purchase-order]").disabled})');
        assert(!loaded.error && loaded.documentCount === 1 && !loaded.printDisabled, 'Saved PO did not load the official printable document');
        const expected = await evaluate('document.querySelector(".processed-purchase-orders-table tbody tr:nth-child(2) .processed-purchase-order-code").textContent.trim()');
        assert(await evaluate('document.querySelector("[data-po-preview-document] .po-official").getAttribute("aria-label").includes(' + JSON.stringify(expected) + ')'), 'Stale PO data shown');        await evaluate('document.querySelector("#purchase-order-review-modal .btn-close").click()');
        await waitFor('!document.querySelector("#purchase-order-review-modal.show")');
    await waitFor('!document.body.classList.contains("modal-open") && !document.querySelector(".modal-backdrop")');
    await waitFor('bootstrap.Modal.getInstance(document.querySelector("#purchase-order-review-modal"))?._isTransitioning === false');
    }
    for (const [name, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: name === 'mobile' });
        await evaluate('document.querySelector("#purchase-order-summary").scrollIntoView()');
        await new Promise(resolve => setTimeout(resolve, 250));
        const sizing = await evaluate('({viewport:innerWidth, width:document.documentElement.scrollWidth, section:getComputedStyle(document.querySelector("#purchase-order-summary")).display})');
        assert(sizing.width <= sizing.viewport, 'Page horizontal overflow at ' + name);
        const shot = await send('Page.captureScreenshot', { format: 'png' });
        await writeFile(new URL('../../storage/app/processed-po-' + name + '.png', import.meta.url), Buffer.from(shot.data, 'base64'));
        console.log('LAYOUT', name, JSON.stringify(sizing));
    }
    assert(errors.length === 0, 'Browser errors: ' + JSON.stringify(errors));
    console.log('PASS: exact Apache page, both actions, four independent previews, selected-template printing, refresh, reopening, record switching, desktop/mobile, no JS errors');
} finally {
    await send('Page.close').catch(() => {});
    socket.close();
}