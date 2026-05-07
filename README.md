# FeCommerce-WooFrame

A lightweight WordPress plugin that enables [Framer](https://framer.com) websites to fetch product data directly from your WooCommerce store via the WordPress REST API.

## The Problem

By default, WooCommerce's REST API blocks requests from external origins. If you're building a Framer site that needs to display products, prices, or other store data, the browser will reject the API responses due to CORS restrictions.

## What This Plugin Does

FeCommerce-WooFrame selectively allows cross-origin requests from Framer domains so your Framer plugins and sites can communicate with your WooCommerce store.

**Allowed origins:**
- `https://framer.com`
- `https://app.framer.com`
- `https://*.plugins.framercdn.com` (Framer plugin sandbox)

No other origins are affected -- your store's default CORS behavior remains unchanged for all non-Framer requests.

## Installation

1. Download or clone this repository
2. Copy the `fecommerce-wooframe` folder into `wp-content/plugins/`
3. Activate the plugin from **Plugins** in your WordPress admin

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce (active)

## How It Works

The plugin hooks into `rest_api_init` and replaces the default WordPress CORS headers with ones that permit Framer origins. It also handles preflight `OPTIONS` requests so the browser completes the handshake before sending actual data requests.

## License

GPL-2.0-or-later -- see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

[Dhaval Gajjar](https://dhavalgajjar.com)
