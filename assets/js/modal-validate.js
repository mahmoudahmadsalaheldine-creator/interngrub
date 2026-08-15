(function () {
    'use strict';

    function fieldLabel(el) {
        return el.dataset.valLabel || el.name || 'This field';
    }

    function checkField(el) {
        var v  = el.value;
        var t  = v.trim();
        var ds = el.dataset;

        if ('valRequired' in ds && t === '')
            return (ds.valRequired || 'This field') + ' is required.';

        if (t === '') return ''; // empty + optional → skip remaining checks

        if ('valMinlen' in ds) {
            var min = parseInt(ds.valMinlen, 10);
            if (t.length < min) return fieldLabel(el) + ' must be at least ' + min + ' characters.';
        }
        if ('valMaxlen' in ds) {
            var max = parseInt(ds.valMaxlen, 10);
            if (v.length > max) return fieldLabel(el) + ' must be ' + max + ' characters or fewer.';
        }
        if ('valEmail' in ds) {
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t))
                return 'Please enter a valid email address.';
        }
        if ('valUrl' in ds) {
            try { new URL(v); } catch (e) {
                return fieldLabel(el) + ' must be a valid URL (include https://).';
            }
        }
        if ('valUsername' in ds) {
            if (t.length < 3)  return 'Username must be at least 3 characters.';
            if (t.length > 50) return 'Username must be 50 characters or fewer.';
            if (!/^[a-zA-Z0-9._\-]+$/.test(t))
                return 'Username may only contain letters, numbers, dots, hyphens, and underscores.';
        }
        if ('valDate' in ds) {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return fieldLabel(el) + ' must be a valid date.';
            var d = new Date(v + 'T00:00:00');
            if (isNaN(d.getTime())) return fieldLabel(el) + ' is not a valid date.';
        }
        return '';
    }

    // Intercept submit inside any modal
    document.addEventListener('submit', function (e) {
        var modal = e.target.closest('.modal-overlay');
        if (!modal) return;
        var fields = modal.querySelectorAll(
            '[data-val-required],[data-val-email],[data-val-url],' +
            '[data-val-username],[data-val-date],[data-val-minlen],[data-val-maxlen]'
        );
        for (var i = 0; i < fields.length; i++) {
            var el = fields[i];
            if ('valIfFilled' in el.dataset && el.value.trim() === '') continue;
            var err = checkField(el);
            if (err) {
                e.preventDefault();
                if (typeof window.showToast === 'function') window.showToast(err, 'error');
                return;
            }
        }
    }, true); // capture phase — runs before any inline onsubmit
})();
