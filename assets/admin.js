/*
 * WooCommerce → FeCommerce screen: show/hide and copy the connection key,
 * and add recently seen sites to the allow list.
 * Copyright (C) 2026 FeCommerce. GPL-2.0-or-later.
 */
(function () {
    var key = document.getElementById('fecwf-key');
    var reveal = document.getElementById('fecwf-reveal');
    var copy = document.getElementById('fecwf-copy');

    if (key && reveal) {
        reveal.addEventListener('click', function () {
            var show = key.type === 'password';
            key.type = show ? 'text' : 'password';
            reveal.classList.toggle('is-on', show);
            reveal.setAttribute('aria-pressed', show ? 'true' : 'false');
            reveal.setAttribute('aria-label', show ? 'Hide key' : 'Show key');
        });
    }

    if (key && copy) {
        var timer;
        var done = function () {
            copy.classList.add('is-on');
            clearTimeout(timer);
            timer = setTimeout(function () { copy.classList.remove('is-on'); }, 2000);
        };
        // Copies the real key whether it's shown or hidden.
        var fallback = function () {
            var was = key.type;
            key.type = 'text';
            key.select();
            try { if (document.execCommand('copy')) done(); } catch (e) {}
            key.type = was;
            key.setSelectionRange(0, 0);
        };
        copy.addEventListener('click', function () {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(key.value).then(done, fallback);
            } else {
                fallback();
            }
        });
    }

    document.querySelectorAll('.fecwf-add-site').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = document.getElementById('fecwf-sites'), s = b.getAttribute('data-site');
            if (t.value.split(/\s+/).indexOf(s) === -1) t.value = (t.value.trim() ? t.value.trim() + '\n' : '') + s;
            b.disabled = true;
        });
    });
})();
