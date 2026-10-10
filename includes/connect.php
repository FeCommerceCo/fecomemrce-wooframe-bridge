<?php
/**
 * Connecting this store to Framer sites: pairing codes.
 *
 * The FeCommerce Framer plugin, and the components it places on a Framer site,
 * talk to this store only through FeCommerce's API (api-v2.fecommerce.co),
 * which knows the store's address from the moment the store proved it owns
 * its domain. A Framer project is connected like this:
 *
 *   1. The FeCommerce plugin in Framer shows a short code (it changes every
 *      30 seconds).
 *   2. A store admin types it under WooCommerce → FeCommerce. This plugin asks
 *      the API which Framer project the code belongs to (lookup), and shows
 *      an Approve screen with the project's name and addresses.
 *   3. Approve sends the code, this store's address and a one-time challenge
 *      to the API (claim). The API reads GET /wp-json/fecommerce/v1/challenge
 *      on this address. Only the server that really answers at this domain
 *      can return the challenge, which proves the domain.
 *   4. The person in Framer confirms "Is this your store?" there, and only
 *      then does their project receive its site token.
 *
 * The first claim creates the store's connection: a connection id (sid),
 * served from /fecommerce/v1/status, and a secret the API signs its requests
 * to this store with (includes/signed-requests.php). Every later Framer
 * project joins the same connection. Disconnecting here clears both, and
 * every connected Framer site stops within about a minute.
 *
 * The API is contacted only when an admin enters a code, approves or cancels,
 * or manages connected sites. It receives this store's address, the code,
 * the challenge and the store's name. Nothing about products, orders or
 * customers.
 *
 * Copyright (C) 2026 FeCommerce (https://fecommerce.co)
 * Licensed under the GNU General Public License v2 or later (GPL-2.0-or-later).
 * See the LICENSE file in the plugin's root folder.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FECWF_API_BASE', 'https://api-v2.fecommerce.co');
define('FECWF_CONNECTION_OPTION', 'fecwf_connection');
define('FECWF_SECRET_OPTION', 'fecwf_store_secret');
// Each challenge is stored under its own id: fecwf_challenge_<id> (FEC-151).
define('FECWF_CHALLENGE_TRANSIENT', 'fecwf_challenge');
// The connected Framer sites, as last loaded (includes/connected-sites.php).
define('FECWF_SITES_TRANSIENT', 'fecwf_sites');
// Crockford base32, as the API issues codes.
define('FECWF_CODE_ALPHABET', '0123456789ABCDEFGHJKMNPQRSTVWXYZ');

/**
 * FeCommerce's API. Always https://api-v2.fecommerce.co, except that a plugin
 * developer may point it at the local mock server (http://localhost or
 * 127.0.0.1) with define('FECWF_API_DEV_URL', 'http://localhost:8788') while
 * WP_DEBUG is on. No other address is accepted.
 */
function fecwf_api_base()
{
    if (
        defined('FECWF_API_DEV_URL') && defined('WP_DEBUG') && WP_DEBUG &&
        is_string(FECWF_API_DEV_URL) &&
        preg_match('/^http:\/\/(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$/D', FECWF_API_DEV_URL)
    ) {
        return FECWF_API_DEV_URL;
    }
    return FECWF_API_BASE;
}

/**
 * This store's origin as the API knows it ("https://host"), or a WP_Error
 * saying why this site can't be connected.
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

/**
 * The store's connection: array('sid', 'store', 'issued_at'), or null.
 *
 * Bridge 1.2 saved a fec1 connection key here ('key'). The Framer plugin
 * that used those keys was never released, and FeCommerce's API doesn't know
 * their ids, so such a record counts as not connected.
 */
function fecwf_get_connection()
{
    $c = get_option(FECWF_CONNECTION_OPTION);
    if (!is_array($c) || isset($c['key']) || empty($c['sid']) || !preg_match('/^[a-z2-7]{26}$/D', (string) $c['sid'])) {
        return null;
    }
    return $c;
}

/** The secret the API signs its requests with, or '' when not connected. */
function fecwf_store_secret()
{
    $secret = get_option(FECWF_SECRET_OPTION);
    return (is_string($secret) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret)) ? $secret : '';
}

/** Forget the connection: /status names no sid, and every Framer site stops. */
function fecwf_clear_connection()
{
    delete_option(FECWF_CONNECTION_OPTION);
    delete_option(FECWF_SECRET_OPTION);
    delete_transient(FECWF_SITES_TRANSIENT);
}

/** Random bytes as base64url without padding. */
function fecwf_random_b64url($bytes)
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** The transient one challenge is kept under, or null for a malformed id. */
function fecwf_challenge_key($id)
{
    return (is_string($id) && preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $id)) ? FECWF_CHALLENGE_TRANSIENT . '_' . $id : null;
}

/**
 * A new challenge: 32 random bytes (43 characters) under a random id
 * (22 characters). Returns array(id, challenge).
 */
function fecwf_new_challenge()
{
    $id = fecwf_random_b64url(16);
    $challenge = fecwf_random_b64url(32);
    set_transient(fecwf_challenge_key($id), $challenge, 2 * MINUTE_IN_SECONDS);
    return array($id, $challenge);
}

/*
 * GET /fecommerce/v1/challenge?id=<id>: answers the API's domain check with
 * the challenge saved under that id, and 404 at any other time.
 *
 * The id is random and only ever sent to FeCommerce with the challenge, so
 * only FeCommerce's own read can find it. Someone polling this address can't
 * consume a challenge any more (FEC-151; before 1.3.1 the first read of any
 * kind took it). SINGLE USE: the challenge is deleted the moment it is read.
 * A request without an id gets 404. Responses are never cached, so a page
 * cache or CDN can't replay one either.
 */
add_action('rest_api_init', function () {
    register_rest_route(FECWF_NAMESPACE, '/challenge', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $key = fecwf_challenge_key($request->get_param('id'));
            $challenge = $key ? get_transient($key) : false;
            if ($key) {
                delete_transient($key);
            }
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

/** What an API error means for the admin. */
function fecwf_service_message($code)
{
    $messages = array(
        'code_not_found' => 'That code isn\'t valid. Codes change every 30 seconds: check the one the FeCommerce plugin in Framer shows now, and keep the plugin open while you enter it.',
        'code_in_use' => 'That code was already entered from another store.',
        'wrong_state' => 'That code was already used. In Framer, start connecting again for a new code.',
        'already_used' => 'That code was already used. In Framer, start connecting again for a new code.',
        'pairing_expired' => 'That code has expired. In Framer, start connecting again for a new code.',
        'pairing_cancelled' => 'This connection was cancelled. In Framer, start connecting again for a new code.',
        'invalid_store' => 'FeCommerce didn\'t accept this site\'s address. It must be a public HTTPS domain without a sub-folder.',
        'store_unreachable' => 'FeCommerce couldn\'t reach your site. Make sure it\'s online and publicly reachable, then try again.',
        'store_redirects' => 'Your site address redirects somewhere else (for example to or from www). Set Settings → General → Site Address to the address your site actually loads on, then try again.',
        'bad_store_response' => 'Your site answered FeCommerce with something unexpected. A security plugin, firewall or cache may be changing /wp-json/fecommerce/v1/challenge. Allow that address, then try again.',
        'challenge_failed' => 'FeCommerce couldn\'t confirm your site. A security plugin, firewall or cache may be blocking or caching /wp-json/fecommerce/v1/challenge. Allow that address, then try again.',
        'not_woocommerce' => 'FeCommerce couldn\'t reach your store\'s WooCommerce Store API (/wp-json/wc/store/v1/products). Make sure WooCommerce is active and that no security plugin or firewall blocks that address, then try again.',
        'unknown_sid' => 'FeCommerce doesn\'t recognise this store\'s connection.',
        'unknown_site' => 'That Framer site isn\'t connected to this store any more.',
        'rate_limited' => 'Too many attempts. Wait a few minutes and try again.',
        'invalid_request' => 'FeCommerce didn\'t accept the request. Check the code and try again.',
    );
    return isset($messages[$code]) ? $messages[$code] : 'FeCommerce returned an error. Try again in a few minutes.';
}

/**
 * POST to FeCommerce's API. With $prove_domain, a fresh challenge is added to
 * the body and cleared afterwards whatever happened. Returns the decoded body
 * or a WP_Error whose code is 'fecwf_service_<api error>'.
 */
function fecwf_api_post($path, array $body, $prove_domain = false)
{
    $challenge_key = null;
    if ($prove_domain) {
        list($challenge_id, $challenge) = fecwf_new_challenge();
        $body['challenge'] = $challenge;
        $body['challengeId'] = $challenge_id;
        $challenge_key = fecwf_challenge_key($challenge_id);
    }
    $response = wp_remote_post(fecwf_api_base() . $path, array(
        'timeout' => 20,
        'redirection' => 0,
        'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
        'user-agent' => 'FeCommerce-Bridge/' . FECWF_VERSION,
        'body' => wp_json_encode($body),
    ));
    if ($challenge_key) {
        delete_transient($challenge_key);
    }

    if (is_wp_error($response)) {
        return new WP_Error('fecwf_network', 'Your server couldn\'t reach FeCommerce (' . $response->get_error_message() . '). Check that outgoing HTTPS requests are allowed.');
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status > 299 || !is_array($data)) {
        $code = (is_array($data) && isset($data['error']) && is_string($data['error'])) ? $data['error'] : 'http_' . $status;
        $message = fecwf_service_message($code);
        // The reference lets FeCommerce support find the request.
        if (is_array($data) && isset($data['requestId']) && is_string($data['requestId']) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $data['requestId'])) {
            $message .= ' (Reference: ' . $data['requestId'] . ')';
        }
        return new WP_Error('fecwf_service_' . $code, $message);
    }
    return $data;
}

/**
 * A code as typed ("m3vd 48ta", "M3VD-48TA") → "M3VD48TA", or '' when it
 * can't be a code. Spaces and dashes are ignored, O reads as 0 and I/L as 1,
 * as the API does.
 */
function fecwf_normalize_code($raw)
{
    $code = strtoupper(preg_replace('/[\s-]+/', '', (string) $raw));
    $code = strtr($code, array('O' => '0', 'I' => '1', 'L' => '1'));
    if (strlen($code) !== 8 || strspn($code, FECWF_CODE_ALPHABET) !== 8) {
        return '';
    }
    return $code;
}

/** "M3VD48TA" → "M3VD-48TA" */
function fecwf_display_code($code)
{
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

/** A Framer project's name as shown here: plain text, at most 80 characters. */
function fecwf_clean_project_name($raw)
{
    $name = trim(sanitize_text_field(is_string($raw) ? $raw : ''));
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 80);
    } else {
        $name = substr($name, 0, 80);
    }
    return $name !== '' ? $name : 'Untitled project';
}

/** Up to 10 bare hostnames, anything else dropped. */
function fecwf_clean_hostnames($raw)
{
    $out = array();
    foreach (is_array($raw) ? $raw : array() as $host) {
        $host = strtolower(trim(is_string($host) ? $host : ''));
        if ($host !== '' && strlen($host) <= 253 && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/D', $host)) {
            $out[] = $host;
        }
    }
    return array_slice(array_values(array_unique($out)), 0, 10);
}

/** The store's name for the Framer plugin: the site title, at most 80 characters. */
function fecwf_store_name()
{
    $name = trim(wp_strip_all_tags(get_bloginfo('name')));
    return function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80);
}

/*
 * ─── The pairing in progress ───────────────────────────────────────────────
 *
 * Between Lookup and Approve, the code and what the API said about it are kept
 * for the admin who entered it, until the API's approval deadline.
 */

function fecwf_pending_key()
{
    return 'fecwf_pair_' . get_current_user_id();
}

/** array('code', 'projectName', 'hostnames', 'createdAt', 'approveBy'), or null. */
function fecwf_get_pending()
{
    $p = get_transient(fecwf_pending_key());
    if (!is_array($p) || empty($p['code']) || empty($p['approveBy']) || (int) $p['approveBy'] <= time()) {
        return null;
    }
    return $p;
}

/** Ask the API which Framer project a code belongs to. The pending pairing, or WP_Error. */
function fecwf_lookup($code)
{
    $origin = fecwf_store_origin();
    if (is_wp_error($origin)) {
        return $origin;
    }
    $data = fecwf_api_post('/v1/pair/lookup', array('code' => $code, 'store' => $origin));
    if (is_wp_error($data)) {
        return $data;
    }
    $approve_by = isset($data['approveBy']) ? (int) $data['approveBy'] : 0;
    if (!isset($data['projectName']) || $approve_by <= time()) {
        return new WP_Error('fecwf_bad_response', 'FeCommerce returned an unexpected answer. Try again in a few minutes.');
    }
    return array(
        'code' => $code,
        'projectName' => fecwf_clean_project_name($data['projectName']),
        'hostnames' => fecwf_clean_hostnames(isset($data['hostnames']) ? $data['hostnames'] : array()),
        'createdAt' => isset($data['createdAt']) ? (int) $data['createdAt'] : 0,
        // Never longer than the API's own 5 minutes, whatever it answers.
        'approveBy' => min($approve_by, time() + 5 * MINUTE_IN_SECONDS),
    );
}

/**
 * Approve: claim the pairing with a domain proof. Saves the connection the
 * first time, before returning, so /status names the sid by the time the
 * person in Framer confirms. Returns the claim's binding info or WP_Error.
 */
function fecwf_claim(array $pending)
{
    $origin = fecwf_store_origin();
    if (is_wp_error($origin)) {
        return $origin;
    }
    $connection = fecwf_get_connection();
    $body = array('code' => $pending['code'], 'store' => $origin);
    $name = fecwf_store_name();
    if ($name !== '') {
        $body['storeName'] = $name;
    }
    if ($connection) {
        $body['sid'] = $connection['sid'];
    }
    $data = fecwf_api_post('/v1/pair/claim', $body, true);

    // A sid the API doesn't know (lost on its side, or revoked there) can't be
    // joined: start a new connection instead. Sites on the old sid had
    // already stopped working.
    if ($connection && is_wp_error($data) && $data->get_error_code() === 'fecwf_service_unknown_sid') {
        fecwf_clear_connection();
        $connection = null;
        unset($body['sid']);
        $data = fecwf_api_post('/v1/pair/claim', $body, true);
    }
    if (is_wp_error($data)) {
        return $data;
    }

    $sid = isset($data['sid']) ? (string) $data['sid'] : '';
    $secret = isset($data['storeSecret']) ? (string) $data['storeSecret'] : '';
    if (
        !isset($data['status']) || $data['status'] !== 'approved' ||
        !preg_match('/^[a-z2-7]{26}$/D', $sid) ||
        ($connection && $sid !== $connection['sid']) ||
        (!$connection && !preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret))
    ) {
        return new WP_Error('fecwf_bad_response', 'FeCommerce returned an unexpected answer. Try again in a few minutes.');
    }

    if (!$connection) {
        // The secret is a password: never autoloaded, never shown.
        update_option(FECWF_SECRET_OPTION, $secret, false);
        update_option(FECWF_CONNECTION_OPTION, array(
            'sid' => $sid,
            'store' => $origin,
            'issued_at' => time(),
        ), true);
    }
    delete_transient(FECWF_SITES_TRANSIENT);
    return isset($data['binding']) && is_array($data['binding']) ? $data['binding'] : array();
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

add_action('admin_post_fecwf_pair_lookup', function () {
    fecwf_guard_action('fecwf_pair_lookup');
    $code = fecwf_normalize_code(isset($_POST['fecwf_code']) ? sanitize_text_field(wp_unslash($_POST['fecwf_code'])) : '');
    if ($code === '') {
        fecwf_flash('error', 'Enter the 8-character code the FeCommerce plugin in Framer shows, like K7QP-92MX.');
    } else {
        $pending = fecwf_lookup($code);
        if (is_wp_error($pending)) {
            fecwf_flash('error', $pending->get_error_message());
        } else {
            set_transient(fecwf_pending_key(), $pending, max(1, (int) $pending['approveBy'] - time()));
        }
    }
    wp_safe_redirect(fecwf_admin_url());
    exit;
});

add_action('admin_post_fecwf_pair_approve', function () {
    fecwf_guard_action('fecwf_pair_approve');
    $pending = fecwf_get_pending();
    if (!$pending) {
        fecwf_flash('error', 'The time to approve this code has run out. In Framer, start connecting again for a new code.');
    } else {
        $result = fecwf_claim($pending);
        delete_transient(fecwf_pending_key());
        if (is_wp_error($result)) {
            fecwf_flash('error', $result->get_error_message());
        } else {
            fecwf_flash('success', 'Approved "' . $pending['projectName'] . '". Go back to Framer and confirm your store there to finish connecting.');
        }
    }
    wp_safe_redirect(fecwf_admin_url());
    exit;
});

add_action('admin_post_fecwf_pair_cancel', function () {
    fecwf_guard_action('fecwf_pair_cancel');
    $pending = fecwf_get_pending();
    delete_transient(fecwf_pending_key());
    if ($pending) {
        $origin = fecwf_store_origin();
        // Cancelling also expires on its own, so a failed call only delays it.
        if (!is_wp_error($origin)) {
            fecwf_api_post('/v1/pair/cancel', array('code' => $pending['code'], 'store' => $origin));
        }
        fecwf_flash('success', 'Cancelled. "' . $pending['projectName'] . '" was not connected.');
    }
    wp_safe_redirect(fecwf_admin_url());
    exit;
});

add_action('admin_post_fecwf_disconnect', function () {
    fecwf_guard_action('fecwf_disconnect');
    if (fecwf_get_connection()) {
        fecwf_clear_connection();
        fecwf_flash('success', 'Disconnected. Every Framer site connected to this store stops showing its products within about a minute.');
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
    echo '<div class="notice notice-info"><p><strong>FeCommerce:</strong> connect this store to your Framer site with the code the FeCommerce plugin in Framer shows. <a href="' . esc_url(fecwf_admin_url()) . '">Connect to Framer</a></p></div>';
});

// Copies installed under the plugin's old names are switched off on activation
// but stay installed. Ask for them to be deleted.
add_action('admin_notices', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'plugins' || !current_user_can('delete_plugins')
        || (!file_exists(WP_PLUGIN_DIR . '/fecommerce-wooframe/fecommerce-wooframe.php')
            && !file_exists(WP_PLUGIN_DIR . '/woocommerce-bridge-fecommerce-co/woocommerce-bridge-fecommerce-co.php'))) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>FeCommerce:</strong> an old copy of this plugin (<em>fecommerce-wooframe-bridge</em> or <em>WooCommerce Bridge - FeCommerce Co</em>) is still installed. It is no longer used, and you can delete it. Your connection and settings are kept.</p></div>';
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

/** "2 minutes ago" for when a code was created, or '' when unknown. */
function fecwf_code_age($created_at)
{
    $created_at = (int) $created_at;
    if ($created_at <= 0 || $created_at > time() + 60) {
        return '';
    }
    return sprintf('%s ago', human_time_diff($created_at, time()));
}

/** The Approve screen for the code an admin just entered. */
function fecwf_render_approve($pending, $origin)
{
    $hosts = $pending['hostnames'];
    $age = fecwf_code_age($pending['createdAt']);
    ?>
    <div class="fecwf-approve">
        <p class="fecwf-question">
            Connect <strong>&ldquo;<?php echo esc_html($pending['projectName']); ?>&rdquo;</strong>
            <?php if ($hosts) : ?>
                (<span class="fecwf-hosts"><?php echo esc_html(implode(', ', $hosts)); ?></span>)
            <?php endif; ?>
            to <code><?php echo esc_html($origin); ?></code>?
        </p>
        <p class="fecwf-unverified">The project name and domains above were sent by the Framer plugin and are <strong>not verified</strong>.</p>
        <div class="fecwf-meta">
            <div><span>Framer project <em class="fecwf-unverified-tag">Sent by the Framer plugin — not verified</em></span><strong title="<?php echo esc_attr($pending['projectName']); ?>"><?php echo esc_html($pending['projectName']); ?></strong></div>
            <div><span>Code</span><strong><?php echo esc_html(fecwf_display_code($pending['code'])); ?><?php echo $age !== '' ? ' · ' . esc_html('created ' . $age) : ''; ?></strong></div>
        </div>
        <p>That site will be able to show your products and run cart and checkout with this store.</p>
        <div class="notice notice-warning inline"><p><strong>Only approve a code you just created in your own Framer project.</strong> FeCommerce will never ask you for a code.</p></div>
        <div class="fecwf-actions">
            <?php
            fecwf_action_form('fecwf_pair_approve', 'Approve', 'fecwf-btn fecwf-btn-primary');
            fecwf_action_form('fecwf_pair_cancel', 'Cancel', 'fecwf-btn');
            ?>
        </div>
        <p class="fecwf-fine">You have until <?php echo esc_html(wp_date(get_option('time_format'), (int) $pending['approveBy'])); ?> to approve. After you approve, the person in Framer confirms your store there to finish.</p>
    </div>
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
    $pending = is_wp_error($origin) ? null : fecwf_get_pending();
    $api_host = wp_parse_url(FECWF_API_BASE, PHP_URL_HOST);
    if ($connection && is_wp_error($origin)) {
        $pill = array(' is-warn', 'Needs attention');
    } elseif ($connection) {
        $pill = array(' is-on', 'Connected');
    } else {
        $pill = array('', 'Not connected');
    }
    ?>
    <div class="wrap fecwf">
        <div class="fecwf-header">
            <img class="fecwf-logo" src="<?php echo esc_url(plugins_url('assets/icon.png', FECWF_FILE)); ?>" alt="" width="44" height="44" />
            <div class="fecwf-title">
                <h1>FeCommerce</h1>
                <p class="fecwf-subtitle">Connect this WooCommerce store to Framer</p>
            </div>
            <span class="fecwf-pill<?php echo esc_attr($pill[0]); ?>"><?php echo esc_html($pill[1]); ?></span>
        </div>
        <hr class="wp-header-end" />
        <?php if (is_array($notice)) : ?>
            <div class="notice notice-<?php echo esc_attr($notice[0]); ?> is-dismissible"><p><?php echo esc_html($notice[1]); ?></p></div>
        <?php endif; ?>

        <div class="fecwf-card">
            <h2>Connect to Framer</h2>
            <?php if (is_wp_error($origin)) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($origin->get_error_message()); ?></p></div>
            <?php elseif ($pending) : ?>
                <?php fecwf_render_approve($pending, $origin); ?>
            <?php else : ?>
                <?php if ($connection) : ?>
                    <div class="fecwf-meta">
                        <div><span>Store</span><strong title="<?php echo esc_attr($connection['store']); ?>"><?php echo esc_html($connection['store']); ?></strong></div>
                        <div><span>Connected since</span><strong><?php echo esc_html(wp_date(get_option('date_format'), (int) $connection['issued_at'])); ?></strong></div>
                    </div>
                    <p>To connect another Framer site, enter the code its FeCommerce plugin shows.</p>
                <?php else : ?>
                    <p>Connect <code><?php echo esc_html($origin); ?></code> to your Framer site with the code the FeCommerce plugin in Framer shows.</p>
                <?php endif; ?>
                <ol class="fecwf-steps">
                    <li>In Framer, open the <strong>FeCommerce</strong> plugin and click <strong>Connect store</strong>.</li>
                    <li>Enter the code it shows below and click <strong>Continue</strong>. Codes change every 30 seconds, so keep the plugin open.</li>
                    <li>Check the project and click <strong>Approve</strong>, then confirm your store in Framer.</li>
                </ol>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="fecwf-code-form">
                    <input type="hidden" name="action" value="fecwf_pair_lookup" />
                    <?php wp_nonce_field('fecwf_pair_lookup'); ?>
                    <label class="fecwf-label" for="fecwf-code">Code from Framer</label>
                    <div class="fecwf-code-row">
                        <input type="text" id="fecwf-code" name="fecwf_code" class="fecwf-code" placeholder="K7QP-92MX" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false" required />
                        <button type="submit" class="fecwf-btn fecwf-btn-primary">Continue</button>
                    </div>
                </form>
                <p class="fecwf-fine">
                    Continue sends the code and your store's address to FeCommerce (<code><?php echo esc_html($api_host); ?></code>) to look up the Framer project.
                    Approve then confirms the address belongs to this site by having FeCommerce read <code>/wp-json/fecommerce/v1/challenge</code> once, and checks that WooCommerce answers at <code>/wp-json/wc/store/v1/products</code>.
                    FeCommerce keeps your store's hostname, its name, a connection id and dates; nothing about your products, orders or customers.
                </p>
            <?php endif; ?>
        </div>

        <?php
        if ($connection && !is_wp_error($origin)) {
            fecwf_render_sites_section();
        }
        ?>

        <?php if ($connection) : ?>
            <div class="fecwf-card">
                <h2>Manage</h2>
                <p>Disconnecting all stops every Framer site connected to this store within about a minute. To stop just one site, use Disconnect next to it under Connected Framer sites. To connect again later, enter a new code from Framer.</p>
                <div class="fecwf-actions">
                    <?php fecwf_action_form('fecwf_disconnect', 'Disconnect all Framer sites', 'fecwf-btn fecwf-btn-danger', 'Disconnect this store from Framer? Every Framer site connected to it stops showing its products, cart and checkout within about a minute.'); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php fecwf_render_allowlist_section(); ?>
    </div>
    <?php
}
