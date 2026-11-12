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
        dlg.showModal();
        const first = form.querySelector('input:not([type=hidden]), select');
        if (first) first.focus();
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
