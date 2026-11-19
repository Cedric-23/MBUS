// Instant feedback on submit: disables the button and shows a spinner so slow
// server responses (cold start, remote DB) don't look like a dead click.
(function () {
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM' || e.defaultPrevented || f.hasAttribute('data-no-busy')) return;
    var b = e.submitter || f.querySelector('button[type=submit]');
    if (!b || b.name === 'resend') return;
    setTimeout(function () {
      if (b.classList.contains('is-busy')) return;
      b.classList.add('is-busy');
      b.setAttribute('aria-busy', 'true');
      b.style.pointerEvents = 'none';
      b.dataset.label = b.textContent;
      b.textContent = 'Please wait…';
    }, 0);
  }, false);
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    document.querySelectorAll('.is-busy').forEach(function (b) {
      b.classList.remove('is-busy'); b.removeAttribute('aria-busy'); b.style.pointerEvents = '';
      if (b.dataset.label) b.textContent = b.dataset.label;
    });
  });
})();
