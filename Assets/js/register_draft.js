// Registration helper: (1) live "Gmail only" notice, (2) keep everything typed except passwords
// so a reload / back / opening another page never wipes the form. Cleared once registration succeeds.
(function () {
  var KEY = 'mbus_reg_draft_v1';
  var form = document.querySelector('form.form-body'); if (!form) return;
  var emailInputs = form.querySelectorAll('input[type=email]');

  function hintFor(input) {
    var h = input.parentNode.querySelector('.gmail-live-hint');
    if (!h) { h = document.createElement('small'); h.className = 'gmail-live-hint'; h.setAttribute('role', 'status'); h.style.cssText = 'display:block;color:#c0392b;font-size:.78rem;margin-top:4px'; input.parentNode.appendChild(h); }
    return h;
  }
  function checkEmail(input) {
    var v = (input.value || '').trim().toLowerCase(), h = hintFor(input);
    var at = v.indexOf('@');
    if (v === '' || at === -1 || at === v.length - 1) { h.textContent = ''; input.setCustomValidity(''); return; }
    var domain = v.slice(at + 1);
    if (domain !== 'gmail.com' && 'gmail.com'.indexOf(domain) !== 0) { h.textContent = 'Only @gmail.com addresses are accepted.'; input.setCustomValidity('Only @gmail.com addresses are accepted.'); }
    else if (domain !== 'gmail.com') { h.textContent = ''; input.setCustomValidity('Use a full @gmail.com address.'); }
    else { h.textContent = ''; input.setCustomValidity(''); }
  }
  emailInputs.forEach(function (i) { i.addEventListener('input', function () { checkEmail(i); }); i.addEventListener('blur', function () { checkEmail(i); }); });

  function fields() {
    return Array.prototype.filter.call(form.elements, function (el) {
      if (!el.name || el.disabled) return false;
      var t = (el.type || '').toLowerCase();
      return t !== 'password' && t !== 'file' && t !== 'hidden' && t !== 'submit' && t !== 'button';
    });
  }
  function load() { try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } }
  function save() {
    try {
      var d = {}; fields().forEach(function (el) { d[el.name] = (el.type === 'checkbox' || el.type === 'radio') ? (el.checked ? el.value : null) : el.value; });
      var ut = form.querySelector('#user-type'); d.__user_type = ut ? ut.value : '';
      localStorage.setItem(KEY, JSON.stringify(d));
    } catch (e) {}
  }
  // Restore only into empty fields so values the server sent back after an error are never overwritten.
  var d = load(), ut = form.querySelector('#user-type');
  if (d.__user_type && ut && ut.value === d.__user_type) {
    fields().forEach(function (el) {
      if (!(el.name in d) || d[el.name] === null) return;
      if (el.type === 'checkbox' || el.type === 'radio') { if (el.value === d[el.name]) el.checked = true; }
      else if (el.value === '' || el.tagName === 'SELECT') el.value = d[el.name];
    });
  }
  emailInputs.forEach(checkEmail);
  form.addEventListener('input', save); form.addEventListener('change', save);
  window.addEventListener('pagehide', save);
  window.mbusClearRegDraft = function () { try { localStorage.removeItem(KEY); } catch (e) {} };
})();
