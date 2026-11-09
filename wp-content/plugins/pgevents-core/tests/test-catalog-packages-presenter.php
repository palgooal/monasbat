<?php
/**
 * Isolated presenter regression tests; no database or network.
 * Run: php tests/test-catalog-packages-presenter.php [original-widget.php]
 * The optional original widget is a pre-extraction source copy, loaded under
 * another class name. It proves strict card-array and full HTML equivalence.
 * Without that argument only policy/adapter regression checks run.
 */

namespace {

$total = 0;
$passed = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failures[] = $label;
        echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

function check_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) !== false);
}

function check_not_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) === false);
}

define('ABSPATH', '/tmp/');

function __($text, $domain = 'default') { return $text; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_html__($text, $domain = 'default') { return $text; }
function esc_url($url, $protocols = null) { return (string) $url; }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url_raw($url, $protocols = null) { return (string) $url; }
function sanitize_key($key) {
    $key = strtolower((string) $key);
    return preg_replace('/[^a-z0-9_\-]/', '', $key);
}
function wp_parse_url($url, $component = -1) { return parse_url($url); }
function home_url($path = '') { return 'https://hilwah.net' . $path; }
function add_query_arg($key, $value = null, $url = null) {
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $sep . $key . '=' . $value;
}
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, $decimals); }
function get_option($name, $default = false) { return $default; }

class WP_User { public $ID = 0; }

}

namespace Elementor {
    class Controls_Manager {
        const TEXT = 'text';
        const TEXTAREA = 'textarea';
        const SWITCHER = 'switcher';
        const SELECT = 'select';
        const COLOR = 'color';
        const NUMBER = 'number';
        const URL = 'url';
    }
    class Group_Control_Typography {
        public static function get_type() { return 'typography'; }
    }
    class Widget_Base {
        public $test_settings = [];
        public function get_settings_for_display() { return $this->test_settings; }
        protected function start_controls_section($id, $args) {}
        protected function add_control($id, $args) {}
        protected function add_group_control($type, $args) {}
        protected function end_controls_section() {}
        public function get_id() { return 'test-instance'; }
    }
}

namespace {
class PGE_Catalog
{
    public static $plans = [];
    public static $tiers_by_plan = [];

    public static function get_plans() { return self::$plans; }
    public static function get_plan_tiers($plan_id) { return self::$tiers_by_plan[$plan_id] ?? []; }
}

$widget_file = __DIR__ . '/../../../themes/pgevents-pro/inc/elementor/widgets/class-pge-packages-widget.php';
$widget_src = file_exists($widget_file) ? (string) file_get_contents($widget_file) : '';
check_true('ملف class-pge-packages-widget.php موجود وقابل للقراءة', $widget_src !== '');

require $widget_file;
check_true('Actual widget loaded', class_exists('PGE_Packages_Widget'));

$widget = new PGE_Packages_Widget();
$ref = new ReflectionClass($widget);

function call_private($obj, $ref, $method, $args = [])
{
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
}

$baseline = null;
if (!empty($argv[1])) {
    $original = file_get_contents($argv[1]);
    if ($original === false) throw new RuntimeException('Cannot read baseline');
    eval('?>' . str_replace('class PGE_Packages_Widget extends', 'class PGE_Packages_Widget_Baseline extends', $original));
    $baseline = new PGE_Packages_Widget_Baseline();
}
$plan = ['id'=>1,'plan_key'=>'classic','name'=>'Classic','status'=>'active','features'=>'["One","Two",3]'];
$tier = ['status'=>'active','price'=>'590.00','currency'=>'SAR','salla_product_id'=>'123','salla_url'=>'https://store.example/p123'];
$cases = [
 'zero'=>[array_replace($tier,['price'=>'0'])],
 'single'=>[$tier],
 'multiple'=>[$tier,array_replace($tier,['price'=>'890'])],
 'missing-price'=>[array_diff_key($tier,['price'=>true])],
 'inactive'=>[array_replace($tier,['status'=>'inactive'])],
 'invalid-url'=>[array_replace($tier,['salla_url'=>'javascript:alert(1)'])],
 'http-url'=>[array_replace($tier,['salla_url'=>'http://store.example/p123'])],
 'mixed-currency'=>[$tier,array_replace($tier,['price'=>'100','currency'=>'USD'])],
 'unavailable-cheaper'=>[$tier,array_replace($tier,['price'=>'1','salla_product_id'=>''])],
 'decimal'=>[array_replace($tier,['price'=>'590.50'])],
 'different-products'=>[$tier,array_replace($tier,['salla_product_id'=>'456','salla_url'=>'https://store.example/p456'])],
 'empty'=>[],
 'missing-currency'=>[array_replace($tier,['currency'=>''])],
];
$settings=['data_source'=>'catalog','max_features'=>7,'fallback_url'=>['url'=>'/packages/']];
foreach ($cases as $label=>$tiers) {
 PGE_Catalog::$plans=[$plan]; PGE_Catalog::$tiers_by_plan=[1=>$tiers];
 $cards=PGE_Catalog_Packages_Presenter::build($settings);
 check($label.': delegation',call_private($widget,$ref,'build_catalog_packages',[$settings]),$cards);
 if ($baseline) {
  $br=new ReflectionClass($baseline);
  check($label.': exact cards',$cards,call_private($baseline,$br,'build_catalog_packages',[$settings]));
  $widget->test_settings=$baseline->test_settings=$settings;
  ob_start(); call_private($baseline,$br,'render'); $old=ob_get_clean();
  ob_start(); call_private($widget,$ref,'render'); $new=ob_get_clean();
  check($label.': exact HTML',$new,$old);
 }
 $native=PGE_Catalog_Packages_Presenter::build($settings,PGE_Catalog_Packages_Presenter::NATIVE)[0];
 $hidden=in_array($label,['missing-price','inactive','invalid-url','http-url','mixed-currency','empty','missing-currency'],true);
 if ($hidden) {
  check($label.': native price hidden',$native['price_text'],'');
  check($label.': native currency hidden',$native['currency'],'');
  check($label.': native details CTA',$native['is_direct_purchase'],false);
  check($label.': native no prefix',$native['has_starting_price'],false);
 } else {
  $expected=$label==='zero'?'0':($label==='decimal'?'590.5':'590');
  check($label.': native price',$native['price_text'],$expected);
  check($label.': native currency',$native['currency'],'SAR');
  check($label.': native prefix',$native['has_starting_price'],in_array($label,['multiple','different-products'],true));
 }
}
foreach ([[],[array_replace($plan,['status'=>'inactive'])]] as $plans) {
 PGE_Catalog::$plans=$plans;
 check('No Native legacy fallback',PGE_Catalog_Packages_Presenter::build($settings,PGE_Catalog_Packages_Presenter::NATIVE),[]);
 if ($baseline) foreach (['catalog','manual','invalid'] as $source) {
  $widget->test_settings=$baseline->test_settings=array_replace($settings,['data_source'=>$source]);
  ob_start(); call_private($baseline,new ReflectionClass($baseline),'render'); $old=ob_get_clean();
  ob_start(); call_private($widget,$ref,'render'); $new=ob_get_clean();
  check('Fallback HTML: '.$source,$new,$old);
 }
}
echo "Checks: $total; passed: $passed; failed: ".count($failures)."\n";
if (!$baseline) echo "Baseline parity not run; supply original widget path.\n";
exit($failures ? 1 : 0);
}
