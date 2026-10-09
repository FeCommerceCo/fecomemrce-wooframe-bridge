<?php
/**
 * Plugin Name:       FeCommerce Bridge for WooCommerce
 * Plugin URI:        https://www.fecommerce.co/products/plugins/woocommerce
 * Description:       Lets your Framer site and the FeCommerce Framer plugin talk to this WooCommerce store directly, and issues the store's Framer connection key.
 * Version:           1.2.1
 * Author:            FeCommerce Co
 * Author URI:        https://fecommerce.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       fecommerce-bridge-for-woocommerce
 *
 * Copyright (C) 2026 FeCommerce (https://fecommerce.co)
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Before 1.2.0 this plugin was installed as
 * fecommerce-wooframe/fecommerce-wooframe.php (and 1.2.0 test builds as
 * woocommerce-bridge-fecommerce-co/woocommerce-bridge-fecommerce-co.php).
 * WordPress treats the renamed plugin as a different one, so both can be
 * installed side by side. Both define the same functions, so only one copy may
 * run. While an old copy is active, this one stays idle and only switches the
 * old copies off when it is activated. The connection and settings carry over
 * because all copies use the same fecwf_* options. Old releases have no
 * uninstall.php, so deleting the old copy afterwards removes nothing.
 */
if (defined('FECWF_FILE')) {
    register_activation_hook(__FILE__, function () {
        deactivate_plugins(array(
            'fecommerce-wooframe/fecommerce-wooframe.php',
            'woocommerce-bridge-fecommerce-co/woocommerce-bridge-fecommerce-co.php',
        ), true);
    });
    return;
}

define('FECWF_VERSION', '1.2.1');
define('FECWF_NAMESPACE', 'fecommerce/v1');
define('FECWF_FILE', __FILE__);
// Reviews accepted from the storefront form per hour, store-wide. A store
// can raise it in wp-config.php.
if (!defined('FECWF_REVIEWS_PER_HOUR')) {
    define('FECWF_REVIEWS_PER_HOUR', 30);
}

require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/site-allowlist.php';

/*
 * ─── Who may call what ─────────────────────────────────────────────────────
 *
 * PUBLIC STORE DATA is the only thing opened to other sites: WooCommerce's
 * Store API (/wc/store/...) and this plugin's own /fecommerce/v1 routes.
 * Products, categories and the shopper's cart, which the published Framer
 * site reads directly. A merchant's site can live on any domain (their own,
 * *.framer.app, *.framer.website), so ANY origin may read these, but never
 * with cookies: the credentials header is removed, so another website cannot
 * read a logged-in customer's session. FeCommerce components carry their cart
 * in the Cart-Token header instead, which needs no cookies.
 *
 * WooCommerce's key-protected REST API (/wc/v1-3/...) is NOT opened: the
 * FeCommerce Framer plugin uses only the public Store API and holds no API
 * keys. (Bridge 1.1 opened it to Framer's origins; 1.2 removes that.)
 *
 * Every other REST request keeps WordPress's default CORS behaviour. Nothing
 * else on the store is affected. The store admin can optionally restrict the
 * public routes to a list of sites (includes/site-allowlist.php). It is off by
 * default.
 */

/**
 * Framer's own origins: the plugin sandbox, the editor and the canvas. Always
 * allowed to read the public routes, even when the admin restricts them, so
 * syncing from Framer keeps working. localhost only with WP_DEBUG on, for
 * plugin development.
 */
function fecwf_is_framer_origin($origin)
{
    if (!is_string($origin) || $origin === '') {
        return false;
    }
    if (
        // Plugin runtime: [id].plugins.framercdn.com and the version-specific
        // [id]-[versionId].plugins.framercdn.com
        preg_match('/^https:\/\/[a-z0-9]+(-[a-zA-Z0-9]+)?\.plugins\.framercdn\.com$/', $origin) ||
        // Canvas preview
        preg_match('/^https:\/\/[a-z0-9-]+\.framercanvas\.com$/', $origin) ||
        in_array($origin, array('https://framer.com', 'https://app.framer.com'), true)
    ) {
        return true;
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
        return (bool) preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:[0-9]+)?$/', $origin);
    }
    return false;
}

/** Store API or this plugin's namespace: public data, any origin, no cookies. */
function fecwf_is_public_route($route)
{
    return is_string($route) && (
        strpos($route, '/wc/store/') === 0 ||
        strpos($route, '/' . FECWF_NAMESPACE . '/') === 0
    );
}

/**
 * The REST route of the current request, also during a preflight, before a
 * WP_REST_Request exists.
 */
function fecwf_current_route()
{
    if (isset($GLOBALS['wp']) && isset($GLOBALS['wp']->query_vars['rest_route'])) {
        return '/' . ltrim((string) $GLOBALS['wp']->query_vars['rest_route'], '/');
    }
    // Read-only routing lookup on a public REST request, not form handling, so
    // there is no nonce to check.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['rest_route'])) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return '/' . ltrim(sanitize_text_field(wp_unslash($_GET['rest_route'])), '/');
    }
    return '';
}

/** True when $origin is this site's own origin. */
function fecwf_is_same_site($origin)
{
    $home = wp_parse_url(home_url());
    if (!$home || empty($home['host'])) {
        return false;
    }
    $own = (isset($home['scheme']) ? $home['scheme'] : 'https') . '://' . $home['host'] .
        (isset($home['port']) ? ':' . $home['port'] : '');
    return strtolower($origin) === strtolower($own);
}

/**
 * Headers the browser must be allowed to SEND: the Store API cart session and
 * nonce. Sent by WordPress on every REST response, preflights included, so
 * they hold even when another plugin ends a preflight early.
 */
add_filter('rest_allowed_cors_headers', function ($headers) {
    return array_values(array_unique(array_merge(
        (array) $headers,
        array('Content-Type', 'Cart-Token', 'Nonce', 'X-WC-Store-API-Nonce')
    )));
});

/**
 * Headers the browser must be allowed to READ: the refreshed cart session and
 * nonce, and the pagination totals the catalogue sync uses to prove it saw
 * every product.
 */
add_filter('rest_exposed_cors_headers', function ($headers) {
    return array_values(array_unique(array_merge(
        (array) $headers,
        array('X-WP-Total', 'X-WP-TotalPages', 'Link', 'Cart-Token', 'Nonce', 'X-WC-Store-API-Nonce')
    )));
});

/**
 * WooCommerce's Store API only answers origins WordPress considers allowed.
 * Public routes allow any origin (without cookies, see below), unless the
 * admin has restricted them.
 */
add_filter('allowed_http_origin', function ($allowed, $origin) {
    if ($allowed || !is_string($origin) || $origin === '') {
        return $allowed;
    }
    $route = fecwf_current_route();
    if (fecwf_is_public_route($route)) {
        return fecwf_public_origin_allowed($origin) ? $origin : $allowed;
    }
    return $allowed;
}, 10, 2);

/**
 * The response's CORS headers, applied last so nothing later replaces them.
 */
add_filter('rest_pre_serve_request', function ($served, $result = null, $request = null) {
    $origin = get_http_origin();
    if (!$origin) {
        return $served;
    }
    $route = ($request instanceof WP_REST_Request) ? $request->get_route() : fecwf_current_route();

    // A site the store admin hasn't allowed (only when restriction is on) gets
    // no Access-Control-Allow-Origin naming it, so its browser can't read the
    // response. WordPress core echoes any origin by default, hence the removal.
    if (fecwf_is_public_route($route) && !fecwf_public_origin_allowed($origin)) {
        if (!headers_sent()) {
            header_remove('Access-Control-Allow-Origin');
            header_remove('Access-Control-Allow-Credentials');
        }
        return $served;
    }

    if (!fecwf_is_public_route($route)) {
        return $served; // WordPress default
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin', false);
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('X-FeCommerce-CORS-Active: 1');

    // Cookies are never shared with another site. The store's own pages keep
    // their usual behaviour.
    if (!fecwf_is_same_site($origin) && !headers_sent()) {
        header_remove('Access-Control-Allow-Credentials');
    }
    return $served;
}, PHP_INT_MAX, 3);

/*
 * ─── /fecommerce/v1 ────────────────────────────────────────────────────────
 */
add_action('rest_api_init', function () {
    // GET /fecommerce/v1/status — lets the Framer plugin tell whether this
    // plugin is installed, and which version.
    register_rest_route(FECWF_NAMESPACE, '/status', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $connection = fecwf_get_connection();
            $response = rest_ensure_response(array(
                'plugin' => 'fecommerce-wooframe',
                'version' => FECWF_VERSION,
                // Whether WooCommerce is active, not its version: an exact
                // version only helps someone looking for an unpatched store.
                'woocommerce' => defined('WC_VERSION'),
                'stripe' => fecwf_stripe_publishable_key() !== null,
                'connect' => true,
                // The id of this store's current connection key, or null when
                // disconnected. The Framer plugin and components accept a key
                // only while the store still names it here, so Regenerate and
                // Disconnect take effect without asking FeCommerce. Not a
                // secret: it is inside the key, which is published with every
                // Framer site that uses it.
                'sid' => $connection ? $connection['sid'] : null,
            ));
            // Short, so a regenerated or disconnected key stops working
            // within about a minute.
            $response->header('Cache-Control', 'public, max-age=60');
            return $response;
        },
    ));

    // GET /fecommerce/v1/config — the storefront's public runtime settings.
    // The Stripe PUBLISHABLE key is read from the merchant's own WooCommerce
    // Stripe settings when the checkout loads, so it never has to be copied
    // into the Framer project. Secret keys are never read or returned.
    register_rest_route(FECWF_NAMESPACE, '/config', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $response = rest_ensure_response(array(
                'stripe' => array(
                    'publishableKey' => fecwf_stripe_publishable_key(),
                ),
            ));
            $response->header('Cache-Control', 'public, max-age=300');
            return $response;
        },
    ));

    // POST /fecommerce/v1/reviews — the storefront's review form.
    register_rest_route(FECWF_NAMESPACE, '/reviews', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'fecwf_submit_review',
        'args' => array(
            'productId' => array('required' => true, 'type' => 'integer', 'minimum' => 1),
            'rating' => array('required' => false, 'type' => 'integer', 'minimum' => 1, 'maximum' => 5),
            'review' => array('required' => true, 'type' => 'string'),
            'author' => array('required' => true, 'type' => 'string'),
            'email' => array('required' => true, 'type' => 'string'),
            // Honeypot: a hidden field people never see or fill in.
            'website' => array('required' => false, 'type' => 'string'),
        ),
    ));
});

/**
 * The publishable key of the official WooCommerce Stripe gateway, for the mode
 * it is in, or null when Stripe is not set up. Only a value shaped like a
 * publishable key (pk_live_… / pk_test_…) is ever returned.
 */
function fecwf_stripe_publishable_key()
{
    $settings = get_option('woocommerce_stripe_settings');
    if (!is_array($settings)) {
        return null;
    }
    if (isset($settings['enabled']) && $settings['enabled'] !== 'yes') {
        return null;
    }
    $test = isset($settings['testmode']) && $settings['testmode'] === 'yes';
    $key = $test
        ? (isset($settings['test_publishable_key']) ? $settings['test_publishable_key'] : '')
        : (isset($settings['publishable_key']) ? $settings['publishable_key'] : '');
    $key = is_string($key) ? trim($key) : '';
    return preg_match('/^pk_(live|test)_[A-Za-z0-9]+$/', $key) ? $key : null;
}

/**
 * Create a product review from the storefront form. Goes through WordPress's
 * own comment pipeline (wp_new_comment), so flood, duplicate and spam checks
 * (Akismet etc.) all apply as for a review left on the store.
 *
 * Anyone can call this route, from any address, so on top of that:
 *   - every review from it is held for moderation, whatever the store's
 *     discussion settings, so nothing appears without the admin approving it;
 *   - at most 5 per address per 10 minutes, and FECWF_REVIEWS_PER_HOUR for
 *     the whole store, so rotating addresses can't flood the moderation queue;
 *   - a filled-in honeypot field gets a normal-looking answer and is dropped.
 */
function fecwf_submit_review(WP_REST_Request $request)
{
    $product_id = (int) $request->get_param('productId');
    $rating = $request->get_param('rating');
    $review = trim((string) $request->get_param('review'));
    $author = trim(sanitize_text_field((string) $request->get_param('author')));
    $email = trim(sanitize_email((string) $request->get_param('email')));

    $error = function ($code, $message, $status) {
        return new WP_Error($code, $message, array('status' => $status));
    };

    if ($review === '' || strlen($review) > 5000) {
        return $error('fecwf_invalid_review', 'Please write a review (up to 5000 characters).', 400);
    }
    if ($author === '' || strlen($author) > 100) {
        return $error('fecwf_invalid_author', 'Please enter your name.', 400);
    }
    if (!is_email($email)) {
        return $error('fecwf_invalid_email', 'Please enter a valid email address.', 400);
    }
    if ($rating !== null && ((int) $rating < 1 || (int) $rating > 5)) {
        return $error('fecwf_invalid_rating', 'Rating must be between 1 and 5.', 400);
    }

    if (get_option('woocommerce_enable_reviews', 'yes') !== 'yes') {
        return $error('fecwf_reviews_disabled', 'Reviews are turned off on this store.', 403);
    }
    $post = get_post($product_id);
    if (!$post || $post->post_type !== 'product' || $post->post_status !== 'publish') {
        return $error('fecwf_unknown_product', 'This product could not be found.', 404);
    }
    if (!comments_open($product_id)) {
        return $error('fecwf_reviews_closed', 'Reviews are closed for this product.', 403);
    }
    if (get_option('woocommerce_review_rating_verification_required', 'no') === 'yes') {
        // Only verified owners may review, and a form on another site cannot
        // prove ownership.
        return $error('fecwf_verified_only', 'Only verified owners can review this product. Please leave your review on the store.', 403);
    }
    if ($rating === null && get_option('woocommerce_review_rating_required', 'yes') === 'yes') {
        return $error('fecwf_rating_required', 'Please choose a rating.', 400);
    }

    // A bot that fills in every field: answer as if accepted, store nothing.
    if (trim((string) $request->get_param('website')) !== '') {
        return rest_ensure_response(array('ok' => true, 'status' => 'pending'));
    }

    // At most 5 submissions per address per 10 minutes, on top of WordPress's
    // own flood check. REMOTE_ADDR, not a forwarded-for header, which the
    // sender controls. Behind a proxy every visitor shares one address; the
    // store-wide cap below is what bounds the total either way.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $limit_key = 'fecwf_rv_' . md5($ip);
    $count = (int) get_transient($limit_key);
    if ($count >= 5) {
        return $error('fecwf_rate_limited', 'Too many reviews from you in a short time. Please try again later.', 429);
    }
    $store_key = 'fecwf_rv_all_' . (int) floor(time() / HOUR_IN_SECONDS);
    $store_count = (int) get_transient($store_key);
    if ($store_count >= FECWF_REVIEWS_PER_HOUR) {
        return $error('fecwf_rate_limited', 'This store is receiving a lot of reviews right now. Please try again later.', 429);
    }
    set_transient($limit_key, $count + 1, 10 * MINUTE_IN_SECONDS);
    set_transient($store_key, $store_count + 1, HOUR_IN_SECONDS);

    // Held for moderation. Spam, trash and errors from other checks stand.
    $hold = function ($approved) {
        return (is_wp_error($approved) || $approved === 'spam' || $approved === 'trash') ? $approved : 0;
    };
    add_filter('pre_comment_approved', $hold, PHP_INT_MAX);
    $comment_id = wp_new_comment(array(
        'comment_post_ID' => $product_id,
        'comment_author' => $author,
        'comment_author_email' => $email,
        'comment_author_url' => '',
        'comment_content' => $review,
        'comment_type' => 'review',
        'comment_parent' => 0,
        'user_id' => 0,
        'comment_author_IP' => $ip,
        'comment_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 254) : '',
    ), true);
    remove_filter('pre_comment_approved', $hold, PHP_INT_MAX);

    if (is_wp_error($comment_id)) {
        // wp_allow_comment reports duplicates (409) and floods (429) with the
        // status as plain error data; other errors carry array('status' => …).
        $data = $comment_id->get_error_data();
        $status = is_int($data) ? $data : ((is_array($data) && isset($data['status'])) ? (int) $data['status'] : 409);
        return $error($comment_id->get_error_code(), $comment_id->get_error_message(), $status);
    }

    if ($rating !== null) {
        add_comment_meta($comment_id, 'rating', (int) $rating, true);
    }
    if (class_exists('WC_Comments') && method_exists('WC_Comments', 'clear_transients')) {
        WC_Comments::clear_transients($product_id);
    }

    $comment = get_comment($comment_id);
    return rest_ensure_response(array(
        'ok' => true,
        'status' => ($comment && (string) $comment->comment_approved === '1') ? 'approved' : 'pending',
    ));
}
