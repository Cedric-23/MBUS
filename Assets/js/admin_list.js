/* Admin list pages: add/edit dialog, search, delete confirmation. */
(function () {
    const dlg = document.getElementById('adDialog');
    const form = dlg ? dlg.querySelector('form') : null;

    function openDialog(btn) {
        if (!dlg) return;
        form.reset();
        form.querySelectorAll('input[type=hidden]:not([name=csrf_token])').forEach(i => { i.value = ''; });
        const data = btn.dataset.edit ? JSON.parse(btn.dataset.edit) : {};
        Object.keys(data).forEach(k => {
            const el = form.elements[k];
            if (el) el.value = data[k];
        });
        document.getElementById('adTitle').textContent = btn.dataset.title || '';
        const submit = document.getElementById('adSubmit');
        submit.name = btn.dataset.action;
        submit.textContent = btn.dataset.adOpen === "add" ? 'Save' : 'Save changes';
        if (form.elements['arrival_time']) { form.elements['arrival_time'].min = data.departure_time || ''; form.elements['arrival_time'].setCustomValidity(''); }
        dlg.showModal();
        const first = form.querySelector('input:not([type=hidden]), select');
        if (first) first.focus();
    }

    // Schedule dialog: arrival follows departure (+1 hour) and can never be earlier.
    if (form && form.elements['departure_time'] && form.elements['arrival_time']) {
        const dep = form.elements['departure_time'], arr = form.elements['arrival_time'];
        const fmt = d => { const p = n => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); };
        dep.addEventListener('change', () => {
            if (!dep.value) return;
            arr.min = dep.value;
            if (!arr.value || arr.value <= dep.value) arr.value = fmt(new Date(new Date(dep.value).getTime() + 3600000));
        });
        arr.addEventListener('change', () => { arr.setCustomValidity(arr.value && dep.value && arr.value <= dep.value ? 'Arrival must be after departure' : ''); });
        form.addEventListener('submit', () => { arr.setCustomValidity(arr.value <= dep.value ? 'Arrival must be after departure' : ''); }, true);
    }

    document.querySelectorAll('[data-ad-open]').forEach(b => b.addEventListener('click', () => openDialog(b)));
    document.querySelectorAll('[data-ad-close]').forEach(b => b.addEventListener('click', () => dlg.close()));
    if (dlg) dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });

    document.querySelectorAll('form[data-confirm]').forEach(f => {
        f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
    });

    const search = document.getElementById('adSearch');
    if (search) {
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            document.querySelectorAll('[data-q]').forEach(el => { el.hidden = q !== '' && !el.dataset.q.includes(q); });
            document.querySelectorAll('[data-group]').forEach(g => {
                g.hidden = !g.querySelector('[data-q]:not([hidden])');
            });
        });
    }
})();
