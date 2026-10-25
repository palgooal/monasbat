<?php
/**
 * Run: php tests/test-catalog-tiers-quota-form.php
 * Executes the actual current callback and feature-save helper from the source
 * file; never copies their logic. In-memory boundary doubles only, no WordPress
 * bootstrap, database, schema, snapshots or file writes.
 */
error_reporting(E_ALL);
set_error_handler(function ($level, $message) { throw new RuntimeException($message); });
$source = file_get_contents(__DIR__ . '/../pgevents-core.php');
$start = strpos($source, 'function pge_catalog_persist_tier_features(');
$end = strpos($source, 'function pge_catalog_plans_admin_styles(', $start);
if ($start === false || $end === false) throw new RuntimeException('Callback boundaries missing');
eval(substr($source, $start, $end - $start));
function current_user_can($cap) { return $cap === 'manage_options'; }
function wp_die($message) { throw new RuntimeException($message); }
function wp_unslash($value) { return $value; }
function absint($value) { return abs((int) $value); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr($value); }
function esc_url($value, $protocols = null) { return esc_html($value); }
function esc_js($value) { return addslashes($value); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function selected($a, $b = true, $echo = true) { $s = (string) $a === (string) $b ? ' selected="selected"' : ''; if ($echo) echo $s; return $s; }
function checked($a, $b = true, $echo = true) { $s = (string) $a === (string) $b ? ' checked="checked"' : ''; if ($echo) echo $s; return $s; }
function submit_button($label, $type, $name, $wrap = true) { echo '<button name="' . $name . '">' . $label . '</button>'; }
function wp_nonce_field($action, $name) { echo '<input name="' . $name . '" value="' . $action . '">'; }
function check_admin_referer($action, $name) { if (($_POST[$name] ?? '') !== $action) throw new RuntimeException('Nonce mismatch'); }
function admin_url($path) { return '/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function pge_feature_resolver_interpret_by_type($type, $value) { return (int) $value; }
class PGE_Catalog {
    public static $tier;
    public static function get_plans() { return [self::get_plan(1)]; }
    public static function get_plan($id) { return ['id' => 1, 'name' => 'Plan', 'plan_key' => 'plan', 'plan_type' => 'personal', 'status' => 'active']; }
    public static function get_tier($id) { return self::$tier; }
    public static function get_plan_tiers($id) { return [self::$tier, array_merge(self::$tier, ['id' => 2])]; }
}
class PGE_Feature_Registry {
    public static function all() { return ['quota_test' => ['type' => 'integer', 'default' => 0, 'admin_label' => 'Test feature', 'description' => 'Test']]; }
}
class PGE_Tier_Features {
    public static $failure = '';
    public static $values = [];
    public static $writes = [];
    public static function set_tier_feature_value($id, $key, $value) {
        self::$writes[] = $id;
        if (self::$failure === 'source' || (self::$failure === 'sibling' && $id === 2)) return false;
        self::$values[$id] = $value;
        return true;
    }
    public static function get_all_tier_features($id) {
        if (self::$failure === 'read') return false;
        return [['feature_key' => 'quota_test', 'feature_value' => self::$values[$id] ?? '3']];
    }
}
$checks = 0;
function verify($condition, $label) { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($label); }
$scenarios = 0;
foreach (['limited' => '7', 'unlimited' => '13'] as $mode => $limit) {
    foreach (['update_tier_features', 'copy_tier_features'] as $action) {
        $failures = $action === 'copy_tier_features' ? ['', 'source', 'incomplete', 'read', 'sibling'] : ['', 'source', 'incomplete'];
        foreach ($failures as $failure) {
            $scenarios++;
            PGE_Catalog::$tier = ['id' => 1, 'plan_id' => 1, 'name' => 'Tier', 'tier_key' => 'tier', 'price' => '10.00', 'currency' => 'SAR', 'status' => 'active', 'sort_order' => 0, 'event_quota_mode' => $mode, 'event_quota_limit' => $limit];
            $before = PGE_Catalog::$tier;
            PGE_Tier_Features::$failure = $failure;
            PGE_Tier_Features::$writes = []; PGE_Tier_Features::$values = [];
            $nonce_name = $action === 'copy_tier_features' ? 'pge_catalog_tier_copy_nonce' : 'pge_catalog_tier_features_nonce';
            $nonce_action = $action === 'copy_tier_features' ? 'pge_copy_catalog_tier_features_1' : 'pge_update_catalog_tier_features_1';
            $_GET = ['plan_id' => '999', 'edit_tier' => '999'];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['plan_id' => '1', 'tier_id' => '1', 'pge_catalog_action' => $action, 'submit_' . $action => '1', $nonce_name => $nonce_action, 'tier_features' => $failure === 'incomplete' ? [] : ['quota_test' => '9'], 'event_quota_mode' => 'limited', 'event_quota_limit' => '999'];
            ob_start();
            try { pge_render_catalog_tiers_page(); $html = ob_get_contents(); }
            finally { ob_end_clean(); }
            verify(strpos($html, $failure === '' ? 'notice-success' : 'notice-error') !== false, "$action/$failure notice");
            preg_match('/<fieldset[^>]*data-target="pge_edit_tier_event_quota_limit"[^>]*>(.*?)<\/fieldset>/s', $html, $match);
            $fieldset = $match[1] ?? '';
            verify(preg_match('/name="event_quota_mode" value="' . $mode . '"[^>]*checked="checked"/', $fieldset) === 1, "$action/$failure actual mode");
            verify(preg_match('/id="pge_edit_tier_event_quota_limit"[^>]*value="' . $limit . '"/', $fieldset) === 1, "$action/$failure actual limit");
            verify(PGE_Catalog::$tier === $before, 'quota data unchanged');
            verify($failure === 'incomplete' ? PGE_Tier_Features::$writes === [] : in_array(1, PGE_Tier_Features::$writes, true), 'feature branch executed');
            if ($action === 'copy_tier_features') verify(in_array(2, PGE_Tier_Features::$writes, true) === in_array($failure, ['', 'sibling'], true), 'copy execution/failure path');
        }
    }
}
echo "PASS: $checks/$checks assertions; $scenarios callback scenarios; warnings treated as failures\n";
