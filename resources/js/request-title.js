// Request form: the title category list follows the chosen screening team.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-requested-team]').forEach((team) => {
        const category = team.form?.querySelector('[data-request-category]');
        if (!category) return;
        const placeholder = category.querySelector('option[value=""]');

        const sync = () => {
            category.querySelectorAll('optgroup').forEach((optgroup) => {
                const matches = optgroup.dataset.team === team.value;
                optgroup.hidden = !matches;
                optgroup.disabled = !matches;
            });
            if (category.selectedOptions[0]?.parentElement?.disabled) category.value = '';
            if (placeholder) placeholder.textContent = team.value ? 'Select category' : 'Select a screening team first';
        };

        team.addEventListener('change', sync);
        sync();
    });
});
