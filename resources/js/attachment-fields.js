const states = new WeakMap();

function identity(file) { return [file.name, file.size, file.type, file.lastModified].join('|'); }
function assign(input, files, state) {
    const transfer = new DataTransfer();
    files.forEach((file) => transfer.items.add(file));
    state.files = files;
    input.files = transfer.files;
    state.applying = true;
    try { input.dispatchEvent(new Event('change', { bubbles: true })); }
    finally { state.applying = false; }
}
function supported(input, file) {
    const accepted = input.accept.split(',').map((value) => value.trim().toLowerCase()).filter(Boolean);
    return !accepted.length || accepted.some((type) => type.startsWith('.') ? file.name.toLowerCase().endsWith(type) : (type.endsWith('/*') ? file.type.startsWith(type.slice(0, -1)) : file.type === type));
}
function merge(input, incoming, state) {
    const maxFiles = input.multiple ? Number(input.dataset.maxFiles || 10) : 1;
    const maxSize = Number(input.dataset.maxFileSize || 5 * 1024 * 1024);
    const files = [...state.files];
    const messages = [];
    incoming.forEach((file) => {
        if (!supported(input, file)) messages.push(`${file.name}: unsupported file type.`);
        else if (file.size > maxSize) messages.push(`${file.name}: exceeds ${Math.round(maxSize / 1024 / 1024)} MB.`);
        else if (!files.some((saved) => identity(saved) === identity(file))) {
            if (files.length >= maxFiles) messages.push(`Up to ${maxFiles} files are allowed.`);
            else files.push(file);
        }
    });
    assign(input, files, state);
    state.feedback.textContent = messages.length ? messages.join(' ') : `${files.length} file(s) attached.`;
}
function pastedFiles(event) {
    const items = [...(event.clipboardData?.items || [])].filter((item) => item.kind === 'file').map((item) => item.getAsFile()).filter(Boolean);
    return items.length ? items : [...(event.clipboardData?.files || [])];
}
function screenshotFiles(files) {
    return files.map((file, index) => {
        if (!file.type.startsWith('image/') || (file.name && !['image.png', 'image.jpg', 'blob'].includes(file.name))) return file;
        return new File([file], `Screenshot-${Date.now()}-${index}.${file.type.split('/')[1].replace('jpeg', 'jpg')}`, { type: file.type, lastModified: Date.now() });
    });
}
function render(input, state) {
    state.urls.forEach((url) => URL.revokeObjectURL(url));
    state.urls = [];
    state.preview.replaceChildren();
    state.files.forEach((file, index) => {
        const item = document.createElement('div');
        item.className = 'attachment-file-card';
        const name = document.createElement('span');
        name.className = 'attachment-file-name';
        name.textContent = `${file.name} (${Math.ceil(file.size / 1024)} KB)`;
        item.append(name);
        const kind = file.type.startsWith('image/') ? 'image' : (file.type === 'application/pdf' ? 'pdf' : 'file');
        if (kind !== 'file') {
            const url = URL.createObjectURL(file);
            state.urls.push(url);
            if (kind === 'image') {
                const thumbnail = document.createElement('img');
                thumbnail.src = url;
                thumbnail.alt = `Preview of ${file.name}`;
                thumbnail.className = 'attachment-file-thumbnail';
                item.prepend(thumbnail);
            }
            const view = document.createElement('button');
            view.type = 'button'; view.className = 'btn btn-sm btn-outline-primary'; view.textContent = 'Preview';
            view.addEventListener('click', () => {
                if (window.openAttachmentViewer) window.openAttachmentViewer({ url, kind, name: file.name });
                else window.open(url, '_blank', 'noopener');
            });
            item.append(view);
        }
        const remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'btn btn-sm btn-outline-danger'; remove.textContent = 'Remove';
        remove.setAttribute('aria-label', `Remove ${file.name}`);
        remove.addEventListener('click', () => { assign(input, state.files.filter((_, savedIndex) => savedIndex !== index), state); state.feedback.textContent = `${state.files.length} file(s) attached.`; });
        item.append(remove);
        state.preview.append(item);
    });
}

export function initializeAttachmentFields(root = document) {
    root.querySelectorAll('input[type="file"]:not([data-single-import]):not([data-chat-attachment])').forEach((input) => {
        if (states.has(input)) return;
        let field = input.closest('[data-attachment-field]');
        if (!field) {
            field = document.createElement('div');
            field.dataset.attachmentField = '';
            const paymentDropzone = input.closest('[data-payment-dropzone]');
            if (paymentDropzone) {
                paymentDropzone.after(field);
                const duplicateList = input.closest('form')?.querySelector('[data-payment-receipt-list]');
                if (duplicateList) duplicateList.hidden = true;
            } else { input.before(field); field.append(input); }
        }
        // Rebuild cloned controls so they receive their own handlers and previews.
        field.querySelectorAll('[data-file-controls], [data-file-preview]').forEach((element) => element.remove());
        const controls = document.createElement('div');
        controls.dataset.fileControls = ''; controls.className = 'attachment-file-controls';
        const pasteBox = document.createElement('div');
        pasteBox.contentEditable = 'true'; pasteBox.setAttribute('role', 'textbox'); pasteBox.setAttribute('aria-label', 'Paste files or screenshots here');
        pasteBox.dataset.pasteFiles = ''; pasteBox.dataset.placeholder = 'Click here, then press Ctrl+V to paste files or a screenshot';
        pasteBox.className = 'attachment-paste-box'; pasteBox.tabIndex = 0;
        const pasteButton = document.createElement('button');
        pasteButton.type = 'button'; pasteButton.textContent = 'Paste'; pasteButton.className = 'btn btn-sm btn-outline-primary'; pasteButton.dataset.pasteButton = '';
        const feedback = document.createElement('div');
        feedback.className = 'small text-muted'; feedback.setAttribute('role', 'status'); feedback.dataset.pasteFeedback = '';
        const preview = document.createElement('div'); preview.dataset.filePreview = '';
        controls.append(pasteBox, pasteButton, feedback); field.append(controls, preview);
        const state = { files: [...input.files], urls: [], preview, feedback };
        states.set(input, state);
        input.addEventListener('change', () => {
            if (state.applying) { render(input, state); return; }
            const picked = [...input.files];
            if (!picked.length) { state.files = []; render(input, state); }
            else merge(input, picked, state);
        });
        field.addEventListener('paste', (event) => {
            if (input.disabled) return;
            event.preventDefault();
            const files = screenshotFiles(pastedFiles(event));
            if (files.length) merge(input, files, state);
            else feedback.textContent = 'No file found in the clipboard. Copy a screenshot or choose files.';
            pasteBox.replaceChildren();
        });
        pasteButton.addEventListener('click', async () => {
            if (input.disabled) return;
            try {
                if (!navigator.clipboard?.read) throw new Error('Clipboard read unavailable');
                const items = await navigator.clipboard.read();
                const files = [];
                for (const item of items) {
                    const types = item.types.filter((type) => type.startsWith('image/') || type === 'application/pdf');
                    const type = types.find((value) => value === 'image/png') || types[0];
                    if (type) files.push(new File([await item.getType(type)], type.startsWith('image/') ? 'image.png' : 'clipboard.pdf', { type }));
                }
                if (!files.length) throw new Error('No files');
                merge(input, screenshotFiles(files), state);
            } catch {
                feedback.textContent = 'Click the paste box and press Ctrl+V, or choose files. Clipboard access may require HTTPS or localhost.';
                pasteBox.focus();
            }
        });
        render(input, state);
    });
}
initializeAttachmentFields();
new MutationObserver(() => initializeAttachmentFields()).observe(document.body, { childList: true, subtree: true });

document.addEventListener('submit', (event) => {
    const form = event.target;
    const files = [...form.querySelectorAll('input[type="file"]:not([disabled])')].flatMap((input) => [...input.files]);
    const limits = document.querySelector('meta[name="attachment-limits"]');
    const maxFiles = Number(limits?.dataset.maxFiles || 20);
    const maxBytes = Number(limits?.dataset.maxBytes || 40 * 1024 * 1024);
    let message = '';
    if (maxFiles > 0 && files.length > maxFiles) message = `Attach up to ${maxFiles} new files per submission. Remove some files before submitting.`;
    else if (maxBytes > 0 && files.reduce((bytes, file) => bytes + file.size, 0) > maxBytes - 1024 * 1024) message = 'The selected files are too large together. Remove some files or use smaller copies before submitting.';
    if (!message) return;
    event.preventDefault(); event.stopImmediatePropagation();
    let alert = form.querySelector('[data-upload-limit-error]');
    if (!alert) { alert = document.createElement('div'); alert.dataset.uploadLimitError = ''; alert.className = 'alert alert-danger'; alert.setAttribute('role', 'alert'); alert.tabIndex = -1; form.prepend(alert); }
    alert.textContent = message; alert.focus();
}, true);
