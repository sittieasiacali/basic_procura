function initializeSkuPicker(picker) {
    const input = picker.querySelector('[data-sku-input]');
    const inventoryId = picker.querySelector('[data-sku-id]');
    const quantity = picker.querySelector('[data-sku-quantity]');
    const feedback = picker.querySelector('[data-sku-feedback]');
    const details = picker.querySelector('[data-sku-details]');
    const options = Array.from(picker.querySelectorAll('datalist option'));

    const resolve = () => {
        const sku = input.value.trim().toUpperCase();
        const option = options.find((candidate) => candidate.value.toUpperCase() === sku);
        const stock = option ? JSON.parse(option.dataset.stock) : null;
        inventoryId.value = stock?.id ?? '';
        details.hidden = !stock;
        input.setCustomValidity(sku && !stock ? 'No available item matches this SKU.' : '');
        feedback.textContent = !sku ? 'Enter a SKU to see the item and available quantity.'
            : !stock ? 'SKU not found in available stock. Check the SKU or assign it in the Inventory tab.'
                : 'SKU matched.';
        feedback.classList.toggle('text-danger', Boolean(sku && !stock));
        if (stock) {
            picker.querySelector('[data-sku-description]').textContent = stock.description;
            picker.querySelector('[data-sku-available]').textContent = `${stock.quantity} ${stock.unit} available`;
            picker.querySelector('[data-sku-location]').textContent = stock.location || 'Location not recorded';
            quantity.max = stock.quantity;
            if (Number(quantity.value) > Number(stock.quantity)) {
                input.setCustomValidity(`Only ${stock.quantity} ${stock.unit} is available for this SKU.`);
                feedback.textContent = `Only ${stock.quantity} ${stock.unit} is available for this SKU.`;
                feedback.classList.add('text-danger');
            }
        } else {
            quantity.removeAttribute('max');
        }
        const metadata = {
            asset_tag: stock?.asset_tag || stock?.inventory_code || '',
            item_code: stock?.sku || stock?.inventory_code || '',
            description: stock?.description || '',
            serial_number: stock?.serial_number || '',
            location: stock?.location || 'Not recorded',
        };
        picker.querySelectorAll('[data-sku-meta]').forEach((field) => {
            if (Object.hasOwn(metadata, field.dataset.skuMeta)) field.value = metadata[field.dataset.skuMeta];
        });
        return stock;
    };

    input.addEventListener('input', resolve);
    input.addEventListener('change', resolve);
    quantity.addEventListener('input', resolve);
    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        if (resolve() && input.reportValidity()) quantity.focus();
    });
    if (input.value) resolve();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-sku-picker]').forEach(initializeSkuPicker);
    document.querySelectorAll('[data-stock-selection]').forEach((form) => {
        const rows = form.querySelector('[data-sku-rows]');
        const template = form.querySelector('[data-sku-template]');
        const add = form.querySelector('[data-add-sku-row]');
        let nextIndex = Date.now();
        const refresh = () => {
            const pickers = rows.querySelectorAll('[data-sku-picker]');
            add.disabled = pickers.length >= 100;
            pickers.forEach((picker) => { picker.querySelector('[data-remove-sku-row]').hidden = pickers.length === 1; });
        };
        add.addEventListener('click', () => {
            if (rows.children.length >= 100) return;
            const fragment = document.createElement('template');
            fragment.innerHTML = template.innerHTML.replaceAll('__ROW__', String(nextIndex++));
            const picker = fragment.content.querySelector('[data-sku-picker]');
            rows.append(fragment.content);
            initializeSkuPicker(picker);
            refresh();
            picker.querySelector('[data-sku-input]').focus();
        });
        rows.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-remove-sku-row]');
            if (!remove || rows.children.length <= 1) return;
            remove.closest('[data-sku-picker]').remove();
            refresh();
        });
        refresh();
    });
});
