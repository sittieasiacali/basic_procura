// Presentation enhancements only: preserve the existing inputs, routes and actions.
const filters = new WeakSet();

export function initializeMobileWorkflow(root = document) {
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
        form.classList.toggle('mobile-filters-collapsed', !active);
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn btn-outline-secondary mobile-filter-toggle';
        toggle.textContent = active ? 'Hide filters' : closedLabel;
        toggle.setAttribute('aria-expanded', String(active));
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
                [...row.cells].forEach((cell, index) => {
                    cell.setAttribute('role', cell.tagName === 'TH' ? 'rowheader' : 'cell');
                    cell.dataset.mobileLabel = labels[index] || 'Details';
                    cell.classList.toggle('mobile-card-cell-wide', cell.colSpan > 1);
                });
            });
        });
    });
}

function startMobileWorkflow() {
    initializeMobileWorkflow();
    // Lists loaded or replaced by existing AJAX controls need the same treatment.
    new MutationObserver((mutations) => {
        const roots = new Set();
        mutations.forEach(({ addedNodes }) => addedNodes.forEach((node) => {
            if (node.nodeType !== 1) return;
            const table = node.closest('table[data-mobile-cards]');
            if (table) roots.add(table.parentElement);
            else if (node.matches('table[data-mobile-cards], form.table-toolbar, form.dashboard-workflow-filters, form.request-list-filters')) roots.add(node.parentElement);
            else if (node.querySelector('table[data-mobile-cards], form.table-toolbar, form.dashboard-workflow-filters, form.request-list-filters')) roots.add(node);
        }));
        roots.forEach((root) => initializeMobileWorkflow(root));
    }).observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startMobileWorkflow);
else startMobileWorkflow();
