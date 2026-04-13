=== Bayarku – DOKU Payment Gateway for WooCommerce ===
Contributors: panduaji
Tags: woocommerce, payment gateway, qris, doku
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
WC requires at least: 7.0
WC tested up to: 10.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

DOKU payment gateway for WooCommerce — QRIS QR Code displayed on your site, no redirect, auto-polling, webhook backup.

== Description ==

Bayarku DOKU integrates DOKU payment methods directly into WooCommerce using the official DOKU SNAP API.

**Currently available:**
- DOKU QRIS — QR Code is displayed on your own website; the buyer never leaves your checkout page.

**Coming soon:**
- DOKU Virtual Account
- DOKU eWallet (OVO, GoPay, Dana)

**Features:**
- QRIS QR Code displayed on your own site — buyer never leaves your page
- Auto-polling every 4 seconds — page redirects to thank-you automatically when payment succeeds
- Webhook backup (`POST /wp-json/bayarku/v1/notify/doku`) — catches payments missed by polling
- Sandbox / Production toggle in WP Admin
- Credentials stored securely in WordPress options — never hardcoded
- HPOS (High-Performance Order Storage) compatible
- QR Code generated locally using PHP GD — no external requests beyond DOKU

**Important:** You must register separately at https://dashboard.doku.com to obtain your Client ID, Shared Key, Private Key, and Terminal ID.

== Installation ==

1. Upload the `bayarku` folder to `/wp-content/plugins/`.
2. Activate the plugin via the WordPress Plugins menu.
3. Go to **WooCommerce > Settings > Payments > QRIS (DOKU)**.
4. Enter your DOKU credentials (Client ID, Shared Key, Private Key, QRIS Client ID, Terminal ID).
5. Set the webhook URL in the DOKU dashboard to: `https://yourdomain.com/wp-json/bayarku/v1/notify/doku`
6. Disable Sandbox when ready for production.
7. Save and test with a real order.

After activating the plugin, go to **Settings > Permalinks** and click Save to flush rewrite rules (required for the payment page).

== Frequently Asked Questions ==

= Do I need a DOKU account? =
Yes. Register at https://dashboard.doku.com. This plugin uses the DOKU SNAP API which requires official credentials.

= Where is the QR Code displayed? =
On your own website at `/bayarku-payment/` — the buyer never leaves your site.

= Is this plugin affiliated with DOKU? =
No. This is an independent open-source plugin that uses the public DOKU SNAP API.

= Can I use sandbox mode? =
Yes. There is a Sandbox toggle on the gateway settings page.

= Are Virtual Account and eWallet available yet? =
Not yet. VA and eWallet are under development and will be available in a future release.

== Third-Party Libraries ==

This plugin includes the following open-source library:

* **QR Code Generator for PHP** by Kazuhiko Arase
* Source: https://github.com/kazuhikoarase/qrcode-generator
* License: MIT
* Location: `includes/lib/qrcode.php`
* Purpose: Generates QR Code images locally using the PHP GD extension — no external service required.

== External Services ==

This plugin connects to the following external service:

= api.doku.com / api-sandbox.doku.com (DOKU SNAP API) =
All payment operations (generate QR, query status, cancel QR) are sent to the official DOKU SNAP API.

* Production: https://api.doku.com
* Sandbox: https://api-sandbox.doku.com
* Data sent: order amount, merchant credentials, QR reference number
* Privacy policy: https://www.doku.com/privacy-policy

You must register at https://dashboard.doku.com and agree to DOKU's terms before using this plugin.

== Privacy Policy ==

This plugin itself does not collect, store, or transmit personal data beyond what WooCommerce already handles. However:

1. **DOKU API**: Order amounts and merchant credentials are sent to DOKU servers to process payments.

QR Code images are generated **locally** on your server using the bundled qrcode-generator library. No data is sent to any external service for QR rendering.

Store owners using this plugin in regions covered by GDPR or similar privacy laws should disclose the DOKU data flow in their own privacy policies.

== Changelog ==

= 1.0.0 =
* Initial release — DOKU QRIS full implementation.
