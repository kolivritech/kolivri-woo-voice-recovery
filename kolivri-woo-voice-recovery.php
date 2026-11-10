<?php
/**
 * Plugin Name: Kolivri WooCommerce AI Voice Order Verifier & Cart Recovery
 * Plugin URI: https://kolivri.com
 * Description: AI phone verification for WooCommerce orders/COD (transactional) and Israeli Spam-Law (Section 30A) opt-in abandoned cart voice recovery with single-use SMS coupons.
 * Version: 1.0.0
 * Author: Kolivri
 * Author URI: https://kolivri.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kolivri-woo-voice-recovery
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOLIVRI_WOO_VOICE_VERSION', '1.0.0');
define('KOLIVRI_WOO_VOICE_PATH', function_exists('plugin_dir_path') ? plugin_dir_path(__FILE__) : dirname(__FILE__) . '/');
define('KOLIVRI_WOO_VOICE_URL', function_exists('plugin_dir_url') ? plugin_dir_url(__FILE__) : '');

require_once KOLIVRI_WOO_VOICE_PATH . 'includes/class-kolivri-woo-engine.php';

class Kolivri_Woo_Voice_Recovery {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Mode 1: Transactional Order, Delivery & COD Phone Verification.
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_new_order'), 20, 1);

        // Mode 2: Israeli Spam-Law (Section 30A) Explicit Consent Checkbox on Checkout.
        add_action('woocommerce_review_order_before_submit', array($this, 'render_checkout_consent_checkbox'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_consent_meta'));
        add_action('kolivri_woo_process_abandoned_cart', array($this, 'handle_abandoned_cart_trigger'), 10, 1);

        if (function_exists('add_shortcode')) {
            add_shortcode('kolivri_woo_roi_preview', array($this, 'shortcode_roi_preview'));
        }

        if (function_exists('register_activation_hook')) {
            register_activation_hook(__FILE__, array($this, 'activate'));
        }
    }

    public function init() {
        if (function_exists('load_plugin_textdomain') && function_exists('plugin_basename')) {
            load_plugin_textdomain('kolivri-woo-voice-recovery', false, dirname(plugin_basename(__FILE__)) . '/languages');
        }
    }

    public function register_settings() {
        $group = 'kolivri_woo_voice_settings';
        register_setting($group, 'kolivri_woo_enabled', array('sanitize_callback' => 'absint'));
        register_setting($group, 'kolivri_woo_order_webhook_id', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'kolivri_woo_cart_webhook_id', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'kolivri_woo_webhook_secret', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'kolivri_woo_min_cart_total', array('sanitize_callback' => 'floatval'));
        register_setting($group, 'kolivri_woo_coupon_percent', array('sanitize_callback' => 'absint'));
        register_setting($group, 'kolivri_woo_default_language', array('sanitize_callback' => 'sanitize_text_field'));
    }

    public function activate() {
        if (!function_exists('add_option')) {
            return;
        }
        add_option('kolivri_woo_enabled', 0);
        add_option('kolivri_woo_min_cart_total', Kolivri_Woo_Engine::DEFAULT_MIN_CART_TOTAL);
        add_option('kolivri_woo_coupon_percent', Kolivri_Woo_Engine::DEFAULT_COUPON_PERCENT);
        add_option('kolivri_woo_default_language', 'he');
        add_option('kolivri_woo_kpi_metrics', Kolivri_Woo_Engine::defaultKpiState());
    }

    /**
     * Render the explicit Section 30A consent checkbox above the Place Order button.
     */
    public function render_checkout_consent_checkbox() {
        $lang = function_exists('determine_locale') ? determine_locale() : (function_exists('get_locale') ? get_locale() : 'he');
        echo Kolivri_Woo_Engine::renderConsentCheckboxHtml($lang, false);
    }

    /**
     * Save explicit consent audit trail on the WooCommerce order meta.
     *
     * @param int $order_id
     */
    public function save_checkout_consent_meta($order_id) {
        $consented = !empty($_POST[Kolivri_Woo_Engine::CONSENT_FIELD_NAME]) && '1' === (string) $_POST[Kolivri_Woo_Engine::CONSENT_FIELD_NAME];
        if (function_exists('update_post_meta')) {
            update_post_meta($order_id, '_kolivri_voice_cart_consent', $consented ? 'yes' : 'no');
            if ($consented) {
                update_post_meta($order_id, '_kolivri_voice_consent_timestamp', gmdate('Y-m-d\TH:i:s\Z'));
                $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
                update_post_meta($order_id, '_kolivri_voice_consent_ip', $ip);
            }
        }
    }

    /**
     * Trigger Mode 1 transactional order/COD verification when a WooCommerce order is placed.
     *
     * @param int $order_id
     */
    public function handle_new_order($order_id) {
        if (!function_exists('get_option') || !get_option('kolivri_woo_enabled', 0)) {
            return;
        }
        if (!function_exists('wc_get_order')) {
            return;
        }
        $wc_order = wc_get_order($order_id);
        if (!$wc_order) {
            return;
        }

        $items = array();
        foreach ($wc_order->get_items() as $item) {
            $items[] = array(
                'name'  => $item->get_name(),
                'qty'   => $item->get_quantity(),
                'total' => (float) $item->get_total(),
            );
        }

        $lang = $wc_order->get_meta('wpml_language');
        if (empty($lang)) {
            $lang = get_option('kolivri_woo_default_language', 'he');
        }

        $order_data = array(
            'order_id'         => (string) $wc_order->get_id(),
            'customer_name'    => trim($wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name()),
            'phone'            => $wc_order->get_billing_phone(),
            'language'         => $lang,
            'items'            => $items,
            'total'            => (float) $wc_order->get_total(),
            'currency'         => $wc_order->get_currency(),
            'payment_method'   => $wc_order->get_payment_method(),
            'delivery_address' => trim($wc_order->get_shipping_address_1() . ' ' . $wc_order->get_shipping_city()),
        );

        $built = Kolivri_Woo_Engine::buildOrderVerificationPayload($order_data, array(
            'webhook_id'   => (string) get_option('kolivri_woo_order_webhook_id', 'wh_order_verify'),
            'secret'       => (string) get_option('kolivri_woo_webhook_secret', ''),
            'callback_url' => function_exists('rest_url') ? rest_url('kolivri-woo/v1/webhook') : '',
        ));

        if (!empty($built['allowed']) && function_exists('wp_remote_post')) {
            wp_remote_post($built['endpoint'], array(
                'timeout' => 10,
                'headers' => $built['headers'],
                'body'    => $built['raw_body'],
            ));
        }
    }

    /**
     * Trigger Mode 2 abandoned cart voice recovery (only when Section 30A consent === true and total >= threshold).
     *
     * @param array $cart_data
     * @return array
     */
    public function handle_abandoned_cart_trigger($cart_data) {
        $config = array(
            'webhook_id'     => function_exists('get_option') ? (string) get_option('kolivri_woo_cart_webhook_id', 'wh_cart_recovery') : 'wh_cart_recovery',
            'secret'         => function_exists('get_option') ? (string) get_option('kolivri_woo_webhook_secret', '') : '',
            'min_cart_total' => function_exists('get_option') ? (float) get_option('kolivri_woo_min_cart_total', Kolivri_Woo_Engine::DEFAULT_MIN_CART_TOTAL) : Kolivri_Woo_Engine::DEFAULT_MIN_CART_TOTAL,
            'coupon_percent' => function_exists('get_option') ? (int) get_option('kolivri_woo_coupon_percent', Kolivri_Woo_Engine::DEFAULT_COUPON_PERCENT) : Kolivri_Woo_Engine::DEFAULT_COUPON_PERCENT,
            'callback_url'   => function_exists('rest_url') ? rest_url('kolivri-woo/v1/webhook') : '',
        );

        $built = Kolivri_Woo_Engine::buildCartRecoveryPayload(is_array($cart_data) ? $cart_data : array(), $config);
        if (empty($built['allowed'])) {
            return $built;
        }

        // Provision the single-use coupon in WooCommerce if WC_Coupon is loaded.
        if (class_exists('WC_Coupon') && !empty($built['coupon']['code'])) {
            $coupon = new WC_Coupon();
            $coupon->set_code($built['coupon']['code']);
            $coupon->set_discount_type('percent');
            $coupon->set_amount($built['coupon']['discount_percent']);
            $coupon->set_individual_use(true);
            $coupon->set_usage_limit(1);
            $coupon->set_date_expires(time() + 86400);
            $coupon->save();
        }

        if (function_exists('wp_remote_post')) {
            wp_remote_post($built['endpoint'], array(
                'timeout' => 10,
                'headers' => $built['headers'],
                'body'    => $built['raw_body'],
            ));
        }

        return $built;
    }

    /**
     * Register the signed webhook callback endpoint `/wp-json/kolivri-woo/v1/webhook`.
     */
    public function register_rest_routes() {
        register_rest_route('kolivri-woo/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_webhook_callback'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Handle incoming signed call outcome webhook from Kolivri.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|array
     */
    public function handle_webhook_callback($request) {
        $raw_body  = method_exists($request, 'get_body') ? (string) $request->get_body() : '';
        $signature = method_exists($request, 'get_header') ? (string) $request->get_header('x-kolivri-signature') : '';
        $secret    = function_exists('get_option') ? (string) get_option('kolivri_woo_webhook_secret', '') : '';

        if (!Kolivri_Woo_Engine::verifyWebhookSignature($raw_body, $signature, $secret)) {
            if (class_exists('WP_REST_Response')) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'invalid_signature'), 401);
            }
            return array('ok' => false, 'error' => 'invalid_signature', 'status' => 401);
        }

        $event = json_decode($raw_body, true);
        if (!is_array($event)) {
            if (class_exists('WP_REST_Response')) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'invalid_json'), 400);
            }
            return array('ok' => false, 'error' => 'invalid_json', 'status' => 400);
        }

        $kpi_state = function_exists('get_option') ? get_option('kolivri_woo_kpi_metrics', array()) : array();
        if (!is_array($kpi_state)) {
            $kpi_state = array();
        }

        $result = Kolivri_Woo_Engine::applyCallOutcome($event, $kpi_state);

        if (!empty($result['applied'])) {
            if (function_exists('update_option')) {
                update_option('kolivri_woo_kpi_metrics', $kpi_state);
            }

            if (!empty($result['order_id']) && function_exists('wc_get_order')) {
                $wc_order = wc_get_order((int) $result['order_id']);
                if ($wc_order) {
                    $clean_status = preg_replace('/^wc-/', '', $result['new_status']);
                    $wc_order->update_status($clean_status, $result['order_note']);
                }
            }
        }

        if (class_exists('WP_REST_Response')) {
            $status_code = (!empty($result['applied']) || !empty($result['idempotent_duplicate'])) ? 200 : 422;
            return new WP_REST_Response($result, $status_code);
        }
        return $result;
    }

    /**
     * Render `[kolivri_woo_roi_preview]` shortcode.
     *
     * @param array|string $atts
     * @return string
     */
    public function shortcode_roi_preview($atts = array()) {
        $parsed = is_array($atts) ? $atts : array();
        if (function_exists('shortcode_atts')) {
            $parsed = shortcode_atts(array(
                'lang'            => '',
                'cod_orders'      => 180,
                'abandoned_carts' => 320,
                'avg_cart'        => 480,
            ), $parsed, 'kolivri_woo_roi_preview');
        }
        return Kolivri_Woo_Engine::renderRoiPreviewShortcode($parsed);
    }
}

Kolivri_Woo_Voice_Recovery::get_instance();
