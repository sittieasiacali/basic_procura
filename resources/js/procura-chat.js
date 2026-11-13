import '../css/procura-chat.css';

const root = document.getElementById('procura-chat');
if (root) {
    const $ = (id) => document.getElementById('pc-' + id);
    // Fit the conversation to the visible browser area, including phone keyboards.
    if (document.body.classList.contains('workspace-chat')) {
        const viewport = window.visualViewport;
        const navigation = document.querySelector('.mobile-bottom-navigation');
        const updateFrame = () => {
            if (!viewport || viewport.scale === 1) document.body.style.setProperty('--pc-viewport-height', (viewport?.height || window.innerHeight) + 'px');
            document.body.style.setProperty('--pc-nav-space', (navigation?.getBoundingClientRect().height || 0) + 'px');
        };
        const observer = new ResizeObserver(updateFrame);
        if (navigation) observer.observe(navigation);
        window.addEventListener('resize', updateFrame);
        viewport?.addEventListener('resize', updateFrame);
        updateFrame();
        window.addEventListener('pagehide', () => {
            observer.disconnect();
            window.removeEventListener('resize', updateFrame);
            viewport?.removeEventListener('resize', updateFrame);
        }, { once: true });
    }
    const shell = root.querySelector('.pc-shell');
    function setSidebarVisible(visible) {
        shell.classList.toggle('pc-sidebar-hidden', !visible);
        const sidebarLabel = visible ? 'Hide request list' : 'View request list';
        $('sidebar-toggle').setAttribute('aria-label', sidebarLabel);
        $('sidebar-toggle').title = sidebarLabel;
        $('sidebar-toggle').setAttribute('aria-expanded', String(visible));
    }
    $('sidebar-toggle').addEventListener('click', () => setSidebarVisible(shell.classList.contains('pc-sidebar-hidden')));
    $('context-toggle')?.addEventListener('click', () => {
        const expanded = root.classList.toggle('pc-info-open');
        const label = expanded ? 'Hide request information' : 'Show request information';
        $('context-toggle').setAttribute('aria-expanded', String(expanded));
        $('context-toggle').setAttribute('aria-label', label);
        $('context-toggle').title = label;
    });
    const base = root.dataset.base;
    const userId = Number(root.dataset.user);
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    let selected = null, current = null, filter = 'all', page = 1, lastPage = 1;
    let scope = $('scope')?.value || 'all';
    let listGeneration = 0, listViewKey = '';
    let lastId = 0, firstId = 0, history = [], polling = false, generation = 0, refreshGeneration = null;
    let decision = null, signed = false, sending = false, pendingClientId = null;
    const label = (text) => String(text || '').replaceAll('_', ' ').toLowerCase().replace(/^\w/, c => c.toUpperCase());
    const node = (tag, text, cls) => { const el = document.createElement(tag); if (text != null) el.textContent = text; if (cls) el.className = cls; return el; };
    const error = (message = '') => { $('error').textContent = message; $('error').hidden = !message; };
    async function api(url, options = {}) {
        const response = await fetch(url, { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Procura-Progress': 'silent', ...options.headers } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            if ([401, 403, 419, 423].includes(response.status)) stopConversation();
            throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to connect. Please try again.');
        }
        return data;
    }
    const post = (url, data) => api(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
    function stopConversation() {
        generation++; selected = null; current = null; history = [];
        shell.classList.remove('pc-selected');
        $('messages').replaceChildren(); $('details-content').replaceChildren(); $('thread').hidden = true; $('empty').hidden = false;
    }
    async function loadList() {
        const version = ++listGeneration;
        const query = new URLSearchParams({ search: $('search').value, filter, scope, page });
        const data = await api(base + '?' + query);
        if (version !== listGeneration) return;
        const scrollTop = listViewKey === String(query) ? $('list').scrollTop : 0;
        $('list').replaceChildren(); lastPage = data.last_page;
        for (const request of data.data) {
            const button = node('button', null, 'pc-row' + (request.id === selected ? ' active' : ''));
            button.type = 'button';
            button.append(node('span', 'PR', 'pc-request-icon'));
            const main = node('span', null, 'pc-row-main');
            const status = request.status || '';
            const tone = ['REJECTED', 'CANCELLED', 'VOIDED'].includes(status) ? 'closed'
                : ['COMPLETED', 'FULFILLED', 'APPROVED', 'SUPPLIER_APPROVED', 'FULLY_PAID'].includes(status) ? 'success'
                : /APPROVAL|PENDING|RETURNED/.test(status) ? 'pending' : 'progress';
            const statusText = label(status).replace(/\b(om|smt|smts|tl|it|po)\b/gi, acronym => acronym.toUpperCase());
            main.append(node('strong', request.request_number),
                node('span', 'Campaign: ' + (request.campaign || 'Not assigned'), 'pc-campaign-badge'),
                node('span', statusText || 'Status unavailable', 'pc-status-badge pc-status-' + tone),
                node('small', 'Requestor: ' + (request.requestor || 'Not available'), 'pc-row-requestor'),
                node('small', request.last_message || request.title, 'pc-row-preview'));
            button.append(main);
            if (request.unread_count) button.append(node('span', String(request.unread_count), 'pc-unread'));
            button.addEventListener('click', () => open(request.id));
            $('list').append(button);
        }
        if (!data.data.length) $('list').append(node('p', 'No requests found.', 'pc-readonly'));
        $('list').scrollTop = scrollTop;
        listViewKey = String(query);
        $('page').textContent = page + ' / ' + lastPage; $('prev').disabled = page <= 1; $('next').disabled = page >= lastPage;
    }
    function fileLink(file) { const link = node('a', '\u21a7 ' + file.name); link.href = file.url; link.target = '_blank'; link.rel = 'noopener'; return link; }
    function renderDetails(data) {
        const content = $('details-content'); content.replaceChildren();
        const r = data.request;
        content.append(node('p', [r.requestor, r.campaign, r.team, r.category, 'Total: \u20b1' + r.total_amount].filter(Boolean).join(' \u00b7 ')));
        content.append(node('p', 'Purpose: ' + (r.purpose || '')));
        content.append(node('p', ['Required: ' + (r.required_date || 'Not specified'), 'Deliver to: ' + (r.delivery_location || 'Not specified')].join(' \u00b7 ')));
        const table = node('table');
        for (const item of r.items) {
            const tr = node('tr'); const info = node('td', item.description + (item.brand_specifications ? ' - ' + item.brand_specifications : '')); item.files.forEach(file => info.append(fileLink(file))); tr.append(info, node('td', item.quantity + ' ' + item.unit)); table.append(tr);
        }
        content.append(table);
        r.attachments.forEach(file => content.append(fileLink(file)));
        r.quotations.forEach(q => {
            const quote = node('div', null, 'pc-quote');
            const title = node('label');
            const input = node('input'); input.type = 'radio'; input.name = 'pc-quotation'; input.value = q.id;
            if (q.is_recommended) input.checked = true;
            title.append(input, document.createTextNode(' ' + q.supplier + ' \u00b7 \u20b1' + q.total + (q.is_recommended ? ' \u00b7 Recommended' : '')));
            quote.append(title, node('p', [q.item, q.delivery_terms, q.warranty, q.remarks].filter(Boolean).join(' \u00b7 ')));
            q.files.forEach(file => quote.append(fileLink(file))); content.append(quote);
        });
    }
    function renderWorkflow(data) {
        $('workflow').replaceChildren(); $('participants').replaceChildren(); $('request-summary').replaceChildren();
        if (!current || !current.request || history.length === 0) root.querySelectorAll('.pc-info-card:not(.pc-details)').forEach(card => { card.open = window.matchMedia('(min-width: 1400px)').matches; });
        const request = data.request;
        for (const [name, value] of [
            ['Request No.', request.request_number], ['Campaign', request.campaign], ['Requestor', request.requestor],
            ['Team', request.team], ['Current handler', request.responsible_role],
            ['Total amount', new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(request.total_amount || 0))],
            ['Purpose', request.purpose],
        ]) {
            $('request-summary').append(node('dt', name), node('dd', value || 'Not specified'));
        }
        const states = { current: 'Current stage', submitted: 'Submitted', visited: 'Previously handled', not_visited: 'Not reached', stopped: label(request.status), completed: 'Completed' };
        for (const step of data.workflow || []) {
            const item = node('span', null, 'pc-workflow-step pc-step-' + step.state);
            item.append(node('span', ['visited', 'completed', 'submitted'].includes(step.state) ? '\u2713' : step.state === 'stopped' ? '\u00d7' : '\u2022', 'pc-step-dot'), node('span', step.name));
            if (step.state === 'current') item.setAttribute('aria-current', 'step');
            item.title = states[step.state];
            $('workflow').append(item);
            const participant = node('div', null, 'pc-participant pc-step-' + step.state);
            participant.append(node('span', step.name), node('small', states[step.state]));
            $('participants').append(participant);
        }
        const observer = node('div', null, 'pc-participant');
        observer.append(node('span', 'SMT'), node('small', 'Monitoring / optional acknowledgement'));
        $('participants').append(observer);
    }
    function renderActions(data) {
        $('actions').replaceChildren();
        const action = (text, handler, cls) => { const button = node('button', text, cls); button.type = 'button'; button.addEventListener('click', handler); $('actions').append(button); };
        if (data.permissions.approve_om) {
            action('Approve', () => showDecision('om-approve'), 'pc-approve');
            action('Reject', () => showDecision('om-reject'), 'pc-reject');
        }
        if (data.permissions.approve_president) {
            action('Approve selected supplier', () => showDecision('president-approve'), 'pc-approve');
            action('Return to canvassing', () => showDecision('president-return'));
            if (!data.request.quotations.length) action('Acknowledge without pricing', () => showDecision('president-acknowledge'));
        }
        if (data.permissions.acknowledge) {
            action(data.acknowledged ? '\u2713 SMT acknowledged' : 'Acknowledge update (optional)', async () => {
                try { await post(base + '/' + selected + '/acknowledge', { message_id: data.workflow_message_id }); await refresh(); } catch (e) { error(e.message); }
            });
        }
        $('compose').hidden = !data.permissions.send; $('readonly').hidden = data.permissions.send;
        $('readonly').textContent = data.request.status === 'REJECTED'
            ? 'Rejected by OM. Processing has stopped and this conversation is read-only.'
            : 'You can read this conversation. Messaging is available to the requestor and current or previous handler.';
    }
    function renderMessages(scroll = false, hasOlder = true) {
        const box = $('messages'); const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
        const oldHeight = box.scrollHeight, oldTop = box.scrollTop;
        box.replaceChildren();
        if (hasOlder && history.length && firstId > 1) {
            const button = node('button', 'Load earlier messages', 'pc-history'); button.type = 'button';
            button.addEventListener('click', async () => {
                const id = selected, gen = generation;
                try {
                    const data = await api(base + '/' + id + '?before=' + firstId);
                    if (gen !== generation) return;
                    history = [...data.messages, ...history]; firstId = history[0]?.id || firstId;
                    renderMessages(false, data.has_more);
                } catch (e) { error(e.message); }
            }); box.append(button);
        }
        if (!history.length) box.append(node('p', 'Start the conversation about this request.', 'pc-readonly'));
        for (const m of history) {
            const wrap = node('div', null, 'pc-message ' + (m.kind !== 'message' ? 'system' : m.user_id === userId ? 'mine' : ''));
            if (m.kind === 'message') {
                const sender = node('div', null, 'pc-message-sender');
                const initials = m.user_name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('');
                sender.append(node('span', initials, 'pc-person-avatar'), node('strong', m.user_name));
                wrap.append(sender);
            }
            const bubble = node('div', m.body, 'pc-bubble'); if (m.attachment) bubble.append(fileLink(m.attachment));
            bubble.append(node('small', new Date(m.created_at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })));
            wrap.append(bubble); box.append(wrap);
        }
        if (scroll || nearBottom) box.scrollTop = box.scrollHeight; else box.scrollTop = oldTop + box.scrollHeight - oldHeight;
    }
    async function refresh(initial = false) {
        if (!selected || refreshGeneration === generation) return;
        const id = selected, gen = generation;
        initial ||= current === null;
        refreshGeneration = gen;
        let data;
        try { data = await api(base + '/' + id + (initial ? '' : '?after=' + lastId)); }
        catch (e) { if (gen === generation && /forbidden|unauthorized|read-only|inactive/i.test(e.message)) stopConversation(); throw e; }
        finally { if (refreshGeneration === gen) refreshGeneration = null; }
        if (gen !== generation) return;
        const changed = !current || current.request.status !== data.request.status || current.workflow_message_id !== data.workflow_message_id;
        current = data;
        $('title').textContent = data.request.request_number;
        $('subtitle').textContent = 'Campaign: ' + (data.request.campaign || 'Not assigned');
        if ($('toolbar-request')) $('toolbar-request').textContent = data.request.request_number;
        if ($('toolbar-campaign')) $('toolbar-campaign').textContent = 'Campaign: ' + (data.request.campaign || 'Not assigned');
        $('stage').textContent = label(data.request.status).replace(/\b(om|smt|smts|tl|it|po)\b/gi, acronym => acronym.toUpperCase());
        if (initial || changed) { renderDetails(data); renderWorkflow(data); }
        renderActions(data);
        if (initial) history = data.messages;
        else { const ids = new Set(history.map(m => m.id)); history.push(...data.messages.filter(m => !ids.has(m.id))); }
        firstId = history[0]?.id || 0; lastId = history.at(-1)?.id || 0;
        if (initial || data.messages.length) renderMessages(initial);
        const threadVisible = !window.matchMedia('(max-width: 767.98px)').matches || shell.classList.contains('pc-sidebar-hidden');
        if (lastId && !document.hidden && threadVisible) {
            await post(base + '/' + id + '/read', { message_id: lastId });
            window.dispatchEvent(new Event('procura:notifications-changed'));
        }
        if (!initial && data.has_more) await refresh();
    }
    async function open(id) {
        generation++; selected = id; current = null; history = []; firstId = lastId = 0; pendingClientId = null;
        $('body').value = ''; $('file').value = ''; $('file-name').textContent = '';
        $('messages').replaceChildren(); $('thread').hidden = false; $('empty').hidden = true;
        root.classList.add('pc-campaign-open');
        root.querySelector('.pc-shell').classList.add('pc-selected');
        if (window.matchMedia('(max-width: 767.98px)').matches) setSidebarVisible(false);
        try { error(); await refresh(true); await loadList(); } catch (e) { error(e.message); }
    }
    $('compose').addEventListener('submit', async (event) => {
        event.preventDefault(); if (sending || !selected) return;
        const body = $('body').value.trim(), file = $('file').files[0]; if (!body && !file) return;
        if (file?.size > 10 * 1024 * 1024) { error('Attachments must be 10 MB or smaller.'); return; }
        const sendLabel = $('send').textContent;
        sending = true; $('send').disabled = true; $('send').textContent = 'Sending\u2026'; $('send').setAttribute('aria-busy', 'true'); const id = selected;
        pendingClientId ||= crypto.randomUUID();
        const form = new FormData(); form.append('body', body); form.append('client_id', pendingClientId); if (file) form.append('attachment', file);
        try {
            error(); await api(base + '/' + id + '/messages', { method: 'POST', body: form });
            if (selected === id) { $('body').value = ''; $('file').value = ''; $('file-name').textContent = ''; pendingClientId = null; await refresh(); }
        } catch (e) { error(e.message); } finally { sending = false; $('send').disabled = false; $('send').textContent = sendLabel; $('send').removeAttribute('aria-busy'); }
    });
    $('body').addEventListener('input', () => { pendingClientId = null; });
    $('file').addEventListener('change', () => { pendingClientId = null; $('file-name').textContent = $('file').files[0]?.name || ''; });
    const canvas = $('signature'), context = canvas.getContext('2d');
    let drawing = false;
    const point = event => { const rect = canvas.getBoundingClientRect(); return [(event.clientX - rect.left) * canvas.width / rect.width, (event.clientY - rect.top) * canvas.height / rect.height]; };
    canvas.addEventListener('pointerdown', event => { drawing = true; canvas.setPointerCapture(event.pointerId); context.beginPath(); context.moveTo(...point(event)); });
    canvas.addEventListener('pointermove', event => { if (!drawing) return; context.lineWidth = 3; context.lineCap = 'round'; context.strokeStyle = '#171f70'; context.lineTo(...point(event)); context.stroke(); signed = true; });
    canvas.addEventListener('pointerup', () => drawing = false); canvas.addEventListener('pointercancel', () => drawing = false);
    $('clear').addEventListener('click', () => { context.clearRect(0, 0, canvas.width, canvas.height); signed = false; });
    function showDecision(type) {
        if (type === 'president-approve' && !root.querySelector('input[name=pc-quotation]:checked')) { error('Select a supplier quotation in request details first.'); root.querySelector('.pc-details').open = true; return; }
        decision = { type, id: selected, quotationId: root.querySelector('input[name=pc-quotation]:checked')?.value };
        $('decision-title').textContent = type === 'om-reject' ? 'Reject request and stop processing' : type.includes('return') ? 'Return for correction' : type.includes('acknowledge') ? 'Acknowledge without pricing' : 'Approve request';
        const requiresReason = type.includes('return') || type === 'om-reject';
        $('remarks').value = ''; $('remarks').required = requiresReason; $('required').textContent = requiresReason ? '(required)' : '(optional)';
        $('clear').click(); $('decision-error').textContent = ''; $('decision').showModal();
    }
    $('cancel').addEventListener('click', () => $('decision').close());
    $('decision-form').addEventListener('submit', async event => {
        event.preventDefault(); if (!signed) { $('decision-error').textContent = 'Draw your signature before confirming.'; return; }
        $('confirm').disabled = true;
        const { type, id, quotationId } = decision;
        const payload = { remarks: $('remarks').value, signature_name: root.dataset.name, signature_data: canvas.toDataURL('image/png') };
        let url;
        if (type.startsWith('om-')) url = root.dataset[type === 'om-approve' ? 'omApprove' : type === 'om-reject' ? 'omReject' : 'omReturn'].replace('__ID__', id);
        else { url = root.dataset.president.replace('__ID__', id); payload.decision = type === 'president-approve' ? 'APPROVE' : type === 'president-return' ? 'REJECT' : 'ACKNOWLEDGE'; if (quotationId) payload.quotation_id = Number(quotationId); }
        try { await post(url, payload); $('decision').close(); if (selected === id) await refresh(); await loadList(); }
        catch (e) { $('decision-error').textContent = e.message; }
        finally { $('confirm').disabled = false; }
    });
    $('scope')?.addEventListener('change', async () => {
        const menu = $('scope');
        if (scope === menu.value) return;
        scope = menu.value; page = 1;
        setSidebarVisible(true);
        stopConversation();
        root.querySelector('.pc-shell').classList.remove('pc-selected');
        $('scope-description').textContent = menu.selectedOptions[0].dataset.description || '';
        try { error(); await loadList(); } catch (e) { error(e.message); }
    });
    root.querySelectorAll('[data-filter]').forEach(button => button.addEventListener('click', async () => {
        filter = button.dataset.filter; page = 1;
        root.querySelectorAll('[data-filter]').forEach(item => { item.classList.toggle('active', item === button); item.setAttribute('aria-pressed', String(item === button)); });
        try { await loadList(); } catch (e) { error(e.message); }
    }));
    let searchTimer;
    const searchToggles = [...document.querySelectorAll('[data-chat-search-toggle]')];
    function toggleSearch(open) {
        root.classList.toggle('pc-search-open', open);
        searchToggles.forEach(button => button.setAttribute('aria-expanded', String(open)));
        if (open) {
            root.classList.add('pc-campaign-open');
            setSidebarVisible(true);
            stopConversation();
            root.querySelector('.pc-shell').classList.remove('pc-selected');
            requestAnimationFrame(() => $('search').focus());
        } else {
            clearTimeout(searchTimer);
            const hadSearch = $('search').value.length > 0;
            $('search').value = ''; page = 1;
            $('search').blur();
            searchToggles[0]?.focus();
            if (hadSearch) loadList().catch(e => error(e.message));
        }
    }
    searchToggles.forEach(button => button.addEventListener('click', () => toggleSearch(!root.classList.contains('pc-search-open'))));
    $('search').addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); toggleSearch(false); }
    });
    $('search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(async () => { page = 1; try { await loadList(); } catch(e) { error(e.message); } }, 300); });
    $('prev').addEventListener('click', async () => { page--; try { await loadList(); } catch(e) { error(e.message); } });
    $('next').addEventListener('click', async () => { page++; try { await loadList(); } catch(e) { error(e.message); } });
    $('open-campaign')?.addEventListener('click', () => {
        root.classList.add('pc-campaign-open');
        setSidebarVisible(true);
    });
    async function poll() {
        if (polling || document.hidden) return; polling = true;
        try { await refresh(); await loadList(); $('connection').textContent = '\u25cf Connected'; error(); }
        catch (e) { $('connection').textContent = 'Reconnecting...'; error(e.message); }
        finally { polling = false; }
    }
    poll(); if (/^\d+$/.test(root.dataset.request)) open(Number(root.dataset.request));
    const timer = setInterval(poll, 4000);
    window.addEventListener('pagehide', () => clearInterval(timer), { once: true });
}
