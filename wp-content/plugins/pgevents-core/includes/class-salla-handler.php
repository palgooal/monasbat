<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Mon_Salla_Handler
 * النسخة المتوافقة مع الهيكلية الشاملة للباقات
 */
class Mon_Salla_Handler
{
    private $webhook_secret;
    private $client_id;
    private $client_secret;

    public function __construct()
    {
        // كل المفاتيح تُقرأ من لوحة التحكم (DB) أو wp-config كـ fallback
        $this->webhook_secret = defined('PGE_SALLA_WEBHOOK_SECRET')
            ? PGE_SALLA_WEBHOOK_SECRET
            : (string) get_option('pge_salla_webhook_secret', '');

        $this->client_id = defined('PGE_SALLA_CLIENT_ID')
            ? PGE_SALLA_CLIENT_ID
            : (string) get_option('pge_salla_client_id', '');

        $this->client_secret = defined('PGE_SALLA_CLIENT_SECRET')
            ? PGE_SALLA_CLIENT_SECRET
            : (string) get_option('pge_salla_client_secret', '');

        add_action('rest_api_init', [$this, 'register_webhook_route']);
        $this->init_admin_hooks();
    }

    public function register_webhook_route()
    {
        register_rest_route('mon/v1', '/salla-callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_salla_notification'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_salla_notification($request)
    {
        $payload   = $request->get_body();
        $signature = $request->get_header('x_salla_signature');

        if (!$this->is_valid_signature($payload, $signature)) {
            return new WP_REST_Response(['error' => 'Invalid signature'], 401);
        }

        $data        = json_decode($payload, true);
        $event       = $data['event']    ?? '';
        $event_data  = $data['data']     ?? [];
        $merchant_id = (int) ($data['merchant'] ?? 0);

        // ── S1: توجيه الحدث حسب نوعه ──────────────────────────────────────
        switch ($event) {

            // أحداث الطلبات — البيانات مباشرة في data
            case 'order.created':
            case 'order.updated':
            case 'order.payment.updated':
                return $this->handle_order_event($event_data);

            // تحديث حالة الطلب — البيانات داخل data.order
            case 'order.status.updated':
                return $this->handle_order_event($event_data['order'] ?? []);

            // تجديد التوكن (كل 14 يوم)
            case 'app.store.authorize':
                return $this->handle_app_authorize($event_data, $merchant_id);

            // تثبيت / إلغاء / تحديث التطبيق
            case 'app.installed':
                return $this->handle_app_installed($event_data, $merchant_id);

            case 'app.store.uninstall':
            case 'app.uninstalled':
                return $this->handle_app_uninstalled($merchant_id);

            case 'app.updated':
                return $this->handle_app_updated($event_data, $merchant_id);

            default:
                error_log("ℹ️ Salla Webhook: حدث غير معالج — " . $event);
                return new WP_REST_Response(['status' => 'ignored', 'event' => $event], 200);
        }
    }

    // ── معالج أحداث الطلبات (order.*) ─────────────────────────────────────
    private function handle_order_event($order_data)
    {
        if (empty($order_data)) {
            return new WP_REST_Response(['message' => 'No Data'], 200);
        }

        $status_slug = sanitize_key((string) ($order_data['status']['slug'] ?? ''));

        $activation_statuses   = ['completed', 'delivered'];
        $deactivation_statuses = ['canceled', 'cancelled', 'refunded', 'returned'];

        if (in_array($status_slug, $activation_statuses, true)) {
            $this->process_user_and_plan($order_data);
            return new WP_REST_Response(['status' => 'success', 'message' => 'Package Activated'], 200);
        } elseif (in_array($status_slug, $deactivation_statuses, true)) {
            $this->process_order_deactivation($order_data);
            return new WP_REST_Response(['status' => 'deactivated', 'message' => 'Package Revoked'], 200);
        }

        return new WP_REST_Response(['status' => 'ignored', 'message' => 'Status: ' . $status_slug], 200);
    }

    // ── S2: حفظ توكنات App Store (يصلنا كل 14 يوم) ──────────────────────
    private function handle_app_authorize($data, $merchant_id)
    {
        if (empty($data['access_token'])) {
            error_log("⚠️ Salla app.store.authorize: بيانات التوكن مفقودة للمتجر $merchant_id");
            return new WP_REST_Response(['error' => 'Missing token data'], 400);
        }

        $tokens = [
            'access_token'  => sanitize_text_field($data['access_token']  ?? ''),
            'refresh_token' => sanitize_text_field($data['refresh_token'] ?? ''),
            'expires'       => (int) ($data['expires']    ?? 0),
            'scope'         => sanitize_text_field($data['scope']         ?? ''),
            'token_type'    => sanitize_text_field($data['token_type']    ?? 'bearer'),
            'updated_at'    => current_time('mysql'),
        ];

        update_option('pge_salla_tokens_' . $merchant_id, $tokens, false);

        error_log("✅ Salla tokens saved for merchant: $merchant_id | expires: " . date('Y-m-d H:i', $tokens['expires']));

        return new WP_REST_Response(['status' => 'authorized'], 200);
    }

    // ── S3a: تثبيت التطبيق ────────────────────────────────────────────────
    private function handle_app_installed($data, $merchant_id)
    {
        update_option('pge_salla_install_' . $merchant_id, [
            'installed_at' => current_time('mysql'),
            'merchant_id'  => $merchant_id,
            'data'         => $data,
        ], false);

        error_log("📦 Salla app installed for merchant: $merchant_id");

        return new WP_REST_Response(['status' => 'installed'], 200);
    }

    // ── S3b: إلغاء تثبيت التطبيق ─────────────────────────────────────────
    private function handle_app_uninstalled($merchant_id)
    {
        delete_option('pge_salla_tokens_'  . $merchant_id);
        delete_option('pge_salla_install_' . $merchant_id);

        error_log("🗑️ Salla app uninstalled for merchant: $merchant_id — tokens deleted");

        return new WP_REST_Response(['status' => 'uninstalled'], 200);
    }

    // ── S3c: تحديث التطبيق (التوكن سيصل بعدها في app.store.authorize) ────
    private function handle_app_updated($data, $merchant_id)
    {
        error_log("🔄 Salla app updated for merchant: $merchant_id — awaiting new token");
        return new WP_REST_Response(['status' => 'noted'], 200);
    }

    /**
     * دالة إلغاء تفعيل الباقة (مسار Legacy فقط).
     *
     * $order_id اختياري وللتسجيل فقط (Backward-compatible: القيمة الافتراضية
     * '' لا تُغيّر سلوك أي استدعاء قديم لم يُمرِّره).
     */
    private function deactivate_user_package($email, $order_id = '')
    {
        $user = get_user_by('email', $email);
        if ($user) {
            // حاجز حماية Catalog — يجب أن يكون أول شيء يُفحَص، قبل أي
            // update_user_meta/delete_user_meta، بلا استثناء: إن كان مصدر
            // اشتراك هذا المستخدم Catalog (بصرف النظر عن كونه active أو
            // expired حالياً)، فإن Catalog هو مصدر الحقيقة الوحيد ويُمنع أي
            // مسار Legacy — بما فيه إلغاء طلب Legacy قديم يصل بعد تفعيل
            // Catalog أحدث — من تغيير حالته أو تصفير حدوده أو مسح ميزاته.
            $legacy_write_allowed = function_exists('pge_is_legacy_write_allowed_for_user')
                ? pge_is_legacy_write_allowed_for_user($user->ID)
                : (get_user_meta($user->ID, '_mon_package_source', true) !== 'catalog');

            if (!$legacy_write_allowed) {
                $this->log_catalog_event('legacy_deactivation_blocked_for_catalog', [
                    'user_id'  => $user->ID,
                    'order_id' => $order_id,
                ]);
                return;
            }

            update_user_meta($user->ID, '_mon_package_status', 'expired'); // أو canceled
            // تصفير الحدود لضمان عدم قدرته على استخدام النظام
            update_user_meta($user->ID, '_mon_guest_limit', 0);
            update_user_meta($user->ID, '_mon_host_photos_limit', 0);
            update_user_meta($user->ID, '_mon_events_limit', 0);
            update_user_meta($user->ID, '_mon_active_features', []); // مسح المميزات

            error_log("❌ Salla Webhook: Package revoked for user: " . $email);
        }
    }

    private function is_valid_signature($payload, $signature)
    {
        if (empty($signature) || empty($this->webhook_secret)) return false;
        $computed_signature = hash_hmac('sha256', $payload, $this->webhook_secret);
        return hash_equals((string)$computed_signature, (string)$signature);
    }

    private function process_user_and_plan($order_data)
    {
        $this->process_order_packages($order_data, 'activate');
    }

    private function process_order_deactivation($order_data)
    {
        $this->process_order_packages($order_data, 'deactivate');
    }

    /**
     * يصنّف عناصر الطلب مرة واحدة ثم يطبّق أولوية Catalog أو Legacy.
     */
    private function process_order_packages($order_data, $action)
    {
        $order_id = $this->extract_order_id($order_data);
        $matches = $this->classify_order_items($order_data, $order_id, $action);

        if (!empty($matches['blocked'])) {
            return;
        }

        $catalog_count = count($matches['catalog']);
        $legacy_count = count($matches['legacy']);

        if ($catalog_count > 1) {
            $this->log_catalog_event('catalog_multiple_tiers', [
                'order_id' => $order_id,
            ]);
            return;
        }

        if ($catalog_count > 0 && $legacy_count > 0) {
            $this->log_catalog_event('mixed_catalog_legacy_order', [
                'order_id' => $order_id,
            ]);
        }

        if ($catalog_count === 1) {
            $catalog_match = reset($matches['catalog']);
            $this->process_catalog_match($order_data, $catalog_match, $order_id, $action);
            return;
        }

        if ($legacy_count === 0) {
            return;
        }

        $customer_email = $this->extract_customer_email($order_data);
        if ($customer_email === '') {
            error_log('⚠️ Salla Webhook: Legacy customer email missing');
            return;
        }

        if ($action === 'deactivate') {
            $this->deactivate_user_package($customer_email, $order_id);
            return;
        }

        // 1. معالجة المستخدم (إنشاء أو جلب)
        $user = get_user_by('email', $customer_email);
        if (!$user) {
            $user = $this->create_new_salla_user($order_data);
        }

        if (!$user) return;

        // يحتفظ Legacy بسياسته الحالية: أول باقة معروفة فقط داخل الطلب.
        $plan_key = reset($matches['legacy']);
        if ($plan_key) {
            Mon_Events_Users::activate_user_package($customer_email, [
                'order_id' => $order_id,
                'plan_key' => $plan_key
            ]);

            update_user_meta($user->ID, '_created_via_salla', 'yes');
            set_transient('mon_salla_success_' . $user->ID, "تم تفعيل " . $plan_key . " للعميل: " . $customer_email, 60);
        }
    }

    private function classify_order_items($order_data, $order_id, $action)
    {
        $matches = [
            'catalog' => [],
            'legacy'  => [],
            'blocked' => false,
        ];
        $seen_items = [];
        $items = isset($order_data['items']) && is_array($order_data['items'])
            ? $order_data['items']
            : [];

        if ($action === 'deactivate' && empty($items)) {
            $this->log_catalog_event('catalog_refund_items_missing', [
                'order_id' => $order_id,
            ]);
            $matches['blocked'] = true;
            return $matches;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product_id = $this->extract_product_id($item);
            $sku = $this->extract_salla_sku($item);
            $item_key = $sku !== '' ? 'sku:' . $sku : 'product:' . $product_id;

            if (($product_id === '' && $sku === '') || isset($seen_items[$item_key])) {
                continue;
            }
            $seen_items[$item_key] = true;

            $quantity = isset($item['quantity']) && is_scalar($item['quantity'])
                ? absint($item['quantity'])
                : 1;

            $tier = null;
            $product_tiers = [];

            if (class_exists('PGE_Catalog') && $sku !== '') {
                $tier = PGE_Catalog::get_tier_by_salla_sku($sku);

                // Product fallback is only for a single legacy Catalog tier that has no SKU yet.
                if (!is_array($tier) && $product_id !== '') {
                    $product_tiers = PGE_Catalog::get_tiers_by_salla_product_id($product_id);
                    if (count($product_tiers) === 1 && empty($product_tiers[0]['salla_sku'])) {
                        $tier = $product_tiers[0];
                    }
                }
            } elseif (class_exists('PGE_Catalog') && $product_id !== '') {
                $product_tiers = PGE_Catalog::get_tiers_by_salla_product_id($product_id);

                if (count($product_tiers) > 1) {
                    $this->log_catalog_event('catalog_sku_missing', [
                        'order_id'   => $order_id,
                        'product_id' => $product_id,
                    ]);
                    $matches['blocked'] = true;
                    continue;
                }

                if (count($product_tiers) === 1) {
                    $tier = $product_tiers[0];
                }
            }

            if (is_array($tier)) {
                $tier_id = absint($tier['id'] ?? 0);
                $plan_id = absint($tier['plan_id'] ?? 0);
                $plan = $plan_id > 0 ? PGE_Catalog::get_plan($plan_id) : null;
                $validation_error = '';
                $tier_product_id = isset($tier['salla_product_id']) && is_scalar($tier['salla_product_id'])
                    ? trim((string) $tier['salla_product_id'])
                    : '';

                if ($sku !== '' && $tier_product_id !== '' && $tier_product_id !== $product_id) {
                    $validation_error = 'catalog_product_sku_mismatch';
                } elseif (($tier['status'] ?? '') !== 'active') {
                    $validation_error = 'inactive_tier';
                } elseif ($plan_id === 0) {
                    $validation_error = 'invalid_plan_id';
                } elseif (!is_array($plan)) {
                    $validation_error = 'plan_not_found';
                } elseif (($plan['status'] ?? '') !== 'active') {
                    $validation_error = 'inactive_plan';
                } elseif (absint($plan['id'] ?? 0) !== $plan_id) {
                    $validation_error = 'tier_plan_mismatch';
                } elseif ($action === 'activate') {
                    $unit_price = $this->extract_catalog_unit_price($item, $quantity);
                    $currency = $this->extract_catalog_currency($item);
                    $tier_price = isset($tier['price']) && is_numeric($tier['price'])
                        ? (float) $tier['price']
                        : null;
                    $tier_currency = isset($tier['currency']) && is_scalar($tier['currency'])
                        ? strtoupper(trim((string) $tier['currency']))
                        : '';

                    if ($unit_price === null || $tier_price === null || abs($unit_price - $tier_price) > 0.01) {
                        $validation_error = 'catalog_amount_mismatch';
                    } elseif ($currency === '' || $tier_currency === '' || strcasecmp($currency, $tier_currency) !== 0) {
                        $validation_error = 'catalog_currency_mismatch';
                    }
                }

                $match_key = $tier_id > 0 ? (string) $tier_id : 'product:' . $product_id;
                $matches['catalog'][$match_key] = [
                    'product_id'       => $product_id,
                    'sku'              => $sku,
                    'tier'             => $tier,
                    'plan'             => $plan,
                    'validation_error' => $validation_error,
                ];

                $this->log_catalog_event('catalog_product_matched', [
                    'order_id'   => $order_id,
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'plan_id'    => $plan_id,
                    'tier_id'    => $tier_id,
                ]);

                if ($quantity > 1) {
                    $this->log_catalog_event('catalog_quantity_ignored', [
                        'order_id'   => $order_id,
                        'product_id' => $product_id,
                        'sku'        => $sku,
                        'tier_id'    => $tier_id,
                        'quantity'   => $quantity,
                    ]);
                }
                continue;
            }

            $plan_key = $this->map_product_to_plan($product_id);
            if ($plan_key) {
                $matches['legacy'][$product_id] = $plan_key;
                continue;
            }

            $this->log_catalog_event('catalog_product_unmapped', [
                'order_id'   => $order_id,
                'product_id' => $product_id,
                'sku'        => $sku,
            ]);
        }

        return $matches;
    }

    private function process_catalog_match($order_data, $match, $order_id, $action)
    {
        $tier = $match['tier'];
        $plan = $match['plan'];
        $product_id = $match['product_id'];
        $sku = $match['sku'] ?? '';
        $plan_id = absint($tier['plan_id'] ?? 0);
        $tier_id = absint($tier['id'] ?? 0);

        if ($match['validation_error'] !== '') {
            $this->log_catalog_event($match['validation_error'], [
                'order_id'   => $order_id,
                'product_id' => $product_id,
                'sku'        => $sku,
                'plan_id'    => $plan_id,
                'tier_id'    => $tier_id,
            ]);
            $this->log_catalog_event(
                $action === 'activate' ? 'catalog_activation_failed' : 'catalog_deactivation_failed',
                [
                    'order_id'   => $order_id,
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'plan_id'    => $plan_id,
                    'tier_id'    => $tier_id,
                    'error_code' => $match['validation_error'],
                ]
            );
            return;
        }

        if ($order_id === '') {
            $this->log_catalog_event('catalog_order_id_missing', [
                'product_id' => $product_id,
                'sku'        => $sku,
                'plan_id'    => $plan_id,
                'tier_id'    => $tier_id,
            ]);
            return;
        }

        $customer_mobile = $this->extract_customer_mobile($order_data);
        $customer_email = $this->extract_customer_email($order_data);
        $customer_name = $this->extract_customer_name($order_data);
        $resolution = self::resolve_catalog_customer_user($customer_mobile, $customer_email);
        $normalized_mobile = $resolution['normalized_mobile'];
        $mobile_last4 = $normalized_mobile !== '' ? substr($normalized_mobile, -4) : '';
        $user = null;

        if ($resolution['status'] === 'matched' && $resolution['user'] instanceof WP_User) {
            $user = $resolution['user'];
            $resolution_log_codes = [
                'mobile'           => 'catalog_customer_resolved_by_mobile',
                'email'            => 'catalog_customer_resolved_by_email',
                'mobile_and_email' => 'catalog_customer_resolved_by_mobile_and_email',
            ];
            $this->log_catalog_event(
                $resolution_log_codes[$resolution['method']] ?? 'catalog_customer_resolved_by_email',
                [
                    'order_id'          => $order_id,
                    'plan_id'           => $plan_id,
                    'tier_id'           => $tier_id,
                    'user_id'           => absint($user->ID),
                    'resolution_method' => $resolution['method'],
                    'mobile_last4'      => $mobile_last4,
                ]
            );
        } elseif ($resolution['status'] === 'not_found') {
            if ($action !== 'activate') {
                $this->log_catalog_event('customer_not_found', [
                    'order_id'     => $order_id,
                    'plan_id'      => $plan_id,
                    'tier_id'      => $tier_id,
                    'mobile_last4' => $mobile_last4,
                ]);
                return;
            }

            if ($normalized_mobile === '') {
                $this->log_catalog_event('customer_mobile_required_for_creation', [
                    'order_id'  => $order_id,
                    'plan_id'   => $plan_id,
                    'tier_id'   => $tier_id,
                    'error_code' => 'customer_mobile_required_for_creation',
                ]);
                return;
            }

            $user = $this->create_catalog_customer_user($normalized_mobile, $customer_email, $customer_name);
            if (is_wp_error($user)) {
                $creation_error = $user->get_error_code();
                if (in_array($creation_error, ['customer_identity_conflict', 'customer_username_conflict'], true)) {
                    $this->log_catalog_event($creation_error, [
                        'order_id'     => $order_id,
                        'plan_id'      => $plan_id,
                        'tier_id'      => $tier_id,
                        'mobile_last4' => $mobile_last4,
                    ]);
                }
                $this->log_catalog_event('customer_creation_failed', [
                    'order_id'     => $order_id,
                    'plan_id'      => $plan_id,
                    'tier_id'      => $tier_id,
                    'mobile_last4' => $mobile_last4,
                    'error_code'   => $creation_error,
                ]);
                return;
            }

            $this->log_catalog_event('catalog_customer_created', [
                'order_id'          => $order_id,
                'plan_id'           => $plan_id,
                'tier_id'           => $tier_id,
                'user_id'           => absint($user->ID),
                'resolution_method' => 'created',
                'mobile_last4'      => $mobile_last4,
            ]);
        } else {
            $identity_error = $resolution['error_code'] ?: 'customer_identity_missing';
            $this->log_catalog_event($identity_error, [
                'order_id'     => $order_id,
                'plan_id'      => $plan_id,
                'tier_id'      => $tier_id,
                'mobile_last4' => $mobile_last4,
                'error_code'   => $identity_error,
            ]);
            return;
        }

        if (!$user instanceof WP_User) {
            $this->log_catalog_event(
                $action === 'activate' ? 'catalog_activation_failed' : 'catalog_deactivation_failed',
                [
                    'order_id'   => $order_id,
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'plan_id'    => $plan_id,
                    'tier_id'    => $tier_id,
                    'error_code' => 'customer_not_found',
                ]
            );
            return;
        }

        if ($action === 'activate') {
            $result = Mon_Events_Users::activate_catalog_tier(
                absint($user->ID),
                absint($plan['id']),
                $tier_id,
                $order_id
            );
        } else {
            $result = Mon_Events_Users::deactivate_catalog_tier(
                absint($user->ID),
                $order_id
            );
        }

        if (is_wp_error($result)) {
            $this->log_catalog_event(
                $action === 'activate' ? 'catalog_activation_failed' : 'catalog_deactivation_failed',
                [
                    'order_id'   => $order_id,
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'plan_id'    => $plan_id,
                    'tier_id'    => $tier_id,
                    'user_id'    => absint($user->ID),
                    'error_code' => $result->get_error_code(),
                ]
            );
            return;
        }

        if ($action === 'activate') {
            update_user_meta($user->ID, '_created_via_salla', 'yes');
        }

        $this->log_catalog_event(
            $action === 'activate' ? 'catalog_activation_success' : 'catalog_deactivation_success',
            [
                'order_id'   => $order_id,
                'product_id' => $product_id,
                'sku'        => $sku,
                'plan_id'    => $plan_id,
                'tier_id'    => $tier_id,
                'user_id'    => absint($user->ID),
            ]
        );
    }

    private function extract_product_id($item)
    {
        $product_id = $item['product']['id'] ?? ($item['product_id'] ?? '');
        if (!is_scalar($product_id)) {
            return '';
        }

        return trim(sanitize_text_field((string) $product_id));
    }

    private function extract_salla_sku($item)
    {
        $sku = $item['sku'] ?? '';
        if (!is_string($sku)) {
            return '';
        }

        return trim(sanitize_text_field($sku));
    }

    private function extract_catalog_unit_price($item, $quantity)
    {
        $price_without_tax = $item['amounts']['price_without_tax']['amount'] ?? null;
        if (is_scalar($price_without_tax) && is_numeric($price_without_tax)) {
            return (float) $price_without_tax;
        }

        $total = $item['amounts']['total']['amount'] ?? null;
        if ($quantity > 0 && is_scalar($total) && is_numeric($total)) {
            return (float) $total / $quantity;
        }

        return null;
    }

    private function extract_catalog_currency($item)
    {
        $currency = $item['amounts']['price_without_tax']['currency']
            ?? ($item['amounts']['total']['currency'] ?? '');

        if (!is_scalar($currency)) {
            return '';
        }

        return strtoupper(trim(sanitize_text_field((string) $currency)));
    }

    private function extract_order_id($order_data)
    {
        $order_id = $order_data['id'] ?? '';
        if (!is_scalar($order_id)) {
            return '';
        }

        return trim(sanitize_text_field((string) $order_id));
    }

    private function extract_customer_email($order_data)
    {
        $email = $order_data['customer']['email'] ?? '';
        if (!is_scalar($email)) {
            return '';
        }

        $email = sanitize_email((string) $email);
        return is_email($email) ? $email : '';
    }

    private function extract_customer_mobile($order_data)
    {
        $mobile = $order_data['customer']['mobile'] ?? '';
        if (!is_string($mobile)) {
            return '';
        }

        return trim(sanitize_text_field($mobile));
    }

    private function extract_customer_name($order_data)
    {
        $name = $order_data['customer']['name'] ?? '';
        if (!is_string($name)) {
            return '';
        }

        return trim(sanitize_text_field($name));
    }

    private static function normalize_mobile($mobile)
    {
        if (!is_string($mobile)) {
            return '';
        }

        $mobile = strtr($mobile, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $normalized = preg_replace('/[^0-9]+/', '', $mobile);

        if (!is_string($normalized) || strlen($normalized) < 8 || strlen($normalized) > 15) {
            return '';
        }

        return $normalized;
    }

    private static function catalog_mobile_meta_keys()
    {
        return ['pge_phone', 'billing_phone', 'phone_number'];
    }

    private static function find_catalog_users_by_mobile($normalized_mobile)
    {
        if (
            !is_string($normalized_mobile)
            || $normalized_mobile === ''
            || self::normalize_mobile($normalized_mobile) !== $normalized_mobile
        ) {
            return [
                'status'   => 'not_found',
                'user'     => null,
                'user_ids' => [],
            ];
        }

        global $wpdb;

        $matched_ids = [];
        $meta_keys = self::catalog_mobile_meta_keys();
        $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ($placeholders)",
                ...$meta_keys
            ),
            ARRAY_A
        );

        foreach ((array) $meta_rows as $row) {
            $stored_mobile = $row['meta_value'] ?? '';
            if (!is_string($stored_mobile) || is_serialized($stored_mobile)) {
                continue;
            }
            if (self::normalize_mobile($stored_mobile) === $normalized_mobile) {
                $user_id = absint($row['user_id'] ?? 0);
                if ($user_id > 0) {
                    $matched_ids[$user_id] = true;
                }
            }
        }

        $login_user = get_user_by('login', $normalized_mobile);
        if ($login_user instanceof WP_User) {
            $matched_ids[absint($login_user->ID)] = true;
        }

        $user_ids = array_keys($matched_ids);
        sort($user_ids, SORT_NUMERIC);

        if (count($user_ids) > 1) {
            return [
                'status'   => 'ambiguous',
                'user'     => null,
                'user_ids' => $user_ids,
            ];
        }

        if (count($user_ids) === 1) {
            $user = get_user_by('id', $user_ids[0]);
            if ($user instanceof WP_User) {
                return [
                    'status'   => 'matched',
                    'user'     => $user,
                    'user_ids' => $user_ids,
                ];
            }
        }

        return [
            'status'   => 'not_found',
            'user'     => null,
            'user_ids' => [],
        ];
    }

    private static function resolve_catalog_customer_user($mobile, $email)
    {
        $normalized_mobile = self::normalize_mobile($mobile);
        $email = is_string($email) ? sanitize_email($email) : '';
        $email = is_email($email) ? $email : '';

        if ($normalized_mobile === '' && $email === '') {
            return [
                'status'            => 'missing',
                'user'              => null,
                'method'            => null,
                'error_code'        => 'customer_identity_missing',
                'normalized_mobile' => '',
            ];
        }

        $mobile_match = self::find_catalog_users_by_mobile($normalized_mobile);
        if ($mobile_match['status'] === 'ambiguous') {
            return [
                'status'            => 'ambiguous',
                'user'              => null,
                'method'            => null,
                'error_code'        => 'customer_mobile_ambiguous',
                'normalized_mobile' => $normalized_mobile,
            ];
        }

        $mobile_user = $mobile_match['status'] === 'matched' ? $mobile_match['user'] : null;
        $email_user = $email !== '' ? get_user_by('email', $email) : null;
        $email_user = $email_user instanceof WP_User ? $email_user : null;

        if ($mobile_user instanceof WP_User && $email_user instanceof WP_User) {
            if (absint($mobile_user->ID) !== absint($email_user->ID)) {
                return [
                    'status'            => 'conflict',
                    'user'              => null,
                    'method'            => null,
                    'error_code'        => 'customer_identity_conflict',
                    'normalized_mobile' => $normalized_mobile,
                ];
            }

            return [
                'status'            => 'matched',
                'user'              => $mobile_user,
                'method'            => 'mobile_and_email',
                'error_code'        => null,
                'normalized_mobile' => $normalized_mobile,
            ];
        }

        if ($mobile_user instanceof WP_User) {
            return [
                'status'            => 'matched',
                'user'              => $mobile_user,
                'method'            => 'mobile',
                'error_code'        => null,
                'normalized_mobile' => $normalized_mobile,
            ];
        }

        if ($email_user instanceof WP_User) {
            return [
                'status'            => 'matched',
                'user'              => $email_user,
                'method'            => 'email',
                'error_code'        => null,
                'normalized_mobile' => $normalized_mobile,
            ];
        }

        return [
            'status'            => 'not_found',
            'user'              => null,
            'method'            => null,
            'error_code'        => null,
            'normalized_mobile' => $normalized_mobile,
        ];
    }

    private function create_catalog_customer_user($normalized_mobile, $email, $customer_name)
    {
        if (
            !is_string($normalized_mobile)
            || $normalized_mobile === ''
            || self::normalize_mobile($normalized_mobile) !== $normalized_mobile
        ) {
            return new WP_Error('customer_mobile_required_for_creation', 'A valid mobile number is required.');
        }

        $email = is_string($email) ? sanitize_email($email) : '';
        $email = is_email($email) ? $email : '';
        $login_owner = get_user_by('login', $normalized_mobile);
        $email_owner = $email !== '' ? get_user_by('email', $email) : null;

        if ($login_owner instanceof WP_User) {
            if ($email_owner instanceof WP_User && absint($login_owner->ID) !== absint($email_owner->ID)) {
                return new WP_Error('customer_identity_conflict', 'Customer identity conflict.');
            }
            return new WP_Error('customer_username_conflict', 'Customer username already exists.');
        }

        if ($email_owner instanceof WP_User) {
            return new WP_Error('customer_identity_conflict', 'Customer email already exists.');
        }

        $display_name = is_string($customer_name)
            ? trim(sanitize_text_field($customer_name))
            : '';
        if ($display_name === '') {
            $display_name = 'عميل جديد';
        }

        $random_password = wp_generate_password(32, true, true);
        $user_id = wp_create_user($normalized_mobile, $random_password, $email);
        unset($random_password);

        if (is_wp_error($user_id)) {
            return new WP_Error('customer_creation_failed', 'Unable to create customer account.');
        }

        $updated_user = wp_update_user([
            'ID'           => $user_id,
            'display_name' => $display_name,
            'nickname'     => $display_name,
        ]);
        if (is_wp_error($updated_user)) {
            return new WP_Error('customer_creation_failed', 'Unable to update customer account.');
        }

        foreach (self::catalog_mobile_meta_keys() as $meta_key) {
            if (update_user_meta($user_id, $meta_key, $normalized_mobile) === false) {
                return new WP_Error('customer_creation_failed', 'Unable to store customer mobile.');
            }
        }

        $user = get_user_by('id', $user_id);
        return $user instanceof WP_User
            ? $user
            : new WP_Error('customer_creation_failed', 'Unable to load customer account.');
    }

    private function log_catalog_event($code, $context = [])
    {
        $allowed_keys = ['order_id', 'product_id', 'sku', 'plan_id', 'tier_id', 'user_id', 'error_code', 'quantity', 'resolution_method', 'mobile_last4'];
        $safe_context = [];

        foreach ($allowed_keys as $key) {
            if (!array_key_exists($key, $context) || !is_scalar($context[$key])) {
                continue;
            }
            $safe_context[$key] = sanitize_text_field((string) $context[$key]);
        }

        error_log('Salla Catalog [' . sanitize_key($code) . '] ' . wp_json_encode($safe_context));
    }

    private function create_new_salla_user($order_data)
    {
        $email = $order_data['customer']['email'];
        $random_password = wp_generate_password(12, false);
        $user_id = wp_create_user($email, $random_password, $email);

        if (is_wp_error($user_id)) return false;

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $order_data['customer']['first_name'] ?? '',
            'last_name'    => $order_data['customer']['last_name'] ?? '',
            'display_name' => $order_data['customer']['full_name'] ?? $email,
        ]);

        $user = get_user_by('id', $user_id);
        $this->send_welcome_email($user);
        return $user;
    }

    private function map_product_to_plan($salla_product_id)
    {
        $plans = get_option('mon_packages_settings', []);
        foreach ($plans as $plan_key => $data) {
            if (isset($data['salla_id']) && (string)$data['salla_id'] === (string)$salla_product_id) {
                return $plan_key;
            }
        }
        return false;
    }

    private function send_welcome_email($user)
    {
        $key = get_password_reset_key($user);
        $set_password_url = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user->user_login), 'login');

        $subject = 'تم إنشاء حسابك وتفعيل باقتك بنجاح! 🎉';
        $message = "مرحباً، لقد تم تفعيل اشتراكك في " . get_bloginfo('name') . ".\n\n";
        $message .= "لإدارة مناسباتك، يرجى تعيين كلمة المرور عبر الرابط التالي:\n" . $set_password_url;

        wp_mail($user->user_email, $subject, $message);
    }

    private function init_admin_hooks()
    {
        // عرض إشعار النجاح في لوحة التحكم
        add_action('admin_notices', function () {
            $msg = get_transient('mon_salla_success_' . get_current_user_id());
            if ($msg) {
                echo "<div class='notice notice-success is-dismissible'><p>{$msg} ✅</p></div>";
                delete_transient('mon_salla_success_' . get_current_user_id());
            }
        });

        // إضافة أعمدة في جدول المستخدمين لمراقبة الاشتراكات — منطق العرض
        // نفسه معزول في helpers.php (pge_resolve_admin_user_package_name/
        // pge_resolve_admin_user_package_source) لا داخل هذا الملف، حتى لا
        // يبقى منطق العرض داخل كلاس الـWebhook.
        add_filter('manage_users_columns', function ($cols) {
            $cols['mon_plan'] = 'الباقة الحالية';
            $cols['mon_source'] = 'مصدر الاشتراك';
            return $cols;
        });

        add_filter('manage_users_custom_column', function ($val, $col, $user_id) {
            if ($col === 'mon_plan') {
                $name = function_exists('pge_resolve_admin_user_package_name')
                    ? pge_resolve_admin_user_package_name($user_id)
                    : (string) get_user_meta($user_id, '_mon_package_name', true);

                $is_placeholder = ($name === '' || $name === '—' || $name === 'بيانات Catalog غير مكتملة');
                if ($is_placeholder) {
                    return $name !== '' ? esc_html($name) : '—';
                }
                return '<mark><strong>' . esc_html($name) . '</strong></mark>';
            }
            if ($col === 'mon_source') {
                $source_label = function_exists('pge_resolve_admin_user_package_source')
                    ? pge_resolve_admin_user_package_source($user_id)
                    : 'Legacy';
                return esc_html($source_label);
            }
            return $val;
        }, 10, 3);
    }
}

new Mon_Salla_Handler();
