/* Admin verification panel: bulk selection, review dialog, bulk reject. */
(function () {
    const form = document.getElementById('vfForm');
    const checks = () => Array.from(document.querySelectorAll('.vf-check'));
    const bulk = document.getElementById('vfBulk');
    const sel = document.getElementById('vfSel');
    const all = document.getElementById('vfAll');

    function sync() {
        const n = checks().filter(c => c.checked).length;
        if (bulk) bulk.hidden = n === 0;
        if (sel) sel.textContent = n;
        if (all) {
            const total = checks().length;
            all.checked = total > 0 && n === total;
            all.indeterminate = n > 0 && n < total;
            all.disabled = total === 0;
        }
    }
    checks().forEach(c => c.addEventListener('change', sync));
    if (all) all.addEventListener('change', () => { checks().forEach(c => { c.checked = all.checked; }); sync(); });
    sync();

    /* Review dialog */
    const dlg = document.getElementById('vfDialog');
    const esc = s => String(s == null ? '' : s);
    document.querySelectorAll('[data-vf-open]').forEach(btn => btn.addEventListener('click', () => {
        const d = JSON.parse(btn.dataset.vfOpen);
        document.getElementById('vfId').value = d.id;
        document.getElementById('vfTitle').textContent = d.name;
        const dl = document.getElementById('vfDl');
        dl.textContent = '';
        [['Email', d.email], ['ID type', d.type], ['ID number', d.number], ['ID expiry', d.expiry + (d.expired ? ' (expired)' : '')],
         ['Submitted', d.submitted], ['Status', d.status + (d.reason ? ': ' + d.reason : '')]].forEach(([k, v]) => {
            const row = document.createElement('div');
            const dt = document.createElement('dt'), dd = document.createElement('dd');
            dt.textContent = k; dd.textContent = esc(v);
            row.appendChild(dt); row.appendChild(dd); dl.appendChild(row);
        });
        const doc = document.getElementById('vfDoc');
        doc.textContent = '';
        const url = 'verification_file.php?id=' + encodeURIComponent(d.id);
        if (String(d.mime || '').indexOf('image/') === 0) {
            const a = document.createElement('a'); a.href = url; a.target = '_blank'; a.rel = 'noopener';
            const img = document.createElement('img'); img.src = url; img.alt = 'Submitted ID'; img.loading = 'lazy';
            a.appendChild(img); doc.appendChild(a);
        } else {
            const a = document.createElement('a'); a.href = url; a.target = '_blank'; a.rel = 'noopener';
            a.className = 'ad-btn'; a.textContent = 'Open submitted document';
            doc.appendChild(a);
        }
        document.getElementById('vfOneReason').value = '';
        document.getElementById('vfReasonWrap').hidden = !d.pending;
        document.getElementById('vfRej').hidden = !d.pending;
        document.getElementById('vfApp').hidden = !d.pending;
        document.getElementById('vfApp').disabled = !!d.expired;
        document.getElementById('vfApp').title = d.expired ? 'This ID has expired' : '';
        dlg.showModal();
    }));
    document.getElementById('vfOne').addEventListener('submit', e => {
        const rej = e.submitter && e.submitter.value === 'reject';
        if (rej && !document.getElementById('vfOneReason').value.trim()) {
            e.preventDefault();
            document.getElementById('vfOneReason').focus();
            document.getElementById('vfOneReason').setCustomValidity('Add a reason');
            document.getElementById('vfOneReason').reportValidity();
        } else document.getElementById('vfOneReason').setCustomValidity('');
    });

    /* Bulk reject */
    const bd = document.getElementById('vfBulkDialog');
    const rb = document.getElementById('vfRejectBtn');
    if (rb) rb.addEventListener('click', () => { document.getElementById('vfBulkReason').value = ''; bd.showModal(); document.getElementById('vfBulkReason').focus(); });
    const go = document.getElementById('vfBulkGo');
    if (go) go.addEventListener('click', () => {
        const r = document.getElementById('vfBulkReason').value.trim();
        if (!r) { document.getElementById('vfBulkReason').focus(); return; }
        document.getElementById('vfReason').value = r;
        const h = document.createElement('input'); h.type = 'hidden'; h.name = 'review'; h.value = 'reject';
        form.appendChild(h); form.submit();
    });

    document.querySelectorAll('[data-vf-close]').forEach(b => b.addEventListener('click', () => { const d = b.closest('dialog'); if (d) d.close(); }));
    [dlg, bd].forEach(d => { if (d) d.addEventListener('click', e => { if (e.target === d) d.close(); }); });
    document.querySelectorAll('.vf-filters input[type=search]').forEach(i => i.addEventListener('keydown', e => { if (e.key === 'Enter') i.form.submit(); }));
})();
