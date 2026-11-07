/* Show/hide password: one tap to show, one tap to hide. Works on touch (the old hold-to-show
   did not) and uses inline icons, so it doesn't depend on the icon font loading. */
(() => {
    'use strict';
    const EYE = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
    const EYE_OFF = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5.5 19 2 12 2 12a19.6 19.6 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a19.5 19.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>';

    const css = document.createElement('style');
    css.textContent = '.pw-wrap{position:relative;display:block}.pw-wrap>input{padding-right:48px!important;width:100%;box-sizing:border-box}' +
        '.password-toggle,.pw-eye{display:grid!important;place-items:center;width:44px;height:44px;padding:0!important;border:0;background:none;color:#6b7a8d;cursor:pointer;-webkit-tap-highlight-color:transparent}' +
        '.pw-eye{position:absolute;right:2px;top:50%;transform:translateY(-50%)}' +
        '.password-toggle{right:2px!important}.password-toggle:focus-visible,.pw-eye:focus-visible{outline:3px solid #1e90ff;outline-offset:-3px;border-radius:10px}';
    document.head.appendChild(css);

    function bind(input, btn) {
        btn.type = 'button';
        btn.removeAttribute('onmousedown'); btn.removeAttribute('onmouseup'); btn.removeAttribute('onmouseleave');
        btn.innerHTML = EYE;
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? EYE_OFF : EYE;
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', String(show));
            input.focus({ preventScroll: true });
        });
    }

    document.querySelectorAll('input[type="password"]').forEach(input => {
        const field = input.parentElement;
        const existing = field && field.querySelector(':scope > .password-toggle');
        if (existing) { bind(input, existing); return; }
        const wrap = document.createElement('span');
        wrap.className = 'pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        const btn = document.createElement('button');
        btn.className = 'pw-eye';
        wrap.appendChild(btn);
        bind(input, btn);
    });
})();
