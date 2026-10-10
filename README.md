# FeCommerce Bridge for WooCommerce

A small WordPress plugin that lets your [Framer](https://framer.com) site and the FeCommerce Framer plugin work with your WooCommerce store, and connects your store to Framer sites with a **pairing code**.

## Why it's needed

Browsers only let a website read another site's data when that site says it may (CORS). Your Framer site and your WooCommerce store live on different domains, so without this plugin the browser blocks the product, cart and checkout requests, and the FeCommerce Framer plugin can't sync your catalogue.

## What it does

**Public store data, any domain, never with cookies.** WooCommerce's Store API (`/wp-json/wc/store/…`) and this plugin's own routes (`/wp-json/fecommerce/v1/…`) can be read from any domain, so your Framer site works on `*.framer.app`, `*.framer.website` or your own domain. These requests never carry cookies: the `Access-Control-Allow-Credentials` header is removed for other sites, so no other website can read a logged-in customer's session. FeCommerce components keep the shopper's cart in the `Cart-Token` header instead. The browser may send `Cart-Token`, `Nonce` and `X-WC-Store-API-Nonce`, and read them back along with `X-WP-Total` / `X-WP-TotalPages`.

**WooCommerce's key-protected API stays closed.** The FeCommerce Framer plugin uses only the public Store API and never holds WooCommerce API keys, so this plugin doesn't open WooCommerce's authenticated REST API (`/wp-json/wc/v1–v3/…`) to other sites. (Version 1.1 opened it to Framer's addresses; 1.2 removes that.)

**Framer's own addresses** are always allowed to read the public routes, even when you restrict which sites may use your store (below), so syncing from Framer keeps working:

- `https://framer.com`, `https://app.framer.com`
- `https://*.plugins.framercdn.com` (the Framer plugin sandbox, including version-specific domains)
- `https://*.framercanvas.com` (canvas preview)
- `http://localhost` and `http://127.0.0.1`, only while `WP_DEBUG` is on

**Connecting to Framer: pairing codes.** The FeCommerce Framer plugin and the components it places on your site talk to your store only through FeCommerce's API, `https://api-v2.fecommerce.co`, which knows your store's address because your store proved it owns its domain. To connect a Framer project:

1. In Framer, open the FeCommerce plugin and click **Connect store**. It shows a code like `K7QP-92MX` that changes every 30 seconds.
2. Go to **WooCommerce → FeCommerce** (requires `manage_woocommerce`), enter the code and click **Continue**. The plugin asks the API which Framer project the code belongs to.
3. Check the project name and addresses on the Approve screen and click **Approve**. The plugin makes a one-time random challenge and sends the code, your store's address (`home_url()`), the challenge and your site title to the API. The API reads `GET /wp-json/fecommerce/v1/challenge` on your store once (only your server can answer with the challenge, which proves the domain) and reads one product id from `GET /wp-json/wc/store/v1/products` to check that WooCommerce answers there.
4. Back in Framer, confirm "Is this your store?". Only then does the Framer project receive its site token.

The first approval creates the store's connection: a connection id (`sid`, served from `/status`) and a secret that FeCommerce signs its requests to your store with (stored like a password, never shown). Every later Framer project joins the same connection. The API is contacted only when an admin enters a code, approves, cancels or manages connected sites. It keeps your store's hostname, its name, the connection id and dates; nothing about products, orders or customers. Your site address must be HTTPS at the root of the domain (no sub-folder).

**Connected Framer sites** lists every Framer project connected to your store (name, addresses, when it connected and was last used), loaded when you ask for it. **Disconnect** next to one stops that site at once and leaves the others running. Loading the list and disconnecting one site each carry a fresh domain proof, like Approve.

**The shopper's real IP, only from signed requests.** Your Framer site's product, cart and checkout requests reach your store through FeCommerce's API, so they all come from the API's address. The API adds the shopper's IP in `X-Forwarded-For` and signs each request (`X-FEC-Sid`, `X-FEC-Timestamp`, `X-FEC-Signature`, HMAC-SHA256 with your store's secret). When the signature checks out (constant-time compare, timestamp within 5 minutes), the plugin sets `REMOTE_ADDR`, `X-Forwarded-For` and `X-Real-IP` to the shopper's IP for that request, so WooCommerce's geolocation (tax and shipping), fraud and rate-limit plugins see the shopper. Any other request is left exactly as it arrived.

**Disconnect all Framer sites** clears the connection here. FeCommerce serves a Framer site only while your store's `/status` still names its connection, so every connected site stops within about a minute, without asking FeCommerce.

**Optional: restrict which sites may use your store.** Off by default. When on, the public routes only answer browser requests from the site addresses you list, plus your own site and Framer's addresses (so syncing keeps working). Requests from unlisted sites get `403`. This is a browser restriction, not access control: a server or script can send any `Origin` header or none, and WooCommerce's Store API is public on every store. Cart and checkout keep WooCommerce's own protections. The screen suggests addresses recently seen using your store (at most 20, updated at most daily per address).

**Everything else is unchanged.** Every other REST route keeps WordPress's default CORS behaviour.

## Endpoints

| Route | What it returns |
|---|---|
| `GET /wp-json/fecommerce/v1/challenge` | `{ challenge }` while an Approve or a connected-sites request is in progress (2 minutes at most), `404` otherwise. Single use. `Cache-Control: no-store` |
| `GET /wp-json/fecommerce/v1/status` | `{ plugin, version, woocommerce, stripe, connect, sid }`: whether this plugin is installed and up to date, and the store's connection id (`null` when disconnected). Cached 60 seconds. |
| `GET /wp-json/fecommerce/v1/config` | `{ stripe: { publishableKey } }`: the **publishable** key from your WooCommerce Stripe settings (live or test, matching the gateway's mode), or `null`. Your checkout reads it at runtime, so the key never needs to be copied into your Framer project. Secret keys are never read or returned. |
| `POST /wp-json/fecommerce/v1/reviews` | Creates a product review from your Framer site's review form. Body: `{ productId, rating, review, author, email }`. Goes through WordPress's own comment pipeline, so your duplicate, flood and spam settings (Akismet etc.) all apply, and every review is held for moderation whatever your discussion settings. Respects "Enable reviews", "Ratings required" and "Verified owners only" (refused, since a form on another site can't prove ownership). At most 5 per visitor per 10 minutes and 30 per hour store-wide (`define('FECWF_REVIEWS_PER_HOUR', …)` in `wp-config.php` to change). An optional `website` field is a honeypot: when filled in, the review is dropped with a normal-looking answer. |

## Installation

1. Download the latest release ZIP from [Releases](https://github.com/FeCommerceCo/fecomemrce-wooframe-bridge/releases).
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**, choose the ZIP and click **Install Now**.
3. Click **Activate**.

**Updating from 1.1 or older:** the plugin was renamed in 1.2.0, so WordPress installs it next to the old one instead of replacing it. Activating it switches the old `fecommerce-wooframe-bridge` off automatically. Then delete the old plugin. Your settings and connection are kept.

Then open the FeCommerce plugin in Framer, click **Connect store**, and enter the code it shows under **WooCommerce → FeCommerce**.

To check the plugin is active, open `https://your-store.example/wp-json/fecommerce/v1/status`. You should see `"version": "1.2.1"`.

If Approve fails with "couldn't confirm your site", a security plugin, firewall or page cache is blocking or caching `/wp-json/fecommerce/v1/challenge`. Allow that address and try again.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce (active)

## Building the release ZIP

Build from a tag with `git archive`, never by hand-picking files:

```bash
git archive --format=zip --prefix=fecommerce-bridge-for-woocommerce/ \
  -o fecommerce-bridge-for-woocommerce-<version>.zip v<version>
```

This includes every tracked plugin file, `assets/` too, in the folder WordPress expects. Files marked `export-ignore` in `.gitattributes` are left out. Before uploading the release, open the ZIP and check that `assets/` is there.

## Changelog

### Unreleased
- Connecting to Framer now uses a pairing code: enter the code the FeCommerce plugin in Framer shows, check the project on the Approve screen, and approve. There is no connection key to copy any more.
- All requests go to FeCommerce's API at `https://api-v2.fecommerce.co`. The `auth.fecommerce.co` connection service is no longer used.
- The first approval stores the store's connection id and a request-signing secret. Several Framer projects share one connection.
- **Disconnect all Framer sites** replaces Regenerate and Disconnect.
- New: **Connected Framer sites** lists every connected Framer project with Disconnect for each one.
- New: requests signed by FeCommerce's API carry the shopper's real IP, which WooCommerce's geolocation and fraud and rate-limit plugins then see. Unsigned requests are unchanged.

### 1.2.1
- Redesigned WooCommerce → FeCommerce screen in FeCommerce's colours: status badge, cards, and a connection key that's hidden until you click the eye button, with a Copy button.
- The screen's styles and scripts are now files in `assets/`, loaded only on that screen. No inline scripts remain.
- Accessibility: labelled buttons, a "Key copied" announcement for screen readers, visible keyboard focus and stronger text contrast. The layout mirrors correctly in right-to-left languages.

### 1.2.0
- New: **Connect to Framer** (WooCommerce → FeCommerce) issues the store's connection key, now required by the FeCommerce Framer plugin. Regenerate and Disconnect included.
- New: `GET /fecommerce/v1/challenge`, the one-time domain check used while connecting.
- New, optional: restrict which sites may use the store's public data (a browser restriction, not access control).
- Reviews from the Framer review form are always held for moderation, capped store-wide and screened with a honeypot field.
- `/status` reports `connect: true`.
- Deleting the plugin removes its settings and connection (`uninstall.php`).
- Security: WooCommerce's key-protected REST API (`/wc/v1–v3`) is no longer opened to Framer's addresses, and `Authorization` is no longer an allowed cross-site header. FeCommerce uses only the public Store API.
- Security: `/status` reports whether WooCommerce is active instead of its exact version.
- Security: the connection challenge is single-use and never cached.
- Copyright and license notices added to every file.

### 1.1.0
- Published Framer sites on any domain (including custom domains) can read the Store API, without cookies.
- Cart and checkout work cross-origin: `Cart-Token` and `Nonce` headers are allowed and readable; `X-WP-Total` is readable for catalogue sync.
- The keyed WooCommerce API is opened to Framer's own origins only; `localhost` only with `WP_DEBUG`.
- WordPress's default CORS behaviour is no longer replaced for every request, only for the routes above.
- New: `/fecommerce/v1/status`, `/fecommerce/v1/config` (Stripe publishable key at runtime), `/fecommerce/v1/reviews`.
- Declares `Requires Plugins: woocommerce`.

### 1.0.0
- Initial release: CORS headers for Framer origins.

## Copyright and license

Copyright (C) 2026 [FeCommerce](https://fecommerce.co)

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but **without any warranty**; without even the implied warranty of merchantability or fitness for a particular purpose. See the GNU General Public License for more details.

The full license text is in [LICENSE](LICENSE), and at <https://www.gnu.org/licenses/gpl-2.0.html>.

**If you redistribute or modify this plugin:**
- keep this copyright notice, the license notice in each PHP file, and the `LICENSE` file;
- mark the files you changed and the date of your changes;
- distribute your version under GPL-2.0-or-later as well.

"FeCommerce" and the FeCommerce logo are names of FeCommerce and are not licensed under the GPL. Forks must not present themselves as the official FeCommerce plugin.

## Author

[FeCommerce Co](https://fecommerce.co)
