=== FeCommerce Bridge for WooCommerce ===
Contributors: fecommerceco
Tags: woocommerce, framer, cors, headless, rest-api
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets your Framer site and the FeCommerce Framer plugin work with your WooCommerce store, and connects your store to Framer sites with a pairing code.

== Description ==

Browsers only let a website read another site's data when that site says it may (CORS). Your Framer site and your WooCommerce store live on different domains, so without this plugin the browser blocks product, cart and checkout requests, and the FeCommerce Framer plugin can't sync your catalogue.

**Public store data, any domain, never with cookies.** WooCommerce's Store API and this plugin's own routes can be read from any domain, so your Framer site works on framer.app, framer.website or your own domain. Cookies are never shared with another site, so no other website can read a logged-in customer's session. The cart travels in the Cart-Token header instead.

**WooCommerce's key-protected API stays closed.** The FeCommerce Framer plugin uses only the public Store API and never holds WooCommerce API keys, so this plugin doesn't open WooCommerce's authenticated REST API to other sites.

**Everything else is unchanged.** Every other REST route keeps WordPress's default behaviour.

**Connect with a pairing code.** In Framer, the FeCommerce plugin shows a short code that changes every 30 seconds. Enter it under **WooCommerce → FeCommerce**, check the Framer project's name and addresses, and click **Approve**; then confirm your store in Framer. Approve sends the code, your store's address and site title to FeCommerce's API (api-v2.fecommerce.co), which confirms the address belongs to your site by reading `/wp-json/fecommerce/v1/challenge` once and checks that WooCommerce answers at `/wp-json/wc/store/v1/products`. FeCommerce keeps your store's hostname, its name, a connection id and dates; nothing about your products, orders or customers. It is contacted only when you enter a code, approve, cancel or manage connected sites. Under **Connected Framer sites** you can see every connected Framer project and disconnect one without affecting the others.

**Optional: restrict which sites may use your store.** Off by default. When on, only the site addresses you list (plus your own site and Framer's editor) can show your store's products, cart and checkout to their visitors. It controls other websites, not direct access: your store's public product and cart API stays public, as on every WooCommerce store.

It also adds four small endpoints:

* `GET /wp-json/fecommerce/v1/status`: confirms the plugin is installed, and its version.
* `GET /wp-json/fecommerce/v1/challenge`: answers FeCommerce's one-time domain check while you approve, and nothing (404) at any other time.
* `GET /wp-json/fecommerce/v1/config`: your Stripe **publishable** key from WooCommerce Stripe settings, so your checkout can read it at runtime. Secret keys are never read or returned.
* `POST /wp-json/fecommerce/v1/reviews`: product reviews from your Framer site's review form, through WordPress's own duplicate, flood and spam checks. Every review from it waits for your approval.

== Installation ==

1. Upload the plugin ZIP in **Plugins → Add New → Upload Plugin**, or install it from the plugin directory.
2. Activate it.
3. In Framer, open the FeCommerce plugin and click **Connect store**.
4. Enter the code it shows under **WooCommerce → FeCommerce**, approve, then confirm your store in Framer.

Your site address (Settings → General) must start with `https://` and WordPress must be installed at the root of the domain, not in a sub-folder.

== Frequently Asked Questions ==

= Does this expose my API keys or customer data? =

No. Your API keys stay with you; this plugin adds CORS headers, four small endpoints and a settings screen. Connecting stores a connection id and a secret FeCommerce signs its requests with; the secret is never shown or sent anywhere. Store API requests from other sites never carry cookies, so a customer's logged-in session can't be read by another website.

= Does this change CORS for the rest of my site? =

No. Only WooCommerce's Store API, the keyed WooCommerce API (for Framer's own addresses) and this plugin's routes are affected.

== Copyright ==

FeCommerce Bridge for WooCommerce
Copyright (C) 2026 FeCommerce (https://fecommerce.co)

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

The full license is in the LICENSE file included with this plugin, and at https://www.gnu.org/licenses/gpl-2.0.html.

If you redistribute or modify this plugin, keep this copyright notice and the license, and mark your changes.

"FeCommerce" and the FeCommerce logo are names of FeCommerce and are not licensed under the GPL. Forks must not present themselves as the official FeCommerce plugin.

== Changelog ==

= Unreleased =
* Connect to Framer with a pairing code: enter the code the FeCommerce plugin in Framer shows, check the project, and approve. No connection key to copy.
* All requests go to FeCommerce's API at api-v2.fecommerce.co instead of auth.fecommerce.co.
* Several Framer projects share one store connection. Disconnect all Framer sites replaces Regenerate and Disconnect.
* New: Connected Framer sites lists every connected Framer project, with Disconnect for each one.

= 1.2.1 =
* Redesigned WooCommerce → FeCommerce screen in FeCommerce's colours: status badge, cards, and a connection key that's hidden until you click the eye button, with a Copy button.
* The screen's styles and scripts are now files in `assets/`, loaded only on that screen. No inline scripts remain.
* Accessibility: labelled buttons, a "Key copied" announcement for screen readers, visible keyboard focus and stronger text contrast. The layout mirrors correctly in right-to-left languages.

= 1.2.0 =
* Renamed to FeCommerce Bridge for WooCommerce. It now installs in the `fecommerce-bridge-for-woocommerce` folder. Activating it switches the old copy off, and keeps your settings. Then delete the old copy.
* New: Connect to Framer (WooCommerce → FeCommerce) issues the store's connection key, which the FeCommerce Framer plugin now requires. Regenerate and Disconnect included.
* New endpoint: `GET /fecommerce/v1/challenge`, the one-time domain check used while connecting.
* New, optional: restrict which sites may use the store's public data (a browser restriction, not access control).
* Reviews from the Framer review form are always held for moderation, capped store-wide (30 an hour, `FECWF_REVIEWS_PER_HOUR`) and screened with a honeypot field.
* `/status` reports `connect: true` and the current connection key's `sid` (null when disconnected), cached for 60 seconds. FeCommerce in Framer accepts a key only while this matches, so Regenerate and Disconnect stop the old key on published sites.
* Deleting the plugin removes its settings and connection.
* Security: WooCommerce's key-protected REST API (/wc/v1-3) is no longer opened to Framer's addresses, and the Authorization header is no longer allowed cross-site. FeCommerce uses only the public Store API.
* Security: /status reports whether WooCommerce is active instead of its exact version.
* Security: the connection challenge is single-use and never cached.
* Copyright and license notices added to every file.

= 1.1.1 =
* Renamed to fecommerce-wooframe-bridge. No functional changes.

= 1.1.0 =
* Published Framer sites on any domain, including custom domains, can read the Store API, without cookies.
* Cart and checkout work cross-origin: Cart-Token and Nonce headers are allowed and readable; X-WP-Total is readable for catalogue sync.
* The keyed WooCommerce API is opened to Framer's own origins only; localhost only with WP_DEBUG.
* WordPress's default CORS behaviour is no longer replaced for every request.
* New endpoints: status, config (Stripe publishable key at runtime), reviews.
* Declares Requires Plugins: woocommerce.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.1 =
Redesigned settings screen with show/hide and Copy for the connection key. Safe to update.

= 1.2.0 =
Required by the FeCommerce Framer plugin. Upload and activate it, delete the old fecommerce-wooframe-bridge plugin, then go to WooCommerce → FeCommerce and click Connect to Framer.

= 1.1.1 =
Name change only. Safe to update.

= 1.1.0 =
Needed by the FeCommerce Framer plugin's direct-to-store release: published sites call your store directly, including on custom domains.
