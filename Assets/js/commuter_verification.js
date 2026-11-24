(function () {
    'use strict';

    const form = document.querySelector('.v-form');
    const input = document.getElementById('verification_document');
    const group = document.querySelector('.v-upload-group');
    const name = document.getElementById('fileName');
    const preview = document.getElementById('verificationPreview');
    const image = document.getElementById('verificationPreviewImage');
    const frame = document.getElementById('verificationPreviewFrame');
    const browse = document.getElementById('verificationBrowse');
    const submit = form && form.querySelector('button[type="submit"]');
    if (!form || !input || !group || !name || !preview || !image || !frame || !browse || !submit) return;

    const defaultName = name.textContent;
    const maxBytes = 5 * 1024 * 1024;
    let objectUrl = null;
    let pending = null;

    browse.addEventListener('click', function () { input.click(); });

    function clearPreview() {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        image.removeAttribute('src');
        frame.removeAttribute('src');
        image.hidden = true;
        frame.hidden = true;
        preview.hidden = true;
    }

    function shrinkImage(file, maxDim, quality) {
        return new Promise(resolve => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = function () {
                const scale = Math.min(1, maxDim / Math.max(img.width, img.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(img.width * scale));
                canvas.height = Math.max(1, Math.round(img.height * scale));
                const context = canvas.getContext('2d');
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                canvas.toBlob(blob => resolve(blob), 'image/jpeg', quality);
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
            img.src = url;
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

    function showPreview(file) {
        clearPreview();
        objectUrl = URL.createObjectURL(file);
        if (file.type === 'application/pdf') {
            frame.src = objectUrl;
            frame.hidden = false;
        } else {
            image.src = objectUrl;
            image.hidden = false;
        }
        preview.hidden = false;
    }

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        clearPreview();
        group.classList.remove('is-done', 'has-error');
        input.setCustomValidity('');
        if (!file) {
            name.textContent = defaultName;
            return;
        }
        if (file.size > maxBytes) {
            name.textContent = defaultName;
            group.classList.add('has-error');
            input.value = '';
            input.setCustomValidity('This file is larger than 5 MB. Choose a smaller photo.');
            input.reportValidity();
            return;
        }

        const token = {};
        pending = token;
        name.textContent = 'Preparing photo…';
        submit.disabled = true;
        optimizeImage(file).then(small => {
            if (pending !== token) return;
            if (small !== file) {
                try {
                    const transfer = new DataTransfer();
                    transfer.items.add(small);
                    input.files = transfer.files;
                } catch (_) { /* keep the original file when the browser cannot replace it */ }
            }
            const finalFile = input.files && input.files[0] ? input.files[0] : file;
            if (finalFile.size > maxBytes) {
                name.textContent = defaultName;
                group.classList.add('has-error');
                input.value = '';
                input.setCustomValidity('This photo is larger than 5 MB. Choose a smaller image.');
                return;
            }
            name.textContent = finalFile.name;
            group.classList.add('is-done');
            showPreview(finalFile);
        }).catch(() => {
            name.textContent = file.name;
            group.classList.add('is-done');
            showPreview(file);
        }).finally(() => {
            if (pending === token) {
                pending = null;
                submit.disabled = false;
            }
        });
    });

    form.addEventListener('submit', function (event) {
        if (!pending) return;
        event.preventDefault();
        pending.then(() => form.submit());
    });
})();
