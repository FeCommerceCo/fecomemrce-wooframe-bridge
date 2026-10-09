# WooCommerce Bridge - FeCommerce Co

A small WordPress plugin that lets your [Framer](https://framer.com) site and the FeCommerce Framer plugin talk to your WooCommerce store directly, and issues your store's Framer **connection key**.

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

**Connection key for Framer.** The FeCommerce Framer plugin, and the components it places on your site, only talk to a store named in a connection key signed by FeCommerce. They check the signature themselves. To get the key:

1. Go to **WooCommerce → FeCommerce** and click **Connect to Framer** (requires `manage_woocommerce`).
2. The plugin makes a one-time random challenge and sends your store's address (`home_url()`) and the challenge to the FeCommerce connection service, `https://auth.fecommerce.co`.
3. The service reads `GET /wp-json/fecommerce/v1/challenge` on your store once. Only your server can answer with the challenge, which proves the domain. It then reads one product id from `GET /wp-json/wc/store/v1/products` to check that WooCommerce answers there.
4. The service signs a key naming your store. Copy it into the FeCommerce plugin in Framer.

The service is contacted only when an admin clicks **Connect to Framer**, **Regenerate** or **Disconnect**. It keeps your store's hostname, a connection id and dates; nothing about products, orders or customers. Your site address must be HTTPS at the root of the domain (no sub-folder). The key is not a secret and is safe on your published Framer site.

Published Framer sites check their key themselves, so Regenerate and Disconnect don't switch off sites that are already live. To stop a site immediately, use the restriction below.

**Optional: restrict which sites may use your store.** Off by default. When on, the public routes only answer browser requests from the site addresses you list, plus your own site and Framer's addresses (so syncing keeps working). Requests from unlisted sites get `403`. This is a browser restriction, not access control: a server or script can send any `Origin` header or none, and WooCommerce's Store API is public on every store. Cart and checkout keep WooCommerce's own protections. The screen suggests addresses recently seen using your store (at most 20, updated at most daily per address).

**Everything else is unchanged.** Every other REST route keeps WordPress's default CORS behaviour.

## Endpoints

| Route | What it returns |
|---|---|
| `GET /wp-json/fecommerce/v1/challenge` | `{ challenge }` while a Connect, Regenerate or Disconnect is in progress (2 minutes at most), `404` otherwise. `Cache-Control: no-store` |
| `GET /wp-json/fecommerce/v1/status` | `{ plugin, version, woocommerce, stripe, connect }` so the Framer plugin can tell this plugin is installed and up to date |
| `GET /wp-json/fecommerce/v1/config` | `{ stripe: { publishableKey } }`: the **publishable** key from your WooCommerce Stripe settings (live or test, matching the gateway's mode), or `null`. Your checkout reads it at runtime, so the key never needs to be copied into your Framer project. Secret keys are never read or returned. |
| `POST /wp-json/fecommerce/v1/reviews` | Creates a product review from your Framer site's review form. Body: `{ productId, rating, review, author, email }`. Goes through WordPress's own comment pipeline, so your duplicate, flood and spam settings (Akismet etc.) all apply, and every review is held for moderation whatever your discussion settings. Respects "Enable reviews", "Ratings required" and "Verified owners only" (refused, since a form on another site can't prove ownership). At most 5 per visitor per 10 minutes and 30 per hour store-wide (`define('FECWF_REVIEWS_PER_HOUR', …)` in `wp-config.php` to change). An optional `website` field is a honeypot: when filled in, the review is dropped with a normal-looking answer. |

## Installation

1. Download the latest release ZIP from [Releases](https://github.com/FeCommerceCo/fecomemrce-wooframe-bridge/releases).
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**, choose the ZIP and click **Install Now**.
3. Click **Activate**.

**Updating from 1.1 or older:** the plugin was renamed in 1.2.0, so WordPress installs it next to the old one instead of replacing it. Activating it switches the old `fecommerce-wooframe-bridge` off automatically. Then delete the old plugin. Your settings and connection are kept.

Then go to **WooCommerce → FeCommerce**, click **Connect to Framer**, and copy the connection key into the FeCommerce plugin in Framer.

To check the plugin is active, open `https://your-store.example/wp-json/fecommerce/v1/status`. You should see `"version": "1.2.0"`.

If Connect fails with "couldn't confirm your site", a security plugin, firewall or page cache is blocking or caching `/wp-json/fecommerce/v1/challenge`. Allow that address and try again.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce (active)

## Building the release ZIP

Build from a tag with `git archive`, never by hand-picking files:

```bash
git archive --format=zip --prefix=woocommerce-bridge-fecommerce-co/ \
  -o woocommerce-bridge-fecommerce-co-<version>.zip v<version>
```

This includes every tracked plugin file, `assets/` too, in the folder WordPress expects. Files marked `export-ignore` in `.gitattributes` are left out. Before uploading the release, open the ZIP and check that `assets/` is there.

## Changelog

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
