/*
 * WooCommerce → FeCommerce screen: confirm Disconnect, copy the "Allow
 * FeCommerce" rule, and add recently seen sites to the allow list.
 * Copyright (C) 2026 FeCommerce. GPL-2.0-or-later.
 */
(function () {
    document.querySelectorAll('form[data-fecwf-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (!window.confirm(f.getAttribute('data-fecwf-confirm'))) e.preventDefault();
        });
    });

    // Copy buttons (the "Allow FeCommerce" rule): clipboard, else select the
    // text so the admin can press Cmd/Ctrl+C.
    document.querySelectorAll('[data-fecwf-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
            var id = b.getAttribute('data-fecwf-copy'), el = document.getElementById(id);
            var live = document.getElementById(id + '-status');
            if (!el) return;
            var text = el.textContent;
            var selectIt = function () {
                var range = document.createRange();
                range.selectNodeContents(el);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                if (live) live.textContent = 'Selected. Press Cmd+C or Ctrl+C to copy.';
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    if (live) live.textContent = 'Copied.';
                }, selectIt);
            } else {
                selectIt();
            }
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
