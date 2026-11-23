/* Upload rows: show the chosen photo as a small thumbnail in the row (the big preview box is hidden by CSS)
   and turn "Browse" into "Change" once a file is picked. register.js still does the validation and resizing. */
(() => {
    document.querySelectorAll('.upload-group').forEach(group => {
        const icon = group.querySelector('.upload-icon');
        const img = group.querySelector('.document-preview-image');
        const input = group.querySelector('input[type="file"]');
        const button = group.querySelector('.document-input-button button');
        if (!icon || !img || !input) return;
        const idleLabel = button ? button.textContent : '';
        const n0 = group.querySelector('.document-name'), h0 = group.querySelector('.document-desc span:last-child');
        if (n0 && h0) { n0.dataset.idle = n0.textContent; h0.dataset.idle = h0.textContent; }

        const sync = () => {
            const src = img.getAttribute('src');
            icon.classList.toggle('has-thumb', Boolean(src));
            icon.style.backgroundImage = src ? 'url("' + src + '")' : '';
            const f = input.files && input.files[0];
            if (button) button.textContent = f ? 'Change' : idleLabel;
            group.classList.toggle('is-done', Boolean(f && src));
            const name = group.querySelector('.document-name'), hint = group.querySelector('.document-desc span:last-child');
            if (name && hint) {
                if (f && src) { name.textContent = f.name; hint.textContent = '\u2713 Ready \u00b7 ' + Math.max(1, Math.round(f.size / 1024)) + ' KB'; }
                else { name.textContent = name.dataset.idle || name.textContent; hint.textContent = hint.dataset.idle || hint.textContent; }
            }
        };

        new MutationObserver(sync).observe(img, { attributes: true, attributeFilter: ['src'] });
        input.addEventListener('change', () => setTimeout(sync, 0));
        sync();
    });
})();

(() => {
    const builder = document.querySelector('[data-seat-builder]');
    if (!builder) return;

    const grid = builder.querySelector('[data-seat-grid]');
    const dataInput = builder.querySelector('[data-seat-layout]');
    const countLabel = builder.querySelector('[data-seat-count]');
    const addRow = builder.querySelector('[data-seat-add-row]');
    const reset = builder.querySelector('[data-seat-reset]');
    const form = builder.closest('form');
    let layout;

    try { layout = JSON.parse(dataInput.value); } catch (_) { layout = null; }
    if (!Array.isArray(layout) || !layout.length || !layout.every(row => Array.isArray(row) && row.length === 4 && row.every(v => typeof v === 'boolean'))) {
        layout = Array.from({ length: 10 }, () => [true, true, true, true]);
    }
    layout = layout.slice(0, 25);

    function seatTotal() {
        return layout.reduce((total, row) => total + row.filter(Boolean).length, 0);
    }

    function render() {
        grid.replaceChildren();
        let number = 0;
        layout.forEach((row, rowIndex) => {
            const rowElement = document.createElement('div');
            rowElement.className = 'seat-row';
            rowElement.setAttribute('role', 'row');
            row.forEach((active, seatIndex) => {
                if (seatIndex === 2) {
                    /* plain gap, exactly like the aisle on the real seat map (no label) */
                    const aisle = document.createElement('span');
                    aisle.className = 'seat-aisle';
                    aisle.setAttribute('aria-hidden', 'true');
                    rowElement.append(aisle);
                }
                if (active) number++;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `seat-cell ${active ? 'is-open' : 'is-removed'}`;
                button.setAttribute('role', 'gridcell');
                button.setAttribute('aria-pressed', String(active));
                button.setAttribute('aria-label', `Row ${rowIndex + 1}, seat ${seatIndex + 1}: ${active ? `included as seat ${number}` : 'removed'}`);
                button.textContent = active ? String(number) : '—';
                button.addEventListener('click', () => {
                    layout[rowIndex][seatIndex] = !layout[rowIndex][seatIndex];
                    render();
                });
                rowElement.append(button);
            });
            grid.append(rowElement);
        });
        const total = seatTotal();
        countLabel.textContent = `${total} seat${total === 1 ? '' : 's'} selected`;
        dataInput.value = JSON.stringify(layout);
        addRow.disabled = layout.length >= 25;
    }

    addRow.addEventListener('click', () => {
        if (layout.length < 25) {
            layout.push([true, true, true, true]);
            render();
            grid.lastElementChild?.scrollIntoView({ block: 'nearest' });
        }
    });

    reset.addEventListener('click', () => {
        layout = Array.from({ length: 10 }, () => [true, true, true, true]);
        render();
    });

    if (form) {
        form.addEventListener('submit', event => {
            const total = seatTotal();
            if (total < 1 || total > 100) {
                event.preventDefault();
                countLabel.textContent = 'Choose between 1 and 100 seats before submitting.';
                grid.querySelector('button')?.focus();
            }
        });
    }

    render();
})();
