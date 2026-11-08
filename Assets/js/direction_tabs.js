/* Direction tabs (Morong → SBMA / SBMA → Morong) for any list whose items carry data-dir="m|h".
   Usage: mark the list element with data-dir-list; each item gets data-dir. */
(function () {
    'use strict';
    document.querySelectorAll('[data-dir-list]').forEach(function (list) {
        var items = Array.prototype.slice.call(list.querySelectorAll('[data-dir]'));
        if (!items.length) return;
        var labels = { m: 'Morong → SBMA', h: 'SBMA → Morong' }, counts = { m: 0, h: 0 };
        items.forEach(function (i) { if (counts[i.dataset.dir] !== undefined) counts[i.dataset.dir]++; });
        var key = 'mbus_dir_tab', cur = 'm';
        try { cur = sessionStorage.getItem(key) || ''; } catch (e) { cur = ''; }
        if (!counts[cur]) cur = counts.m ? 'm' : 'h';

        var bar = document.createElement('div');
        bar.className = 'dir-tabs'; bar.setAttribute('role', 'tablist'); bar.setAttribute('aria-label', 'Direction');
        var empty = document.createElement('p');
        empty.className = 'dir-empty'; empty.hidden = true;
        var tabs = {};
        ['m', 'h'].forEach(function (d) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'dir-tab'; b.setAttribute('role', 'tab');
            b.append(document.createTextNode(labels[d] + ' '));
            var n = document.createElement('span'); n.className = 'dir-count'; n.textContent = counts[d]; b.append(n);
            b.addEventListener('click', function () { show(d); });
            tabs[d] = b; bar.append(b);
        });
        function show(d) {
            cur = d;
            try { sessionStorage.setItem(key, d); } catch (e) {}
            var shown = 0;
            items.forEach(function (i) { var on = i.dataset.dir === d; i.hidden = !on; if (on) shown++; });
            ['m', 'h'].forEach(function (k) { tabs[k].classList.toggle('on', k === d); tabs[k].setAttribute('aria-selected', k === d ? 'true' : 'false'); });
            empty.hidden = shown > 0; empty.textContent = 'No ' + labels[d] + ' trips here.';
        }
        list.parentNode.insertBefore(bar, list);
        list.parentNode.insertBefore(empty, list.nextSibling);
        show(cur);
    });
})();
