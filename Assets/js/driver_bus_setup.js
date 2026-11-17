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
                    const aisle = document.createElement('span');
                    aisle.className = 'seat-aisle';
                    aisle.setAttribute('aria-hidden', 'true');
                    aisle.textContent = 'AISLE';
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
