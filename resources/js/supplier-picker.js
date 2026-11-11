const initialized = new WeakSet();
let nextPickerId = 0;

function closePicker(picker) {
    picker.querySelector('[data-supplier-options]').hidden = true;
    const input = picker.querySelector('[data-supplier-search]');
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
}

export function initializeSupplierPickers(root = document) {
    root.querySelectorAll('[data-supplier-picker]').forEach((picker) => {
        if (initialized.has(picker)) return;
        initialized.add(picker);
        const input = picker.querySelector('[data-supplier-search]');
        const selectedId = picker.querySelector('[data-supplier-id]');
        const menu = picker.querySelector('[data-supplier-options]');
        const status = picker.querySelector('[data-supplier-status]');
        const row = picker.closest('[data-quotation-row]');
        const fields = Object.fromEntries(['quoted_amount', 'bank_details', 'warranty', 'quotation_url', 'vat_treatment', 'shipping_fee', 'delivery_location']
            .map((name) => [name, row.querySelector(`[name$="[${name}]"]`)]).filter(([, field]) => field));
        const defaults = Object.fromEntries(Object.entries(fields).map(([name, field]) => [name, name === 'vat_treatment' ? 'EXEMPT' : (name === 'delivery_location' ? field.value : '')]));
        const reusedFiles = row.querySelector('[data-reused-attachments]');
        let suppliers = [];
        try { suppliers = JSON.parse(picker.closest('form').querySelector('[data-supplier-directory]').textContent); }
        catch { /* A new name and manual details still work without saved suppliers. */ }
        let selected = null;
        let lastFilled = {};
        let choices = [];
        let active = -1;
        const id = `supplier-picker-${++nextPickerId}`;
        input.id = `${id}-search`;
        menu.id = `${id}-options`;
        status.id = `${id}-status`;
        picker.querySelector('[data-supplier-label]').htmlFor = input.id;
        input.setAttribute('aria-controls', menu.id);
        input.setAttribute('aria-describedby', status.id);
        status.textContent = "Search your team's suppliers or enter a new name.";
        input.setAttribute('aria-expanded', 'false');
        menu.hidden = true;
        menu.replaceChildren();
        reusedFiles?.replaceChildren();

        function clearSelection() {
            selected = null;
            selectedId.value = '';
            reusedFiles?.replaceChildren();
            Object.entries(lastFilled).forEach(([name, value]) => {
                const field = fields[name];
                if (field.value === value) {
                    field.value = defaults[name];
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            lastFilled = {};
            status.textContent = "Search your team's suppliers or enter a new name.";
        }

        function select(choice) {
            if (choice.isNew) {
                clearSelection();
                input.value = choice.name;
                status.textContent = 'New supplier. Enter the quotation and bank details below.';
            } else {
                selected = choice;
                input.value = choice.name;
                selectedId.value = String(choice.id);
                Object.entries(fields).forEach(([name, field]) => {
                    field.value = choice[name] ?? defaults[name];
                    lastFilled[name] = field.value;
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                });
                if (reusedFiles) {
                    reusedFiles.replaceChildren();
                    (choice.attachments || []).forEach((attachment) => {
                        const item = document.createElement('div'); item.className = 'attachment-file-card';
                        const savedId = document.createElement('input'); savedId.type = 'hidden';
                        savedId.name = input.name.replace('[supplier_name]', '[reused_attachment_ids][]'); savedId.value = String(attachment.id);
                        const link = document.createElement('a'); link.textContent = attachment.name;
                        link.href = attachment.url; link.target = '_blank'; link.rel = 'noopener'; link.className = 'attachment-file-name';
                        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-sm btn-outline-danger'; remove.textContent = 'Remove';
                        remove.setAttribute('aria-label', `Remove previous attachment ${attachment.name}`);
                        remove.addEventListener('click', () => item.remove());
                        item.append(savedId, link, remove); reusedFiles.append(item);
                    });
                }
                status.textContent = choice.bank_details ? 'Previous quotation details filled in. You can edit them below.' : 'Supplier selected. No saved bank details; enter them below.';
            }
            input.dispatchEvent(new Event('change', { bubbles: true }));
            closePicker(picker);
        }

        function highlight(index) {
            active = index;
            [...menu.querySelectorAll('[role="option"]')].forEach((option, optionIndex) => {
                option.setAttribute('aria-selected', String(optionIndex === active));
            });
            const option = menu.querySelectorAll('[role="option"]')[active];
            if (option) {
                input.setAttribute('aria-activedescendant', option.id);
                option.scrollIntoView({ block: 'nearest' });
            } else input.removeAttribute('aria-activedescendant');
        }

        function render(query = input.value) {
            const term = query.trim().toLocaleLowerCase();
            const matches = suppliers.filter((supplier) => [supplier.name]
                .some((value) => String(value || '').toLocaleLowerCase().includes(term)));
            choices = matches.slice(0, 40);
            if (term && !suppliers.some((supplier) => supplier.name.trim().toLocaleLowerCase() === term)) {
                choices.push({ isNew: true, name: input.value.trim() });
            }
            active = -1;
            input.removeAttribute('aria-activedescendant');
            menu.replaceChildren();
            choices.forEach((choice, index) => {
                const option = document.createElement('div');
                option.className = 'supplier-picker-option';
                option.id = `${id}-option-${index}`;
                option.dataset.supplierChoice = String(index);
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                const name = document.createElement('strong');
                name.textContent = choice.isNew ? `Use "${choice.name}" as a new supplier` : choice.name;
                option.append(name);
                if (!choice.isNew) {
                    const details = document.createElement('span');
                    details.textContent = choice.bank_details ? 'Bank details available' : 'No saved bank details';
                    option.append(details);
                }
                menu.append(option);
            });
            if (!choices.length || matches.length > 40) {
                const hint = document.createElement('div');
                hint.className = 'supplier-picker-hint';
                hint.textContent = matches.length > 40 ? 'Type to narrow down the results.' : 'No saved suppliers yet. Enter a new supplier name.';
                menu.append(hint);
            }
            menu.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        input.addEventListener('focus', () => {
            document.querySelectorAll('[data-supplier-picker]').forEach((other) => { if (other !== picker) closePicker(other); });
            render();
        });
        input.addEventListener('input', () => {
            if (selected && input.value !== selected.name) clearSelection();
            render();
        });
        input.addEventListener('change', () => {
            if (selectedId.value) return;
            const exact = suppliers.find((supplier) => supplier.name.trim().toLocaleLowerCase() === input.value.trim().toLocaleLowerCase());
            if (exact) select(exact);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (menu.hidden) render();
                if (choices.length) highlight(event.key === 'ArrowDown' ? (active + 1) % choices.length : (active <= 0 ? choices.length - 1 : active - 1));
            } else if (event.key === 'Enter' && !menu.hidden) {
                event.preventDefault();
                const choice = choices[active] || choices.find((choice) => !choice.isNew && choice.name.trim().toLocaleLowerCase() === input.value.trim().toLocaleLowerCase()) || choices.find((choice) => choice.isNew);
                if (choice) select(choice);
                else closePicker(picker);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closePicker(picker);
            } else if (event.key === 'Tab') closePicker(picker);
        });
        menu.addEventListener('pointerdown', (event) => { event.preventDefault(); });
        menu.addEventListener('click', (event) => {
            const option = event.target.closest('[data-supplier-choice]');
            if (option) select(choices[Number(option.dataset.supplierChoice)]);
        });
        picker.querySelector('[data-supplier-toggle]').addEventListener('click', () => {
            const open = menu.hidden;
            input.focus();
            if (open) render('');
            else closePicker(picker);
        });
        picker.addEventListener('focusout', () => {
            queueMicrotask(() => { if (!picker.contains(document.activeElement)) closePicker(picker); });
        });
        // A cloned quotation row receives fresh IDs and handlers.
        if (selectedId.value) {
            const saved = suppliers.find((supplier) => String(supplier.id) === selectedId.value);
            if (saved) select(saved);
            else clearSelection();
        }
    });
}

initializeSupplierPickers();
new MutationObserver(() => initializeSupplierPickers()).observe(document.body, { childList: true, subtree: true });
document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-supplier-picker]')) document.querySelectorAll('[data-supplier-picker]').forEach(closePicker);
});
