/* Commuter verification upload: same row behavior as the driver requirements uploader. */
(() => {
    const form = document.querySelector('.v-form');
    const group = form && form.querySelector('.upload-group');
    const icon = group && group.querySelector('.upload-icon');
    const img = group && group.querySelector('.document-preview-image');
    const frame = group && group.querySelector('.document-preview-frame');
    const input = group && group.querySelector('input[type="file"]');
    const button = group && group.querySelector('.document-input-button button');
    const preview = group && group.querySelector('.document-preview');
    const submit = form && form.querySelector('button[type="submit"]');
    if (!form || !group || !icon || !img || !frame || !input || !button || !preview || !submit) return;

    const idleLabel = button.textContent;
    const name = group.querySelector('.document-name');
    const hint = group.querySelector('.document-desc span:last-child');
    const idleName = name.textContent;
    const idleHint = hint.textContent;
    const maxBytes = 5 * 1024 * 1024;
    let objectUrl = null;
    let pending = null;

    name.dataset.idle = idleName;
    hint.dataset.idle = idleHint;

    function clearPreview() {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        img.removeAttribute('src');
        frame.removeAttribute('src');
        img.classList.remove('active');
        frame.classList.remove('active');
        preview.classList.remove('active');
        icon.classList.remove('has-thumb');
        icon.style.backgroundImage = '';
    }

    function sync() {
        const src = img.getAttribute('src');
        const file = input.files && input.files[0];
        icon.classList.toggle('has-thumb', Boolean(src));
        icon.style.backgroundImage = src ? 'url("' + src + '")' : '';
        button.textContent = file ? 'Change' : idleLabel;
        group.classList.toggle('is-done', Boolean(file && src));
        if (file && src) {
            name.textContent = file.name;
            hint.textContent = '✓ Ready · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
        } else {
            name.textContent = name.dataset.idle || idleName;
            hint.textContent = hint.dataset.idle || idleHint;
        }
    }

    function shrinkImage(file, maxDim, quality) {
        return new Promise(resolve => {
            const url = URL.createObjectURL(file);
            const source = new Image();
            source.onload = function () {
                const scale = Math.min(1, maxDim / Math.max(source.width, source.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(source.width * scale));
                canvas.height = Math.max(1, Math.round(source.height * scale));
                const context = canvas.getContext('2d');
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(source, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                canvas.toBlob(blob => resolve(blob), 'image/jpeg', quality);
            };
            source.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
            source.src = url;
        });
    }

    async function optimizeImage(file) {
        if (!/^image\/(jpeg|png)$/.test(file.type) || file.size <= 1024 * 1024) return file;
        let best = null;
        for (const attempt of [[1600, .82], [1280, .72], [1024, .62], [800, .52]]) {
            const blob = await shrinkImage(file, attempt[0], attempt[1]);
            if (!blob) break;
            best = blob;
            if (blob.size <= 1024 * 1024) break;
        }
        if (!best || best.size >= file.size) return file;
        const base = (file.name || 'id-photo').replace(/\.[^.]+$/, '');
        return new File([best], base + '.jpg', { type: 'image/jpeg' });
    }

    button.addEventListener('click', () => input.click());
    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        clearPreview();
        group.classList.remove('is-done', 'has-error');
        input.setCustomValidity('');
        if (!file) { sync(); return; }
        if (file.size > maxBytes) {
            input.value = '';
            group.classList.add('has-error');
            input.setCustomValidity('This file is larger than 5 MB. Choose a smaller photo.');
            input.reportValidity();
            sync();
            return;
        }

        const token = {};
        pending = token;
        submit.disabled = true;
        name.textContent = 'Preparing photo…';
        optimizeImage(file).then(small => {
            if (pending !== token) return;
            if (small !== file) {
                try {
                    const transfer = new DataTransfer();
                    transfer.items.add(small);
                    input.files = transfer.files;
                } catch (_) { /* retain the original file when replacement is unsupported */ }
            }
            const finalFile = input.files && input.files[0] ? input.files[0] : file;
            if (finalFile.size > maxBytes) {
                input.value = '';
                group.classList.add('has-error');
                input.setCustomValidity('This photo is larger than 5 MB. Choose a smaller image.');
                sync();
                return;
            }
            objectUrl = URL.createObjectURL(finalFile);
            if (finalFile.type === 'application/pdf') {
                frame.src = objectUrl;
                frame.classList.add('active');
            } else {
                img.src = objectUrl;
                img.classList.add('active');
            }
            preview.classList.add('active');
            sync();
        }).catch(() => {
            objectUrl = URL.createObjectURL(file);
            img.src = objectUrl;
            img.classList.add('active');
            preview.classList.add('active');
            sync();
        }).finally(() => {
            if (pending === token) {
                pending = null;
                submit.disabled = false;
            }
        });
    });

    form.addEventListener('submit', event => {
        if (!pending) return;
        event.preventDefault();
        pending.then(() => form.submit());
    });

    sync();
})();
