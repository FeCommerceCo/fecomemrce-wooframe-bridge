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
          header('X-FeCommerce-CORS-Active: yes');

          $origin = get_http_origin();
          if ($origin && (
              // Plugin runtime: production ([id].plugins.framercdn.com) and
              // version-specific ([id]-[versionId].plugins.framercdn.com) domains
              preg_match('/^https:\/\/[a-z0-9]+(-[a-zA-Z0-9]+)?\.plugins\.framercdn\.com$/', $origin) ||
              // Canvas preview (project-xxx.framercanvas.com)
              preg_match('/^https:\/\/[a-z0-9-]+\.framercanvas\.com$/', $origin) ||
              // Published sites on framer.app and framer.website (free + paid)
              preg_match('/^https:\/\/[a-z0-9-]+\.framer\.app$/', $origin) ||
              preg_match('/^https:\/\/[a-z0-9-]+\.framer\.website$/', $origin) ||
              // Editor + apps
              in_array($origin, ['https://framer.com', 'https://app.framer.com'], true) ||
              // Local dev
              preg_match('/^https?:\/\/localhost(:[0-9]+)?$/', $origin) ||
              preg_match('/^https?:\/\/127\.0\.0\.1(:[0-9]+)?$/', $origin)
          )) {
              header('Access-Control-Allow-Origin: ' . $origin);
              header('Vary: Origin');
              header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
              header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
          }
          $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
          if ('OPTIONS' === $request_method) {
            status_header(200);
            exit();
          }

          return $value;
      }, 15);
  });
