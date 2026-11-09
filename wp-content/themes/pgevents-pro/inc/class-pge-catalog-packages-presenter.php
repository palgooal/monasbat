<?php
defined('ABSPATH') || exit;

/**
 * Read-only Catalog card presenter, independent of Elementor.
 * build($settings, ELEMENTOR) preserves the existing card contract exactly.
 * build($settings, NATIVE) prices only active, purchasable, valid-price tiers
 * in one currency. No eligible tiers or mixed currencies: empty price/currency,
 * no starting-price prefix and an internal details CTA. No Legacy fallback.
 * Settings: max_features and fallback_url[url], matching the current widget.
 * Returns ordered cards: plan_key, name, price_text, currency, features, url,
 * is_featured, has_starting_price, is_direct_purchase. No writes or remote I/O.
 */
final class PGE_Catalog_Packages_Presenter
{
    const ELEMENTOR = 'elementor';
    const NATIVE = 'native';

    public static function get_https_purchase_url($url)
    {
        if (!is_string($url)) {
            return '';
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
        ) {
            return '';
        }

        $sanitized_url = esc_url_raw($url, ['https']);
        return $sanitized_url !== '' ? $sanitized_url : '';
    }

    public static function build($settings = [], $policy = self::ELEMENTOR)
    {
        if (!in_array($policy, [self::ELEMENTOR, self::NATIVE], true)) {
            throw new \InvalidArgumentException('Unknown catalog presentation policy.');
        }
        if (!class_exists('PGE_Catalog')) {
            return [];
        }

        try {
            $plans = PGE_Catalog::get_plans();
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($plans)) {
            $plans = [];
        }

        $fallback_url = !empty($settings['fallback_url']['url'])
            ? esc_url_raw($settings['fallback_url']['url'])
            : '';
        if ($fallback_url === '') {
            $fallback_url = '#';
        }
        $max_features = max(3, (int) ($settings['max_features'] ?? 7));

        $packages = [];
        foreach ($plans as $plan) {
            if (!is_array($plan) || ($plan['status'] ?? '') !== 'active') {
                continue;
            }

            try {
                $tiers = PGE_Catalog::get_plan_tiers($plan['id'] ?? 0);
            } catch (\Throwable $e) {
                $tiers = [];
            }
            if (!is_array($tiers)) {
                $tiers = [];
            }

            if ($policy === self::NATIVE) {
                // A single currency is required; never compare unlike currencies.
                $tiers = array_values(array_filter($tiers, static function ($tier) {
                    return is_array($tier) && ($tier['status'] ?? '') === 'active'
                        && is_string($tier['salla_product_id'] ?? null)
                        && trim($tier['salla_product_id']) !== ''
                        && self::get_https_purchase_url($tier['salla_url'] ?? '') !== ''
                        && isset($tier['price']) && is_numeric($tier['price'])
                        && is_finite((float) $tier['price']) && (float) $tier['price'] >= 0;
                }));
                $currencies = [];
                foreach ($tiers as $tier) {
                    $currencies[] = strtoupper(trim((string) ($tier['currency'] ?? '')));
                }
                $currencies = array_values(array_unique($currencies));
                if (count($currencies) !== 1 || !preg_match('/^[A-Z]{3}$/', $currencies[0])) {
                    $tiers = [];
                }
            }

            $min_price = null;
            $min_currency = '';
            $priced_tier_count = 0;
            foreach ($tiers as $tier) {
                if (!is_array($tier) || ($tier['status'] ?? '') !== 'active') {
                    continue;
                }
                if (!isset($tier['price']) || !is_numeric($tier['price'])) {
                    continue;
                }
                $priced_tier_count++;
                $tier_price = (float) $tier['price'];
                if ($min_price === null || $tier_price < $min_price) {
                    $min_price = $tier_price;
                    $min_currency = trim((string) ($tier['currency'] ?? ''));
                }
            }

            if ($min_price === null) {
                $price_text = $policy === self::NATIVE ? '' : __('تواصل معنا', 'pgevents');
                $currency_text = '';
            } elseif (floor($min_price) === $min_price) {
                $price_text = number_format_i18n($min_price, 0);
                $currency_text = $min_currency;
            } else {
                $price_text = number_format_i18n($min_price, 2);
                if (substr($price_text, -1) === '0') {
                    $price_text = substr($price_text, 0, -1);
                }
                $currency_text = $min_currency;
            }

            $decoded = null;
            $features_raw = $plan['features'] ?? null;
            if (is_string($features_raw) && trim($features_raw) !== '') {
                $decoded = json_decode($features_raw, true);
            }
            $features = [];
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item)) {
                        $features[] = $item;
                    }
                }
            }
            $features = array_slice($features, 0, $max_features);

            $plan_key = sanitize_key((string) ($plan['plan_key'] ?? ''));
            $details_url = $fallback_url;
            if ($plan_key !== '') {
                $details_url = add_query_arg(
                    'plan',
                    $plan_key,
                    home_url('/packages/')
                );
            }

            $purchasable_tier_urls = [];
            $purchasable_tier_product_ids = [];
            foreach ($tiers as $tier) {
                if (!is_array($tier) || ($tier['status'] ?? '') !== 'active') {
                    continue;
                }
                $tier_salla_product_id = is_string($tier['salla_product_id'] ?? null)
                    ? trim($tier['salla_product_id'])
                    : '';
                $tier_salla_url = self::get_https_purchase_url($tier['salla_url'] ?? '');
                if ($tier_salla_product_id !== '' && $tier_salla_url !== '') {
                    $purchasable_tier_urls[] = $tier_salla_url;
                    $purchasable_tier_product_ids[] = $tier_salla_product_id;
                }
            }
            $unique_purchase_urls = array_values(array_unique($purchasable_tier_urls));
            $unique_purchase_product_ids = array_values(array_unique($purchasable_tier_product_ids));
            $is_direct_purchase = count($unique_purchase_urls) === 1 && count($unique_purchase_product_ids) === 1;
            $card_url = $is_direct_purchase ? $unique_purchase_urls[0] : $details_url;

            $packages[] = [
                'plan_key'    => $plan_key,
                'name'        => (string) ($plan['name'] ?? ''),
                'price_text'  => $price_text,
                'currency'    => $currency_text,
                'features'    => $features,
                'url'         => $card_url,
                'is_featured' => false,
                // E2E-02 FIX PASS 3: بادئة "ابتداءً من" فقط عند وجود أكثر من
                // Tier نشط مسعَّر (التباس حقيقي في السعر الأدنى)، وليس عند
                // مجرد وجود سعر واحد — Tier نشط واحد يُعرَض بسعره الفعلي دون
                // أي بادئة، مطابقةً للعقد المطلوب.
                'has_starting_price' => ($min_price !== null && $priced_tier_count > 1),
                'is_direct_purchase' => $is_direct_purchase,
            ];
        }

        return $packages;
    }

}
