/* Upload rows: show the chosen photo as a small thumbnail in the row (the big preview box is hidden by CSS)
   and turn "Browse" into "Change" once a file is picked. register.js still does the validation and resizing. */
(() => {
    document.querySelectorAll('.upload-group').forEach(group => {
        const icon = group.querySelector('.upload-icon');
        const img = group.querySelector('.document-preview-image');
        const input = group.querySelector('input[type="file"]');
        const button = group.querySelector('.document-input-button button');
        if (!icon || !img || !input || group.hasAttribute('data-multi')) return;
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
                if (f && src) { name.textContent = f.name; hint.textContent = '✓ Ready · ' + Math.max(1, Math.round(f.size / 1024)) + ' KB'; }
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

    /* The driver / door / aisle styles ship with this script, so the layout can never break
       when the stylesheet is an older cached copy. */
    if (!document.getElementById('mbus-seat-builder-style')) {
        const style = document.createElement('style');
        style.id = 'mbus-seat-builder-style';
        style.textContent = [
            '.seat-layout-preview::before{content:"FRONT OF BUS";text-align:center;color:#8a98ae;font-size:11px;font-weight:700;letter-spacing:.14em}',
            '.seat-aisle{display:block;position:relative;align-self:stretch}',
            '.seat-layout-preview .seat-aisle::before{content:"";position:absolute;top:-4px;bottom:-4px;left:50%;border-left:2px dashed #c3d5f5;transform:translateX(-1px)}',
            '.seat-builder .seat-driver{grid-column:1 / 3;box-sizing:border-box;height:48px;display:flex;align-items:center;justify-content:center;border-radius:14px;background:#14213d;color:#fff;font:inherit;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}',
            '.seat-builder .seat-door{box-sizing:border-box;height:48px;padding:0;border:1.5px solid #d3def2;border-radius:12px;background:repeating-linear-gradient(135deg,#eef3fb 0 6px,#e3ebf8 6px 12px);color:#6b7a99;font:inherit;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;cursor:pointer}',
            '.seat-builder .seat-door:hover{border-color:#2f6fe4;color:#2f6fe4}',
            '@media (max-width:640px){.seat-builder .seat-driver,.seat-builder .seat-door{height:44px}}'
        ].join('\n');
        document.head.append(style);
    }

    const grid = builder.querySelector('[data-seat-grid]');
    const dataInput = builder.querySelector('[data-seat-layout]');
    const countLabel = builder.querySelector('[data-seat-count]');
    const doorWarning = builder.querySelector('[data-seat-warning]');
    const layoutLocked = builder.dataset.layoutLocked === '1';
    const addRow = builder.querySelector('[data-seat-add-row]');
    const minusRow = builder.querySelector('[data-seat-minus-row]');
    const reset = builder.querySelector('[data-seat-reset]');
    const form = builder.closest('form');
    let layout;

    /* The first row, left pair is the driver's cell; seats 1 and 2 start beside the driver. */
    function layoutForSeatCount(count) {
        const result = [[false, false, false, false]];
        let left = Math.max(1, Math.min(40, count));
        const bench = left >= 9;   // back row: 5 seats side by side
        if (bench) left -= 5;
        for (let i = 2; i < 4 && left > 0; i++) { result[0][i] = true; left--; }
        while (left > 0) {
            const row = [];
            for (let i = 0; i < 4; i++) { row.push(left > 0); if (left > 0) left--; }
            result.push(row);
        }
        if (bench) result.push([true, true, true, true, true]);
        return result;
    }

    function defaultLayout() { return layoutForSeatCount(40); }

    /* Old layouts had four seats in row 1. Normalize them once so the driver occupies only the front-left pair,
       seats 1 and 2 are beside the driver, and every later seat is drawn exactly once in order. */
    function normalizeLegacyLayout(value) {
        if (value[0][0] && value[0][1] && value[0][2] && value[0][3]) {
            return layoutForSeatCount(value.reduce((total, row) => total + row.filter(Boolean).length, 0));
        }
        return value;
    }

    function driverInside() {
        return !layout[0][0] && !layout[0][1];
    }

    try { layout = JSON.parse(dataInput.value); } catch (_) { layout = null; }
    if (!Array.isArray(layout) || !layout.length || !layout.every(row => Array.isArray(row) && (row.length === 4 || (row.length === 5 && row.every(Boolean))) && row.every(v => typeof v === 'boolean'))) {
        layout = defaultLayout();
    }
    layout = normalizeLegacyLayout(layout);
    layout = layout.slice(0, 25);

    const MAX_SEATS = 40, MAX_DOORS = 1;
    function seatTotal() {
        return layout.reduce((total, row) => total + row.filter(Boolean).length, 0);
    }
    /* a door is a side-by-side pair with no seats (the driver's cell does not count) */
    function doorTotal() {
        let n = 0;
        layout.forEach((row, i) => [0, 2].forEach(s => { if (!(i === 0 && s === 0) && !row[s] && !row[s + 1]) n++; }));
        return n;
    }
    function limitMessage() {
        const total = seatTotal(), doors = doorTotal();
        if (total > MAX_SEATS) return `Too many seats: ${total}. The most is ${MAX_SEATS}.`;
        if (doors > MAX_DOORS) return `Too many doors: ${doors}. The most is ${MAX_DOORS}.`;
        if (total < 1) return 'Choose at least 1 seat.';
        return '';
    }

    function render() {
        grid.replaceChildren();
        const legacyFrontSeats = !driverInside() && layout[0][2] && layout[0][3];
        let number = 0;
        if (!driverInside()) {
            const front = document.createElement('div');
            front.className = 'seat-row seat-front';
            front.setAttribute('role', 'row');
            const cab = document.createElement('span');
            cab.className = 'seat-driver';
            cab.style.gridColumn = '1 / 3';
            cab.setAttribute('role', 'gridcell');
            cab.setAttribute('aria-label', 'Driver seat');
            cab.textContent = 'Driver';
            const lane = document.createElement('span');
            lane.className = 'seat-aisle';
            lane.setAttribute('aria-hidden', 'true');
            front.append(cab, lane);
            if (legacyFrontSeats) {
                [2, 3].forEach(seatIndex => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'seat-cell is-open';
                    button.style.gridColumn = seatIndex === 2 ? '4' : '5';
                    button.setAttribute('role', 'gridcell');
                    button.setAttribute('aria-pressed', 'true');
                    button.setAttribute('aria-label', `Front row, seat ${seatIndex + 1}`);
                    button.textContent = String(seatIndex + 1);
                    button.addEventListener('click', () => {
                        layout[0][seatIndex] = false;
                        render();
                    });
                    front.append(button);
                });
            }
            grid.append(front);
        }
        layout.forEach((row, rowIndex) => {
            const rowElement = document.createElement('div');
            rowElement.className = 'seat-row';
            rowElement.setAttribute('role', 'row');

            /* back bench: 5 fixed seats, no aisle */
            if (row.length === 5) {
                rowElement.classList.add('seat-back');
                row.forEach((_, i) => {
                    number++;
                    const seat = document.createElement('span');
                    seat.className = 'seat-cell is-open';
                    seat.setAttribute('role', 'gridcell');
                    seat.setAttribute('aria-label', `Back row, seat ${number}`);
                    seat.textContent = String(number);
                    rowElement.append(seat);
                });
                grid.append(rowElement);
                return;
            }

            [0, 2].forEach(start => {
                if (start === 2) {
                    /* the aisle: a visible dashed lane down the middle of the bus */
                    const aisle = document.createElement('span');
                    aisle.className = 'seat-aisle';
                    aisle.setAttribute('aria-hidden', 'true');
                    rowElement.append(aisle);
                }

                /* driver seat: first row, left side, while that pair is free. Not tappable. */
                if (rowIndex === 0 && start === 0 && driverInside()) {
                    const driver = document.createElement('span');
                    driver.className = 'seat-driver';
                    driver.style.gridColumn = '1 / 3';
                    driver.setAttribute('role', 'gridcell');
                    driver.setAttribute('aria-label', 'Driver seat');
                    driver.textContent = 'Driver';
                    rowElement.append(driver);
                    return;
                }

                /* two seats side by side both removed: the pair is cleared automatically and shown as a door */
                if (!row[start] && !row[start + 1]) {
                    const door = document.createElement('button');
                    door.type = 'button';
                    door.className = 'seat-door';
                    door.style.gridColumn = start === 0 ? '1 / 3' : '4 / 6';
                    door.setAttribute('role', 'gridcell');
                    door.setAttribute('aria-label', `Row ${rowIndex + 1}: door, no seats. Tap to add the two seats back`);
                    door.disabled = layoutLocked;
                    door.textContent = 'Door';
                    door.addEventListener('click', () => {
                        if (layoutLocked) return;
                        layout[rowIndex][start] = true;
                        layout[rowIndex][start + 1] = true;
                        render();
                    });
                    rowElement.append(door);
                    return;
                }

                [start, start + 1].forEach(seatIndex => {
                    const active = row[seatIndex];
                    if (legacyFrontSeats && rowIndex === 0 && start === 2 && active && number < 4) number = 4;
                    if (active) number++;
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = `seat-cell ${active ? 'is-open' : 'is-removed'}`;
                    button.setAttribute('role', 'gridcell');
                    button.setAttribute('aria-pressed', String(active));
                    button.setAttribute('aria-label', `Row ${rowIndex + 1}, seat ${seatIndex + 1}: ${active ? `included as seat ${number}` : 'removed'}`);
                    const other = seatIndex === start ? start + 1 : start;
                    const wouldCreateAdditionalDoor = active && !row[other] && doorTotal() >= MAX_DOORS;
                    button.disabled = layoutLocked || wouldCreateAdditionalDoor;
                    if (wouldCreateAdditionalDoor) button.setAttribute('aria-label', 'Maximum of 1 door reached; this seat cannot be removed');
                    button.textContent = active ? String(number) : '—';
                    button.addEventListener('click', () => {
                        if (layoutLocked) return;
                        if (active && !layout[rowIndex][other] && doorTotal() >= MAX_DOORS) return;
                        layout[rowIndex][seatIndex] = !layout[rowIndex][seatIndex];
                        render();
                    });
                    rowElement.append(button);
                });
            });
            grid.append(rowElement);
        });
        const total = seatTotal();
        const doors = doorTotal();
        const problem = limitMessage();
        countLabel.textContent = problem || `${total} seat${total === 1 ? '' : 's'} selected · ${doors} door${doors === 1 ? '' : 's'}`;
        countLabel.style.color = problem ? '#b42318' : '';
        if (doorWarning) {
            doorWarning.textContent = doors >= MAX_DOORS ? 'Maximum of 1 door reached. Remove it before adding another.' : '';
            doorWarning.hidden = doors < MAX_DOORS;
        }
        dataInput.value = JSON.stringify(layout);
        addRow.disabled = layoutLocked || layout.length >= 25;
        minusRow.disabled = layoutLocked || layout.length - (layout[layout.length - 1].length === 5 ? 1 : 0) <= 1;
        reset.disabled = layoutLocked;
    }

    addRow.addEventListener('click', () => {
        if (!layoutLocked && layout.length < 25) {
            const at = layout[layout.length - 1].length === 5 ? layout.length - 1 : layout.length;
            layout.splice(at, 0, [true, true, true, true]);
            render();
            grid.lastElementChild?.scrollIntoView({ block: 'nearest' });
        }
    });

    minusRow.addEventListener('click', () => {
        const benchRow = layout[layout.length - 1].length === 5 ? 1 : 0;
        if (!layoutLocked && layout.length - benchRow > 1) {
            layout.splice(layout.length - 1 - benchRow, 1);
            render();
        }
    });

    reset.addEventListener('click', () => {
        if (layoutLocked) return;
        layout = defaultLayout();
        render();
    });

    if (form) {
        form.addEventListener('submit', event => {
            if (limitMessage()) {
                event.preventDefault();
                countLabel.textContent = limitMessage();
                grid.querySelector('button')?.focus();
            }
        });
    }

    render();
})();


/* One upload box for up to 3 files (license / vehicle registration): the first file stays in the main field and
   the others are handed to hidden fields, so the server still gets license_document, _2 and _3. register.js
   shrinks each photo through its own change handler. */
(() => {
    const MAX = 3;
    document.querySelectorAll('.upload-group[data-multi]').forEach(group => {
        const prefix = group.dataset.extraPrefix;
        const main = group.querySelector('input[type="file"]');
        const holder = document.querySelector('[data-extra-for="' + prefix + '"]');
        const box = group.querySelector('.upload-document-group');
        const icon = group.querySelector('.upload-icon');
        const button = group.querySelector('.document-input-button button');
        const name = group.querySelector('.document-name');
        const hint = group.querySelector('.document-hint');
        if (!main || !holder || !box || !name || !hint) return;
        const extras = Array.from(holder.querySelectorAll('input[type="file"]'));
        const inputs = [main].concat(extras);
        const idle = { name: name.textContent, hint: hint.textContent, button: button ? button.textContent : '' };
        const strip = document.createElement('div');
        strip.className = 'multi-thumbs';
        strip.hidden = true;
        box.insertBefore(strip, box.querySelector('.document-desc'));
        const urls = [];

        function give(input, file) {
            let changed = false;
            try {
                const dt = new DataTransfer();
                if (file) dt.items.add(file);
                input.files = dt.files;
                changed = true;
            } catch (_) { /* old browser: keep what it has */ }
            return changed;
        }

        function draw() {
            urls.splice(0).forEach(u => URL.revokeObjectURL(u));
            strip.replaceChildren();
            const files = inputs.map(i => i.files && i.files[0]).filter(Boolean);
            const done = files.length > 0;
            strip.hidden = !done;
            if (icon) icon.style.display = done ? 'none' : '';
            group.classList.toggle('is-done', done);
            if (button) button.textContent = done ? 'Change' : idle.button;
            if (!done) { name.textContent = idle.name; hint.textContent = idle.hint; return; }
            files.forEach(f => {
                const t = document.createElement('span');
                t.className = 'mt';
                if (/^image\//.test(f.type)) {
                    const u = URL.createObjectURL(f); urls.push(u);
                    t.style.backgroundImage = 'url("' + u + '")';
                } else { t.textContent = 'PDF'; t.classList.add('mt-pdf'); }
                strip.append(t);
            });
            const kb = Math.max(1, Math.round(files.reduce((n, f) => n + f.size, 0) / 1024));
            name.textContent = files.length === 1 ? files[0].name : files.length + ' files selected';
            hint.textContent = '\u2713 Ready \u00b7 ' + kb + ' KB';
        }

        /* capture phase: runs before register.js sees the change, so it only ever handles one file per field */
        main.addEventListener('change', () => {
            const picked = Array.from(main.files || []).slice(0, MAX);
            if (picked.length > 1) give(main, picked[0]);
            extras.forEach((input, i) => {
                const file = picked[i + 1] || null;
                if ((input.files && input.files.length) || file) {
                    give(input, file);
                    if (file) input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            setTimeout(draw, 0);
        }, { capture: true });

        /* register.js swaps in a smaller copy after it finishes: redraw then */
        [group].concat(Array.from(holder.querySelectorAll('.upload-group'))).forEach(g => {
            const img = g.querySelector('.document-preview-image');
            if (img) new MutationObserver(() => setTimeout(draw, 0)).observe(img, { attributes: true, attributeFilter: ['src'] });
        });
        draw();
    });
})();
