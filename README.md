> 🛒 **Get the Commercial Plugin + Support ($29)**: [Buy on **CodeGrape Marketplace (#58554)**](https://codegrape.com/item/kolivri-woocommerce-ai-phone-cart-recovery-cod-verifier/58554) · [Try the Live AI Phone Call Demo at **Kolivri.com**](https://kolivri.com)

=== Kolivri AI Phone Cart Recovery & COD Order Verifier for WooCommerce ===
Contributors: kolivri
Tags: woocommerce, abandoned cart, voice ai, cod verification, israel
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recover high-value abandoned WooCommerce carts and verify Cash-on-Delivery (COD) orders with real-time AI phone calls in 8 languages (native Hebrew & English).

== Description ==

**Kolivri AI Phone Cart Recovery & COD Order Verifier for WooCommerce** connects your WooCommerce store to [Kolivri](https://kolivri.com/he/) (`https://app.kolivri.co.il`), the all-in-one AI Voice Call Center & CRM platform powered by the Memoria temporal memory graph (`100% LongMemEval`, `98% Hebrew LongMemEval`).

SMS and email cart reminders suffer from low open rates on high-ticket orders. Kolivri places natural, sub-second AI voice calls in **8 languages** (Hebrew, English, Arabic, Russian, French, Spanish, German, and Portuguese) to verify Cash-on-Delivery (COD) shipments and recover abandoned carts.

= Two Built-In Operating Modes =

1. **Mode 1 — Transactional Order, Delivery & COD Phone Verification (Section 30A Exempt)**:
   - Automatically triggers on `woocommerce_checkout_order_processed`.
   - Calls the buyer to confirm order items, delivery address, and Cash-on-Delivery (COD) readiness before you dispatch a courier.
   - Strictly transactional (`forbid_upsell = true`, `promotional = false`) to comply with Israeli Communications Law (Section 30A).
   - Updates WooCommerce order status (`wc-processing`, `wc-on-hold`, or `wc-cancelled`) via HMAC-SHA256 signed webhooks.

2. **Mode 2 — Opt-In Abandoned Cart AI Voice Recovery (Israeli Section 30A Compliant)**:
   - Renders an explicit, unchecked opt-in consent checkbox (`kolivri_voice_cart_consent`) on the WooCommerce checkout page and logs the UTC timestamp and IP audit trail on the order metadata.
   - Triggers an outbound AI phone call only when explicit consent is `true` and the cart total meets your minimum threshold (default `₪300`).
   - Provisions a single-use WooCommerce coupon (`WC_Coupon`, e.g. `KLV5-XXXXXX`, `usage_limit = 1`) that the AI agent texts to the shopper during the call to close the sale immediately.

= Interactive ROI Calculator Shortcode =

Embed `[kolivri_woo_roi_preview]` (supports `lang="he"` and `lang="en"`) on any page to display an interactive COD Return Savings + Abandoned Cart Voice Recovery ROI calculator.

= Key Links =

* **Kolivri Website (Hebrew)**: [https://kolivri.com/he/](https://kolivri.com/he/)
* **Kolivri Website (English)**: [https://kolivri.com/](https://kolivri.com/)
* **Live WooCommerce Voice ROI Calculator**: [https://kolivri.com/he/woo-voice-recovery/](https://kolivri.com/he/woo-voice-recovery/)
* **Kolivri Cloud Portal**: [https://app.kolivri.co.il](https://app.kolivri.co.il)
* **Live AI Demo Line**: `054-787-5670` (`+972-54-787-5670`)

== Installation ==

1. Upload the `kolivri-woo-voice-recovery` folder to the `/wp-content/plugins/` directory, or install the `kolivri-woo-voice-recovery.zip` package via **Plugins > Add New > Upload Plugin** in WordPress.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Configure your Kolivri Webhook IDs (`kolivri_woo_order_webhook_id`, `kolivri_woo_cart_webhook_id`), HMAC secret (`kolivri_woo_webhook_secret`), minimum cart threshold (`kolivri_woo_min_cart_total`), and single-use discount percentage (`kolivri_woo_coupon_percent`).
4. Point your Kolivri campaign webhook callback in `https://app.kolivri.co.il` to `https://your-store.com/wp-json/kolivri-woo/v1/webhook`.

== Frequently Asked Questions ==

= How does this plugin comply with Israeli Spam Law (Section 30A / תיקון 40 לחוק התקשורת)? =

Mode 1 (Order & COD Verification) is strictly transactional (`promotional: false`, `forbid_upsell: true`), verifying an order the customer just placed. Mode 2 (Abandoned Cart Recovery) enforces a strict boolean `consent === true` gate backed by an explicit, unchecked checkout checkbox (`kolivri_voice_cart_consent`) with timestamp and IP logging. Non-consented carts are rejected before any payload leaves your server.

= How are incoming call-outcome webhooks authenticated? =

Every webhook request to `/wp-json/kolivri-woo/v1/webhook` is verified using constant-time `hash_equals` HMAC-SHA256 signature validation (`X-Kolivri-Signature: sha256=<hex>`) and idempotent `call_id` deduplication so duplicate retries never double-count KPIs or re-trigger order transitions.

= Which languages are supported? =

All 8 native Kolivri Voice AI languages: Hebrew (`he`), English (`en`), Arabic (`ar`), Russian (`ru`), French (`fr`), Spanish (`es`), German (`de`), and Portuguese (`pt`), with automatic RTL/LTR detection and `+972` E.164 phone normalization.

== Changelog ==

= 1.0.0 =
* Initial release of Kolivri AI Phone Cart Recovery & COD Order Verifier for WooCommerce.
* Added Mode 1 Transactional Order & COD Phone Verification (`woocommerce_checkout_order_processed`).
* Added Mode 2 Israeli Section 30A Opt-In Abandoned Cart Voice Recovery with dynamic single-use `WC_Coupon` generation.
* Added HMAC-SHA256 signed REST webhook endpoint (`/wp-json/kolivri-woo/v1/webhook`) with idempotent `call_id` processing.
* Added bilingual `[kolivri_woo_roi_preview]` interactive ROI calculator shortcode.
