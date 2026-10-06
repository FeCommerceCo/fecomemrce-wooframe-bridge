# FeCommerce-WooFrame

A small WordPress plugin that lets your [Framer](https://framer.com) site and the FeCommerce Framer plugin talk to your WooCommerce store directly.

## Why it's needed

Browsers only let a website read another site's data when that site says it may (CORS). Your Framer site and your WooCommerce store live on different domains, so without this plugin the browser blocks the product, cart and checkout requests, and the FeCommerce Framer plugin can't sync your catalogue.

## What it does

**Public store data, any domain, never with cookies.** WooCommerce's Store API (`/wp-json/wc/store/…`) and this plugin's own routes (`/wp-json/fecommerce/v1/…`) can be read from any domain, so your Framer site works on `*.framer.app`, `*.framer.website` or your own domain. These requests never carry cookies: the `Access-Control-Allow-Credentials` header is removed for other sites, so no other website can read a logged-in customer's session. FeCommerce components keep the shopper's cart in the `Cart-Token` header instead. The browser may send `Cart-Token`, `Nonce` and `X-WC-Store-API-Nonce`, and read them back along with `X-WP-Total` / `X-WP-TotalPages`.

**Keyed REST API, Framer only.** WooCommerce's authenticated API (`/wp-json/wc/v1–v3/…`), used by the FeCommerce plugin's catalogue sync with your API keys, is opened only to Framer's own addresses:

- `https://framer.com`, `https://app.framer.com`
- `https://*.plugins.framercdn.com` (the Framer plugin sandbox, including version-specific domains)
- `https://*.framercanvas.com` (canvas preview)
- `http://localhost` and `http://127.0.0.1`, only while `WP_DEBUG` is on

**Everything else is unchanged.** Every other REST route, and every other origin on the keyed API, keeps WordPress's default CORS behaviour.

## Endpoints

| Route | What it returns |
|---|---|
| `GET /wp-json/fecommerce/v1/status` | `{ plugin, version, woocommerce, stripe }` so the Framer plugin can tell this plugin is installed and up to date |
| `GET /wp-json/fecommerce/v1/config` | `{ stripe: { publishableKey } }`: the **publishable** key from your WooCommerce Stripe settings (live or test, matching the gateway's mode), or `null`. Your checkout reads it at runtime, so the key never needs to be copied into your Framer project. Secret keys are never read or returned. |
| `POST /wp-json/fecommerce/v1/reviews` | Creates a product review from your Framer site's review form. Body: `{ productId, rating, review, author, email }`. Goes through WordPress's own comment pipeline, so your moderation, duplicate, flood and spam settings (Akismet etc.) all apply. Respects "Enable reviews", "Ratings required" and "Verified owners only" (refused, since a form on another site can't prove ownership). At most 5 per visitor per 10 minutes. |

## Installation

1. Download the latest release ZIP from [Releases](https://github.com/FeCommerceCo/fecomemrce-wooframe-bridge/releases).
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**, choose the ZIP and click **Install Now**.
3. Click **Activate**.

To check it's working, open `https://your-store.example/wp-json/fecommerce/v1/status`. You should see `"version": "1.1.0"`.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce (active)

## Changelog

### 1.1.0
- Published Framer sites on any domain (including custom domains) can read the Store API, without cookies.
- Cart and checkout work cross-origin: `Cart-Token` and `Nonce` headers are allowed and readable; `X-WP-Total` is readable for catalogue sync.
- The keyed WooCommerce API is opened to Framer's own origins only; `localhost` only with `WP_DEBUG`.
- WordPress's default CORS behaviour is no longer replaced for every request, only for the routes above.
- New: `/fecommerce/v1/status`, `/fecommerce/v1/config` (Stripe publishable key at runtime), `/fecommerce/v1/reviews`.
- Declares `Requires Plugins: woocommerce`.

### 1.0.0
- Initial release: CORS headers for Framer origins.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

[FeCommerce](https://fecommerce.co)
