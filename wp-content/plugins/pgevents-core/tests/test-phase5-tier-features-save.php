<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ Phase 5 — Commit 3.1: اختبارات
 * منطق حفظ Tier Feature Overrides عبر **تنفيذ حقيقي** لكود الإنتاج، لا نسخ
 * منه.
 *
 * ============================================================================
 * التغيير الجذري عن Commit 3 (الآن مُلغى بالكامل)
 * ============================================================================
 * النسخة السابقة من هذا الملف اقتبست نصياً (byte-for-byte) فرع
 * `update_tier_features` من pge_render_catalog_tiers_page() في
 * pgevents-core.php، ثم شغّلت الاقتباس. تحليل Mutation (8 طفرات افتراضية على
 * pgevents-core.php وحده) أثبت أن 0/8 كانت ستُكتشَف — لأن الاختبار كان يتحقق
 * من نسخة مجمَّدة من المنطق، لا من المنطق الحي نفسه. هذا الملف يستبدل تلك
 * البنية بالكامل.
 *
 * ============================================================================
 * كيف يُنفَّذ كود الإنتاج الحقيقي هنا
 * ============================================================================
 * 1. يُعرَّف حد أدنى من Stubs لدوال ووردبريس (لا منطق عمل فيها — فقط سلوك
 *    عابر: إرجاع القيمة الافتراضية، تسجيل الاستدعاء، أو طباعة نص خام مهمل).
 * 2. يُبنى Fake $wpdb (نفس تصميم Commit 3 — يخدم mon_plans/mon_plan_tiers/
 *    mon_tier_features بمحاكاة كافية لأشكال SQL الصادرة عن class-pge-catalog.php
 *    وclass-pge-tier-features.php الحقيقيين، بما فيها قيد UNIQUE
 *    (tier_id, feature_key) عبر last_error).
 * 3. يُحمَّل **pgevents-core.php كاملاً** عبر require_once واحد فقط — لا تحميل
 *    انتقائي لثلاثة ملفات كما في Commit 3. هذا يضمن أن `pge_render_catalog_tiers_page()`
 *    المُستدعاة أدناه هي **نفس الدالة الحقيقية المُعرَّفة في ملف الإنتاج**، لا
 *    نسخة منها. تدقيق شامل (موثَّق في تقرير التنفيذ) تحقّق أن كل الأسطر ذات
 *    المستوى الأعلى (Top-Level) في كل الملفات الـ~21 التي يستدعيها
 *    pgevents-core.php عبر require_once/include_once هي: حراسات
 *    ABSPATH/function_exists/class_exists، أو تسجيلات Hooks خاملة
 *    (add_action/add_filter/register_activation_hook/register_deactivation_hook/
 *    add_shortcode — كلها No-Op عبر Stubs أدناه، فلا شيء منها يُستدعى فعلياً)،
 *    أو 3 إنشاءات كائن (new PGE_Admin_Controller()/new Mon_Salla_Handler()/
 *    new Mon_Cartat_Handler()) تم التحقق من أن Constructor كل منها يستدعي فقط
 *    add_action/add_filter/get_option/defined() — آمنة تماماً مع Stubs أدناه.
 * 4. كل سيناريو اختبار يضبط $_SERVER['REQUEST_METHOD']/$_POST الحقيقيين، ثم
 *    يستدعي `pge_render_catalog_tiers_page()` **مباشرة بالاسم** (الدالة
 *    الحقيقية، لا غلاف)، ملفوفة بـ ob_start()/ob_end_clean() **لإهمال أي HTML
 *    مُولَّد بالكامل** — لا فحص لأي نص/وسم HTML في أي مكان في هذا الملف.
 * 5. النتيجة تُقاس حصراً عبر: (أ) حالة قاعدة البيانات المزيَّفة بعد التنفيذ
 *    (tier_features_map() — ما الذي كُتب فعلياً وما لم يُكتب)، (ب) عدد
 *    استدعاءات check_admin_referer() الحقيقية، (ج) ترتيب الأحداث الفعلي
 *    (Nonce قبل أي كتابة Repository أم بعدها) عبر سجل تسلسل مشترك تُغذّيه
 *    نفس الـStubs التي يستدعيها الكود الحقيقي وقت التنفيذ.
 *
 * ============================================================================
 * قيد صادق واحد يجب الإفصاح عنه (لا يُخفى)
 * ============================================================================
 * `$notice_type`/`$notice_message`/`$editing_tier_id` داخل
 * pge_render_catalog_tiers_page() متغيرات محلية لا تُعاد ولا تُكشَف بأي شكل
 * غير HTML المُهمَل عمداً. بما أن هذا الملف يرفض فحص HTML كلياً (تنفيذاً
 * لتوجيه صريح)، فإن أي طفرة تؤثر **فقط** على نص الإشعار دون أي أثر على
 * الكتابة الفعلية في قاعدة البيانات (تحديداً: طفرة "تجاهل فشل Repository في
 * تحديد notice_type فقط، دون تغيير أي سلوك آخر") **لا يمكن اكتشافها عبر حالة
 * قاعدة البيانات وحدها** — لأن الحقل الذي فشلت كتابته فعلياً يبقى غائباً عن
 * الـDB بصرف النظر عن قيمة $notice_type. الجانب الفعلي وذو الدلالة من تلك
 * الطفرة — استمرار معالجة بقية الحقول دون توقف (Best-Effort) — **مُختبَر
 * فعلاً** أدناه (قسم G) عبر حالة قاعدة البيانات. الجانب النصي البحت غير
 * مُختبَر هنا، وهذا مُوثَّق صراحة لا مُخفى.
 *
 * التشغيل:
 *   php tests/test-phase5-tier-features-save.php
 *
 * ============================================================================
 * تحقق فعلي (لا نظري): تشغيل حقيقي + تحليل Mutation منفَّذ فعلاً
 * ============================================================================
 * خلافاً لكل الجولات السابقة من هذا المشروع، أُتيح هنا PHP CLI حقيقي (PHP 8.1)
 * في بيئة التنفيذ. هذا الملف شُغِّل فعلياً (لا فحص AST نظري فقط) وأعطى
 * 34/34 ناجحة مقابل pgevents-core.php الحقيقي دون أي تعديل عليه.
 *
 * إضافة إلى ذلك، أُجري تحليل Mutation **فعلي** (لا افتراضي): نُسخت الإضافة
 * بالكامل إلى نسخة معزولة، وطُبِّقت كل واحدة من الطفرات الثماني المطلوبة على
 * تلك النسخة المعزولة فقط من pgevents-core.php (الملف الحقيقي في المشروع لم
 * يُلمَس إطلاقاً)، ثم شُغِّل هذا الملف نفسه (بلا أي تعديل عليه) ضد كل نسخة
 * مُطفَّرة على حدة. النتيجة الفعلية المُنفَّذة:
 *
 *   Mutation #1 (Boolean غائب يُكتب '1' بدل '0')........... اكتُشفت (B2 فشلت)
 *   Mutation #2 (إزالة فحص اكتمال الطلب)................... اكتُشفت (E1/E2/E3a/E3b فشلت)
 *   Mutation #3 (قبول "+1" في Integer)..................... اكتُشفت (C5 فشلت)
 *   Mutation #4 (إعادة Fallback رقمي لنوع غير معروف)....... اكتُشفت (F1/F2 فشلتا، عبر حقن Reflection)
 *   Mutation #5 (تجاهل فشل Repository في notice فقط)....... **لم تُكتشَف** (0 فشل) — كما هو مُفصَح عنه أدناه
 *   Mutation #6 (حلقة على POST بدل Registry)............... اكتُشفت (A1/A2/B2 فشلت)
 *   Mutation #7 (إزالة check_admin_referer())............... اكتُشفت (H1/H2a/H2c فشلت)
 *   Mutation #8 (نقل التحقق من Nonce بعد الكتابة)........... اكتُشفت (H2c فشلت)
 *
 * أي 7 من 8 طفرات اكتُشفت فعلياً وتلقائياً بتشغيل هذا الملف بلا أي تعديل
 * عليه، لمجرد تعديل pgevents-core.php وحده في نسخة معزولة. الطفرة الثامنة
 * (#5) لم تُكتشَف للسبب المشروح أعلاه بالتفصيل (تغيّر يقتصر أثره على نص
 * الإشعار المحلي ($notice_type/$notice_message)، وهو مُهمَل عمداً بالكامل
 * هنا) — هذا سلوك متوقَّع ومُفصَح عنه مسبقاً في هذا الملف، لا اكتشاف مفاجئ.
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ════════════════════════════════════════════════════════════════════════
// القسم 1 — Stubs ووردبريس (لا منطق عمل — سلوك عابر فقط)
// ════════════════════════════════════════════════════════════════════════

define('ABSPATH', __DIR__ . '/');

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── تحميل الإضافة (plugin_dir_path/plugin_dir_url يجب أن تُعرَّف قبل
// require_once pgevents-core.php لأنها تُستدعى فوراً عند بداية ذلك الملف
// لتعريف PGE_URL/PGE_PATH). ─────────────────────────────────────────────
function plugin_dir_path($file)
{
    return rtrim(dirname($file), '/\\') . '/';
}

function plugin_dir_url($file)
{
    return '';
}

// ── تسجيل Hooks: كلها No-Op عمداً. هذا يعني أن pgevents-core.php بالكامل
// يُحمَّل (تعريفات فقط) دون أن يُنفَّذ أي Callback مسجَّل تلقائياً — الطريقة
// الوحيدة لتشغيل أي منطق هي استدعاء الدالة المطلوبة بالاسم صراحة، كما تفعل
// كل سيناريوهات هذا الملف أدناه مع pge_render_catalog_tiers_page(). ──────
function add_action(...$args) {}
function add_filter(...$args) {}
function add_shortcode(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}

// ── get_option(): تُستخدَم مرة واحدة عند تحميل pgevents-core.php لاختيار
// مزوّد واتساب (افتراضياً 'cartat' — يطابق تماماً سلوك get_option() الحقيقية
// عند عدم وجود قيمة محفوظة). ─────────────────────────────────────────────
$GLOBALS['__test_options'] = [];
function get_option($name, $default = false)
{
    return $GLOBALS['__test_options'][$name] ?? $default;
}

// ── دوال عامة مستخدَمة فعلياً داخل pge_render_catalog_tiers_page() (مؤكَّدة
// عبر تدقيق كامل لنطاق السطور 81-1279 في pgevents-core.php). ─────────────

function absint($value)
{
    return abs((int) $value);
}

function wp_unslash($value)
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }
    return is_string($value) ? stripslashes($value) : $value;
}

function current_time($type = 'mysql', $gmt = 0)
{
    return '2026-01-01 00:00:00';
}

function sanitize_text_field($value)
{
    return trim((string) $value);
}

function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function esc_url_raw($url, $protocols = null)
{
    return (string) $url;
}

function current_user_can($cap)
{
    return true;
}

class Pge_Test_Wp_Die_Exception extends Exception
{
}

function wp_die($message = '', $title = '', $args = [])
{
    throw new Pge_Test_Wp_Die_Exception(is_string($message) ? $message : 'wp_die');
}

// ── دوال طباعة/تهريب HTML: القيمة المُعادة/المطبوعة **مُهمَلة بالكامل** في
// كل هذا الملف (كل استدعاء لـpge_render_catalog_tiers_page() مُغلَّف بـ
// ob_start()/ob_end_clean()) — هذه الـStubs موجودة فقط لمنع Fatal Error
// (Call to undefined function) أثناء تنفيذ الدالة الحقيقية، لا لإنتاج HTML
// صحيح أو قابل للفحص. ────────────────────────────────────────────────────

function esc_html($text)
{
    return (string) $text;
}

function esc_attr($text)
{
    return (string) $text;
}

function esc_url($url, $protocols = null)
{
    return (string) $url;
}

function esc_html__($text, $domain = 'default')
{
    return (string) $text;
}

function esc_html_e($text, $domain = 'default')
{
    echo $text;
}

function selected($a, $b = true, $echo = true)
{
    $r = ((string) $a === (string) $b) ? ' selected="selected"' : '';
    if ($echo) {
        echo $r;
    }
    return $r;
}

function checked($a, $b = true, $echo = true)
{
    $r = ((string) $a === (string) $b) ? ' checked="checked"' : '';
    if ($echo) {
        echo $r;
    }
    return $r;
}

function submit_button(...$args)
{
    // No-Op عمداً — الناتج HTML مُهمَل بالكامل.
}

function wp_nonce_field(...$args)
{
    // No-Op عمداً — لا تحقق هنا من Nonce الحقيقي، ذلك عمل check_admin_referer()
    // أدناه (وهي، خلافاً لهذه، تُسجَّل وتُستخدَم فعلياً في الاختبار).
}

function add_query_arg(...$args)
{
    return '';
}

function admin_url($path = '')
{
    return '';
}

// ── check_admin_referer(): الدالة الوحيدة من بين كل الـStubs التي تحمل قيمة
// اختبارية حقيقية — تُسجِّل كل استدعاء (Action + ترتيبه الزمني الفعلي نسبةً
// لعمليات الكتابة في $wpdb أدناه) لأن الكود الحقيقي هو من يقرر متى يستدعيها،
// لا هذا الاختبار. ────────────────────────────────────────────────────────

$GLOBALS['__test_check_admin_referer_calls'] = [];
$GLOBALS['__test_sequence'] = [];

function __pge_test_record_sequence($type, $detail = null)
{
    $GLOBALS['__test_sequence'][] = ['type' => $type, 'detail' => $detail];
}

function check_admin_referer($action = -1, $query_arg = '_wpnonce')
{
    $GLOBALS['__test_check_admin_referer_calls'][] = ['action' => $action, 'query_arg' => $query_arg];
    __pge_test_record_sequence('nonce', $action);
    return true;
}

// ════════════════════════════════════════════════════════════════════════
// القسم 2 — Fake $wpdb (يخدم mon_plans/mon_plan_tiers/mon_tier_features)
// ════════════════════════════════════════════════════════════════════════
//
// نفس تصميم Commit 3 حرفياً (لم يتغيّر — هذا الجزء لم يكن موضع الاعتراض؛
// الاعتراض كان على *كيفية استدعاء* منطق الحفظ، لا على محاكاة قاعدة البيانات
// نفسها). الإضافة الوحيدة هنا: insert()/update() على جدول mon_tier_features
// تُسجِّل الآن حدثاً في __test_sequence لإتاحة اختبار ترتيب Nonce-قبل-الكتابة
// على تنفيذ حقيقي (انظر قسم "ترتيب التنفيذ" أدناه).

class Fake_Wpdb_Phase5Save
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $plans = [];
    public $tiers = [];
    public $tier_features = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $tier_features_next_id = 1;

    /** @var string|null إن ضُبِط، أي insert()/update() على mon_tier_features لهذا feature_key تحديداً يفشل (يحاكي فشل Repository — قسم G). */
    public $force_write_failure_for_feature_key = null;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? '';
            $i++;
            if ($m[0] === '%d') {
                return (string) (int) $val;
            }
            return "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    public function get_charset_collate()
    {
        return '';
    }

    private function which_table($sql_or_table)
    {
        if (strpos($sql_or_table, $this->prefix . 'mon_tier_features') !== false) {
            return 'tier_features';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plan_tiers') !== false) {
            return 'tiers';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plans') !== false) {
            return 'plans';
        }
        return null;
    }

    private function &store_for($which)
    {
        if ($which === 'tiers') {
            return $this->tiers;
        }
        if ($which === 'plans') {
            return $this->plans;
        }
        return $this->tier_features;
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        $this->last_error = '';
        $which = $this->which_table($sql);
        if ($which === null) {
            return [];
        }

        $rows = array_values($this->store_for($which));

        if (preg_match('/WHERE\s+(.+)$/is', $sql, $m)) {
            $where = trim($m[1]);
            $where = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $where);
            $conditions = preg_split('/\bAND\b/i', $where);
            foreach ($conditions as $cond) {
                $cond = trim($cond);
                if ($cond === '') {
                    continue;
                }
                if (preg_match("/^(\\w+)\\s*=\\s*'([^']*)'$/", $cond, $cm)) {
                    $field = $cm[1];
                    $value = $cm[2];
                } elseif (preg_match('/^(\\w+)\\s*=\\s*(-?\\d+)$/', $cond, $cm)) {
                    $field = $cm[1];
                    $value = $cm[2];
                } else {
                    continue;
                }
                $rows = array_values(array_filter($rows, function ($r) use ($field, $value) {
                    return array_key_exists($field, $r) && (string) $r[$field] === (string) $value;
                }));
            }
        }

        return $rows;
    }

    public function insert($table, $data, $format = null)
    {
        $this->last_error = '';
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }

        if ($which === 'tier_features') {
            __pge_test_record_sequence('write', $data['feature_key'] ?? null);

            if (
                $this->force_write_failure_for_feature_key !== null
                && ($data['feature_key'] ?? null) === $this->force_write_failure_for_feature_key
            ) {
                $this->last_error = 'Simulated write failure (Section G)';
                return false;
            }

            foreach ($this->tier_features as $row) {
                if ((int) $row['tier_id'] === (int) $data['tier_id'] && $row['feature_key'] === $data['feature_key']) {
                    $this->last_error = "Duplicate entry '{$data['tier_id']}-{$data['feature_key']}' for key 'tier_feature'";
                    return false;
                }
            }

            $id = $this->tier_features_next_id++;
            $this->tier_features[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }

        if ($which === 'tiers') {
            $id = $this->tiers_next_id++;
            $this->tiers[$id] = array_merge(['id' => $id], $data);
        } else {
            $id = $this->plans_next_id++;
            $this->plans[$id] = array_merge(['id' => $id], $data);
        }
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $this->last_error = '';
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }

        if ($which === 'tier_features') {
            __pge_test_record_sequence('write', $where['feature_key'] ?? null);

            if (
                $this->force_write_failure_for_feature_key !== null
                && ($where['feature_key'] ?? null) === $this->force_write_failure_for_feature_key
            ) {
                $this->last_error = 'Simulated write failure (Section G)';
                return false;
            }
        }

        $store_key = $which === 'tiers' ? 'tiers' : ($which === 'plans' ? 'plans' : 'tier_features');
        $matched = 0;
        foreach ($this->{$store_key} as $id => $row) {
            $ok = true;
            foreach ($where as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                foreach ($data as $k => $v) {
                    $this->{$store_key}[$id][$k] = $v;
                }
                $matched++;
            }
        }

        return $matched;
    }

    // ── مساعدات اختبار فقط: بذر Plan/Tier مباشرة. ─────────────────────────

    public function seed_plan($id, array $row)
    {
        $this->plans[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->plans_next_id) {
            $this->plans_next_id = $id + 1;
        }
    }

    public function seed_tier($id, array $row)
    {
        $this->tiers[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->tiers_next_id) {
            $this->tiers_next_id = $id + 1;
        }
    }

    /** مساعد اختبار: كل صفوف mon_tier_features المخزَّنة حالياً لـtier_id محدَّد، كخريطة feature_key => feature_value. */
    public function tier_features_map($tier_id)
    {
        $out = [];
        foreach ($this->tier_features as $row) {
            if ((int) $row['tier_id'] === (int) $tier_id) {
                $out[$row['feature_key']] = $row['feature_value'];
            }
        }
        return $out;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Phase5Save();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ════════════════════════════════════════════════════════════════════════
// القسم 3 — تحميل الإضافة **بالكامل** (ملف واحد فقط: pgevents-core.php)
// ════════════════════════════════════════════════════════════════════════
//
// هذا هو الفرق الجوهري عن Commit 3: لا تحميل انتقائي لثلاثة ملفات — تحميل
// pgevents-core.php نفسه، الذي يستدعي بدوره كل الملفات الحقيقية (بما فيها
// class-pge-catalog.php وclass-pge-feature-registry.php وclass-pge-tier-features.php)
// عبر require_once الحقيقية بداخله. `pge_render_catalog_tiers_page()`
// المُعرَّفة بعد هذا السطر هي **نفس الدالة الحقيقية بلا أي نسخ أو تعديل**.

require_once __DIR__ . '/../pgevents-core.php';

// ════════════════════════════════════════════════════════════════════════
// القسم 4 — أدوات الاختبار (نفس نمط check()/check_true() في بقية tests/)
// ════════════════════════════════════════════════════════════════════════

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

// ════════════════════════════════════════════════════════════════════════
// القسم 5 — تنفيذ الفرع الحقيقي (لا غلاف، لا نسخ — استدعاء مباشر بالاسم)
// ════════════════════════════════════════════════════════════════════════

/**
 * تُشغِّل pge_render_catalog_tiers_page() **الحقيقية** اعتماداً على $_POST/
 * $_SERVER المضبوطة مسبقاً من قِبَل المُستدعي. الناتج HTML يُهمَل بالكامل
 * (ob_end_clean()) — لا فحص لأي جزء منه في أي مكان في هذا الملف. النتيجة
 * الوحيدة المُعادة هنا معلومة عن حدوث wp_die() غير متوقَّع (Fatal Guard)،
 * لا أي شيء متعلّق بمنطق الحفظ نفسه — ذلك يُقاس لاحقاً عبر $wpdb فقط.
 */
function pge_test_execute_real_save_branch()
{
    $GLOBALS['__test_sequence'] = [];
    $_GET = [];

    ob_start();
    try {
        pge_render_catalog_tiers_page();
    } catch (Pge_Test_Wp_Die_Exception $e) {
        ob_end_clean();
        return ['wp_die' => true, 'wp_die_message' => $e->getMessage()];
    }
    ob_end_clean();

    return ['wp_die' => false];
}

/** كل مفاتيح Registry الحقيقية من نوع boolean (عبر PGE_Feature_Registry::all() الحقيقية). */
function pge_test_all_boolean_keys()
{
    $out = [];
    foreach (PGE_Feature_Registry::all() as $key => $def) {
        if ($def['type'] === 'boolean') {
            $out[] = $key;
        }
    }
    return $out;
}

/** كل مفاتيح Registry الحقيقية من نوع integer أو percentage. */
function pge_test_all_numeric_keys()
{
    $out = [];
    foreach (PGE_Feature_Registry::all() as $key => $def) {
        if ($def['type'] === 'integer' || $def['type'] === 'percentage') {
            $out[] = $key;
        }
    }
    return $out;
}

/** طلب tier_features كامل وصحيح: كل الـboolean محدَّدة ('1')، كل الأرقام = '5'. */
function pge_test_full_valid_tier_features()
{
    $out = [];
    foreach (pge_test_all_boolean_keys() as $key) {
        $out[$key] = '1';
    }
    foreach (pge_test_all_numeric_keys() as $key) {
        $out[$key] = '5';
    }
    return $out;
}

function pge_test_reset_request()
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'pge_catalog_action'              => 'update_tier_features',
        'submit_update_tier_features'     => '1',
        'pge_catalog_tier_features_nonce' => 'dummy-nonce-value',
    ];
}

echo "=== Phase 5 — Commit 3.1: تنفيذ حقيقي لفرع حفظ ميزات المستوى ===\n";

// ── تهيئة Plan/Tier مشترَكة لكل الاختبارات (Plan #1) ────────────────────

$wpdb->seed_plan(1, [
    'plan_key' => 'test_plan', 'name' => 'خطة اختبار', 'plan_type' => 'personal',
    'status' => 'active', 'sort_order' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
]);

$next_tier_id = 1;
function pge_test_seed_tier($plan_id = 1)
{
    global $wpdb, $next_tier_id;
    $tier_id = $next_tier_id++;
    $wpdb->seed_tier($tier_id, [
        'plan_id' => $plan_id, 'tier_key' => 'tier_' . $tier_id, 'name' => 'مستوى اختبار ' . $tier_id,
        'price' => '10.00', 'currency' => 'SAR', 'salla_product_id' => '', 'salla_sku' => '', 'salla_url' => '',
        'status' => 'active', 'sort_order' => 0,
        'invitation_credit_limit' => '0', 'replacement_credit_limit' => '0',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    return $tier_id;
}

// ════════════════════════════════════════════════════════════════════════
echo "\n--- قسم A: قبول مفاتيح Registry فقط، رفض المفاتيح غير المعروفة (Mutation #6) ---\n";
// ════════════════════════════════════════════════════════════════════════

$tier_a = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_a;
$_POST['plan_id'] = '1';
$tf = pge_test_full_valid_tier_features();
$tf['totally_unknown_made_up_key'] = '1'; // مفتاح غير موجود في Registry إطلاقاً
$_POST['tier_features'] = $tf;

$seq_a = pge_test_execute_real_save_branch();
$map_a = $wpdb->tier_features_map($tier_a);

check_true('A0. لا wp_die غير متوقَّع أثناء التنفيذ', !$seq_a['wp_die']);
check('A1. مفتاح غير معروف لا يُكتب في mon_tier_features (لو حلقة الحفظ الحقيقية كررت على $_POST بدل Registry لظهر هنا)', array_key_exists('totally_unknown_made_up_key', $map_a), false);
check('A2. عدد الصفوف المكتوبة = 19 بالضبط (كل مفاتيح Registry الحقيقية، لا أكثر ولا أقل)', count($map_a), count(PGE_Feature_Registry::all()));

// ════════════════════════════════════════════════════════════════════════
echo "\n--- قسم B: Boolean Handling (Mutation #1) ---\n";
// ════════════════════════════════════════════════════════════════════════

$tier_b = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_b;
$_POST['plan_id'] = '1';
$tf = pge_test_full_valid_tier_features();
unset($tf['google_maps']);            // غائب تماماً → '0'
$tf['guest_qr'] = '0';                // موجود بقيمة '0' صراحة → فشل حقلي
$tf['rsvp'] = 'false';                // موجود بقيمة 'false' → فشل حقلي
$tf['attendance_statistics'] = 'yes'; // موجود بقيمة 'yes' → فشل حقلي
$tf['guest_comments'] = ['0' => '1']; // موجود كـArray → فشل حقلي، بلا Warning يوقف التنفيذ
$_POST['tier_features'] = $tf;
// event_website يبقى '1' (Checkbox محدَّد طبيعياً) — Positive Control

pge_test_execute_real_save_branch();
$map_b = $wpdb->tier_features_map($tier_b);

check('B1. Checkbox محدَّد (event_website) يُكتب "1"', $map_b['event_website'] ?? null, '1');
check('B2. Checkbox غائب تماماً (google_maps) يُكتب "0" صراحة — طفرة "غائب=1" كانت ستفشل هنا', $map_b['google_maps'] ?? null, '0');
check('B3. قيمة "0" صريحة موجودة (guest_qr) = فشل حقلي، لا كتابة', array_key_exists('guest_qr', $map_b), false);
check('B4. قيمة "false" (rsvp) = فشل حقلي، لا كتابة', array_key_exists('rsvp', $map_b), false);
check('B5. قيمة "yes" (attendance_statistics) = فشل حقلي، لا كتابة', array_key_exists('attendance_statistics', $map_b), false);
check('B6. قيمة Array (guest_comments) = فشل حقلي، لا كتابة، بلا توقف التنفيذ (بقية الحقول وصلت لاحقاً — انظر B1)', array_key_exists('guest_comments', $map_b), false);

// ════════════════════════════════════════════════════════════════════════
echo "\n--- قسم C: Integer Validation (Mutation #3) ---\n";
// ════════════════════════════════════════════════════════════════════════

$tier_c = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_c;
$_POST['plan_id'] = '1';
$tf = pge_test_full_valid_tier_features();
$tf['host_limit'] = '-3';               // سالب صالح
$tf['admin_supervisor_limit'] = 'abc';  // غير صالح (حروف)
$tf['invitation_design_limit'] = '1.5'; // غير صالح (كسر)
$_POST['tier_features'] = $tf;

pge_test_execute_real_save_branch();
$map_c = $wpdb->tier_features_map($tier_c);

check('C1. عدد صحيح موجب صالح ("5" الافتراضي) يُقبل ويُكتب حرفياً', $map_c['support_services_discount_percentage'] ?? null, '5');
check('C2. عدد سالب صالح ("-3") مقبول (لا فحص نطاق) ويُكتب حرفياً', $map_c['host_limit'] ?? null, '-3');
check('C3. قيمة حرفية "abc" غير صالحة = فشل حقلي، لا كتابة', array_key_exists('admin_supervisor_limit', $map_c), false);
check('C4. قيمة كسرية "1.5" غير صالحة = فشل حقلي، لا كتابة', array_key_exists('invitation_design_limit', $map_c), false);

$tier_c2 = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_c2;
$_POST['plan_id'] = '1';
$tf2 = pge_test_full_valid_tier_features();
$tf2['host_limit'] = '+1';                     // غير صالح (لا + مسموح) — طفرة "allow +1" كانت ستفشل هنا
$tf2['admin_supervisor_limit'] = '0';          // صفر صالح
$tf2['invitation_design_limit'] = '999999999'; // رقم كبير جداً، مقبول (لا فحص نطاق)
$_POST['tier_features'] = $tf2;

pge_test_execute_real_save_branch();
$map_c2 = $wpdb->tier_features_map($tier_c2);

check('C5. علامة "+" البادئة مرفوضة ("+1")، لا كتابة (Mutation #3 المحدَّدة)', array_key_exists('host_limit', $map_c2), false);
check('C6. الصفر "0" قيمة صالحة، يُكتب', $map_c2['admin_supervisor_limit'] ?? null, '0');
check('C7. رقم كبير جداً "999999999" مقبول بلا فحص نطاق، يُكتب حرفياً', $map_c2['invitation_design_limit'] ?? null, '999999999');

// ════════════════════════════════════════════════════════════════════════
echo "\n--- قسم D: Percentage — نفس آلية Integer تماماً ---\n";
// ════════════════════════════════════════════════════════════════════════

$tier_d = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_d;
$_POST['plan_id'] = '1';
$tf = pge_test_full_valid_tier_features();
$tf['support_services_discount_percentage'] = '150'; // خارج 0-100 منطقياً، لكن لا فحص نطاق
$_POST['tier_features'] = $tf;

pge_test_execute_real_save_branch();
$map_d = $wpdb->tier_features_map($tier_d);

check('D1. Percentage خارج 0-100 ("150") مقبول بلا فحص نطاق، يُكتب حرفياً', $map_d['support_services_discount_percentage'] ?? null, '150');

$tier_d2 = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_d2;
$_POST['plan_id'] = '1';
$tf2 = pge_test_full_valid_tier_features();
$tf2['support_services_discount_percentage'] = 'abc'; // نفس رفض integer تماماً
$_POST['tier_features'] = $tf2;

pge_test_execute_real_save_branch();
$map_d2 = $wpdb->tier_features_map($tier_d2);

check('D2. Percentage بصيغة غير صحيحة ("abc") مرفوض تماماً كـinteger، لا كتابة', array_key_exists('support_services_discount_percentage', $map_d2), false);

// ════════════════════════════════════════════════════════════════════════
echo "\n--- قسم E: اكتمال الطلب (Mutation #2) — صفر كتابات عند النقص، عبر حالة DB فقط ---\n";
// ════════════════════════════════════════════════════════════════════════

// E1: tier_features غائبة تماماً.
$tier_e1 = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_e1;
$_POST['plan_id'] = '1';
// لا $_POST['tier_features'] إطلاقاً.

pge_test_execute_real_save_branch();
$map_e1 = $wpdb->tier_features_map($tier_e1);

check('E1. tier_features غائبة تماماً → صفر كتابات (لو فحص الاكتمال أُزيل لظهرت كتابات هنا — Mutation #2)', count($map_e1), 0);

// E2: tier_features موجودة لكنها ليست Array (سلسلة نصية).
$tier_e2 = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_e2;
$_POST['plan_id'] = '1';
$_POST['tier_features'] = 'not-an-array-string';

pge_test_execute_real_save_branch();
$map_e2 = $wpdb->tier_features_map($tier_e2);

check('E2. tier_features ليست Array → صفر كتابات', count($map_e2), 0);

// E3: tier_features Array كاملة البوليان لكن ناقصة مفتاحاً رقمياً واحداً فقط.
$tier_e3 = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_e3;
$_POST['plan_id'] = '1';
$tf = pge_test_full_valid_tier_features();
unset($tf['host_limit']); // مفتاح رقمي واحد ناقص فقط — بقية الطلب سليم تماماً
$_POST['tier_features'] = $tf;

pge_test_execute_real_save_branch();
$map_e3 = $wpdb->tier_features_map($tier_e3);

check('E3a. غياب مفتاح رقمي واحد فقط (host_limit) → صفر كتابات لكل الميزات', count($map_e3), 0);
check('E3b. حتى المفاتيح البوليانية السليمة تماماً لم تُكتب (لا Partial Save عند نقص الطلب)', array_key_exists('event_website', $map_e3), false);

// E4: طلب كامل (Positive Control) — يجب أن يُعالَج طبيعياً بلا اعتراض اكتمال.
$tier_e4 = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_e4;
$_POST['plan_id'] = '1';
$_POST['tier_features'] = pge_test_full_valid_tier_features();

pge_test_execute_real_save_branch();
$map_e4 = $wpdb->tier_features_map($tier_e4);

check('E4. طلب كامل يُعالَج طبيعياً بلا اعتراض اكتمال — كل المفاتيح كُتبت', count($map_e4), count(PGE_Feature_Registry::all()));

// ════════════════════════════════════════════════════════════════════════
echo "\n--- قسم F: نوع Registry غير معروف (Mutation #4) — عبر حقن مؤقَّت وقابل للعكس في Registry الحقيقية ---\n";
// ════════════════════════════════════════════════════════════════════════
//
// PGE_Feature_Registry الحقيقية اليوم تُعرِّف 3 أنواع فقط (boolean/integer/
// percentage) — لا وجود لمفتاح من نوع غير معروف يمكن الوصول إليه طبيعياً عبر
// طلب POST حقيقي. لاختبار الفرع "رفض نوع غير معروف" الحقيقي داخل
// pge_render_catalog_tiers_page() (لا نسخة منه) دون تعديل ملف الإنتاج على
// الإطلاق، نستخدم Reflection لحقن مفتاح اصطناعي واحد **مؤقتاً** داخل ذاكرة
// التخزين المؤقت الساكنة (`private static $features`) لكلاس Registry الحقيقي
// نفسه في وقت التشغيل فقط — لا تعديل على أي ملف على القرص، ولا نسخ لأي منطق
// إنتاجي. Registry::all() الحقيقية (نفس الطريقة الحقيقية) هي من تُعيد هذه
// البيانات المحقونة لأي مستهلِك بما فيه pge_render_catalog_tiers_page() نفسها
// أثناء هذا السيناريو فقط. تُعاد الذاكرة المؤقتة إلى null فوراً بعد
// السيناريو (نفس تقنية Reflection) لتُجبَر إعادة البناء من المصفوفة الحقيقية
// الثابتة في features() لبقية اختبارات هذا الملف — لا تلوّث دائم.

$registry_reflection = new ReflectionClass('PGE_Feature_Registry');
$features_property = $registry_reflection->getProperty('features');
$features_property->setAccessible(true);

$real_features_snapshot = PGE_Feature_Registry::all(); // يبني الكاش الحقيقي أولاً إن لم يكن مبنياً
$synthetic_features = $real_features_snapshot;
$synthetic_features['synthetic_unknown_feature'] = [
    'key' => 'synthetic_unknown_feature',
    'type' => 'enum', // نوع لا تعرفه الحلقة الحقيقية (boolean/integer/percentage فقط)
    'default' => 'x',
];
$features_property->setValue(null, $synthetic_features);

$tier_f = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_f;
$_POST['plan_id'] = '1';
$tf = pge_test_full_valid_tier_features();
$tf['synthetic_unknown_feature'] = '42'; // قيمة شكلياً صالحة كعدد — يجب أن تُرفَض رغم ذلك لأن النوع نفسه غير معروف
$_POST['tier_features'] = $tf;

pge_test_execute_real_save_branch();
$map_f = $wpdb->tier_features_map($tier_f);

// إعادة الكاش الحقيقي إلى حالته الأصلية فوراً — الاختبارات اللاحقة كلها تتعامل
// مع Registry الحقيقية غير المعدَّلة (19 ميزة فقط).
$features_property->setValue(null, null);

check('F1. نوع Registry غير معروف يُرفَض حتى مع قيمة شكلياً صالحة كعدد — لا كتابة (لو الفرع "else" الحقيقي أُعيد كـFallback رقمي صامت لظهرت كتابة هنا)', array_key_exists('synthetic_unknown_feature', $map_f), false);
check('F2. بقية المفاتيح الحقيقية (19) كُتبت طبيعياً رغم وجود مفتاح Registry إضافي غير معروف (لا توقف للتنفيذ)', count($map_f), count($real_features_snapshot));
check_true('F3. Registry الحقيقية عادت لحالتها الأصلية بعد الحقن المؤقت (19 ميزة بالضبط، بلا أثر للحقن)', PGE_Feature_Registry::all() === $real_features_snapshot);

// ════════════════════════════════════════════════════════════════════════
echo "\n--- قسم G: فشل Repository — استمرار بلا Rollback (Mutation #5، الجانب المُلاحَظ عبر DB) ---\n";
// ════════════════════════════════════════════════════════════════════════

$tier_g = pge_test_seed_tier();
$wpdb->force_write_failure_for_feature_key = 'rsvp'; // فشل كتابة مصطنَع لحقل واحد فقط

pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_g;
$_POST['plan_id'] = '1';
$_POST['tier_features'] = pge_test_full_valid_tier_features();

pge_test_execute_real_save_branch();
$wpdb->force_write_failure_for_feature_key = null; // إلغاء الفرض فوراً بعد الاستخدام
$map_g = $wpdb->tier_features_map($tier_g);

check('G1. فشل Repository لحقل واحد (rsvp) لا يمنع كتابة الحقول الأخرى (لا Rollback، لا توقف مبكر)', count($map_g), count(PGE_Feature_Registry::all()) - 1);
check('G2. الحقل الذي فشلت كتابته فعلياً غير موجود', array_key_exists('rsvp', $map_g), false);
check('G3. حقل قبل الفاشل بترتيب Registry (event_website) كُتب فعلياً', $map_g['event_website'] ?? null, '1');
check('G4. حقل بعد الفاشل بترتيب Registry (attendance_statistics) كُتب فعلياً أيضاً — تأكيد أن المعالجة استمرت ولم تتوقف عند أول فشل', $map_g['attendance_statistics'] ?? null, '1');

// ════════════════════════════════════════════════════════════════════════
echo "\n--- قسم H: التحقق من Nonce — الاستدعاء والترتيب (Mutation #7 وMutation #8)، على تنفيذ حقيقي ---\n";
// ════════════════════════════════════════════════════════════════════════

// H1: check_admin_referer() استُدعيت بالفعل مرة واحدة تماماً لكل سيناريو POST
// نُفِّذ أعلاه حتى هذه النقطة (A، B، C×2، D×2، E×4، F، G = 12 سيناريو —
// سيناريو H نفسه أدناه يُضيف الاستدعاء الثالث عشر بعد هذا التحقق). لو الكود
// الحقيقي أزال استدعاء check_admin_referer() بالكامل (Mutation #7)، هذا
// العدّاد يبقى أقل من 12 ولا يرتفع أبداً — لأن check_admin_referer() (Stub)
// لا تُستدعى إلا من داخل الكود الحقيقي نفسه وقت التنفيذ.
check('H1. check_admin_referer() الحقيقية استُدعيت 12 مرة بالضبط عبر كل سيناريوهات هذا الملف حتى الآن', count($GLOBALS['__test_check_admin_referer_calls']), 12);

// H2: التحقق من ترتيب التنفيذ الفعلي لسيناريو جديد مستقل: يجب أن يحدث حدث
// 'nonce' واحد **قبل** أي حدث 'write' على mon_tier_features. هذا يقيس ترتيب
// التنفيذ الحقيقي للكود الحقيقي وقت هذا الاستدعاء بالذات — لو Mutation #8
// (نقل check_admin_referer() إلى ما بعد حلقة الكتابة) طُبِّقت على
// pgevents-core.php وحده، سيظهر حدث 'write' واحد على الأقل **قبل** أول حدث
// 'nonce' في هذا السجل، فيفشل هذا التحقق تلقائياً بلا أي تعديل على هذا
// الملف.
$tier_h = pge_test_seed_tier();
pge_test_reset_request();
$_POST['tier_id'] = (string) $tier_h;
$_POST['plan_id'] = '1';
$_POST['tier_features'] = pge_test_full_valid_tier_features();

pge_test_execute_real_save_branch();
$sequence_h = $GLOBALS['__test_sequence'];

$first_nonce_index = null;
$first_write_index = null;
foreach ($sequence_h as $index => $event) {
    if ($event['type'] === 'nonce' && $first_nonce_index === null) {
        $first_nonce_index = $index;
    }
    if ($event['type'] === 'write' && $first_write_index === null) {
        $first_write_index = $index;
    }
}

check_true('H2a. حدث Nonce واحد على الأقل سُجِّل فعلياً لهذا السيناريو', $first_nonce_index !== null);
check_true('H2b. حدث كتابة واحد على الأقل سُجِّل فعلياً لهذا السيناريو (طلب كامل صحيح)', $first_write_index !== null);
check_true('H2c. أول حدث Nonce سبق أول حدث كتابة فعلياً أثناء هذا التنفيذ الحقيقي', $first_nonce_index !== null && $first_write_index !== null && $first_nonce_index < $first_write_index);

// ── الخلاصة ──────────────────────────────────────────────────────────────

echo "\n=== النتيجة: $passed / $total ناجحة ===\n";

if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

exit(0);
