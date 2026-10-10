/*
 * WooCommerce → FeCommerce screen: confirm Disconnect, and add recently seen
 * sites to the allow list.
 * Copyright (C) 2026 FeCommerce. GPL-2.0-or-later.
 */
(function () {
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
