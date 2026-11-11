const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

export function initializeBudgetContributions(root = document) {
    root.querySelectorAll('[data-budget-contributions]').forEach((section) => {
        if (section.dataset.initialized) return;
        section.dataset.initialized = 'true';
        const rows = section.querySelector('[data-source-account-rows]');
        const template = section.querySelector('[data-source-account-template]');
        const add = section.querySelector('[data-add-source-account]');
        const total = Math.round(Number(section.dataset.total) * 100);
        let index = Math.max(0, ...[...rows.querySelectorAll('[data-source-amount]')].map(input => Number(input.name.match(/\[(\d+)\]/)?.[1] || 0))) + 1;
        const refresh = () => {
            const amounts = [...rows.querySelectorAll('[data-source-amount]')];
            const selects = [...rows.querySelectorAll('[data-source-campaign]')];
            const sum = amounts.reduce((value, input) => value + Math.round(Number(input.value || 0) * 100), 0);
            const difference = total - sum;
            amounts.forEach(input => input.setCustomValidity(''));
            const duplicate = selects.some((select, i) => select.value && selects.some((other, j) => i !== j && other.value === select.value));
            selects.forEach(select => select.setCustomValidity(select.value && selects.filter(other => other.value === select.value).length > 1 ? 'Choose each campaign source account only once.' : ''));
            const missing = selects.some(select => !select.value);
            const message = duplicate ? 'Choose each campaign source account only once.' : missing ? 'Select a campaign source account for each contribution.' : difference === 0
                ? `Fully funded: ${money.format(sum / 100)} from ${amounts.length} source account(s).`
                : `${money.format(Math.abs(difference) / 100)} ${difference > 0 ? 'left to allocate' : 'over the approved PO total'}.`;
            if (difference !== 0) amounts[0]?.setCustomValidity(message);
            const feedback = section.querySelector('[data-source-account-feedback]');
            feedback.textContent = message;
            feedback.classList.toggle('text-danger', duplicate || missing || difference !== 0);
            feedback.classList.toggle('text-success', !duplicate && !missing && difference === 0);
            rows.querySelectorAll('[data-remove-source-account]').forEach(button => button.disabled = amounts.length === 1);
            add.disabled = amounts.length >= 20;
            return difference;
        };
        add.addEventListener('click', () => {
            if (rows.children.length >= 20) return;
            const remaining = refresh();
            rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index++)));
            const row = rows.lastElementChild;
            if (remaining > 0) row.querySelector('[data-source-amount]').value = (remaining / 100).toFixed(2);
            refresh();
            row.querySelector('select').focus();
        });
        rows.addEventListener('click', event => {
            const button = event.target.closest('[data-remove-source-account]');
            if (!button || rows.children.length <= 1) return;
            button.closest('[data-source-account-row]').remove();
            refresh();
        });
        rows.addEventListener('input', refresh);
        rows.addEventListener('change', refresh);
        section.closest('form')?.addEventListener('submit', event => {
            refresh();
            const invalid = [...rows.querySelectorAll('input, select')].find(input => !input.checkValidity());
            if (!invalid) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            invalid.reportValidity();
        }, { capture: true });
        refresh();
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initializeBudgetContributions());
else initializeBudgetContributions();
