<?php
/**
 * Optional: restrict which sites may use this store.
 *
 * Off by default: any website may read the public store routes (the Store API
 * and /fecommerce/v1), without cookies, as before. When the admin turns it on,
 * those routes only answer cross-site browser requests from the listed site
 * addresses. Always allowed regardless of the list:
 *   - this store's own site (its theme and checkout),
 *   - Framer's own addresses (the plugin sandbox, editor and canvas), so
 *     syncing from Framer never breaks.
 *
 * Requests without an Origin header (servers, command-line tools) are not
 * browser cross-site requests and are unaffected, as the data is public.
 *
 * This is a BROWSER restriction, not access control. It stops another website
 * from using the store in its visitors' browsers, which always send their
 * real Origin and can't be made to fake it. It can't stop a server or script
 * calling the routes directly, since that caller can send any Origin or none:
 * WooCommerce's Store API is public on every WooCommerce store, and cart and
 * checkout keep WooCommerce's own protections (cart token, nonce, payment
 * gateway) whatever this setting says.
 *
 * To help the admin build the list, the addresses of HTTPS sites recently
 * seen calling the store are remembered (at most 20). Anyone can send a
 * request with any Origin header, so this list is only a suggestion the admin
 * must check, and it is written at most once every 5 minutes so it can't be
 * used to flood the database.
 *
 * The three settings are small and stored with autoload, so checking them on
 * every Store API request costs no extra database query.
 *
 * Copyright (C) 2026 FeCommerce (https://fecommerce.co)
 * Licensed under the GNU General Public License v2 or later (GPL-2.0-or-later).
 * See the LICENSE file in the plugin's root folder.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FECWF_RESTRICT_OPTION', 'fecwf_restrict_sites');
define('FECWF_ALLOWED_SITES_OPTION', 'fecwf_allowed_sites');
define('FECWF_SEEN_SITES_OPTION', 'fecwf_seen_sites');
define('FECWF_MAX_SEEN_SITES', 20);
define('FECWF_SEEN_WRITE_INTERVAL', 5 * MINUTE_IN_SECONDS);

/** "https://Shop.Example.com:443/" → "https://shop.example.com", or '' when not a web origin. */
function fecwf_normalize_origin($raw)
{
    $raw = strtolower(trim((string) $raw));
    $parts = wp_parse_url($raw);
    if (!$parts || empty($parts['scheme']) || empty($parts['host']) || !in_array($parts['scheme'], array('https', 'http'), true)) {
        return '';
    }
    if (!preg_match('/^[a-z0-9.-]+$/', $parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }
    if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
        return '';
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : 0;
    $default = $parts['scheme'] === 'https' ? 443 : 80;
    return $parts['scheme'] . '://' . $parts['host'] . (($port && $port !== $default) ? ':' . $port : '');
}

function fecwf_restricting()
{
    return get_option(FECWF_RESTRICT_OPTION) === 'yes';
}

function fecwf_allowed_sites()
{
    $list = get_option(FECWF_ALLOWED_SITES_OPTION, array());
    return is_array($list) ? $list : array();
}

/** Recently seen sites: origin => last seen (unix time). */
function fecwf_seen_sites()
{
    $seen = get_option(FECWF_SEEN_SITES_OPTION, array());
    if (!is_array($seen)) {
        return array();
    }
    unset($seen['__last_write']);
    return $seen;
}

/** This WordPress site's own origins (home and site URL can differ). */
function fecwf_own_origins()
{
    return array_values(array_unique(array_filter(array(
        fecwf_normalize_origin(home_url()),
        fecwf_normalize_origin(site_url()),
    ))));
}

/** May a browser on $origin use the public store routes? */
function fecwf_public_origin_allowed($origin)
{
    if (!fecwf_restricting()) {
        return true;
    }
    if (fecwf_is_framer_origin($origin)) {
        return true;
    }
    $origin = fecwf_normalize_origin($origin);
    return $origin !== '' && (in_array($origin, fecwf_own_origins(), true) || in_array($origin, fecwf_allowed_sites(), true));
}

/** Remember a site that called the store, for the admin's suggestions. */
function fecwf_note_seen_origin($origin)
{
    if (fecwf_is_framer_origin($origin)) {
        return;
    }
    $origin = fecwf_normalize_origin($origin);
    if ($origin === '' || strpos($origin, 'https://') !== 0 || in_array($origin, fecwf_own_origins(), true)) {
        return;
    }
    $stored = get_option(FECWF_SEEN_SITES_OPTION, array());
    $stored = is_array($stored) ? $stored : array();
    $last_write = isset($stored['__last_write']) ? (int) $stored['__last_write'] : 0;
    $now = time();
    if (isset($stored[$origin]) && $stored[$origin] > $now - DAY_IN_SECONDS) {
        return; // seen today already: no write
    }
    if ($now - $last_write < FECWF_SEEN_WRITE_INTERVAL) {
        return; // throttled: at most one write per interval, whatever the traffic
    }
    unset($stored['__last_write']);
    $stored[$origin] = $now;
    arsort($stored);
    $stored = array_slice($stored, 0, FECWF_MAX_SEEN_SITES, true);
    $stored['__last_write'] = $now;
    update_option(FECWF_SEEN_SITES_OPTION, $stored, true);
}

/*
 * Enforcement. A refused browser request gets a 403 before any route runs, and
 * no Access-Control-Allow-Origin naming it (see fecwf_cors_headers in the main
 * file), so the browser can't read anything either.
 */
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    $origin = get_http_origin();
    if (!$origin || !($request instanceof WP_REST_Request) || !fecwf_is_public_route($request->get_route())) {
        return $result;
    }
    fecwf_note_seen_origin($origin);
    if (!fecwf_public_origin_allowed($origin)) {
        return new WP_Error('fecwf_site_not_allowed', 'This store only accepts requests from the sites its owner has allowed.', array('status' => 403));
    }
    return $result;
}, 10, 3);

/*
 * ─── Admin ─────────────────────────────────────────────────────────────────
 */
add_action('admin_post_fecwf_save_sites', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('You are not allowed to do this.', 403);
    }
    check_admin_referer('fecwf_save_sites');

    $lines = preg_split('/[\r\n,]+/', isset($_POST['fecwf_sites']) ? (string) wp_unslash($_POST['fecwf_sites']) : '');
    $sites = array();
    $rejected = array();
    foreach ($lines as $line) {
        $line = trim(sanitize_text_field($line));
        if ($line === '') {
            continue;
        }
        $origin = fecwf_normalize_origin($line);
        if ($origin === '') {
            $rejected[] = $line;
        } else {
            $sites[] = $origin;
        }
    }
    $sites = array_slice(array_values(array_unique($sites)), 0, 50);
    $restrict = !empty($_POST['fecwf_restrict']);

    update_option(FECWF_ALLOWED_SITES_OPTION, $sites, true);
    update_option(FECWF_RESTRICT_OPTION, $restrict ? 'yes' : 'no', true);

    $message = $restrict
        ? ($sites ? 'Saved. Only the listed sites (plus this store and Framer) may show this store to their visitors.' : 'Saved. No sites are listed, so only this store and Framer may use it: every published Framer site is now blocked.')
        : 'Saved. Any site may use this store (restriction is off).';
    if ($rejected) {
        $message .= ' Ignored (not a web address): ' . implode(', ', array_map('sanitize_text_field', $rejected)) . '.';
    }
    fecwf_flash(($restrict && !$sites) || $rejected ? 'warning' : 'success', $message);
    wp_safe_redirect(fecwf_admin_url());
    exit;
});

function fecwf_render_allowlist_section()
{
    $restrict = fecwf_restricting();
    $sites = fecwf_allowed_sites();
    $seen = array_diff_key(fecwf_seen_sites(), array_flip($sites));
    ?>
    <h2 style="margin-top:32px">Restrict which sites may use this store</h2>
    <p style="max-width:720px">Optional. When on, only the websites listed here, plus this store itself and Framer's editor, can show your store's products, cart and checkout to their visitors. Use it to stop one site, for example an old Framer project you no longer want selling from your store. This controls websites, not direct access: your store's public product and cart API stays public, as on every WooCommerce store.</p>
    <div class="notice notice-warning inline" style="max-width:720px"><p>List <strong>every</strong> address your Framer site is published on (your own domain, its <code>.framer.website</code> or <code>.framer.app</code> address, and any preview address you use). A site that isn't listed loses its products, cart and checkout.</p></div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="fecwf_save_sites" />
        <?php wp_nonce_field('fecwf_save_sites'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Restriction</th>
                <td><label><input type="checkbox" name="fecwf_restrict" value="1" <?php checked($restrict); ?> /> Only allow the sites listed below</label></td>
            </tr>
            <tr>
                <th scope="row"><label for="fecwf-sites">Allowed sites</label></th>
                <td>
                    <textarea id="fecwf-sites" name="fecwf_sites" class="large-text code" rows="5" placeholder="https://www.your-site.com&#10;https://your-site.framer.website"><?php echo esc_textarea(implode("\n", $sites)); ?></textarea>
                    <p class="description">One address per line, like <code>https://www.your-site.com</code>.</p>
                    <?php if ($seen) : ?>
                        <p style="margin-top:12px"><strong>Recently seen using your store:</strong></p>
                        <p class="description">Only add addresses you recognise as your own sites. Anyone can make a request that appears here.</p>
                        <p>
                            <?php foreach (array_keys($seen) as $origin) : ?>
                                <button type="button" class="button button-small fecwf-add-site" data-site="<?php echo esc_attr($origin); ?>" style="margin:0 6px 6px 0">+ <?php echo esc_html($origin); ?></button>
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php submit_button('Save'); ?>
    </form>
    <script>
        document.querySelectorAll('.fecwf-add-site').forEach(function (b) {
            b.addEventListener('click', function () {
                var t = document.getElementById('fecwf-sites'), s = b.getAttribute('data-site');
                if (t.value.split(/\s+/).indexOf(s) === -1) t.value = (t.value.trim() ? t.value.trim() + '\n' : '') + s;
                b.disabled = true;
            });
        });
    </script>
    <?php
}
