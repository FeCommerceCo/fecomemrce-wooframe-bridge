<?php
/**
 * Connecting this store to Framer: the connection key.
 *
 * The FeCommerce Framer plugin, and the components it places on a Framer site,
 * only talk to a store named in a connection key signed by FeCommerce. The key
 * is issued here, when a store admin clicks "Connect to Framer":
 *
 *   1. This plugin makes a one-time random challenge and keeps it for two
 *      minutes.
 *   2. It sends this store's address and the challenge to the FeCommerce
 *      connection service (auth.fecommerce.co).
 *   3. The service asks GET /wp-json/fecommerce/v1/challenge on this address.
 *      Only the server that really answers at this domain can return the
 *      challenge, which proves the domain.
 *   4. The service signs a key naming this store and returns it. The admin
 *      copies it into the FeCommerce plugin in Framer.
 *
 * The service is contacted only when an admin clicks Connect, Regenerate or
 * Disconnect. It receives this store's address and the challenge, and keeps
 * the store's hostname, a connection id and dates. Nothing else is sent.
 *
 * Copyright (C) 2026 FeCommerce (https://fecommerce.co)
 * Licensed under the GNU General Public License v2 or later (GPL-2.0-or-later).
 * See the LICENSE file in the plugin's root folder.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FECWF_CONNECTION_OPTION', 'fecwf_connection');
define('FECWF_CHALLENGE_TRANSIENT', 'fecwf_challenge');

/**
 * The connection service. Production unless wp-config.php opts into staging
 * for testing with define('FECWF_AUTH_URL', 'https://auth-staging.fecommerce.co').
 * No other address is accepted.
 */
function fecwf_auth_base()
{
    $allowed = array('https://auth.fecommerce.co', 'https://auth-staging.fecommerce.co');
    if (defined('FECWF_AUTH_URL') && in_array(FECWF_AUTH_URL, $allowed, true)) {
        return FECWF_AUTH_URL;
    }
    return $allowed[0];
}

/**
 * This store's origin as the service will sign it ("https://host"), or a
 * WP_Error saying why this site can't be connected.
 */
function fecwf_store_origin()
{
    $home = wp_parse_url(home_url());
    if (!$home || empty($home['host'])) {
        return new WP_Error('fecwf_no_home', 'WordPress has no site address set (Settings → General).');
    }
    if (!isset($home['scheme']) || strtolower($home['scheme']) !== 'https') {
        return new WP_Error('fecwf_not_https', 'Your site address must start with https://. Change it under Settings → General once HTTPS works on your site.');
    }
    if (!empty($home['port'])) {
        return new WP_Error('fecwf_port', 'Your site address uses a custom port. Framer can only connect to a store on the standard HTTPS port.');
    }
    if (!empty($home['path']) && $home['path'] !== '/') {
        return new WP_Error('fecwf_subfolder', 'WordPress is installed in a sub-folder (' . $home['path'] . '). Framer can only connect to a store at the root of its domain, like https://shop.example.com.');
    }
    return 'https://' . strtolower($home['host']);
}

/** The saved connection: array('key', 'sid', 'store', 'issued_at', 'auth'), or null. */
function fecwf_get_connection()
{
    $c = get_option(FECWF_CONNECTION_OPTION);
    return (is_array($c) && !empty($c['key']) && !empty($c['sid'])) ? $c : null;
}

/** 32 random bytes, base64url without padding (43 characters). */
function fecwf_new_challenge()
{
    $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    set_transient(FECWF_CHALLENGE_TRANSIENT, $challenge, 2 * MINUTE_IN_SECONDS);
    return $challenge;
}

/*
 * GET /fecommerce/v1/challenge: answers the service's domain check with the
 * challenge this plugin is waiting on, and 404 at any other time.
 *
 * SINGLE USE: the challenge is deleted the moment it is read, so the one read
 * the service makes is the only one that can succeed. Someone polling this
 * address during the two-minute window can at most make that Connect fail
 * (the admin clicks again); they can't reuse the challenge to request keys or
 * revocations of their own, because the service reads the challenge from
 * this address itself, and by then it is gone. Responses are never cached, so
 * a page cache or CDN can't replay one either.
 *
 * Even a key issued to someone else (say, a cache that ignores no-store
 * replayed a challenge) is useless: Framer accepts only the key whose sid this
 * store's /fecommerce/v1/status names, which is the one saved here. And a key
 * grants nothing secret anyway: it is published with every Framer site that
 * uses the store. What's left is nuisance: failed Connects, and attempts
 * counted against the store's daily limit at the service.
 */
add_action('rest_api_init', function () {
    register_rest_route(FECWF_NAMESPACE, '/challenge', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $challenge = get_transient(FECWF_CHALLENGE_TRANSIENT);
            delete_transient(FECWF_CHALLENGE_TRANSIENT);
            if (!is_string($challenge) || $challenge === '') {
                $response = new WP_REST_Response(array('code' => 'fecwf_no_challenge', 'message' => 'No connection is in progress.'), 404);
            } else {
                $response = new WP_REST_Response(array('challenge' => $challenge), 200);
            }
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('CDN-Cache-Control', 'no-store');
            return $response;
        },
    ));
});

/** What a service error means for the admin. */
function fecwf_service_message($code)
{
    $messages = array(
        'invalid_store' => 'The connection service didn\'t accept this site\'s address. It must be a public HTTPS domain without a sub-folder.',
        'store_unreachable' => 'The connection service couldn\'t reach your site. Make sure it\'s online and publicly reachable, then try again.',
        'store_redirects' => 'Your site address redirects somewhere else (for example to or from www). Set Settings → General → Site Address to the address your site actually loads on, then try again.',
        'challenge_failed' => 'The connection service couldn\'t confirm your site. A security plugin, firewall or cache may be blocking or caching /wp-json/fecommerce/v1/challenge. Allow that address, then try again.',
        'not_woocommerce' => 'The connection service couldn\'t reach your store\'s WooCommerce Store API (/wp-json/wc/store/v1/products). Make sure WooCommerce is active and that no security plugin or firewall blocks that address, then try again.',
        'rate_limited' => 'Too many attempts. Wait a few minutes and try again.',
        'unknown_sid' => 'The connection service doesn\'t recognise this connection.',
    );
    return isset($messages[$code]) ? $messages[$code] : 'The connection service returned an error. Try again in a few minutes.';
}

/**
 * POST to the connection service with a fresh challenge, and clear the
 * challenge afterwards whatever happened. Returns the decoded body or WP_Error.
 */
function fecwf_call_service($path, array $body)
{
    $body['challenge'] = fecwf_new_challenge();
    $response = wp_remote_post(fecwf_auth_base() . $path, array(
        'timeout' => 20,
        'redirection' => 0,
        'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
        'user-agent' => 'FeCommerce-Bridge/' . FECWF_VERSION,
        'body' => wp_json_encode($body),
    ));
    delete_transient(FECWF_CHALLENGE_TRANSIENT);

    if (is_wp_error($response)) {
        return new WP_Error('fecwf_network', 'Your server couldn\'t reach the FeCommerce connection service (' . $response->get_error_message() . '). Check that outgoing HTTPS requests are allowed.');
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status !== 200 || !is_array($data)) {
        $code = (is_array($data) && isset($data['error'])) ? (string) $data['error'] : 'http_' . $status;
        return new WP_Error('fecwf_service_' . $code, fecwf_service_message($code));
    }
    return $data;
}

/** Issue a new key for this store and return the connection, or WP_Error. */
function fecwf_register()
{
    $origin = fecwf_store_origin();
    if (is_wp_error($origin)) {
        return $origin;
    }
    $data = fecwf_call_service('/v1/register', array('store' => $origin));
    if (is_wp_error($data)) {
        return $data;
    }
    $key = isset($data['key']) ? (string) $data['key'] : '';
    $sid = isset($data['sid']) ? (string) $data['sid'] : '';
    $store = isset($data['store']) ? (string) $data['store'] : '';
    if (!preg_match('/^fec1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $key) || strlen($key) > 1024 || !preg_match('/^[a-z2-7]{26}$/', $sid) || $store !== $origin) {
        return new WP_Error('fecwf_bad_response', 'The connection service returned an unexpected answer. Try again in a few minutes.');
    }
    return array(
        'key' => $key,
        'sid' => $sid,
        'store' => $store,
        'issued_at' => time(),
        'auth' => fecwf_auth_base(),
    );
}

/** Record a key as revoked with the service. True, or WP_Error. */
function fecwf_revoke(array $connection)
{
    $origin = fecwf_store_origin();
    if (is_wp_error($origin)) {
        return $origin;
    }
    $data = fecwf_call_service('/v1/revoke', array('store' => $origin, 'sid' => $connection['sid']));
    return is_wp_error($data) ? $data : true;
}

/*
 * ─── Admin actions ─────────────────────────────────────────────────────────
 */

function fecwf_admin_url()
{
    return admin_url('admin.php?page=fecwf');
}

/** Keep a notice for the current admin's next page load. */
function fecwf_flash($type, $message)
{
    set_transient('fecwf_notice_' . get_current_user_id(), array($type, $message), MINUTE_IN_SECONDS);
}

function fecwf_guard_action($action)
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die('You are not allowed to do this.', 403);
    }
    check_admin_referer($action);
}

add_action('admin_post_fecwf_connect', function () {
    fecwf_guard_action('fecwf_connect');
    if (fecwf_get_connection()) {
        fecwf_flash('warning', 'This store is already connected. Use Regenerate for a new key.');
    } else {
        $connection = fecwf_register();
        if (is_wp_error($connection)) {
            fecwf_flash('error', $connection->get_error_message());
        } else {
            update_option(FECWF_CONNECTION_OPTION, $connection, true);
            fecwf_flash('success', 'Connected. Copy the connection key below into the FeCommerce plugin in Framer.');
        }
    }
    wp_safe_redirect(fecwf_admin_url());
    exit;
});

add_action('admin_post_fecwf_regenerate', function () {
    fecwf_guard_action('fecwf_regenerate');
    $old = fecwf_get_connection();
    $new = fecwf_register();
    if (is_wp_error($new)) {
        fecwf_flash('error', $new->get_error_message() . ' Your current key is unchanged.');
    } else {
        update_option(FECWF_CONNECTION_OPTION, $new, true);
        $note = '';
        if ($old) {
            $revoked = fecwf_revoke($old);
            if (is_wp_error($revoked)) {
                $note = ' The old key couldn\'t be marked as replaced with the connection service (' . $revoked->get_error_message() . ').';
            }
        }
        fecwf_flash('success', 'New connection key issued. Paste it into the FeCommerce plugin in each Framer project that uses this store.' . $note);
    }
    wp_safe_redirect(fecwf_admin_url());
    exit;
});

add_action('admin_post_fecwf_disconnect', function () {
    fecwf_guard_action('fecwf_disconnect');
    $old = fecwf_get_connection();
    if ($old) {
        $revoked = fecwf_revoke($old);
        delete_option(FECWF_CONNECTION_OPTION);
        fecwf_flash(
            is_wp_error($revoked) ? 'warning' : 'success',
            'Disconnected. The key is no longer shown here.' .
                (is_wp_error($revoked) ? ' It couldn\'t be marked as revoked with the connection service (' . $revoked->get_error_message() . ').' : '')
        );
    }
    wp_safe_redirect(fecwf_admin_url());
    exit;
});

/*
 * ─── Admin screen: WooCommerce → FeCommerce ────────────────────────────────
 */
add_action('admin_menu', function () {
    $hook = add_submenu_page('woocommerce', 'FeCommerce', 'FeCommerce', 'manage_woocommerce', 'fecwf', 'fecwf_render_admin_page');
    if ($hook) {
        // Styles and script for this screen only.
        add_action('admin_enqueue_scripts', function ($current) use ($hook) {
            if ($current !== $hook) {
                return;
            }
            wp_enqueue_style('fecwf-admin', plugins_url('assets/admin.css', FECWF_FILE), array(), FECWF_VERSION);
            wp_enqueue_script('fecwf-admin', plugins_url('assets/admin.js', FECWF_FILE), array(), FECWF_VERSION, true);
        });
    }
}, 60);

add_filter('plugin_action_links_' . plugin_basename(FECWF_FILE), function ($links) {
    array_unshift($links, '<a href="' . esc_url(fecwf_admin_url()) . '">' . (fecwf_get_connection() ? 'Settings' : 'Connect to Framer') . '</a>');
    return $links;
});

// A reminder on the Plugins screen until the store is connected.
add_action('admin_notices', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'plugins' || !current_user_can('manage_woocommerce') || fecwf_get_connection()) {
        return;
    }
    echo '<div class="notice notice-info"><p><strong>FeCommerce:</strong> connect this store to Framer to get your connection key. <a href="' . esc_url(fecwf_admin_url()) . '">Connect to Framer</a></p></div>';
});

// The copy installed under the plugin's old name (before 1.2.0) is switched off
// on activation but stays installed. Ask for it to be deleted.
add_action('admin_notices', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'plugins' || !current_user_can('delete_plugins')
        || !file_exists(WP_PLUGIN_DIR . '/fecommerce-wooframe/fecommerce-wooframe.php')) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>FeCommerce:</strong> the old <em>fecommerce-wooframe-bridge</em> plugin is still installed. It is no longer used, and you can delete it. Your connection and settings are kept.</p></div>';
});

function fecwf_action_form($action, $label, $class, $confirm = '')
{
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"<?php echo $confirm !== '' ? ' data-fecwf-confirm="' . esc_attr($confirm) . '"' : ''; ?>>
        <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>" />
        <?php wp_nonce_field($action); ?>
        <button type="submit" class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
}

function fecwf_render_admin_page()
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $notice = get_transient('fecwf_notice_' . get_current_user_id());
    delete_transient('fecwf_notice_' . get_current_user_id());
    $origin = fecwf_store_origin();
    $connection = fecwf_get_connection();
    $auth_host = wp_parse_url(fecwf_auth_base(), PHP_URL_HOST);
    ?>
    <div class="wrap fecwf">
        <div class="fecwf-header">
            <img class="fecwf-logo" src="<?php echo esc_url(plugins_url('assets/icon.png', FECWF_FILE)); ?>" alt="" width="44" height="44" />
            <div class="fecwf-title">
                <h1>FeCommerce</h1>
                <p class="fecwf-subtitle">Connect this WooCommerce store to Framer</p>
            </div>
            <span class="fecwf-pill<?php echo $connection ? ' is-on' : ''; ?>"><?php echo $connection ? 'Connected' : 'Not connected'; ?></span>
        </div>
        <hr class="wp-header-end" />
        <?php if (is_array($notice)) : ?>
            <div class="notice notice-<?php echo esc_attr($notice[0]); ?> is-dismissible"><p><?php echo esc_html($notice[1]); ?></p></div>
        <?php endif; ?>

        <div class="fecwf-card">
            <h2>Connect to Framer</h2>
            <?php if (is_wp_error($origin)) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($origin->get_error_message()); ?></p></div>
            <?php elseif (!$connection) : ?>
                <p>The FeCommerce plugin in Framer only works with a store that has a <strong>connection key</strong>. Click below to get one for <code><?php echo esc_html($origin); ?></code>, then paste it into the FeCommerce plugin in Framer.</p>
                <div class="fecwf-actions">
                    <?php fecwf_action_form('fecwf_connect', 'Connect to Framer', 'fecwf-btn fecwf-btn-primary'); ?>
                </div>
                <p class="fecwf-fine">
                    Clicking Connect sends your store's address to the FeCommerce connection service (<code><?php echo esc_html($auth_host); ?></code>).
                    The service confirms the address belongs to this site by reading <code>/wp-json/fecommerce/v1/challenge</code> once, checks that WooCommerce answers by reading one product id from <code>/wp-json/wc/store/v1/products</code>, then signs your key.
                    It keeps your store's hostname, a connection id and the dates; nothing else about your store or customers.
                </p>
            <?php else : ?>
                <div class="fecwf-meta">
                    <div><span>Store</span><strong title="<?php echo esc_attr($connection['store']); ?>"><?php echo esc_html($connection['store']); ?></strong></div>
                    <div><span>Connected since</span><strong><?php echo esc_html(wp_date(get_option('date_format'), (int) $connection['issued_at'])); ?></strong></div>
                </div>

                <label class="fecwf-label" for="fecwf-key">Connection key</label>
                <div class="fecwf-key">
                    <input type="password" id="fecwf-key" value="<?php echo esc_attr($connection['key']); ?>" readonly autocomplete="off" spellcheck="false" />
                    <button type="button" class="fecwf-icon-btn" id="fecwf-reveal" aria-label="Show key" aria-pressed="false" aria-controls="fecwf-key">
                        <span class="fecwf-when-off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></span>
                        <span class="fecwf-when-on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19M6.6 6.6A18.4 18.4 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M2 2l20 20"/></svg></span>
                    </button>
                    <button type="button" class="fecwf-icon-btn fecwf-copy" id="fecwf-copy" aria-label="Copy key">
                        <span class="fecwf-when-off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span class="fecwf-btn-text">Copy</span></span>
                        <span class="fecwf-when-on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg><span class="fecwf-btn-text">Copied</span></span>
                    </button>
                </div>
                <span class="screen-reader-text" id="fecwf-copy-status" role="status" aria-live="polite"></span>

                <ol class="fecwf-steps">
                    <li>In Framer, open the <strong>FeCommerce</strong> plugin.</li>
                    <li>Paste the key into <strong>Connection key</strong> and click <strong>Connect</strong>.</li>
                </ol>
                <p class="fecwf-fine">This key isn't a password: it only proves to FeCommerce that this store is yours. It's safe on your published Framer site.</p>
            <?php endif; ?>
        </div>

        <?php if ($connection && !is_wp_error($origin)) : ?>
            <div class="fecwf-card">
                <h2>Manage</h2>
                <p>Framer sites accept only the key this store currently shows, so Regenerate and Disconnect take effect on published sites within about a minute. To stop one particular site while keeping the others, restrict which sites may use this store below.</p>
                <div class="fecwf-actions">
                    <?php
                    fecwf_action_form('fecwf_regenerate', 'Regenerate key', 'fecwf-btn', 'Issue a new connection key? The old key stops working on published Framer sites within about a minute, so paste the new key into each Framer project that uses this store and republish.');
                    fecwf_action_form('fecwf_disconnect', 'Disconnect', 'fecwf-btn fecwf-btn-danger', 'Disconnect this store from Framer? Published Framer sites that use this store stop showing its products within about a minute.');
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <?php fecwf_render_allowlist_section(); ?>
    </div>
    <?php
}
