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

    if (key && reveal) {
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
            copy.classList.add('is-on');
            if (status) status.textContent = 'Key copied';
            clearTimeout(timer);
            timer = setTimeout(function () {
                copy.classList.remove('is-on');
                if (status) status.textContent = '';
            }, 2000);
        };
        // Copies the real key whether it's shown or hidden.
        var fallback = function () {
            var was = key.type;
            key.type = 'text';
            key.select();
            try { if (document.execCommand('copy')) done(); } catch (e) {}
            key.type = was;
            key.setSelectionRange(0, 0);
            copy.focus();
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

    document.querySelectorAll('.fecwf-add-site').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = document.getElementById('fecwf-sites'), s = b.getAttribute('data-site');
            if (t.value.split(/\s+/).indexOf(s) === -1) t.value = (t.value.trim() ? t.value.trim() + '\n' : '') + s;
            b.disabled = true;
        });
    });
})();
