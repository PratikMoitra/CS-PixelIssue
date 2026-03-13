=== CS Meta Sync ===
Contributors: csdevelopment
Tags: woocommerce, meta, facebook, pixel, catalog, conversions api
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce products to Meta Commerce catalog and integrate Meta Pixel & Conversions API.

== Description ==

CS Meta Sync is a lightweight WooCommerce plugin that gives you direct control over your Meta (Facebook/Instagram) integration:

* **Product Catalog Sync** — Pushes your WooCommerce products to a Meta Commerce catalog via the Graph API.
* **Meta Pixel** — Injects the Pixel base code and fires standard e-commerce events (ViewContent, AddToCart, InitiateCheckout, Purchase).
* **Conversions API** — Sends the same events server-side for improved match quality and ad-blocker resilience.

== Installation ==

1. Upload the `cs-meta-sync` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to WooCommerce → CS Meta Sync to enter your Meta credentials.
4. Enable the features you want (Pixel, CAPI, Catalog Sync).

== Frequently Asked Questions ==

= What credentials do I need? =

You need a Meta Pixel ID, a Conversions API Access Token, a Catalog ID, and a Graph API System User Token with `catalog_management` permission.

= Does this replace the official Facebook for WooCommerce plugin? =

Yes. You should deactivate the official plugin before using CS Meta Sync to avoid duplicate tracking.

== Changelog ==

= 1.0.0 =
* Initial release.
