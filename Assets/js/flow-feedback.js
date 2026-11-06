/* Light feedback for the commuter booking flow:
 * a thin progress bar while the next page loads, and a busy state on buttons that submit. */
(function () {
    var bar = document.createElement('div');
    bar.className = 'nav-progress';
    bar.setAttribute('aria-hidden', 'true');
    document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(bar); });

    function start() { bar.classList.remove('done'); bar.classList.add('run'); }
    function stop() { bar.classList.remove('run'); bar.classList.add('done'); }

    // Coming back with the back button restores the page from cache: clear any stuck bar.
    window.addEventListener('pageshow', function (e) {
        stop();
        document.querySelectorAll('.is-busy').forEach(function (b) { window.setBusy(b, false); });
    });

    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a || e.defaultPrevented || a.target === '_blank' || e.metaKey || e.ctrlKey) return;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
        start();
    });

    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        start();
        var btn = e.submitter || e.target.querySelector('button[type=submit]');
        if (btn) {
            // Disable after the browser has read the button's name/value.
            setTimeout(function () { window.setBusy(btn, true); }, 0);
        }
    });

    /* setBusy(button, true, 'Reserving') swaps the label for a small spinner + text. */
    window.setBusy = function (btn, busy, label) {
        if (!btn) return;
        if (busy) {
            if (btn.classList.contains('is-busy')) return;
            btn.dataset.label = btn.innerHTML;
            btn.classList.add('is-busy');
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span>' + (label || 'Please wait');
            start();
        } else if (btn.classList.contains('is-busy')) {
            btn.classList.remove('is-busy');
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            btn.innerHTML = btn.dataset.label || btn.innerHTML;
            stop();
        }
    };
})();
