<?php
/**
 * Plugin Name: FeCommerce-WooFrame
 * Plugin URI: https://github.com/dhavalgajjar/fecommerce-wooframe
 * Description: Allow your Framer designs to fetch from this WooCommerce store.
 * Version: 1.0.0
 * Author: Dhaval Gajjar
 * Author URI: https://dhavalgajjar.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: fecommerce-wooframe
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function ($value) {
        $origin = get_http_origin();
        if ($origin && (
            preg_match('/^https:\/\/[a-z0-9]+\.plugins\.framercdn\.com$/', $origin) ||
            in_array($origin, ['https://framer.com', 'https://app.framer.com'], true)
        )) {
            header('Access-Control-Allow-Origin: ' . esc_url($origin));
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
        }
        if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
            status_header(200);
            exit();
        }
        return $value;
    }, 15);
});
