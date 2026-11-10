<?php
/**
 * Core engine for Kolivri WooCommerce AI Phone Order/COD Verifier & Opt-In Cart Recovery.
 *
 * Pure, deterministic domain methods allow full standalone testing via `php tests/test-woo-voice-plugin.php`
 * without requiring a running WordPress/WooCommerce database, while WordPress hooks and REST endpoints
 * in `kolivri-woo-voice-recovery.php` delegate to this engine in production.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kolivri_Woo_Engine {

    const ENGINE_BASE_URL        = 'https://app.kolivri.co.il/api/v1/engine/trigger/';
    const CONSENT_FIELD_NAME     = 'kolivri_voice_cart_consent';
    const DEFAULT_MIN_CART_TOTAL = 300.0;
    const DEFAULT_COUPON_PERCENT = 5;
    const SIGNATURE_HEADER       = 'X-Kolivri-Signature';

    /**
     * All 8 languages supported by the Kolivri voice engine.
     */
    const SUPPORTED_LANGUAGES = array('he', 'en', 'ar', 'ru', 'fr', 'es', 'de', 'pt');

    /**
     * Localized transactional order verification script directives per language.
     */
    const ORDER_SCRIPT_DIRECTIVES = array(
        'he' => 'שיחה תפעולית לאימות הזמנה ומשלוח בלבד (ללא תוכן שיווקי). אמת שם לקוח, כתובת מסירה ומוכנות לתשלום במזומן לשליח.',
        'en' => 'Transactional order and delivery verification call only (no promotional content). Verify customer name, delivery address, and COD readiness.',
        'ar' => 'مكالمة تشغيلية لتأكيد الطلب والعنوان والدفع عند الاستلام فقط (بدون محتوى ترويجي).',
        'ru' => 'Транзакционный звонок только для подтверждения заказа, адреса доставки и оплаты при получении (без рекламы).',
        'fr' => 'Appel transactionnel uniquement pour confirmer la commande, l’adresse de livraison et le paiement à la livraison.',
        'es' => 'Llamada transaccional únicamente para verificar el pedido, la dirección de entrega y el pago contra reembolso.',
        'de' => 'Transaktionaler Anruf ausschließlich zur Bestätigung von Bestellung, Lieferadresse und Nachnahme.',
        'pt' => 'Chamada transacional apenas para confirmar o pedido, endereço de entrega e pagamento na entrega.',
    );

    /**
     * Localized opt-in abandoned cart recovery script directives per language.
     */
    const CART_SCRIPT_DIRECTIVES = array(
        'he' => 'שיחת שחזור עגלה בהסכמה מפורשת (סעיף 30א). הצע עזרה בהשלמת ההזמנה ושלח קופון חד-פעמי ב-SMS.',
        'en' => 'Explicit opt-in abandoned cart recovery call. Offer checkout assistance and send the single-use SMS coupon code.',
        'ar' => 'مكالمة استعادة سلة التسوق بموافقة مسبقة صريحة. قدم المساعدة لإتمام الطلب وأرسل قسيمة خصم لمرة واحدة عبر SMS.',
        'ru' => 'Звонок по возврату корзины с явного согласия клиента. Предложите помощь и отправьте одноразовый SMS-купон.',
        'fr' => 'Appel de récupération de panier avec consentement explicite. Proposez de l’aide et envoyez le coupon SMS à usage unique.',
        'es' => 'Llamada de recuperación de carrito con consentimiento explícito. Ofrezca ayuda y envíe el cupón SMS de un solo uso.',
        'de' => 'Warenkorb-Rückgewinnungsanruf mit ausdrücklicher Einwilligung. Hilfe anbieten und Einmal-SMS-Gutschein senden.',
        'pt' => 'Chamada de recuperação de carrinho com consentimento explícito. Ofereça ajuda e envie o cupom SMS de uso único.',
    );

    /**
     * Normalize a WordPress/WPML locale or ISO string into one of Kolivri's 8 supported language codes.
     *
     * @param string $language
     * @return string
     */
    public static function normalizeLanguage($language) {
        $raw = strtolower(trim((string) $language));
        if ('' === $raw) {
            return 'he';
        }
        $short = substr($raw, 0, 2);
        if ('iw' === $short) {
            $short = 'he';
        }
        return in_array($short, self::SUPPORTED_LANGUAGES, true) ? $short : 'he';
    }

    /**
     * Normalize an Israeli or international phone number to E.164 format.
     *
     * @param string $phone
     * @return string
     */
    public static function normalizePhone($phone) {
        $raw = trim((string) $phone);
        if ('' === $raw) {
            return '';
        }
        $has_plus = (0 === strpos($raw, '+'));
        $digits   = preg_replace('/\D+/', '', $raw);
        if ('' === $digits) {
            return '';
        }
        $local_il = '';
        if (0 === strpos($digits, '9720')) {
            $local_il = '0' . substr($digits, 4);
        } elseif (0 === strpos($digits, '972') && strlen($digits) >= 11 && strlen($digits) <= 12) {
            $local_il = '0' . substr($digits, 3);
        } elseif (0 === strpos($digits, '0')) {
            $local_il = $digits;
        }
        if ('' !== $local_il && preg_match('/^0(?:5\d{8}|[23489]\d{7}|7\d{8})$/', $local_il)) {
            return '+972' . substr($local_il, 1);
        }
        if ($has_plus && 0 !== strpos($digits, '972')) {
            $e164 = '+' . $digits;
            if (preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
                return $e164;
            }
        }
        return '';
    }

    /**
     * Build the full Kolivri trigger URL for a given webhook ID.
     *
     * @param string $webhook_id
     * @return string
     */
    public static function buildTriggerUrl($webhook_id) {
        $clean_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string) $webhook_id));
        if ('' === $clean_id) {
            $clean_id = 'default';
        }
        return self::ENGINE_BASE_URL . $clean_id;
    }

    /**
     * Generate an HMAC-SHA256 signature (`sha256=<hex>`) for a raw JSON payload.
     *
     * @param string $raw_body
     * @param string $secret
     * @return string
     */
    public static function signPayload($raw_body, $secret) {
        $secret = (string) $secret;
        if ('' === $secret) {
            return '';
        }
        return 'sha256=' . hash_hmac('sha256', (string) $raw_body, $secret);
    }

    /**
     * Verify an incoming or outbound `X-Kolivri-Signature` HMAC-SHA256 signature.
     *
     * @param string $raw_body
     * @param string $signature_header
     * @param string $secret
     * @return bool
     */
    public static function verifyWebhookSignature($raw_body, $signature_header, $secret) {
        $raw_body         = (string) $raw_body;
        $signature_header = trim((string) $signature_header);
        $secret           = (string) $secret;

        if ('' === $raw_body || '' === $signature_header || '' === $secret) {
            return false;
        }

        $provided_hex = $signature_header;
        if (0 === stripos($provided_hex, 'sha256=')) {
            $provided_hex = substr($provided_hex, 7);
        }
        $provided_hex = strtolower(trim($provided_hex));
        if (64 !== strlen($provided_hex) || !ctype_xdigit($provided_hex)) {
            return false;
        }

        $expected_hex = hash_hmac('sha256', $raw_body, $secret);
        return hash_equals($expected_hex, $provided_hex);
    }

    /**
     * Snake-case alias for WordPress callers.
     */
    public static function verify_webhook_signature($raw_body, $signature_header, $secret) {
        return self::verifyWebhookSignature($raw_body, $signature_header, $secret);
    }

    /**
     * Mode 1: Build a signed outbound payload for Transactional Order, Delivery & COD Phone Verification.
     * 100% legal operational/transactional call under Israeli Communications Law Section 30A (not promotional).
     *
     * @param array $order  {
     *     @type int|string $order_id         WooCommerce order ID.
     *     @type string     $customer_name    Full name of the buyer.
     *     @type string     $phone            Buyer phone number.
     *     @type string     $language         ISO language code (he, en, ar, ru, fr, es, de, pt).
     *     @type array      $items            List of order items [['name' => ..., 'qty' => ..., 'total' => ...]].
     *     @type float      $total            Order total amount.
     *     @type string     $currency         Currency code (default ILS).
     *     @type string     $payment_method   e.g. 'cod', 'bacs', 'credit_card'.
     *     @type string     $delivery_address Full shipping/delivery address.
     * }
     * @param array $config {
     *     @type string $webhook_id           Kolivri engine webhook ID.
     *     @type string $secret               Shared HMAC-SHA256 webhook secret.
     *     @type string $callback_url         Signed callback receiver URL on the WooCommerce site.
     * }
     * @return array
     */
    public static function buildOrderVerificationPayload(array $order, array $config = array()) {
        $order_id = isset($order['order_id']) ? (string) $order['order_id'] : '';
        $phone    = self::normalizePhone(isset($order['phone']) ? $order['phone'] : '');

        if ('' === $order_id || '' === $phone) {
            return array(
                'allowed' => false,
                'reason'  => 'missing_order_id_or_phone',
            );
        }

        $lang           = self::normalizeLanguage(isset($order['language']) ? $order['language'] : 'he');
        $webhook_id     = isset($config['webhook_id']) ? (string) $config['webhook_id'] : 'wh_order_verify';
        $secret         = isset($config['secret']) ? (string) $config['secret'] : 'kolivri_default_secret';
        $callback_url   = isset($config['callback_url']) ? (string) $config['callback_url'] : 'https://kolivri.com/wp-json/kolivri-woo/v1/webhook';
        $payment_method = isset($order['payment_method']) ? strtolower(trim((string) $order['payment_method'])) : 'cod';
        $is_cod         = ('cod' === $payment_method || !empty($order['is_cod']));

        $normalized_items = array();
        $raw_items        = isset($order['items']) && is_array($order['items']) ? $order['items'] : array();
        foreach ($raw_items as $item) {
            if (is_array($item)) {
                $normalized_items[] = array(
                    'name'  => isset($item['name']) ? trim((string) $item['name']) : 'Item',
                    'qty'   => isset($item['qty']) ? max(1, (int) $item['qty']) : 1,
                    'total' => isset($item['total']) ? round((float) $item['total'], 2) : 0.0,
                );
            } elseif (is_string($item)) {
                $normalized_items[] = array(
                    'name'  => trim($item),
                    'qty'   => 1,
                    'total' => 0.0,
                );
            }
        }

        $payload = array(
            'event'            => 'woocommerce.order.verify',
            'mode'             => 'order_verification',
            'legal_basis'      => 'transactional_order_verification_sec30a_exempt',
            'promotional'      => false,
            'language'         => $lang,
            'direction'        => in_array($lang, array('he', 'ar'), true) ? 'rtl' : 'ltr',
            'customer'         => array(
                'name'  => isset($order['customer_name']) ? trim((string) $order['customer_name']) : '',
                'phone' => $phone,
            ),
            'order'            => array(
                'order_id'         => $order_id,
                'total'            => isset($order['total']) ? round((float) $order['total'], 2) : 0.0,
                'currency'         => isset($order['currency']) ? strtoupper(trim((string) $order['currency'])) : 'ILS',
                'payment_method'   => $payment_method,
                'is_cod'           => $is_cod,
                'items'            => $normalized_items,
                'delivery_address' => isset($order['delivery_address']) ? trim((string) $order['delivery_address']) : '',
            ),
            'agent_directives' => array(
                'script_prompt'   => self::ORDER_SCRIPT_DIRECTIVES[$lang],
                'allowed_actions' => array('confirmed', 'rescheduled', 'cancelled'),
                'forbid_upsell'   => true,
            ),
            'callback_url'     => $callback_url,
            'timestamp'        => isset($config['timestamp']) ? (int) $config['timestamp'] : time(),
        );

        $endpoint  = self::buildTriggerUrl($webhook_id);
        $raw_body  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = self::signPayload($raw_body, $secret);

        return array(
            'allowed'   => true,
            'mode'      => 'order_verification',
            'endpoint'  => $endpoint,
            'payload'   => $payload,
            'raw_body'  => $raw_body,
            'signature' => $signature,
            'headers'   => array(
                'Content-Type'        => 'application/json; charset=utf-8',
                self::SIGNATURE_HEADER => $signature,
            ),
        );
    }

    /**
     * Snake-case alias for WordPress callers.
     */
    public static function build_order_verification_payload(array $order, array $config = array()) {
        return self::buildOrderVerificationPayload($order, $config);
    }

    /**
     * Generate a deterministic or unique single-use SMS coupon code for an opted-in abandoned cart.
     *
     * @param string $cart_id
     * @param int    $discount_percent
     * @return array
     */
    public static function generateSingleUseCoupon($cart_id, $discount_percent = self::DEFAULT_COUPON_PERCENT) {
        $discount = max(1, min(50, (int) $discount_percent));
        $hash     = strtoupper(substr(hash('sha256', 'kolivri_coupon:' . (string) $cart_id), 0, 6));
        $code     = sprintf('KLV%d-%s', $discount, $hash);

        return array(
            'code'             => $code,
            'discount_percent' => $discount,
            'discount_type'    => 'percent',
            'single_use'       => true,
            'usage_limit'      => 1,
            'expires_in_hours' => 24,
            'delivery_channel' => 'sms_during_voice_call',
        );
    }

    /**
     * Render the Israeli Spam Law (Section 30A) explicit checkout consent checkbox HTML.
     *
     * @param string $language
     * @param bool   $checked
     * @return string
     */
    public static function renderConsentCheckboxHtml($language = 'he', $checked = false) {
        $lang = self::normalizeLanguage($language);
        $labels = array(
            'he' => 'אני מאשר/ת קבלת שיחה קולית אוטומטית וקופון חד-פעמי ב-SMS לסיוע בהשלמת ההזמנה במידה והעגלה תינטש (בהתאם לסעיף 30א לחוק התקשורת).',
            'en' => 'I explicitly consent to receive an automated voice assistance call and a single-use SMS coupon if I do not complete my checkout (Israeli Communications Law Sec. 30A).',
            'ar' => 'أوافق صراحةً على تلقي مكالمة صوتية آلية وقسيمة SMS لمرة واحدة للمساعدة في إتمام الطلب في حال ترك السلة (وفقاً للمادة 30أ من قانون الاتصالات).',
            'ru' => 'Я даю явное согласие на автоматический голосовой звонок и SMS с одноразовым купоном для помощи в оформлении заказа (Закон о связи Израиля, ст. 30А).',
        );
        $label_text = isset($labels[$lang]) ? $labels[$lang] : $labels['en'];
        $dir        = in_array($lang, array('he', 'ar'), true) ? 'rtl' : 'ltr';
        $checked_attr = $checked ? ' checked="checked"' : '';

        return sprintf(
            '<p class="form-row kolivri-voice-consent-row" dir="%s"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="%s"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="%s" id="%s" value="1"%s /> <span>%s</span></label></p>',
            htmlspecialchars($dir, ENT_QUOTES, 'UTF-8'),
            self::CONSENT_FIELD_NAME,
            self::CONSENT_FIELD_NAME,
            self::CONSENT_FIELD_NAME,
            $checked_attr,
            htmlspecialchars($label_text, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Mode 2: Build a signed outbound payload for Israeli Spam-Law Compliant Abandoned Cart Voice Recovery.
     *
     * Enforces TWO hard gates before scheduling any call:
     * 1. Explicit boolean opt-in consent (`$cart['consent'] === true`) under Israeli Spam Law Section 30A.
     * 2. Minimum cart total threshold (default 300 ILS).
     *
     * @param array $cart {
     *     @type string $cart_id
     *     @type bool   $consent           MUST be strictly boolean true.
     *     @type string $consent_timestamp Optional ISO-8601 consent timestamp.
     *     @type string $consent_ip        Optional customer IP at time of consent.
     *     @type string $customer_name
     *     @type string $phone
     *     @type string $language
     *     @type float  $total
     *     @type string $currency
     *     @type array  $items
     *     @type string $checkout_url
     * }
     * @param array $config {
     *     @type string $webhook_id
     *     @type string $secret
     *     @type float  $min_cart_total    Default 300.0 ILS.
     *     @type int    $coupon_percent    Default 5%.
     *     @type string $callback_url
     * }
     * @return array
     */
    public static function buildCartRecoveryPayload(array $cart, array $config = array()) {
        // Gate 1: Strict Israeli Spam Law (Section 30A) explicit opt-in consent enforcement.
        if (!array_key_exists('consent', $cart) || true !== $cart['consent']) {
            return array(
                'allowed' => false,
                'mode'    => 'abandoned_cart_recovery',
                'reason'  => 'missing_explicit_spam_law_consent',
                'law_ref' => 'IL-Communications-Law-Sec-30A',
                'message' => 'Abandoned cart voice recovery requires explicit boolean opt-in consent (kolivri_voice_cart_consent === true).',
            );
        }

        // Gate 2: Minimum cart value threshold enforcement (default 300 ILS).
        $min_threshold = isset($config['min_cart_total']) ? (float) $config['min_cart_total'] : self::DEFAULT_MIN_CART_TOTAL;
        $cart_total    = isset($cart['total']) ? (float) $cart['total'] : 0.0;
        if ($cart_total < $min_threshold) {
            return array(
                'allowed'        => false,
                'mode'           => 'abandoned_cart_recovery',
                'reason'         => 'below_minimum_cart_threshold',
                'min_cart_total' => round($min_threshold, 2),
                'cart_total'     => round($cart_total, 2),
                'message'        => sprintf('Cart total (%.2f ILS) is below the minimum threshold (%.2f ILS).', $cart_total, $min_threshold),
            );
        }

        $cart_id = isset($cart['cart_id']) ? trim((string) $cart['cart_id']) : '';
        $phone   = self::normalizePhone(isset($cart['phone']) ? $cart['phone'] : '');
        if ('' === $cart_id || '' === $phone) {
            return array(
                'allowed' => false,
                'mode'    => 'abandoned_cart_recovery',
                'reason'  => 'missing_cart_id_or_phone',
            );
        }

        $lang           = self::normalizeLanguage(isset($cart['language']) ? $cart['language'] : 'he');
        $webhook_id     = isset($config['webhook_id']) ? (string) $config['webhook_id'] : 'wh_cart_recovery';
        $secret         = isset($config['secret']) ? (string) $config['secret'] : 'kolivri_default_secret';
        $coupon_percent = isset($config['coupon_percent']) ? (int) $config['coupon_percent'] : self::DEFAULT_COUPON_PERCENT;
        $callback_url   = isset($config['callback_url']) ? (string) $config['callback_url'] : 'https://kolivri.com/wp-json/kolivri-woo/v1/webhook';

        $coupon = self::generateSingleUseCoupon($cart_id, $coupon_percent);

        $payload = array(
            'event'            => 'woocommerce.cart.abandoned_opt_in',
            'mode'             => 'abandoned_cart_recovery',
            'legal_basis'      => 'explicit_opt_in_sec30a_compliant',
            'promotional'      => true,
            'language'         => $lang,
            'direction'        => in_array($lang, array('he', 'ar'), true) ? 'rtl' : 'ltr',
            'consent_audit'    => array(
                'field'     => self::CONSENT_FIELD_NAME,
                'consented' => true,
                'timestamp' => isset($cart['consent_timestamp']) ? (string) $cart['consent_timestamp'] : gmdate('Y-m-d\TH:i:s\Z'),
                'ip'        => isset($cart['consent_ip']) ? (string) $cart['consent_ip'] : '',
                'law_ref'   => 'IL-Communications-Law-Sec-30A',
            ),
            'customer'         => array(
                'name'  => isset($cart['customer_name']) ? trim((string) $cart['customer_name']) : '',
                'phone' => $phone,
            ),
            'cart'             => array(
                'cart_id'      => $cart_id,
                'total'        => round($cart_total, 2),
                'currency'     => isset($cart['currency']) ? strtoupper(trim((string) $cart['currency'])) : 'ILS',
                'items'        => isset($cart['items']) && is_array($cart['items']) ? $cart['items'] : array(),
                'checkout_url' => isset($cart['checkout_url']) ? (string) $cart['checkout_url'] : '',
            ),
            'incentive_coupon' => $coupon,
            'agent_directives' => array(
                'script_prompt'   => self::CART_SCRIPT_DIRECTIVES[$lang],
                'allowed_actions' => array('recovered', 'rescheduled', 'cancelled'),
                'sms_coupon_code' => $coupon['code'],
            ),
            'callback_url'     => $callback_url,
            'timestamp'        => isset($config['timestamp']) ? (int) $config['timestamp'] : time(),
        );

        $endpoint  = self::buildTriggerUrl($webhook_id);
        $raw_body  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = self::signPayload($raw_body, $secret);

        return array(
            'allowed'   => true,
            'mode'      => 'abandoned_cart_recovery',
            'endpoint'  => $endpoint,
            'coupon'    => $coupon,
            'payload'   => $payload,
            'raw_body'  => $raw_body,
            'signature' => $signature,
            'headers'   => array(
                'Content-Type'        => 'application/json; charset=utf-8',
                self::SIGNATURE_HEADER => $signature,
            ),
        );
    }

    /**
     * Snake-case alias for WordPress callers.
     */
    public static function build_cart_recovery_payload(array $cart, array $config = array()) {
        return self::buildCartRecoveryPayload($cart, $config);
    }

    /**
     * Default KPI state structure stored in `kolivri_woo_kpi_metrics`.
     *
     * @return array
     */
    public static function defaultKpiState() {
        return array(
            'confirmed_orders_count'       => 0,
            'rescheduled_orders_count'     => 0,
            'cancelled_orders_count'       => 0,
            'recovered_carts_count'        => 0,
            'verified_cod_revenue_ils'     => 0.0,
            'recovered_revenue_ils'        => 0.0,
            'prevented_return_losses_ils'  => 0.0,
            'processed_call_ids'           => array(),
            'history'                      => array(),
        );
    }

    /**
     * Process a verified call completion webhook from Kolivri and transition the order/cart state + KPI counters.
     *
     * Supported outcomes:
     * - `confirmed`:   Order/COD confirmed by customer -> WooCommerce `wc-processing`
     * - `rescheduled`: Customer requested a new delivery window -> WooCommerce `wc-on-hold`
     * - `cancelled`:   Customer cancelled / bogus COD order caught before dispatch -> WooCommerce `wc-cancelled`
     * - `recovered`:   Opted-in abandoned cart converted after voice call + SMS coupon -> `cart-recovered`
     *
     * @param array $event     Decoded JSON webhook body from Kolivri.
     * @param array $kpi_state Reference to KPI metrics array (updated in place and returned).
     * @return array
     */
    public static function applyCallOutcome(array $event, array &$kpi_state = array()) {
        if (empty($kpi_state)) {
            $kpi_state = self::defaultKpiState();
        } else {
            $kpi_state = array_merge(self::defaultKpiState(), $kpi_state);
        }

        $outcome = isset($event['outcome']) ? strtolower(trim((string) $event['outcome'])) : '';
        $valid_outcomes = array('confirmed', 'rescheduled', 'cancelled', 'recovered');
        if (!in_array($outcome, $valid_outcomes, true)) {
            return array(
                'applied' => false,
                'reason'  => 'unsupported_call_outcome',
                'outcome' => $outcome,
                'kpi'     => $kpi_state,
            );
        }

        $amount          = isset($event['amount']) ? round((float) $event['amount'], 2) : 0.0;
        $order_id        = isset($event['order_id']) ? (string) $event['order_id'] : '';
        $cart_id         = isset($event['cart_id']) ? (string) $event['cart_id'] : '';
        $call_id         = isset($event['call_id']) ? trim((string) $event['call_id']) : '';
        $shipping_cost   = isset($event['estimated_return_cost_ils']) ? round((float) $event['estimated_return_cost_ils'], 2) : 65.0;

        if ('' !== $call_id && in_array($call_id, $kpi_state['processed_call_ids'], true)) {
            return array(
                'applied'              => false,
                'idempotent_duplicate' => true,
                'reason'               => 'duplicate_call_id',
                'call_id'              => $call_id,
                'outcome'              => $outcome,
                'kpi'                  => $kpi_state,
            );
        }

        $new_status      = '';
        $note            = '';

        if ('confirmed' === $outcome) {
            $new_status = 'wc-processing';
            $kpi_state['confirmed_orders_count']   += 1;
            $kpi_state['verified_cod_revenue_ils']  = round($kpi_state['verified_cod_revenue_ils'] + $amount, 2);
            $note = sprintf('Kolivri AI Voice verified order #%s (Call ID: %s). Ready for dispatch.', $order_id, $call_id);
        } elseif ('rescheduled' === $outcome) {
            $new_status = 'wc-on-hold';
            $window     = isset($event['delivery_window']) ? trim((string) $event['delivery_window']) : 'Customer requested callback window';
            $kpi_state['rescheduled_orders_count'] += 1;
            $note = sprintf('Kolivri AI Voice rescheduled delivery for order #%s: %s.', $order_id, $window);
        } elseif ('cancelled' === $outcome) {
            $new_status = 'wc-cancelled';
            $kpi_state['cancelled_orders_count']      += 1;
            $kpi_state['prevented_return_losses_ils']  = round($kpi_state['prevented_return_losses_ils'] + $shipping_cost, 2);
            $note = sprintf('Kolivri AI Voice cancelled unconfirmed order #%s before courier dispatch (saved %.2f ILS return fee).', $order_id, $shipping_cost);
        } elseif ('recovered' === $outcome) {
            $new_status = 'cart-recovered';
            $kpi_state['recovered_carts_count'] += 1;
            $kpi_state['recovered_revenue_ils']  = round($kpi_state['recovered_revenue_ils'] + $amount, 2);
            $coupon_used = isset($event['coupon_code']) ? (string) $event['coupon_code'] : '';
            $note = sprintf('Kolivri AI Voice recovered abandoned cart %s (%.2f ILS, coupon %s).', $cart_id, $amount, $coupon_used);
        }

        if ('' !== $call_id) {
            $kpi_state['processed_call_ids'][] = $call_id;
            if (count($kpi_state['processed_call_ids']) > 500) {
                $kpi_state['processed_call_ids'] = array_slice($kpi_state['processed_call_ids'], -500);
            }
        }

        $kpi_state['history'][] = array(
            'call_id'    => $call_id,
            'outcome'    => $outcome,
            'new_status' => $new_status,
            'order_id'   => $order_id,
            'cart_id'    => $cart_id,
            'amount'     => $amount,
        );
        if (count($kpi_state['history']) > 100) {
            $kpi_state['history'] = array_slice($kpi_state['history'], -100);
        }

        $transition = array(
            'applied'    => true,
            'outcome'    => $outcome,
            'new_status' => $new_status,
            'order_id'   => $order_id,
            'cart_id'    => $cart_id,
            'call_id'    => $call_id,
            'amount'     => $amount,
            'order_note' => $note,
            'kpi'        => $kpi_state,
        );

        return $transition;
    }

    /**
     * Snake-case alias for WordPress callers.
     */
    public static function apply_call_outcome(array $event, array &$kpi_state = array()) {
        return self::applyCallOutcome($event, $kpi_state);
    }

    /**
     * Calculate monthly WooCommerce ROI metrics for verified COD orders and opt-in cart recovery.
     *
     * @param array $inputs {
     *     @type int   $monthly_cod_orders        Default 180.
     *     @type float $failed_cod_rate           Default 0.15 (15% unverified COD return rate).
     *     @type float $return_shipping_loss_ils  Default 65.0 ILS courier round-trip + restocking cost.
     *     @type int   $monthly_abandoned_carts   Default 320 carts >= 300 ILS.
     *     @type float $opt_in_rate               Default 0.42 (42% checkout opt-in rate).
     *     @type float $voice_recovery_rate       Default 0.24 (24% conversion on opted-in voice calls).
     *     @type float $avg_cart_value_ils        Default 480.0 ILS.
     * }
     * @return array
     */
    public static function calculateRoi(array $inputs = array()) {
        $cod_orders        = isset($inputs['monthly_cod_orders']) ? max(0, (int) $inputs['monthly_cod_orders']) : 180;
        $failed_cod_rate   = isset($inputs['failed_cod_rate']) ? max(0.0, min(1.0, (float) $inputs['failed_cod_rate'])) : 0.15;
        $return_loss_ils   = isset($inputs['return_shipping_loss_ils']) ? max(0.0, (float) $inputs['return_shipping_loss_ils']) : 65.0;
        $abandoned_carts   = isset($inputs['monthly_abandoned_carts']) ? max(0, (int) $inputs['monthly_abandoned_carts']) : 320;
        $opt_in_rate       = isset($inputs['opt_in_rate']) ? max(0.0, min(1.0, (float) $inputs['opt_in_rate'])) : 0.42;
        $recovery_rate     = isset($inputs['voice_recovery_rate']) ? max(0.0, min(1.0, (float) $inputs['voice_recovery_rate'])) : 0.24;
        $avg_cart_ils      = isset($inputs['avg_cart_value_ils']) ? max(0.0, (float) $inputs['avg_cart_value_ils']) : 480.0;

        $prevented_cod_returns = (int) round($cod_orders * $failed_cod_rate);
        $cod_savings_ils       = round($prevented_cod_returns * $return_loss_ils, 2);

        $opted_in_carts        = (int) round($abandoned_carts * $opt_in_rate);
        $recovered_carts       = (int) round($opted_in_carts * $recovery_rate);
        $recovered_revenue_ils = round($recovered_carts * $avg_cart_ils, 2);

        $total_monthly_gain    = round($cod_savings_ils + $recovered_revenue_ils, 2);

        return array(
            'monthly_cod_orders'      => $cod_orders,
            'prevented_cod_returns'   => $prevented_cod_returns,
            'cod_savings_ils'         => $cod_savings_ils,
            'monthly_abandoned_carts' => $abandoned_carts,
            'opted_in_carts'          => $opted_in_carts,
            'recovered_carts'         => $recovered_carts,
            'avg_cart_value_ils'      => $avg_cart_ils,
            'recovered_revenue_ils'   => $recovered_revenue_ils,
            'total_monthly_gain_ils'  => $total_monthly_gain,
        );
    }

    /**
     * Render the interactive `[kolivri_woo_roi_preview]` showcase widget for `kolivri.com`.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML markup.
     */
    public static function renderRoiPreviewShortcode(array $atts = array()) {
        $is_en = (isset($atts['lang']) && strtolower((string) $atts['lang']) === 'en')
            || (empty($atts['lang']) && defined('ICL_LANGUAGE_CODE') && strtolower((string) ICL_LANGUAGE_CODE) === 'en')
            || (empty($atts['lang']) && !empty($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/he/') === false && strpos((string) $_SERVER['REQUEST_URI'], '/he') !== 0);
        $lang = $is_en ? 'en' : self::normalizeLanguage(isset($atts['lang']) ? $atts['lang'] : 'he');
        $roi  = self::calculateRoi(array(
            'monthly_cod_orders'      => isset($atts['cod_orders']) ? (int) $atts['cod_orders'] : 180,
            'monthly_abandoned_carts' => isset($atts['abandoned_carts']) ? (int) $atts['abandoned_carts'] : 320,
            'avg_cart_value_ils'      => isset($atts['avg_cart']) ? (float) $atts['avg_cart'] : 480.0,
        ));

        $dir = in_array($lang, array('he', 'ar'), true) ? 'rtl' : 'ltr';
        $lab_prefix = $is_en ? '' : '/he';
        $lab_tools = array(
            'phone-grader'       => array('path' => '/phone-grader/',       'he' => '🔥 מדד המענה',          'en' => '🔥 Phone Grader'),
            'amnesia-test'       => array('path' => '/amnesia-test/',       'he' => '🧠 מבחן האמנזיה',       'en' => '🧠 Amnesia Test'),
            'invoice-recovery'   => array('path' => '/invoice-recovery/',   'he' => '💰 גביית חשבוניות',     'en' => '💰 Invoice Recovery'),
            'ghost-site'         => array('path' => '/ghost-site/',         'he' => '🌐 מיני-סייט חינם',     'en' => '🌐 Ghost Mini-Site'),
            'call-for-me'        => array('path' => '/call-for-me/',        'he' => '🤖 הבוט שיתקשר במקומך', 'en' => '🤖 Call For Me'),
            'dispatch'           => array('path' => '/dispatch/',           'he' => '⚡ זירת חיוג מקבילי',   'en' => '⚡ FOMO Dispatch'),
            'woo-voice-recovery' => array('path' => '/woo-voice-recovery/', 'he' => '🛒 WooCommerce קולי',   'en' => '🛒 Woo Voice ROI'),
            'mcp'                => array('path' => '/mcp/',                'he' => '🛠️ MCP למפתחים',        'en' => '🛠️ Developer MCP'),
        );
        $total_fmt     = number_format($roi['total_monthly_gain_ils'], 0);
        $recovered_fmt = number_format($roi['recovered_revenue_ils'], 0);
        $cod_fmt       = number_format($roi['cod_savings_ils'], 0);

        ob_start();
        ?>
        <section class="kolivri-woo-roi-widget" dir="<?php echo htmlspecialchars($dir, ENT_QUOTES, 'UTF-8'); ?>" lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>" data-widget="kolivri-woo-roi-preview" style="background:#0B1220;color:#F8FAFC;border:1px solid #1E293B;border-radius:16px;padding:clamp(16px,4vw,28px);max-width:880px;margin:24px auto;font-family:'Rubik',system-ui,sans-serif;box-sizing:border-box;">
            <style>
                .kolivri-woo-roi-widget input[type="number"] { min-height: 44px; box-sizing: border-box; }
                .kolivri-woo-roi-widget .klv-cta-btn { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; }
                .kolivri-woo-roi-widget .klv-lab-switcher {
                    position: sticky;
                    top: 8px;
                    z-index: 20;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    padding: 8px 10px;
                    margin: 0 0 20px;
                    background: rgba(11, 18, 32, 0.92);
                    backdrop-filter: blur(10px);
                    border: 1px solid rgba(56, 189, 248, 0.28);
                    border-radius: 14px;
                    white-space: nowrap;
                }
                .kolivri-woo-roi-widget .klv-lab-switcher a {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 44px;
                    padding: 8px 14px;
                    border-radius: 999px;
                    font-size: 13px;
                    font-weight: 700;
                    color: #CBD5E1 !important;
                    background: rgba(30, 41, 59, 0.75);
                    border: 1px solid rgba(148, 163, 184, 0.22);
                    text-decoration: none !important;
                    flex-shrink: 0;
                }
                .kolivri-woo-roi-widget .klv-lab-switcher a.klv-lab-home {
                    background: rgba(56, 189, 248, 0.14);
                    color: #38BDF8 !important;
                    border-color: rgba(56, 189, 248, 0.4);
                }
                .kolivri-woo-roi-widget .klv-lab-switcher a[aria-current="page"] {
                    background: linear-gradient(90deg, #FB8123 0%, #EA580C 100%);
                    color: #FFFFFF !important;
                    border-color: #FB8123;
                }
                @media (max-width: 640px) {
                    .kolivri-woo-roi-widget h3 { font-size: 19px !important; }
                    .kolivri-woo-roi-widget .klv-cta-btn { width: 100%; text-align: center; }
                }
            </style>
            <nav class="klv-lab-switcher" aria-label="Kolivri AI Voice Lab Navigation">
                <a href="<?php echo htmlspecialchars($lab_prefix . '/#ai-voice-lab', ENT_QUOTES, 'UTF-8'); ?>" class="klv-lab-home"><?php echo htmlspecialchars($is_en ? '← Home' : '← חזרה לדף הבית', ENT_QUOTES, 'UTF-8'); ?></a>
                <?php foreach ($lab_tools as $slug => $meta) : ?>
                    <a href="<?php echo htmlspecialchars($lab_prefix . $meta['path'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo 'woo-voice-recovery' === $slug ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($is_en ? $meta['en'] : $meta['he'], ENT_QUOTES, 'UTF-8'); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($is_en) : ?>
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:20px;">
                <div>
                    <span style="display:inline-block;background:#1E3A8A;color:#93C5FD;font-size:12px;font-weight:600;padding:4px 10px;border-radius:999px;margin-bottom:8px;">
                        WooCommerce × Kolivri Voice AI · Explicit Opt-In &amp; Transactional Verification
                    </span>
                    <h3 style="margin:0;font-size:24px;color:#FFFFFF;">Kolivri AI Voice Order Verifier & Cart Recovery for WooCommerce</h3>
                </div>
                <div style="text-align:center;background:#0F172A;border:1px solid #334155;border-radius:12px;padding:12px 18px;">
                    <div style="font-size:12px;color:#94A3B8;">Monthly Saved & Recovered Revenue</div>
                    <div class="kolivri-roi-total" style="font-size:28px;font-weight:700;color:#38BDF8;">₪<?php echo $total_fmt; ?></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:20px;">
                <div style="background:#111827;border:1px solid #1F2937;border-radius:12px;padding:16px;">
                    <strong style="color:#60A5FA;display:block;margin-bottom:6px;">Mode 1: Order &amp; COD Delivery Verification (100% Transactional Call)</strong>
                    <p style="font-size:13px;color:#CBD5E1;margin:0 0 10px;">Verify delivery address and Cash-on-Delivery (COD) readiness in 8 languages before courier dispatch.</p>
                    <label style="font-size:12px;color:#94A3B8;display:block;">Monthly COD / Delivery Orders:
                        <input type="number" class="klv-input-cod" value="<?php echo (int) $roi['monthly_cod_orders']; ?>" min="0" max="10000" style="width:100%;margin-top:4px;padding:6px 10px;border-radius:8px;border:1px solid #334155;background:#0B1220;color:#fff;" />
                    </label>
                    <div style="margin-top:10px;font-size:13px;color:#34D399;">
                        Saved from prevented returns (<span class="klv-prevented-cod"><?php echo (int) $roi['prevented_cod_returns']; ?></span> shipments): <strong>₪<span class="klv-cod-savings"><?php echo $cod_fmt; ?></span></strong>
                    </div>
                </div>

                <div style="background:#111827;border:1px solid #1F2937;border-radius:12px;padding:16px;">
                    <strong style="color:#A78BFA;display:block;margin-bottom:6px;">Mode 2: Opt-In Abandoned Cart Recovery + Single-Use SMS Coupon</strong>
                    <p style="font-size:13px;color:#CBD5E1;margin:0 0 10px;">Automated voice recovery strictly for shoppers who checked explicit opt-in consent on carts over ₪300.</p>
                    <label style="font-size:12px;color:#94A3B8;display:block;">Monthly Abandoned Carts (₪300+):
                        <input type="number" class="klv-input-carts" value="<?php echo (int) $roi['monthly_abandoned_carts']; ?>" min="0" max="20000" style="width:100%;margin-top:4px;padding:6px 10px;border-radius:8px;border:1px solid #334155;background:#0B1220;color:#fff;" />
                    </label>
                    <label style="font-size:12px;color:#94A3B8;display:block;margin-top:8px;">Average Cart Value (₪):
                        <input type="number" class="klv-input-aov" value="<?php echo (int) $roi['avg_cart_value_ils']; ?>" min="300" max="10000" style="width:100%;margin-top:4px;padding:6px 10px;border-radius:8px;border:1px solid #334155;background:#0B1220;color:#fff;" />
                    </label>
                    <div style="margin-top:10px;font-size:13px;color:#38BDF8;">
                        Recovered Carts (<span class="klv-recovered-count"><?php echo (int) $roi['recovered_carts']; ?></span> orders): <strong>₪<span class="klv-recovered-rev"><?php echo $recovered_fmt; ?></span></strong>
                    </div>
                </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;padding-top:12px;border-top:1px solid #1E293B;font-size:12px;color:#94A3B8;">
                <span>Supported Languages: HE · EN · AR · RU · FR · ES · DE · PT | Webhook Security: HMAC-SHA256</span>
                <a href="https://wa.me/972547875670" class="klv-cta-btn" style="background:#2563EB;color:#FFFFFF;text-decoration:none;padding:8px 16px;border-radius:8px;font-weight:600;">Book a WooCommerce Voice Demo →</a>
            </div>
            <?php else : ?>
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:20px;">
                <div>
                    <span style="display:inline-block;background:#1E3A8A;color:#93C5FD;font-size:12px;font-weight:600;padding:4px 10px;border-radius:999px;margin-bottom:8px;">
                        WooCommerce × Kolivri Voice AI · תואם סעיף 30א (חוק הספאם)
                    </span>
                    <h3 style="margin:0;font-size:24px;color:#FFFFFF;">מחשבון רווח לחנות WooCommerce: אימות הזמנות/גוביינא ושחזור עגלות</h3>
                </div>
                <div style="text-align:center;background:#0F172A;border:1px solid #334155;border-radius:12px;padding:12px 18px;">
                    <div style="font-size:12px;color:#94A3B8;">תוספת הכנסה וחיסכון חודשי משוער</div>
                    <div class="kolivri-roi-total" style="font-size:28px;font-weight:700;color:#38BDF8;">₪<?php echo $total_fmt; ?></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:20px;">
                <div style="background:#111827;border:1px solid #1F2937;border-radius:12px;padding:16px;">
                    <strong style="color:#60A5FA;display:block;margin-bottom:6px;">מצב 1: אימות הזמנה ומשלוח (100% שיחה תפעולית)</strong>
                    <p style="font-size:13px;color:#CBD5E1;margin:0 0 10px;">אימות כתובת וגוביינא (COD) ב-8 שפות לפני יציאת השליח.</p>
                    <label style="font-size:12px;color:#94A3B8;display:block;">הזמנות משלוח/גוביינא בחודש:
                        <input type="number" class="klv-input-cod" value="<?php echo (int) $roi['monthly_cod_orders']; ?>" min="0" max="10000" style="width:100%;margin-top:4px;padding:6px 10px;border-radius:8px;border:1px solid #334155;background:#0B1220;color:#fff;" />
                    </label>
                    <div style="margin-top:10px;font-size:13px;color:#34D399;">
                        חיסכון מהחזרות כושלות (<span class="klv-prevented-cod"><?php echo (int) $roi['prevented_cod_returns']; ?></span> משלוחים): <strong>₪<span class="klv-cod-savings"><?php echo $cod_fmt; ?></span></strong>
                    </div>
                </div>

                <div style="background:#111827;border:1px solid #1F2937;border-radius:12px;padding:16px;">
                    <strong style="color:#A78BFA;display:block;margin-bottom:6px;">מצב 2: שחזור עגלות בהסכמה מפורשת + קופון SMS</strong>
                    <p style="font-size:13px;color:#CBD5E1;margin:0 0 10px;">חיוג אוטומטי רק ללקוחות שסימנו הסכמה מפורשת ועגלה מעל ₪300.</p>
                    <label style="font-size:12px;color:#94A3B8;display:block;">עגלות נטושות בחודש (מעל ₪300):
                        <input type="number" class="klv-input-carts" value="<?php echo (int) $roi['monthly_abandoned_carts']; ?>" min="0" max="20000" style="width:100%;margin-top:4px;padding:6px 10px;border-radius:8px;border:1px solid #334155;background:#0B1220;color:#fff;" />
                    </label>
                    <label style="font-size:12px;color:#94A3B8;display:block;margin-top:8px;">שווי עגלה ממוצע (₪):
                        <input type="number" class="klv-input-aov" value="<?php echo (int) $roi['avg_cart_value_ils']; ?>" min="300" max="10000" style="width:100%;margin-top:4px;padding:6px 10px;border-radius:8px;border:1px solid #334155;background:#0B1220;color:#fff;" />
                    </label>
                    <div style="margin-top:10px;font-size:13px;color:#38BDF8;">
                        עגלות משוחזרות (<span class="klv-recovered-count"><?php echo (int) $roi['recovered_carts']; ?></span> הזמנות): <strong>₪<span class="klv-recovered-rev"><?php echo $recovered_fmt; ?></span></strong>
                    </div>
                </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;padding-top:12px;border-top:1px solid #1E293B;font-size:12px;color:#94A3B8;">
                <span>שפות נתמכות: HE · EN · AR · RU · FR · ES · DE · PT | אבטחת Webhook: HMAC-SHA256</span>
                <a href="https://wa.me/972547875670" class="klv-cta-btn" style="background:#2563EB;color:#FFFFFF;text-decoration:none;padding:8px 16px;border-radius:8px;font-weight:600;">תיאום הדגמה לחנות WooCommerce ←</a>
            </div>
            <?php endif; ?>
            <script>
            (function(){
                var root = document.currentScript ? document.currentScript.parentElement : document.querySelector('[data-widget="kolivri-woo-roi-preview"]');
                if (!root) return;
                var codInput = root.querySelector('.klv-input-cod');
                var cartsInput = root.querySelector('.klv-input-carts');
                var aovInput = root.querySelector('.klv-input-aov');
                function recalc() {
                    var cod = Math.max(0, parseInt(codInput.value || '0', 10));
                    var carts = Math.max(0, parseInt(cartsInput.value || '0', 10));
                    var aov = Math.max(0, parseFloat(aovInput.value || '0'));
                    var prevented = Math.round(cod * 0.15);
                    var codSavings = Math.round(prevented * 65);
                    var optedIn = Math.round(carts * 0.42);
                    var recovered = Math.round(optedIn * 0.24);
                    var recoveredRev = Math.round(recovered * aov);
                    var total = codSavings + recoveredRev;
                    root.querySelector('.klv-prevented-cod').textContent = prevented.toLocaleString();
                    root.querySelector('.klv-cod-savings').textContent = codSavings.toLocaleString();
                    root.querySelector('.klv-recovered-count').textContent = recovered.toLocaleString();
                    root.querySelector('.klv-recovered-rev').textContent = recoveredRev.toLocaleString();
                    root.querySelector('.kolivri-roi-total').textContent = '₪' + total.toLocaleString();
                }
                [codInput, cartsInput, aovInput].forEach(function(el){
                    if (el) el.addEventListener('input', recalc);
                });
            })();
            </script>
        </section>
        <?php
        return trim((string) ob_get_clean());
    }
}
