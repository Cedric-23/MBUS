/* Live field checks for sign-up forms. The server repeats every rule; this only explains problems sooner. */
(function () {
    'use strict';
    var typos = { 'gmial.com': 'gmail.com', 'gmai.com': 'gmail.com', 'gnail.com': 'gmail.com', 'gmail.co': 'gmail.com', 'gmail.con': 'gmail.com', 'yaho.com': 'yahoo.com', 'hotmal.com': 'hotmail.com', 'outlok.com': 'outlook.com' };
    var rules = {
        full_name: function (v) {
            v = v.trim().replace(/\s+/g, ' ');
            if (!v) return 'Full name is required.';
            if (v.length < 2) return 'Full name is too short (at least 2 letters).';
            if (!/^[A-Za-zÀ-ÖØ-öø-ÿĀ-ɏ][A-Za-zÀ-ÖØ-öø-ÿĀ-ɏ .'\-]*$/.test(v)) return 'Use letters only (no numbers or symbols).';
            return '';
        },
        email: function (v) {
            v = v.trim().toLowerCase();
            if (!v) return 'Email is required.';
            if (/\s/.test(v)) return 'Email cannot contain spaces.';
            if ((v.match(/@/g) || []).length !== 1) return 'Email needs one @ sign, like name@gmail.com.';
            var parts = v.split('@');
            if (!parts[0]) return 'Email is missing the part before the @.';
            if (!/^[^@\s]+\.[a-z]{2,}$/.test(parts[1])) return 'Email domain looks incomplete (for example gmail.com).';
            if (typos[parts[1]]) return 'Did you mean ' + parts[0] + '@' + typos[parts[1]] + '?';
            return '';
        },
        phone_number: function (v) {
            if (!v) return 'Phone number is required.';
            if (/\D/.test(v)) return 'Phone number can only contain numbers.';
            if (v.length !== 11) return 'Phone number must be 11 digits (you entered ' + v.length + ').';
            if (v.slice(0, 2) !== '09') return 'Phone number must start with 09.';
            return '';
        },
        password: function (v) {
            if (!v) return 'Password is required.';
            if (v.length < 8) return 'Password is too short (at least 8 characters).';
            if (!/[A-Za-z]/.test(v)) return 'Password needs at least one letter.';
            if (!/\d/.test(v)) return 'Password needs at least one number.';
            return '';
        },
        confirm_password: function (v, form) {
            if (!v) return 'Confirm your password.';
            return v !== form.elements.password.value ? 'Passwords do not match.' : '';
        },
        license_number: function (v) {
            v = v.trim();
            if (!v) return 'License number is required.';
            return /^[A-Za-z0-9][A-Za-z0-9\- ]{4,19}$/.test(v) ? '' : 'License number must be 5 to 20 letters, numbers or hyphens.';
        },
        vehicle_plate_number: function (v) {
            v = v.trim();
            if (!v) return 'Plate number is required.';
            return /^[A-Za-z0-9][A-Za-z0-9\- ]{3,11}$/.test(v) ? '' : 'Plate number must be 4 to 12 letters or numbers.';
        },
        address: function (v) { v = v.trim(); return v.length < 8 ? 'Enter your complete address (at least 8 characters).' : ''; }
    };

    function slot(input) {
        var wrap = input.closest('.form-field') || input.parentNode;
        var el = wrap.querySelector('.field-error');
        if (!el) { el = document.createElement('small'); el.className = 'field-error'; el.setAttribute('role', 'alert'); wrap.appendChild(el); }
        return { wrap: wrap, el: el };
    }
    function show(input, msg) {
        var s = slot(input);
        s.el.textContent = msg;
        s.el.hidden = !msg;
        s.wrap.classList.toggle('has-error', !!msg);
        input.setAttribute('aria-invalid', msg ? 'true' : 'false');
    }
    function check(input, form) {
        var r = rules[input.name];
        if (!r) return true;
        var msg = r(input.value, form);
        show(input, msg);
        return !msg;
    }

    document.querySelectorAll('form[data-auth-form]').forEach(function (form) {
        var touched = {};
        Array.prototype.forEach.call(form.elements, function (input) {
            if (!rules[input.name]) return;
            if (input.name === 'phone_number') {
                input.addEventListener('input', function () { input.value = input.value.replace(/\D/g, '').slice(0, 11); });
            }
            input.addEventListener('blur', function () { touched[input.name] = true; check(input, form); });
            input.addEventListener('input', function () {
                if (touched[input.name] || (slot(input).el.textContent && !slot(input).el.hidden)) check(input, form);
                if (input.name === 'password' && touched.confirm_password) check(form.elements.confirm_password, form);
            });
        });
        form.addEventListener('submit', function (ev) {
            var first = null;
            Array.prototype.forEach.call(form.elements, function (input) {
                if (rules[input.name] && !check(input, form) && !first) first = input;
            });
            if (first) { ev.preventDefault(); first.focus(); }
        });
    });
})();
