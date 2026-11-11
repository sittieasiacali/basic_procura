const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

export function initializeItemBudgetAllocations(root = document) {
    root.querySelectorAll('[data-item-budget-allocation]').forEach((section) => {
        if (section.dataset.initialized) return;
        section.dataset.initialized = 'true';
        const rows = [...section.querySelectorAll('[data-item-budget-row]')];
        const inputs = rows.map((row) => row.querySelector('[data-item-budget-input]'));
        const shipping = Math.round(Number(section.dataset.shipping) * 100);
        const expected = Math.round(Number(section.dataset.total) * 100) - shipping;
        const refresh = () => {
            const amounts = inputs.map((input) => Math.max(0, Math.round(Number(input.value || 0) * 100)));
            const sum = amounts.reduce((total, amount) => total + amount, 0);
            const weights = sum > 0 ? amounts : amounts.map(() => 1);
            const weightSum = weights.reduce((total, amount) => total + amount, 0);
            const shares = weights.map((amount) => shipping * amount / weightSum);
            const rounded = shares.map(Math.floor);
            const remainder = shipping - rounded.reduce((total, amount) => total + amount, 0);
            shares.map((share, index) => ({ index, fraction: share - rounded[index] }))
                .sort((a, b) => b.fraction - a.fraction).slice(0, remainder)
                .forEach(({ index }) => rounded[index]++);
            rows.forEach((row, index) => {
                row.querySelector('[data-item-shipping]').textContent = currency.format(rounded[index] / 100);
                row.querySelector('[data-item-total]').textContent = currency.format((amounts[index] + rounded[index]) / 100);
            });
            const difference = expected - sum;
            const message = difference === 0 ? 'Fully allocated. Item totals match the approved budget.'
                : `${currency.format(Math.abs(difference) / 100)} ${difference > 0 ? 'left to allocate' : 'over the approved budget'}.`;
            const feedback = section.querySelector('[data-item-budget-feedback]');
            feedback.textContent = message;
            feedback.classList.toggle('text-danger', difference !== 0);
            feedback.classList.toggle('text-success', difference === 0);
            inputs[0]?.setCustomValidity(difference === 0 ? '' : message);
        };
        section.closest('form')?.addEventListener('submit', (event) => {
            refresh();
            const invalid = inputs.find((input) => !input.checkValidity());
            if (!invalid) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            invalid.reportValidity();
        }, { capture: true });
        inputs.forEach((input) => input.addEventListener('input', refresh));
        refresh();
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initializeItemBudgetAllocations());
else initializeItemBudgetAllocations();
