// Presentation enhancements only: preserve the existing inputs, routes and actions.
const filters = new WeakSet();
const inboxRows = new WeakSet();
const previewSelector = '[data-mobile-request-open], [data-workflow-action-modal], [data-workflow-request-preview], [data-po-preview-url], [data-request-preview-url]';

function text(element) {
    return element?.textContent.trim().replace(/\s+/g, ' ') || '';
}

function createMobileSummary({ title, code, status, meta, note, trigger }, tagName = 'div') {
    const summary = document.createElement(tagName);
    summary.className = 'mobile-request-summary';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'mobile-request-open';
    button.disabled = Boolean(trigger.disabled) || trigger.getAttribute('aria-disabled') === 'true';
    button.setAttribute('aria-label', `Open ${code}: ${title}`);
    const icon = document.createElement('span');
    icon.className = 'mobile-request-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8Z"/><path d="M14 3v5h5M9 12h6M9 16h4"/></svg>';
    const content = document.createElement('span');
    content.className = 'mobile-request-copy';
    const appendText = (className, value) => {
        if (!value) return;
        const span = document.createElement('span');
        span.className = className;
        span.textContent = value;
        content.append(span);
        return span;
    };
    appendText('mobile-request-title', title);
    appendText('mobile-request-code', code);
    const foot = document.createElement('span');
    foot.className = 'mobile-request-foot';
    if (status) {
        const badge = document.createElement('span');
        badge.className = 'mobile-request-status';
        badge.dataset.tone = /success/.test(status.className) ? 'success' : /danger/.test(status.className) ? 'danger' : /warning|pending/.test(status.className) ? 'warning' : 'neutral';
        badge.textContent = text(status);
        foot.append(badge);
    }
    if (meta) {
        const span = document.createElement('span');
        span.className = 'mobile-request-time';
        span.textContent = meta;
        foot.append(span);
    }
    if (foot.childNodes.length) content.append(foot);
    appendText('mobile-request-note', note);
    const chevron = document.createElement('span');
    chevron.className = 'mobile-request-chevron';
    chevron.setAttribute('aria-hidden', 'true');
    button.append(icon, content, chevron);
    button.addEventListener('click', (event) => {
        event.stopPropagation();
        // Reuse the original authorized route/preview handler, including PO previews.
        trigger.click();
    });
    // A row may already listen for Enter/Space. Let the native button handle it once.
    button.addEventListener('keydown', (event) => event.stopPropagation());
    summary.append(button);
    return summary;
}

function keepAdditionalControls(container, trigger) {
    container.querySelectorAll('a, button, input, select, textarea').forEach((control) => {
        if (control === trigger || control.matches(previewSelector) || control.closest('.mobile-request-summary')) return;
        control.classList.add('mobile-request-extra');
    });
}

function initializeInboxTable(table, labels) {
    if (!table.hasAttribute('data-mobile-inbox')) return;
    const normalized = labels.map((label) => label.toLowerCase());
    const primary = ['po number', 'purchase order', 'request'].map((label) => normalized.indexOf(label)).find((index) => index >= 0);
    if (primary === undefined) return;
    table.classList.add('mobile-inbox-table');
    [...table.tBodies].forEach((body) => [...body.rows].forEach((row) => {
        if (inboxRows.has(row)) return;
        row.querySelector(':scope > .mobile-request-summary')?.remove();
        const cells = [...row.cells];
        if (cells.some((cell) => cell.colSpan > 1)) return;
        const trigger = row.querySelector('button[data-po-preview-url], [data-mobile-request-open]')
            || row.querySelector(previewSelector)
            || (row.matches('[data-request-preview-url], [data-po-preview-row]') ? row : null);
        if (!trigger) return;
        inboxRows.add(row);
        const primaryCell = cells[primary];
        const related = cells[normalized.indexOf('request')];
        const title = text(related?.querySelector('.small, .request-title')) || text(primaryCell.querySelector('.small, .request-title')) || text(primaryCell);
        const code = text(primaryCell.querySelector('a, button, strong')) || primaryCell.firstChild?.textContent.trim() || '';
        const relatedCode = related !== primaryCell ? text(related?.querySelector('a, button')) : '';
        const status = cells[normalized.findIndex((label) => /^(current )?status$/.test(label))]?.querySelector('.badge');
        const time = cells[normalized.findIndex((label) => /^(updated|processed|created)$/.test(label))];
        const summary = createMobileSummary({ title, code: [code, relatedCode].filter(Boolean).join(' \u00b7 '), status, meta: text(time), trigger }, 'td');
        summary.colSpan = table.tHead.rows[0].cells.length;
        summary.setAttribute('role', 'cell');
        row.classList.add('mobile-request-row');
        cells.forEach((cell) => cell.classList.add('mobile-request-original'));
        keepAdditionalControls(row, trigger);
        row.append(summary);
    }));
}

function initializeInboxCard(card) {
    if (inboxRows.has(card)) return;
    card.querySelector(':scope > .mobile-request-summary')?.remove();
    const trigger = card.querySelector('[data-workflow-action-modal], a.dashboard-row-action')
        || card.querySelector('.flow-request-number, .request-number');
    if (!trigger) return;
    inboxRows.add(card);
    const title = text(card.querySelector('.flow-request-title, .request-title'));
    const code = text(card.querySelector('.flow-request-number, .request-number'));
    const metadata = card.querySelector('.flow-request-meta, .request-metadata');
    const campaign = text(metadata?.children[2]);
    const time = text(metadata?.lastElementChild);
    const supplier = text(card.querySelector('.executive-decision-supplier'));
    const amount = text(card.querySelector('.executive-decision-amount'));
    const note = text(card.querySelector('.alert-warning')) || [supplier, amount].filter(Boolean).join(' \u00b7 ');
    const summary = createMobileSummary({ title, code: [code, campaign].filter(Boolean).join(' \u00b7 '), status: card.querySelector('.badge'), meta: time, note, trigger });
    card.classList.add('mobile-request-row');
    [...card.children].forEach((child) => child.classList.add('mobile-request-original'));
    keepAdditionalControls(card, trigger);
    card.append(summary);
}

function initializeWorkflowNavigation(root) {
    const page = root.querySelector('.dashboard-page');
    const actions = page?.querySelector('.dashboard-actions-panel');
    const processed = page?.querySelector('[aria-labelledby="processed-requests-heading"]');
    if (!page || !actions || !processed || page.querySelector('.mobile-workflow-switcher')) return;

    const history = processed.closest('.dashboard-history') || processed;
    const initialUrl = new URL(window.location.href);
    const initial = initialUrl.searchParams.get('workflow_view') === 'processed' || (!initialUrl.searchParams.has('workflow_view') && initialUrl.hash === '#processed-requests-heading') ? 'processed' : 'actions';
    const nav = document.createElement('nav');
    nav.className = 'mobile-workflow-switcher';
    nav.setAttribute('aria-label', 'Workflow sections');
    const buttons = {};
    for (const [key, label] of [['actions', 'For action'], ['processed', 'Processed']]) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.addEventListener('click', () => select(key));
        buttons[key] = button;
        nav.append(button);
    }
    actions.before(nav);
    actions.classList.add('mobile-workflow-actions');
    history.classList.add('mobile-workflow-processed');
    history.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || !window.matchMedia('(max-width: 767.98px)').matches) return;
        const url = new URL(link.href, window.location.href);
        if (url.origin === window.location.origin && url.pathname === window.location.pathname) {
            url.searchParams.set('workflow_view', 'processed');
            link.href = url.href;
        }
    });

    function select(view) {
        page.dataset.mobileWorkflowView = view;
        Object.entries(buttons).forEach(([key, button]) => button.setAttribute('aria-pressed', String(key === view)));
        if (window.matchMedia('(max-width: 767.98px)').matches) {
            const url = new URL(window.location.href);
            url.searchParams.set('workflow_view', view);
            window.history.replaceState(window.history.state, '', url);
        }
        page.querySelectorAll('form[method="GET"], form[method="get"]').forEach((form) => {
            let field = form.querySelector('input[name="workflow_view"]');
            if (!field) {
                field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'workflow_view';
                form.append(field);
            }
            field.value = view;
        });
    }
    select(initial);
}

export function initializeMobileWorkflow(root = document) {
    initializeWorkflowNavigation(root);
    root.querySelectorAll('form.table-toolbar, form.dashboard-workflow-filters, form.request-list-filters').forEach((form) => {
        const dashboard = form.classList.contains('dashboard-workflow-filters');
        const requestList = form.classList.contains('request-list-filters');
        const advancedSelector = requestList ? '.request-filter-advanced' : dashboard ? '.dashboard-filter-field:not(.dashboard-filter-search)' : '.table-toolbar-field:not(.table-toolbar-search)';
        if (filters.has(form) || !form.querySelector(advancedSelector)) return;
        filters.add(form);
        const advanced = [...form.querySelectorAll(advancedSelector)];
        const count = advanced.filter((field) => [...field.querySelectorAll('input, select')].some((input) => input.value)).length;
        const active = count > 0;
        const closedLabel = count ? `Filters (${count})` : 'Filters';
        form.classList.toggle('mobile-filters-collapsed', requestList || !active);
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn btn-outline-secondary mobile-filter-toggle';
        const initiallyExpanded = active && !requestList;
        toggle.textContent = initiallyExpanded ? 'Hide filters' : closedLabel;
        toggle.setAttribute('aria-expanded', String(initiallyExpanded));
        let nextId = 0;
        advanced.forEach((field, index) => {
            if (!field.id) {
                // Avoid duplicate IDs when several lists share a dashboard.
                do { nextId++; } while (document.getElementById(`mobile-filter-${nextId}-${index}`));
                field.id = `mobile-filter-${nextId}-${index}`;
            }
        });
        toggle.setAttribute('aria-controls', advanced.map((field) => field.id).join(' '));
        toggle.addEventListener('click', () => {
            const collapsed = form.classList.toggle('mobile-filters-collapsed');
            toggle.setAttribute('aria-expanded', String(!collapsed));
            toggle.textContent = collapsed ? closedLabel : 'Hide filters';
        });
        const search = form.querySelector(requestList ? '.request-filter-search' : dashboard ? '.dashboard-filter-search' : '.table-toolbar-search');
        if (search) search.after(toggle);
        else (form.querySelector('.dashboard-filter-fields') || form).prepend(toggle);
    });

    root.querySelectorAll('table[data-mobile-cards]').forEach((table) => {
        const headers = [...(table.tHead?.rows[0]?.cells || [])];
        if (!headers.length || headers.some((header) => header.colSpan > 1 || header.rowSpan > 1)) return;
        const labels = headers.map((header, index) => header.querySelector('input[type="checkbox"]') ? 'Select' : header.textContent.trim()
            || header.querySelector('[aria-label]')?.getAttribute('aria-label') || (index === 0 ? 'Select' : 'Details'));
        const bulk = table.tHead.querySelector('input[type="checkbox"]');
        if (bulk && !bulk.closest('.mobile-bulk-select')) {
            const label = document.createElement('label');
            label.className = 'mobile-bulk-select';
            const text = document.createElement('span');
            text.className = 'mobile-select-all-label';
            text.textContent = 'Select all on this page';
            bulk.before(label);
            label.append(bulk, text);
        }
        table.classList.add('mobile-card-table');
        // Explicit table roles retain table semantics when rows stack visually.
        table.setAttribute('role', 'table');
        table.tHead.setAttribute('role', 'rowgroup');
        table.tHead.rows[0].setAttribute('role', 'row');
        headers.forEach((header) => header.setAttribute('role', 'columnheader'));
        [...table.tBodies].forEach((body) => {
            body.setAttribute('role', 'rowgroup');
            [...body.rows].forEach((row) => {
                row.setAttribute('role', 'row');
                [...row.cells].filter((cell) => !cell.classList.contains('mobile-request-summary')).forEach((cell, index) => {
                    cell.setAttribute('role', cell.tagName === 'TH' ? 'rowheader' : 'cell');
                    cell.dataset.mobileLabel = labels[index] || 'Details';
                    cell.classList.toggle('mobile-card-cell-wide', cell.colSpan > 1);
                });
            });
        });
        initializeInboxTable(table, labels);
    });

    root.querySelectorAll('[data-mobile-inbox] > .flow-request-card, [data-mobile-inbox] > .dashboard-request-card').forEach(initializeInboxCard);
}

function startMobileWorkflow() {
    const connection = document.querySelector('[data-connection-status]');
    if (connection) {
        const refreshConnection = () => {
            const online = navigator.onLine;
            connection.classList.toggle('is-offline', !online);
            connection.querySelector('span').textContent = online ? 'Connected' : 'Offline';
        };
        window.addEventListener('online', refreshConnection);
        window.addEventListener('offline', refreshConnection);
        refreshConnection();
    }
    initializeMobileWorkflow();
    // Lists loaded or replaced by existing AJAX controls need the same treatment.
    new MutationObserver((mutations) => {
        const roots = new Set();
        mutations.forEach(({ addedNodes }) => addedNodes.forEach((node) => {
            if (node.nodeType !== 1) return;
            const table = node.closest('table[data-mobile-cards]');
            if (table) roots.add(table.parentElement);
            else if (node.matches('[data-mobile-inbox] > article, [data-mobile-inbox], table[data-mobile-cards], form.table-toolbar, form.dashboard-workflow-filters, form.request-list-filters')) roots.add(node.parentElement);
            else if (node.querySelector('[data-mobile-inbox], table[data-mobile-cards], form.table-toolbar, form.dashboard-workflow-filters, form.request-list-filters')) roots.add(node);
        }));
        roots.forEach((root) => initializeMobileWorkflow(root));
    }).observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startMobileWorkflow);
else startMobileWorkflow();
