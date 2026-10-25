<?php
/**
 * Run: php tests/test-catalog-tiers-admin-views.php
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
function current_user_can($cap) { return ($GLOBALS['allowed'] ?? true) && $cap === 'manage_options'; }
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
    public static $tiers = null;
    public static $writes = [];
    public static $fail = false;
    public static function create_tier($data) { self::$writes[] = ['create', $data]; return self::$fail ? false : array_merge(self::$tier, $data); }
    public static function update_tier($id, $data) { self::$writes[] = ['update', $id, $data]; return self::$fail ? false : array_merge(self::$tier, $data); }
    public static function delete_tier($id) { self::$writes[] = ['delete', $id]; return !self::$fail; }
    public static function get_tier_by_salla_sku($sku) { return null; }
    public static function get_plans() { return [self::get_plan(1)]; }
    public static function get_plan($id) { if ($id !== 1) return null; return ['id' => 1, 'name' => 'Plan', 'plan_key' => 'plan', 'plan_type' => 'personal', 'status' => 'active']; }
    public static function get_tier($id) { return $id === 1 ? self::$tier : ($id === 2 ? array_merge(self::$tier, ['id' => 2, 'plan_id' => 2]) : null); }
    public static function get_plan_tiers($id) { return self::$tiers ?? [self::$tier, array_merge(self::$tier, ['id' => 2])]; }
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

function sanitize_text_field($v) { return trim($v); }
function sanitize_key($v) { return strtolower($v); }
function wp_parse_url($v) { return parse_url($v); }
function esc_url_raw($v, $protocols = null) { return $v; }
function render_view($get, $post = []) {
    $_GET = $get; $_POST = $post; $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    PGE_Catalog::$writes = []; PGE_Tier_Features::$writes = [];
    ob_start();
    try { pge_render_catalog_tiers_page(); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
PGE_Catalog::$tier = ['id'=>1,'plan_id'=>1,'name'=>'Tier One','tier_key'=>'tier_one','price'=>'10.00','currency'=>'SAR','status'=>'active','sort_order'=>0,'invitation_credit_limit'=>'14','replacement_credit_limit'=>'3','guest_limit'=>'90','event_quota_mode'=>'limited','event_quota_limit'=>'7','salla_product_id'=>'product-1','salla_sku'=>'SKU-1','salla_url'=>'https://example.test/product'];
$cases = [
    [[], 'none'],
    [['plan_id'=>'1'], 'list'],
    [['plan_id'=>'1','action'=>'add'], 'add'],
    [['plan_id'=>'1','action'=>'edit','tier_id'=>'1'], 'edit'],
    [['plan_id'=>'1','edit_tier'=>'1'], 'edit'],
    [['plan_id'=>'1','action'=>'delete','tier_id'=>'1'], 'delete'],
    [['plan_id'=>'999'], 'none'],
    [['plan_id'=>['1']], 'none'],
    [['plan_id'=>'-1'], 'none'],
    [['plan_id'=>'1','action'=>'unknown'], 'list'],
    [['plan_id'=>'1','action'=>['edit']], 'list'],
];
foreach (['edit','delete'] as $action) foreach (['999','2','0','-1', ['1']] as $id) $cases[] = [['plan_id'=>'1','action'=>$action,'tier_id'=>$id], 'list'];
foreach ($cases as [$query,$view]) {
    $html=render_view($query);
    foreach (['add'=>'create_tier','edit'=>'update_tier','delete'=>'delete_tier'] as $v=>$action) verify(str_contains($html,'value="'.$action.'"') === ($view===$v), json_encode($query).' view '.$v);
    verify(str_contains($html,'widefat fixed striped') === ($view==='list'), 'list isolation');
    verify(str_contains($html,'value="update_tier_features"') === ($view==='edit'), 'features edit only');
    verify(str_contains($html,'value="copy_tier_features"') === ($view==='edit'), 'copy edit only');
    verify(PGE_Catalog::$writes===[] && PGE_Tier_Features::$writes===[], 'GET no writes');
    if ($view==='delete') foreach (['Plan','Tier One','tier_one','pge_catalog_tier_delete_nonce','submit_delete_tier'] as $needle) verify(str_contains($html,$needle),'confirmation identity/contract');
    if ($view==='add'||$view==='edit') foreach (['name','tier_key','price','currency','salla_product_id','salla_sku','salla_url','status','sort_order','invitation_credit_limit','replacement_credit_limit','guest_limit','event_quota_mode','event_quota_limit'] as $field) verify(str_contains($html,'name="'.$field.'"'), 'field '.$field);
}
$contracts = [
 'create_tier'=>['pge_catalog_tier_nonce','pge_create_catalog_tier'],
 'update_tier'=>['pge_catalog_tier_update_nonce','pge_update_catalog_tier_1'],
 'delete_tier'=>['pge_catalog_tier_delete_nonce','pge_delete_catalog_tier_1'],
 'update_tier_features'=>['pge_catalog_tier_features_nonce','pge_update_catalog_tier_features_1'],
 'copy_tier_features'=>['pge_catalog_tier_copy_nonce','pge_copy_catalog_tier_features_1'],
];
foreach ($contracts as $action=>[$nonce,$token]) {
 $post=['plan_id'=>'1','tier_id'=>'1','pge_catalog_action'=>$action,'submit_'.$action=>'1',$nonce=>'bad'];
 try { render_view([], $post); verify(false,'nonce must reject'); } catch (RuntimeException $e) { verify($e->getMessage()==='Nonce mismatch','nonce rejected'); }
 verify(PGE_Catalog::$writes===[] && PGE_Tier_Features::$writes===[], 'nonce no writes');
 $GLOBALS['allowed']=false;
 try { render_view([],array_merge($post,[$nonce=>$token])); verify(false,'permission must reject'); } catch (RuntimeException $e) { verify($e->getMessage()!=='Nonce mismatch','capability rejected first'); }
 verify(PGE_Catalog::$writes===[] && PGE_Tier_Features::$writes===[], 'permission no writes');
 $GLOBALS['allowed']=true;
}
foreach (['create_tier','update_tier','delete_tier'] as $action) {
 [$nonce,$token]=$contracts[$action];
 foreach ([false,true] as $failure) {
  PGE_Catalog::$fail=$failure;
  $post=array_merge(PGE_Catalog::$tier,['plan_id'=>'1','tier_id'=>'1','pge_catalog_action'=>$action,'submit_'.$action=>'1',$nonce=>$token,'name'=>'Retained Name']);
  $html=render_view(['action'=>'unknown','plan_id'=>'999'],$post);
  verify(count(PGE_Catalog::$writes)===1,'POST existing CRUD call');
  verify(str_contains($html,$failure?'notice-error':'notice-success'),'POST notice');
  verify(str_contains($html,'value="delete_tier"')===($action==='delete_tier' && $failure),'delete failure confirmation');
  if ($action!=='delete_tier' && $failure) verify(str_contains($html,'value="Retained Name"'),'failed form retained');
 }
}

foreach ($contracts as $action=>[$nonce,$token]) {
 foreach ([['plan_id'=>'999','tier_id'=>'1'], ['plan_id'=>'1','tier_id'=>'999'], ['plan_id'=>'1','tier_id'=>'2']] as $ids) {
  if ($action==='create_tier' && $ids['plan_id']==='1') continue;
  $html=render_view([],array_merge($ids,['pge_catalog_action'=>$action,'submit_'.$action=>'1',$nonce=>str_replace('_1','_'.$ids['tier_id'],$token)]));
  verify(PGE_Catalog::$writes===[] && PGE_Tier_Features::$writes===[], 'invalid/foreign POST no writes');
  verify(str_contains($html,'notice-error'), 'invalid/foreign POST error');
 }
}
foreach (['create_tier','update_tier'] as $action) {
 [$nonce,$token]=$contracts[$action];
 $html=render_view([],array_merge(PGE_Catalog::$tier,['plan_id'=>'1','tier_id'=>'1','pge_catalog_action'=>$action,'submit_'.$action=>'1',$nonce=>$token,'event_quota_limit'=>'0','name'=>'Validation Retained']));
 verify(PGE_Catalog::$writes===[], 'quota validation no writes');
 verify(str_contains($html,'notice-error') && str_contains($html,'value="Validation Retained"'), 'validation retains form/error');
 verify(str_contains($html,'name="event_quota_limit"') && str_contains($html,'value="0"'), 'quota input retained');
}

foreach ([[], [PGE_Catalog::$tier], [PGE_Catalog::$tier, array_merge(PGE_Catalog::$tier, ['id' => 3, 'status' => 'inactive']), array_merge(PGE_Catalog::$tier, ['id' => 4])]] as $stats_tiers) {
    PGE_Catalog::$tiers = $stats_tiers;
    $html = render_view(['plan_id' => '1']);
    $expected_total = count($stats_tiers);
    $expected_active = count(array_filter($stats_tiers, static fn($tier) => ($tier['status'] ?? '') === 'active'));
    verify(strpos($html, 'إجمالي المستويات') !== false && strpos($html, '<dd>' . $expected_total . '</dd>') !== false, 'total stats from displayed tiers');
    verify(strpos($html, 'المستويات النشطة') !== false && strpos($html, '<dd>' . $expected_active . '</dd>') !== false, 'active stats from displayed tiers');
    foreach ($stats_tiers as $tier) verify(strpos($html, $tier['name']) !== false && strpos($html, $tier['tier_key']) !== false, 'tier row values retained');
}
PGE_Catalog::$tiers = null;
$html = render_view(['plan_id' => '1']);
foreach (['10.00 SAR', 'رصيد الدعوات:', '14', 'الرصيد البديل:', '3', 'المدعوون:', '90', 'المناسبات:', '7', 'ID: product-1', 'SKU: SKU-1', 'فتح المنتج'] as $value) {
    verify(strpos($html, $value) !== false, 'tier table value retained: ' . $value);
}

$feature_html = render_view(['plan_id' => '1', 'action' => 'edit', 'tier_id' => '1']);
foreach (['pge-tier-feature-form', 'pge-tier-feature-grid', 'pge-tier-feature-card--integer', 'pge-tier-feature-actions', 'pge-tier-copy-features', 'pge-tier-copy-actions'] as $class_name) {
    verify(str_contains($feature_html, $class_name), 'feature UI class rendered: ' . $class_name);
}
verify(substr_count($feature_html, 'name="tier_features[quota_test]"') === 2, 'feature save and copy forms retain their field contracts');
preg_match_all('/\bid="([^"]+)"/', $feature_html, $feature_ids);
verify(count($feature_ids[1]) === count(array_unique($feature_ids[1])), 'edit view has no duplicate HTML IDs');
preg_match_all('/<\/?form\b[^>]*>/i', $feature_html, $feature_form_tags);
$feature_form_depth = 0;
$feature_forms_are_separate = true;
foreach ($feature_form_tags[0] as $feature_form_tag) {
    if (str_starts_with(strtolower($feature_form_tag), '</')) {
        $feature_form_depth--;
        continue;
    }
    $feature_form_depth++;
    if ($feature_form_depth > 1) {
        $feature_forms_are_separate = false;
    }
}
verify($feature_forms_are_separate && $feature_form_depth === 0, 'edit, feature save, and feature copy forms are not nested');
echo "PASS: $checks/$checks assertions; 21 GET cases; 31 POST cases; actual callback, no database\n";
