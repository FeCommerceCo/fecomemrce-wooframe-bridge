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
 * revocations of their own. Responses are never cached, so a page cache or
 * CDN can't replay one either.
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
    if (strpos($key, 'fec1.') !== 0 || strlen($key) > 1024 || !preg_match('/^[a-z2-7]{26}$/', $sid) || $store !== $origin) {
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
            update_option(FECWF_CONNECTION_OPTION, $connection, false);
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
        update_option(FECWF_CONNECTION_OPTION, $new, false);
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
    add_submenu_page('woocommerce', 'FeCommerce', 'FeCommerce', 'manage_woocommerce', 'fecwf', 'fecwf_render_admin_page');
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

function fecwf_action_form($action, $label, $class, $confirm = '')
{
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px"<?php echo $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : ''; ?>>
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
    <div class="wrap">
        <h1>FeCommerce</h1>
        <?php if (is_array($notice)) : ?>
            <div class="notice notice-<?php echo esc_attr($notice[0]); ?> is-dismissible"><p><?php echo esc_html($notice[1]); ?></p></div>
        <?php endif; ?>

        <h2>Connect to Framer</h2>
        <?php if (is_wp_error($origin)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($origin->get_error_message()); ?></p></div>
        <?php elseif (!$connection) : ?>
            <p>The FeCommerce plugin in Framer only works with a store that has a <strong>connection key</strong>. Click below to get one for <code><?php echo esc_html($origin); ?></code>, then paste it into the FeCommerce plugin in Framer.</p>
            <?php fecwf_action_form('fecwf_connect', 'Connect to Framer', 'button button-primary'); ?>
            <p class="description" style="margin-top:12px;max-width:720px">
                Clicking Connect sends your store's address to the FeCommerce connection service (<code><?php echo esc_html($auth_host); ?></code>).
                The service confirms the address belongs to this site by reading <code>/wp-json/fecommerce/v1/challenge</code> once, then signs your key.
                It keeps your store's hostname, a connection id and the dates; nothing else about your store or customers.
            </p>
        <?php else : ?>
            <p>Connected as <code><?php echo esc_html($connection['store']); ?></code> since <?php echo esc_html(wp_date(get_option('date_format'), (int) $connection['issued_at'])); ?>.</p>
            <p><label for="fecwf-key"><strong>Connection key</strong></label></p>
            <textarea id="fecwf-key" class="large-text code" rows="4" readonly onclick="this.select()"><?php echo esc_textarea($connection['key']); ?></textarea>
            <p>
                <button type="button" class="button button-primary" id="fecwf-copy">Copy key</button>
                <span id="fecwf-copied" style="margin-left:8px;color:#008a20;display:none">Copied</span>
            </p>
            <ol style="max-width:720px">
                <li>In Framer, open the <strong>FeCommerce</strong> plugin.</li>
                <li>Paste the key into <strong>Connection key</strong> and click <strong>Connect</strong>.</li>
            </ol>
            <p class="description" style="max-width:720px">This key isn't a password: it only proves to FeCommerce that this store is yours. It's safe on your published Framer site.</p>

            <h3 style="margin-top:24px">Manage</h3>
            <?php
            fecwf_action_form('fecwf_regenerate', 'Regenerate key', 'button', 'Issue a new connection key? You will need to paste the new key into each Framer project that uses this store.');
            fecwf_action_form('fecwf_disconnect', 'Disconnect', 'button button-link-delete', 'Disconnect this store from Framer? Published Framer sites keep their key and keep working; to stop them, use "Restrict which sites may use this store" below.');
            ?>
            <p class="description" style="max-width:720px">Published Framer sites check their key themselves, so Regenerate and Disconnect don't switch off sites that are already live. To stop a site immediately, restrict which sites may use this store below.</p>
            <script>
                (function () {
                    var b = document.getElementById('fecwf-copy'), t = document.getElementById('fecwf-key'), ok = document.getElementById('fecwf-copied');
                    if (!b || !t) return;
                    b.addEventListener('click', function () {
                        var done = function () { ok.style.display = 'inline'; setTimeout(function () { ok.style.display = 'none'; }, 2000); };
                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(t.value).then(done, function () { t.select(); });
                        } else {
                            t.select();
                            try { document.execCommand('copy'); done(); } catch (e) {}
                        }
                    });
                })();
            </script>
        <?php endif; ?>

        <?php fecwf_render_allowlist_section(); ?>
    </div>
    <?php
}
