=== fecommerce-wooframe-bridge ===
Contributors: fecommerceco
Tags: woocommerce, framer, cors, headless, rest-api
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets your Framer site and the FeCommerce Framer plugin talk to your WooCommerce store directly, and issues your store's Framer connection key.

== Description ==

Browsers only let a website read another site's data when that site says it may (CORS). Your Framer site and your WooCommerce store live on different domains, so without this plugin the browser blocks product, cart and checkout requests, and the FeCommerce Framer plugin can't sync your catalogue.

**Public store data, any domain, never with cookies.** WooCommerce's Store API and this plugin's own routes can be read from any domain, so your Framer site works on framer.app, framer.website or your own domain. Cookies are never shared with another site, so no other website can read a logged-in customer's session. The cart travels in the Cart-Token header instead.

**Keyed REST API, Framer only.** WooCommerce's authenticated API, used by the FeCommerce plugin's catalogue sync with your API keys, is opened only to Framer's own addresses (framer.com, the Framer plugin sandbox and canvas).

**Everything else is unchanged.** Every other REST route keeps WordPress's default behaviour.

**Connection key for Framer.** The FeCommerce Framer plugin only works with stores that have a connection key. Under **WooCommerce → FeCommerce**, click **Connect to Framer**. This plugin then sends your store's address to the FeCommerce connection service (auth.fecommerce.co). The service confirms the address belongs to your site by reading `/wp-json/fecommerce/v1/challenge` once, and signs a key naming your store. Paste the key into the FeCommerce plugin in Framer. The service keeps your store's hostname, a connection id and dates; nothing about your products, orders or customers. It is contacted only when you click Connect, Regenerate or Disconnect.

**Optional: restrict which sites may use your store.** Off by default. When on, only the site addresses you list (plus your own site and Framer's editor) can use your store's product, cart and checkout data.

It also adds four small endpoints:

* `GET /wp-json/fecommerce/v1/status`: confirms the plugin is installed, and its version.
* `GET /wp-json/fecommerce/v1/challenge`: answers the connection service's one-time domain check while you are connecting, and nothing (404) at any other time.
* `GET /wp-json/fecommerce/v1/config`: your Stripe **publishable** key from WooCommerce Stripe settings, so your checkout can read it at runtime. Secret keys are never read or returned.
* `POST /wp-json/fecommerce/v1/reviews`: product reviews from your Framer site's review form, through WordPress's own moderation, duplicate, flood and spam checks.

== Installation ==

1. Upload the plugin ZIP in **Plugins → Add New → Upload Plugin**, or install it from the plugin directory.
2. Activate it.
3. Go to **WooCommerce → FeCommerce** and click **Connect to Framer**.
4. Copy the connection key into the FeCommerce plugin in Framer.

Your site address (Settings → General) must start with `https://` and WordPress must be installed at the root of the domain, not in a sub-folder.

== Frequently Asked Questions ==

= Does this expose my API keys or customer data? =

No. Your API keys stay with you; this plugin adds CORS headers, four small endpoints and a settings screen. The connection key is not a password: it only proves to FeCommerce that the store is yours. Store API requests from other sites never carry cookies, so a customer's logged-in session can't be read by another website.

= Does this change CORS for the rest of my site? =

No. Only WooCommerce's Store API, the keyed WooCommerce API (for Framer's own addresses) and this plugin's routes are affected.

== Changelog ==

= 1.2.0 =
* New: Connect to Framer (WooCommerce → FeCommerce) issues the store's connection key, which the FeCommerce Framer plugin now requires. Regenerate and Disconnect included.
* New endpoint: `GET /fecommerce/v1/challenge`, the one-time domain check used while connecting.
* New, optional: restrict which sites may use the store's public data.
* `/status` reports `connect: true`.
* Deleting the plugin removes its settings and connection.

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

= 1.2.0 =
Required by the FeCommerce Framer plugin: after updating, go to WooCommerce → FeCommerce and click Connect to Framer.

= 1.1.1 =
Name change only. Safe to update.

= 1.1.0 =
Needed by the FeCommerce Framer plugin's direct-to-store release: published sites call your store directly, including on custom domains.
