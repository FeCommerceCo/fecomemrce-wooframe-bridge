<?php
/**
 * Requests from FeCommerce's API: trust the shopper's IP only when signed.
 *
 * A Framer site's product, cart and checkout requests reach this store through
 * FeCommerce's API (api-v2.fecommerce.co), so to WordPress they all come from
 * the API's address. The API adds the shopper's real IP in X-Forwarded-For and
 * signs every request with the secret this store received when it was first
 * connected (includes/connect.php):
 *
 *   X-FEC-Sid:       the store's connection id
 *   X-FEC-Timestamp: unix seconds
 *   X-FEC-Signature: v1=<base64url(HMAC-SHA256(secret, canonical))>
 *   canonical = "v1\n" + timestamp + "\n" + METHOD + "\n" + path_with_query
 *               + "\n" + hex(SHA-256(body)) + "\n" + x_forwarded_for
 *
 * When the signature checks out (right connection id, matching HMAC compared
 * in constant time, timestamp within 5 minutes), the shopper's IP replaces
 * REMOTE_ADDR, X-Forwarded-For and X-Real-IP for the rest of the request. So
 * WooCommerce's geolocation (tax and shipping), fraud and rate-limit plugins,
 * and this plugin's own review limits, see the shopper instead of FeCommerce.
 *
 * Any other request, signed badly or not at all, is left exactly as it
 * arrived. The shopper IP inside a request is only believed with a valid
 * signature, because anyone can send that header.
 *
 * This runs while plugins load, so it takes effect before WordPress handles
 * the request. Plugins that read the address even earlier (loaded before this
 * one) still see FeCommerce's.
 *
 * Copyright (C) 2026 FeCommerce (https://fecommerce.co)
 * Licensed under the GNU General Public License v2 or later (GPL-2.0-or-later).
 * See the LICENSE file in the plugin's root folder.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Seconds a signature stays valid either side of now (clock skew included).
define('FECWF_SIGNATURE_WINDOW', 300);
// Bodies above this aren't hashed; the API's largest is 16 KB.
define('FECWF_SIGNED_BODY_MAX', 1048576);

/**
 * The shopper's IP when $server describes a request correctly signed by
 * FeCommerce's API for this store's connection, else null.
 *
 * @param array    $server  $_SERVER, or a test double.
 * @param callable $body    Returns the raw request body. Only called once the
 *                          cheaper checks have passed.
 * @param array    $connection fecwf_get_connection()
 * @param string   $secret  fecwf_store_secret()
 * @param int      $now     Current unix time.
 */
function fecwf_signed_request_ip(array $server, $body, $connection, $secret, $now)
{
    $get = function ($name) use ($server) {
        return (isset($server[$name]) && is_string($server[$name])) ? $server[$name] : '';
    };
    $sid = $get('HTTP_X_FEC_SID');
    $ts = $get('HTTP_X_FEC_TIMESTAMP');
    $sig = $get('HTTP_X_FEC_SIGNATURE');
    $xff = $get('HTTP_X_FORWARDED_FOR');
    if ($sid === '' || $ts === '' || $sig === '' || $xff === '' || !$connection || $secret === '') {
        return null;
    }
    if (!hash_equals((string) $connection['sid'], $sid)) {
        return null;
    }
    if (!preg_match('/^[0-9]{1,12}$/D', $ts) || abs($now - (int) $ts) > FECWF_SIGNATURE_WINDOW) {
        return null;
    }
    if (!preg_match('/^v1=([A-Za-z0-9_-]{43})$/D', $sig, $m)) {
        return null;
    }

    // The API sends one address. A proxy in front of this store may append its
    // own ("shopper, proxy"), so the API's is the first one.
    $parts = explode(',', $xff);
    $ip = trim($parts[0]);
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    $method = strtoupper($get('REQUEST_METHOD'));
    $path = $get('REQUEST_URI');
    if ($method === '' || $path === '' || $path[0] !== '/') {
        return null;
    }
    $length = $get('CONTENT_LENGTH');
    if ($length !== '' && (int) $length > FECWF_SIGNED_BODY_MAX) {
        return null;
    }
    $raw = (string) call_user_func($body);
    if (strlen($raw) > FECWF_SIGNED_BODY_MAX) {
        return null;
    }

    $canonical = "v1\n" . $ts . "\n" . $method . "\n" . $path . "\n" . hash('sha256', $raw) . "\n" . $ip;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');
    return hash_equals($expected, $m[1]) ? $ip : null;
}

/**
 * Apply it to the current request: REST requests only, which is all the API
 * ever sends.
 */
function fecwf_apply_signed_request()
{
    if (empty($_SERVER['HTTP_X_FEC_SIGNATURE'])) {
        return;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (strpos($uri, '/wp-json/') !== 0 && strpos($uri, 'rest_route=') === false) {
        return;
    }
    $ip = fecwf_signed_request_ip($_SERVER, function () {
        return file_get_contents('php://input');
    }, fecwf_get_connection(), fecwf_store_secret(), time());
    if ($ip === null) {
        return;
    }
    // Kept for anyone who needs to know the request came through FeCommerce.
    $_SERVER['FECWF_PROXY_ADDR'] = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $ip;
    $_SERVER['HTTP_X_REAL_IP'] = $ip;
    if (!defined('FECWF_SIGNED_REQUEST')) {
        define('FECWF_SIGNED_REQUEST', true);
    }
}

fecwf_apply_signed_request();
