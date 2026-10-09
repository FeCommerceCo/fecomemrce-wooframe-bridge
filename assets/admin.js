/*
 * WooCommerce → FeCommerce screen: show/hide and copy the connection key,
 * confirm Regenerate and Disconnect, and add recently seen sites to the
 * allow list.
 * Copyright (C) 2026 FeCommerce. GPL-2.0-or-later.
 */
(function () {
    var key = document.getElementById('fecwf-key');
    var reveal = document.getElementById('fecwf-reveal');
    var copy = document.getElementById('fecwf-copy');
    var status = document.getElementById('fecwf-copy-status');
    var error = document.getElementById('fecwf-copy-error');

    if (key && reveal) {
        // Rendered as text so the key stays copyable if this script never
        // runs; hide it now that the eye button works.
        key.type = 'password';
        reveal.addEventListener('click', function () {
            var show = key.type === 'password';
            key.type = show ? 'text' : 'password';
            reveal.classList.toggle('is-on', show);
            reveal.setAttribute('aria-pressed', show ? 'true' : 'false');
        });
    }

    if (key && copy) {
        var timer;
        var done = function () {
            if (error) error.hidden = true;
            copy.classList.add('is-on');
            if (status) status.textContent = 'Key copied';
            clearTimeout(timer);
            timer = setTimeout(function () {
                copy.classList.remove('is-on');
                if (status) status.textContent = '';
            }, 2000);
        };
        // Copies the real key whether it's shown or hidden.
        // Leaves the key shown and selected so it can be copied by hand.
        var failed = function () {
            if (key.type === 'password') {
                if (reveal) reveal.click(); else key.type = 'text';
            }
            key.focus();
            key.select();
            if (error) error.hidden = false;
            if (status) status.textContent = error ? error.textContent : "Couldn't copy the key.";
        };
        var fallback = function () {
            var was = key.type, ok = false;
            key.type = 'text';
            key.select();
            try { ok = document.execCommand('copy'); } catch (e) {}
            key.type = was;
            if (!ok) {
                failed();
                return;
            }
            key.setSelectionRange(0, 0);
            copy.focus();
            done();
        };
        copy.addEventListener('click', function () {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(key.value).then(done, fallback);
            } else {
                fallback();
            }
        });
    }

    document.querySelectorAll('form[data-fecwf-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (!window.confirm(f.getAttribute('data-fecwf-confirm'))) e.preventDefault();
        });
    });

    var chips = Array.prototype.slice.call(document.querySelectorAll('.fecwf-add-site'));
    chips.forEach(function (b) {
        b.addEventListener('click', function () {
            var t = document.getElementById('fecwf-sites'), s = b.getAttribute('data-site');
            var live = document.getElementById('fecwf-sites-status');
            if (t.value.split(/\s+/).indexOf(s) === -1) t.value = (t.value.trim() ? t.value.trim() + '\n' : '') + s;
            // Move focus on before disabling, or keyboard users lose their place.
            var next = chips.filter(function (c) { return c !== b && !c.disabled; })[0];
            (next || t).focus();
            b.disabled = true;
            if (live) live.textContent = 'Added ' + s + ' to allowed sites. Click Save to keep it.';
        });
    });
})();
