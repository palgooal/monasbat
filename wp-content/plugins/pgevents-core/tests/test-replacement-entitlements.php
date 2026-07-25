<?php
/**
 * ============================================================================
 * اختبارات ذاتية الاكتفاء — PGE_Replacement_Entitlements (المرحلة 4A)
 * ============================================================================
 * لا PHPUnit. Fake $wpdb واحد يحاكي جدولين معاً: mon_invitation_credit_ledger
 * (تُزرَع صفوفه مباشرة عبر seed_ledger_row() — لا تُستدعى أي دالة من
 * PGE_Invitation_Credit_Ledger لإنشائها؛ هذا الملف يختبر PGE_Replacement_
 * Entitlements فقط، لا يعيد اختبار آلة حالة الـLedger المُغطاة بالكامل في
 * test-invitation-credit-ledger.php) وmon_replacement_entitlements (الجدول
 * الحقيقي محل الاختبار، عبر الكلاس الحقيقي PGE_Replacement_Entitlements).
 *
 * تحذير بيئي: لا مفسّر PHP CLI متاح في بيئة التطوير الحالية (لا صلاحيات
 * root/sudo لتثبيته). التحقق من هذا الملف تم عبر فاحص AST (php-parser عبر
 * Node.js) للتأكد من خلوّه من أخطاء صياغية، بالإضافة إلى تتبّع يدوي دقيق
 * لمنطق كل اختبار مقابل الكود الفعلي في class-pge-replacement-entitlements.php
 * — وليس تنفيذاً حقيقياً. راجع التقرير النهائي المرفق لتفاصيل هذا القيد.
 */

// ══════════════════════════════════════════════════════════════════════════
// Bootstrap بيئة الاختبار — يجب أن يكتمل بالكامل **قبل** أي require_once لأي
// ملف حقيقي من المشروع.
//
// السبب الجذري الفعلي (Fatal: "Call to undefined function
// register_activation_hook()"): class-mon-catalog-schema.php ليس مجرّد
// تعريف class — آخر سطرين فيه (خارج أي دالة/كلاس، كود على مستوى الملف
// يُنفَّذ فوراً عند require_once، لا عند استدعاء أي method لاحقاً) هما:
//   register_activation_hook(PGE_PATH . 'pgevents-core.php', ['Mon_Catalog_Schema', 'maybe_upgrade']);
//   add_action('plugins_loaded', ['Mon_Catalog_Schema', 'maybe_upgrade']);
// أي تحميل لهذا الملف — بصرف النظر عن ABSPATH أو أي شيء آخر يخصّ محتوى
// الكلاس نفسه — يستدعي هاتين الدالتين فوراً. register_activation_hook()
// تحديداً لم تكن مُعرَّفة إطلاقاً في بيئة هذا الاختبار (على خلاف add_action،
// المُعرَّفة عادة في ملفات اختبار أخرى بالمشروع لكنها غائبة هنا أيضاً)، فيفشل
// التحميل قبل الوصول لأي سطر اختبار فعلي. هذا **لا علاقة له بـABSPATH** —
// إصلاح ترتيب ABSPATH وحده (كما جرى سابقاً) لا يمسّ هذا السبب إطلاقاً، ولهذا
// لم ينجح.
//
// PGE_PATH مطلوب أيضاً كوسيط أول لـregister_activation_hook() أعلاه — يُعرَّف
// هنا بقيمة وهمية آمنة (لا يُستخدَم فعلياً لأي عملية حقيقية داخل هذا الاختبار،
// فالدالة نفسها Stub بلا تنفيذ).
// ══════════════════════════════════════════════════════════════════════════

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('PGE_PATH')) {
    define('PGE_PATH', __DIR__ . '/../');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Stubs الحدّ الأدنى المُثبَت فعلياً بالتتبّع أنها مطلوبة لمجرّد require_once
// الناجح لـclass-mon-catalog-schema.php (الكود على مستوى الملف في نهايته) —
// لا شيء إضافي غيرهما.
if (!function_exists('register_activation_hook')) {
    function register_activation_hook(...$args) { /* no-op */ }
}
if (!function_exists('add_action')) {
    function add_action(...$args) { /* no-op */ }
}

// ── تجاوز current_time() قابل للتحكم من داخل الاختبارات (غير مستخدم فعلياً
// في هذه المرحلة لأن granted_at/consumed_at/updated_at لا تعتمد على مهلة
// زمنية أو Lease هنا، لكن مُضاف للاتساق مع نمط ملفي الاختبار الآخرين) ──
$GLOBALS['__test_now_override'] = null;
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0)
    {
        return $GLOBALS['__test_now_override'] ?? '2026-01-01 00:00:00';
    }
}

if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v)
    {
        return preg_replace('/\D+/', '', trim((string) $v));
    }
}

/**
 * ============================================================================
 * Fake $wpdb — يحاكي جدولي mon_invitation_credit_ledger (قراءة فقط، تُزرَع
 * صفوفه مباشرة) وmon_replacement_entitlements (الجدول الحقيقي محل الاختبار).
 * ============================================================================
 */
class Fake_Wpdb_Replacement
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    /** @var array<int,string>|null تجاوز اختياري لأعمدة SHOW COLUMNS */
    public $show_columns_override = null;
    /** @var array<int,array>|null تجاوز اختياري لصفوف SHOW INDEX */
    public $show_index_override = null;
    /** @var bool إجبار فشل INSERT التالي على جدول entitlements */
    public $force_entitlement_insert_failure = false;

    private $ledger_rows = [];
    private $entitlement_rows = [];
    private $entitlement_unique_index = []; // "cycle|event|phone" => id

    private $ledger_next_id = 1;
    private $entitlement_next_id = 1;

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
        if (strpos($sql_or_table, $this->prefix . 'mon_replacement_entitlements') !== false) {
            return 'entitlements';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_invitation_credit_ledger') !== false) {
            return 'ledger';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_var($sql)
    {
        if (stripos(ltrim($sql), 'SELECT COUNT(*)') === 0) {
            $which = $this->which_table($sql);
            if ($which === null) {
                return null;
            }
            $rows = array_values($which === 'entitlements' ? $this->entitlement_rows : $this->ledger_rows);
            $filtered = $this->apply_where($rows, $sql);
            return (string) count($filtered);
        }

        return null;
    }

    public function get_results($sql, $output = null)
    {
        if (stripos($sql, 'SHOW COLUMNS FROM') === 0) {
            $columns = $this->show_columns_override ?? [
                'id', 'user_id', 'credit_cycle_id', 'event_id', 'source_guest_phone',
                'source_ledger_id', 'status', 'consumed_by_ledger_id', 'granted_at',
                'consumed_at', 'created_at', 'updated_at',
            ];
            $out = [];
            foreach ($columns as $field) {
                $out[] = ['Field' => $field];
            }
            return $out;
        }

        if (stripos($sql, 'SHOW INDEX FROM') === 0) {
            if ($this->show_index_override !== null) {
                return $this->show_index_override;
            }
            $out = [];
            $seq = 1;
            foreach (['credit_cycle_id', 'event_id', 'source_guest_phone'] as $col) {
                $out[] = ['Key_name' => 'unique_replacement_entitlement', 'Column_name' => $col, 'Seq_in_index' => $seq++];
            }
            return $out;
        }

        $which = $this->which_table($sql);
        if ($which === null) {
            return [];
        }

        $rows = array_values($which === 'entitlements' ? $this->entitlement_rows : $this->ledger_rows);
        return $this->apply_where($rows, $sql);
    }

    /**
     * نفس نسخة apply_where() المُصحَّحة في test-invitation-credit-ledger.php
     * (إصلاح خلل استخراج WHERE عند وجود كلمة "LIMIT" داخل قيمة نصية حرفية):
     * تُزال جملة LIMIT الحقيقية في نهاية الاستعلام فقط (عبر regex مرساة بنهاية
     * السلسلة) قبل استخراج WHERE، بدل البحث عن أول ظهور لكلمة LIMIT في أي مكان.
     */
    private function apply_where(array $rows, $sql)
    {
        $sql_no_trailing_limit = preg_replace('/\s+LIMIT\s+\d+\s*$/i', '', $sql);

        if (preg_match('/WHERE\s+(.+)$/is', $sql_no_trailing_limit, $m)) {
            $where = trim($m[1]);
            $conditions = preg_split('/\bAND\b/i', $where);
            foreach ($conditions as $cond) {
                $cond = trim($cond);
                if ($cond === '') {
                    continue;
                }

                if (preg_match("/^(\\w+)\\s+IN\\s*\\(([^)]*)\\)$/i", $cond, $cm)) {
                    $field = $cm[1];
                    $values = array_map(function ($v) {
                        return trim(trim($v), "'\"");
                    }, explode(',', $cm[2]));
                    $rows = array_values(array_filter($rows, function ($r) use ($field, $values) {
                        return array_key_exists($field, $r) && in_array((string) $r[$field], $values, true);
                    }));
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

        if ($which === 'entitlements') {
            if ($this->force_entitlement_insert_failure) {
                $this->force_entitlement_insert_failure = false;
                $this->last_error = 'Forced insert failure (test)';
                return false;
            }
            $key = ($data['credit_cycle_id'] ?? '') . '|' . ($data['event_id'] ?? '') . '|' . ($data['source_guest_phone'] ?? '');
            if (isset($this->entitlement_unique_index[$key])) {
                $this->last_error = "Duplicate entry for key 'unique_replacement_entitlement'";
                return false;
            }
            $id = $this->entitlement_next_id++;
            $row = array_merge(['id' => $id, 'consumed_by_ledger_id' => null, 'consumed_at' => null], $data);
            $this->entitlement_rows[$id] = $row;
            $this->entitlement_unique_index[$key] = $id;
            $this->insert_id = $id;
            return 1;
        }

        // إدراج مباشر في جدول ledger (غير مستخدَم فعلياً من الكود محل الاختبار
        // — الـRepository يقرأ فقط — لكن مدعوم هنا للاكتمال وعدم الفشل الصامت)
        $id = $this->ledger_next_id++;
        $this->ledger_rows[$id] = array_merge(['id' => $id], $data);
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }

        $id = $where['id'] ?? null;
        if ($id === null) {
            return false;
        }

        if ($which === 'entitlements') {
            if (!isset($this->entitlement_rows[$id])) {
                return 0;
            }
            foreach ($where as $where_key => $where_value) {
                if ($where_key === 'id') {
                    continue;
                }
                $current_value = $this->entitlement_rows[$id][$where_key] ?? null;
                if ((string) $current_value !== (string) $where_value) {
                    return 0;
                }
            }
            foreach ($data as $k => $v) {
                $this->entitlement_rows[$id][$k] = $v;
            }
            return 1;
        }

        if (!isset($this->ledger_rows[$id])) {
            return 0;
        }
        foreach ($data as $k => $v) {
            $this->ledger_rows[$id][$k] = $v;
        }
        return 1;
    }

    /** أداة اختبار: زرع صف مباشر في جدول Ledger الوهمي بأي حالة/بيانات */
    public function seed_ledger_row($id, array $row)
    {
        $this->ledger_rows[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->ledger_next_id) {
            $this->ledger_next_id = $id + 1;
        }
    }

    /** أداة اختبار: قراءة مباشرة لصف استحقاق دون المرور بـ apply_where */
    public function raw_entitlement_row($id)
    {
        return $this->entitlement_rows[$id] ?? null;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Replacement();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تحميل الملفات الحقيقية من المشروع (بلا أي تعديل عليها) ─────────────────

require_once __DIR__ . '/../includes/class-mon-catalog-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-credit-ledger.php';
require_once __DIR__ . '/../includes/class-pge-replacement-entitlements.php';

// ── أدوات الاختبار ──────────────────────────────────────────────────────────

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
        $actual_str = var_export($actual, true);
        $expected_str = var_export($expected, true);
        echo "FAIL  $label (expected $expected_str, got $actual_str)\n";
    }
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

/** بناء صف ledger جاهز للزرع مع دمج تجاوزات اختيارية */
function ledger_fixture(array $overrides = []): array
{
    return array_merge([
        'user_id'         => 9301,
        'credit_cycle_id' => 'ENT-CYCLE-A',
        'event_id'        => 501,
        'guest_phone'     => '0561111111',
        'credit_type'     => 'primary',
        'status'          => 'consumed',
        'created_at'      => '2026-01-01 00:00:00',
    ], $overrides);
}

echo "=== قسم Schema: جدول mon_replacement_entitlements ===\n";

// ── Schema (1-8) ─────────────────────────────────────────────────────────────

$schema_sql_ref = new ReflectionMethod('Mon_Catalog_Schema', 'get_schema_sql');
$schema_sql_ref->setAccessible(true);
$schema_sql_parts = $schema_sql_ref->invoke(null);
$entitlements_sql = $schema_sql_parts[4] ?? '';

check_true('1. الجدول mon_replacement_entitlements موجود في نص Schema', strpos($entitlements_sql, 'mon_replacement_entitlements') !== false);

$required_entitlement_columns = [
    'id', 'user_id', 'credit_cycle_id', 'event_id', 'source_guest_phone',
    'source_ledger_id', 'status', 'consumed_by_ledger_id', 'granted_at',
    'consumed_at', 'created_at', 'updated_at',
];
$all_columns_present = true;
foreach ($required_entitlement_columns as $col) {
    if (strpos($entitlements_sql, $col) === false) {
        $all_columns_present = false;
        break;
    }
}
check_true('2. جميع الأعمدة المطلوبة موجودة في نص Schema', $all_columns_present);

check_true('3. UNIQUE KEY unique_replacement_entitlement يحتوي الأعمدة الثلاثة بالترتيب الصحيح', strpos($entitlements_sql, 'UNIQUE KEY unique_replacement_entitlement (credit_cycle_id, event_id, source_guest_phone)') !== false);

check_true('4. KEY user_cycle_status موجود', strpos($entitlements_sql, 'KEY user_cycle_status (user_id, credit_cycle_id, status)') !== false);
check_true('4. KEY source_ledger_id موجود', strpos($entitlements_sql, 'KEY source_ledger_id (source_ledger_id)') !== false);
check_true('4. KEY consumed_by_ledger_id موجود', strpos($entitlements_sql, 'KEY consumed_by_ledger_id (consumed_by_ledger_id)') !== false);

$upgrade_170_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_7_0');
$upgrade_170_ref->setAccessible(true);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = null;
check_true('5. upgrade_to_1_7_0() ينجح عند اكتمال البنية (أعمدة + قيد UNIQUE صحيحان)', $upgrade_170_ref->invoke(null) === true);

$wpdb->show_columns_override = ['id', 'user_id', 'credit_cycle_id']; // أعمدة ناقصة عمداً
check_true('6. upgrade_to_1_7_0() يفشل بأمان عند عمود ناقص', $upgrade_170_ref->invoke(null) === false);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = []; // القيد UNIQUE غائب تماماً
check_true('7. upgrade_to_1_7_0() يفشل بأمان عند غياب القيد UNIQUE', $upgrade_170_ref->invoke(null) === false);

$wpdb->show_index_override = [
    ['Key_name' => 'unique_replacement_entitlement', 'Column_name' => 'event_id', 'Seq_in_index' => 1],
    ['Key_name' => 'unique_replacement_entitlement', 'Column_name' => 'credit_cycle_id', 'Seq_in_index' => 2],
    ['Key_name' => 'unique_replacement_entitlement', 'Column_name' => 'source_guest_phone', 'Seq_in_index' => 3],
];
check_true('7. upgrade_to_1_7_0() يفشل بأمان عند ترتيب UNIQUE خاطئ', $upgrade_170_ref->invoke(null) === false);

$wpdb->show_index_override = null; // إعادة الحالة الطبيعية
check_true('8. upgrade_to_1_7_0() Idempotent — يعود true بعد اكتمال البنية مجدداً، وتكراره لا أثر إضافي له', $upgrade_170_ref->invoke(null) === true && $upgrade_170_ref->invoke(null) === true);

echo "\n=== قسم Repository: PGE_Replacement_Entitlements ===\n";

// ── create_entitlement(): نجاح + تكرار (1-2) ───────────────────────────────

$wpdb->seed_ledger_row(6001, ledger_fixture());

$ent_1a = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 501, '0561111111', 6001);
check('1. إنشاء استحقاق ناجح من primary consumed', $ent_1a['result'] ?? null, 'created');
check_true('1. يتضمن id', isset($ent_1a['id']) && $ent_1a['id'] > 0);

$ent_1b = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 501, '0561111111', 6001);
check('2. التكرار يعيد already_exists', $ent_1b['result'] ?? null, 'already_exists');
check('2. نفس id السابق', $ent_1b['id'] ?? null, $ent_1a['id'] ?? null);

// ── رفض حسب حالة/نوع صف source (3-6) ────────────────────────────────────────

$wpdb->seed_ledger_row(6002, ledger_fixture(['event_id' => 502, 'status' => 'reserved']));
$ent_3 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 502, '0561111111', 6002);
check('3. رفض primary reserved', $ent_3['result'] ?? null, 'error');
check('3. سبب الرفض source_not_consumed', $ent_3['reason'] ?? null, 'source_not_consumed');

$wpdb->seed_ledger_row(6003, ledger_fixture(['event_id' => 503, 'status' => 'failed']));
$ent_4 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 503, '0561111111', 6003);
check('4. رفض primary failed', $ent_4['result'] ?? null, 'error');
check('4. سبب الرفض source_not_consumed', $ent_4['reason'] ?? null, 'source_not_consumed');

$wpdb->seed_ledger_row(6004, ledger_fixture(['event_id' => 504, 'status' => 'refunded']));
$ent_5 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 504, '0561111111', 6004);
check('5. رفض primary refunded', $ent_5['result'] ?? null, 'error');
check('5. سبب الرفض source_not_consumed', $ent_5['reason'] ?? null, 'source_not_consumed');

$wpdb->seed_ledger_row(6005, ledger_fixture(['event_id' => 505, 'credit_type' => 'replacement']));
$ent_6 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 505, '0561111111', 6005);
check('6. رفض source credit_type=replacement', $ent_6['result'] ?? null, 'error');
check('6. سبب الرفض source_not_primary', $ent_6['reason'] ?? null, 'source_not_primary');

// ── رفض source_ledger_id غير موجود (7) ──────────────────────────────────────

$ent_7 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 506, '0561111111', 999999);
check('7. رفض source_ledger_id غير موجود', $ent_7['result'] ?? null, 'error');
check('7. سبب الرفض source_ledger_not_found', $ent_7['reason'] ?? null, 'source_ledger_not_found');

// ── رفض اختلاف الحقول المطابِقة (8-11) ──────────────────────────────────────

$wpdb->seed_ledger_row(6008, ledger_fixture(['event_id' => 508]));
$ent_8 = PGE_Replacement_Entitlements::create_entitlement(9999, 'ENT-CYCLE-A', 508, '0561111111', 6008);
check('8. رفض اختلاف user_id', $ent_8['result'] ?? null, 'error');
check('8. سبب الرفض source_user_mismatch', $ent_8['reason'] ?? null, 'source_user_mismatch');

$wpdb->seed_ledger_row(6009, ledger_fixture(['event_id' => 509]));
$ent_9 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 777777, '0561111111', 6009);
check('9. رفض اختلاف event_id', $ent_9['result'] ?? null, 'error');
check('9. سبب الرفض source_event_mismatch', $ent_9['reason'] ?? null, 'source_event_mismatch');

$wpdb->seed_ledger_row(6010, ledger_fixture(['event_id' => 510]));
$ent_10 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 510, '0569999999', 6010);
check('10. رفض اختلاف phone', $ent_10['result'] ?? null, 'error');
check('10. سبب الرفض source_phone_mismatch', $ent_10['reason'] ?? null, 'source_phone_mismatch');

$wpdb->seed_ledger_row(6011, ledger_fixture(['event_id' => 511]));
$ent_11 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-OTHER', 511, '0561111111', 6011);
check('11. رفض اختلاف credit_cycle_id', $ent_11['result'] ?? null, 'error');
check('11. سبب الرفض source_cycle_mismatch', $ent_11['reason'] ?? null, 'source_cycle_mismatch');

// ── تطبيع الهاتف قبل المقارنة والحفظ (12) ───────────────────────────────────

$wpdb->seed_ledger_row(6012, ledger_fixture(['event_id' => 512, 'guest_phone' => '0562222222']));
$ent_12 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 512, '056-222-2222', 6012);
check('12. تطبيع الهاتف يسمح بالمطابقة رغم اختلاف التنسيق', $ent_12['result'] ?? null, 'created');
$ent_12_row = PGE_Replacement_Entitlements::get_entitlement($ent_12['id'] ?? 0);
check('12. الهاتف المخزَّن مطبَّع فعلياً', $ent_12_row['source_guest_phone'] ?? null, '0562222222');

// ── عزل المناسبات وعزل الدورات (13-14) ──────────────────────────────────────

$wpdb->seed_ledger_row(6013, ledger_fixture(['event_id' => 513, 'guest_phone' => '0563333333']));
$wpdb->seed_ledger_row(6014, ledger_fixture(['event_id' => 514, 'guest_phone' => '0563333333']));
$ent_13a = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 513, '0563333333', 6013);
$ent_13b = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 514, '0563333333', 6014);
check('13. عزل المناسبات: نفس الهاتف/الدورة، مناسبة مختلفة → كلاهما created', $ent_13a['result'] ?? null, 'created');
check('13. عزل المناسبات (الثاني)', $ent_13b['result'] ?? null, 'created');
check_true('13. معرّفان مختلفان', ($ent_13a['id'] ?? null) !== ($ent_13b['id'] ?? null));

$wpdb->seed_ledger_row(6016, ledger_fixture(['event_id' => 516, 'guest_phone' => '0564444444', 'credit_cycle_id' => 'ENT-CYCLE-A']));
$wpdb->seed_ledger_row(6017, ledger_fixture(['event_id' => 516, 'guest_phone' => '0564444444', 'credit_cycle_id' => 'ENT-CYCLE-B']));
$ent_14b = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 516, '0564444444', 6016);
$ent_14c = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-B', 516, '0564444444', 6017);
check('14. عزل الدورات: نفس event/phone، دورة مختلفة → كلاهما created', $ent_14b['result'] ?? null, 'created');
check('14. عزل الدورات (الثاني)', $ent_14c['result'] ?? null, 'created');
check_true('14. معرّفان مختلفان', ($ent_14b['id'] ?? null) !== ($ent_14c['id'] ?? null));

// ── count_granted / count_consumed / count_available (15) ──────────────────

$user_count = 9302;
$cycle_count = 'ENT-CYCLE-COUNT';
$wpdb->seed_ledger_row(6020, ledger_fixture(['user_id' => $user_count, 'credit_cycle_id' => $cycle_count, 'event_id' => 520, 'guest_phone' => '0571111111']));
$wpdb->seed_ledger_row(6021, ledger_fixture(['user_id' => $user_count, 'credit_cycle_id' => $cycle_count, 'event_id' => 521, 'guest_phone' => '0572222222']));
$wpdb->seed_ledger_row(6022, ledger_fixture(['user_id' => $user_count, 'credit_cycle_id' => $cycle_count, 'event_id' => 522, 'guest_phone' => '0573333333']));

$grant_c1 = PGE_Replacement_Entitlements::create_entitlement($user_count, $cycle_count, 520, '0571111111', 6020);
$grant_c2 = PGE_Replacement_Entitlements::create_entitlement($user_count, $cycle_count, 521, '0572222222', 6021);
$grant_c3 = PGE_Replacement_Entitlements::create_entitlement($user_count, $cycle_count, 522, '0573333333', 6022);

check('15. count_granted = 3 قبل أي استهلاك', PGE_Replacement_Entitlements::count_granted($user_count, $cycle_count), 3);
check('15. count_available = 3 قبل أي استهلاك', PGE_Replacement_Entitlements::count_available($user_count, $cycle_count), 3);
check('15. count_consumed = 0 قبل أي استهلاك', PGE_Replacement_Entitlements::count_consumed($user_count, $cycle_count), 0);

// استهلاك واحد منها عبر صف replacement/consumed صالح
$wpdb->seed_ledger_row(6023, [
    'user_id' => $user_count, 'credit_cycle_id' => $cycle_count, 'event_id' => 999,
    'guest_phone' => '0579999999', 'credit_type' => 'replacement', 'status' => 'consumed',
    'created_at' => '2026-01-01 00:00:00',
]);
$mc_15 = PGE_Replacement_Entitlements::mark_consumed($grant_c1['id'], 6023);
check('15. mark_consumed نجح تمهيداً لقياس count_consumed', $mc_15['result'] ?? null, 'consumed');

check('15. count_granted تبقى 3 (لا تتأثر بالاستهلاك — إجمالي تاريخي)', PGE_Replacement_Entitlements::count_granted($user_count, $cycle_count), 3);
check('15. count_available أصبحت 2', PGE_Replacement_Entitlements::count_available($user_count, $cycle_count), 2);
check('15. count_consumed أصبحت 1', PGE_Replacement_Entitlements::count_consumed($user_count, $cycle_count), 1);

// ── mark_consumed(): نجاح + Idempotent (16-17) ──────────────────────────────

$wpdb->seed_ledger_row(6030, ledger_fixture(['event_id' => 530, 'guest_phone' => '0581111111']));
$ent_16 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 530, '0581111111', 6030);
$wpdb->seed_ledger_row(6031, [
    'user_id' => 9301, 'credit_cycle_id' => 'ENT-CYCLE-A', 'event_id' => 998,
    'guest_phone' => '0588888888', 'credit_type' => 'replacement', 'status' => 'consumed',
    'created_at' => '2026-01-01 00:00:00',
]);
$mc_16 = PGE_Replacement_Entitlements::mark_consumed($ent_16['id'], 6031);
check('16. mark_consumed ناجح مع replacement ledger consumed', $mc_16['result'] ?? null, 'consumed');
check('16. status أصبحت consumed', PGE_Replacement_Entitlements::get_entitlement($ent_16['id'])['status'] ?? null, 'consumed');

$mc_17 = PGE_Replacement_Entitlements::mark_consumed($ent_16['id'], 6031);
check('17. mark_consumed Idempotent مع نفس ledger id', $mc_17['result'] ?? null, 'already_consumed');

// ── رفض حالات صف الاستهلاك (18-20) ──────────────────────────────────────────

$wpdb->seed_ledger_row(6032, ledger_fixture(['event_id' => 532, 'guest_phone' => '0582222222']));
$ent_18 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 532, '0582222222', 6032);
$wpdb->seed_ledger_row(6033, [
    'user_id' => 9301, 'credit_cycle_id' => 'ENT-CYCLE-A', 'event_id' => 997,
    'guest_phone' => '0587777777', 'credit_type' => 'replacement', 'status' => 'reserved',
    'created_at' => '2026-01-01 00:00:00',
]);
$mc_18 = PGE_Replacement_Entitlements::mark_consumed($ent_18['id'], 6033);
check('18. رفض replacement ledger reserved', $mc_18['result'] ?? null, 'error');
check('18. سبب الرفض consumed_by_not_consumed', $mc_18['reason'] ?? null, 'consumed_by_not_consumed');

$wpdb->seed_ledger_row(6034, ledger_fixture(['event_id' => 533, 'guest_phone' => '0583333333']));
$ent_19 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 533, '0583333333', 6034);
$wpdb->seed_ledger_row(6035, [
    'user_id' => 9301, 'credit_cycle_id' => 'ENT-CYCLE-A', 'event_id' => 996,
    'guest_phone' => '0586666666', 'credit_type' => 'replacement', 'status' => 'failed',
    'created_at' => '2026-01-01 00:00:00',
]);
$mc_19 = PGE_Replacement_Entitlements::mark_consumed($ent_19['id'], 6035);
check('19. رفض replacement ledger failed', $mc_19['result'] ?? null, 'error');
check('19. سبب الرفض consumed_by_not_consumed', $mc_19['reason'] ?? null, 'consumed_by_not_consumed');

$wpdb->seed_ledger_row(6036, ledger_fixture(['event_id' => 534, 'guest_phone' => '0584444444']));
$ent_20 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 534, '0584444444', 6036);
$wpdb->seed_ledger_row(6037, [
    'user_id' => 9301, 'credit_cycle_id' => 'ENT-CYCLE-A', 'event_id' => 995,
    'guest_phone' => '0585555555', 'credit_type' => 'primary', 'status' => 'consumed',
    'created_at' => '2026-01-01 00:00:00',
]);
$mc_20 = PGE_Replacement_Entitlements::mark_consumed($ent_20['id'], 6037);
check('20. رفض credit_type=primary كمستهلك', $mc_20['result'] ?? null, 'error');
check('20. سبب الرفض consumed_by_not_replacement', $mc_20['reason'] ?? null, 'consumed_by_not_replacement');

// ── رفض اختلاف user/cycle لصف الاستهلاك (21) ────────────────────────────────

$wpdb->seed_ledger_row(6038, ledger_fixture(['event_id' => 535, 'guest_phone' => '0581212121']));
$ent_21a = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 535, '0581212121', 6038);
$wpdb->seed_ledger_row(6039, [
    'user_id' => 9999, 'credit_cycle_id' => 'ENT-CYCLE-A', 'event_id' => 994,
    'guest_phone' => '0581313131', 'credit_type' => 'replacement', 'status' => 'consumed',
    'created_at' => '2026-01-01 00:00:00',
]);
$mc_21a = PGE_Replacement_Entitlements::mark_consumed($ent_21a['id'], 6039);
check('21. رفض اختلاف user_id لصف الاستهلاك', $mc_21a['result'] ?? null, 'error');
check('21. سبب الرفض consumed_by_user_mismatch', $mc_21a['reason'] ?? null, 'consumed_by_user_mismatch');

$wpdb->seed_ledger_row(6040, ledger_fixture(['event_id' => 536, 'guest_phone' => '0581414141']));
$ent_21b = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 536, '0581414141', 6040);
$wpdb->seed_ledger_row(6041, [
    'user_id' => 9301, 'credit_cycle_id' => 'ENT-CYCLE-OTHER', 'event_id' => 993,
    'guest_phone' => '0581515151', 'credit_type' => 'replacement', 'status' => 'consumed',
    'created_at' => '2026-01-01 00:00:00',
]);
$mc_21b = PGE_Replacement_Entitlements::mark_consumed($ent_21b['id'], 6041);
check('21. رفض اختلاف credit_cycle_id لصف الاستهلاك', $mc_21b['result'] ?? null, 'error');
check('21. سبب الرفض consumed_by_cycle_mismatch', $mc_21b['reason'] ?? null, 'consumed_by_cycle_mismatch');

// ── رفض تبديل consumed_by_ledger_id بعد الاستهلاك (22) ──────────────────────

$wpdb->seed_ledger_row(6042, ledger_fixture(['event_id' => 537, 'guest_phone' => '0581616161']));
$ent_22 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 537, '0581616161', 6042);
$wpdb->seed_ledger_row(6043, [
    'user_id' => 9301, 'credit_cycle_id' => 'ENT-CYCLE-A', 'event_id' => 992,
    'guest_phone' => '0581717171', 'credit_type' => 'replacement', 'status' => 'consumed',
    'created_at' => '2026-01-01 00:00:00',
]);
$wpdb->seed_ledger_row(6044, [
    'user_id' => 9301, 'credit_cycle_id' => 'ENT-CYCLE-A', 'event_id' => 991,
    'guest_phone' => '0581818181', 'credit_type' => 'replacement', 'status' => 'consumed',
    'created_at' => '2026-01-01 00:00:00',
]);
PGE_Replacement_Entitlements::mark_consumed($ent_22['id'], 6043);
$mc_22 = PGE_Replacement_Entitlements::mark_consumed($ent_22['id'], 6044);
check('22. رفض تبديل consumed_by_ledger_id بعد الاستهلاك', $mc_22['result'] ?? null, 'error');
check('22. سبب الرفض consumed_by_mismatch', $mc_22['reason'] ?? null, 'consumed_by_mismatch');
check('22. consumed_by_ledger_id يبقى القيمة الأولى (6043) دون تغيير', PGE_Replacement_Entitlements::get_entitlement($ent_22['id'])['consumed_by_ledger_id'] ?? null, 6043);

// ── mark_voided(): يعمل فقط من granted، لا يعمل على consumed (23-24) ────────

$wpdb->seed_ledger_row(6045, ledger_fixture(['event_id' => 538, 'guest_phone' => '0581919191']));
$ent_23 = PGE_Replacement_Entitlements::create_entitlement(9301, 'ENT-CYCLE-A', 538, '0581919191', 6045);
$mv_23 = PGE_Replacement_Entitlements::mark_voided($ent_23['id']);
check('23. mark_voided ينجح من granted', $mv_23['result'] ?? null, 'voided');
check('23. status أصبحت voided', PGE_Replacement_Entitlements::get_entitlement($ent_23['id'])['status'] ?? null, 'voided');

$mv_23b = PGE_Replacement_Entitlements::mark_voided($ent_23['id']);
check('23. mark_voided Idempotent على استحقاق مُبطَل أصلاً', $mv_23b['result'] ?? null, 'already_voided');

$mv_23c = PGE_Replacement_Entitlements::mark_consumed($ent_23['id'], 6043);
check('23. لا يمكن استهلاك استحقاق مُبطَل', $mv_23c['result'] ?? null, 'error');
check('23. سبب الرفض entitlement_voided', $mv_23c['reason'] ?? null, 'entitlement_voided');

check('24. لا يمكن void استحقاق مُستهلَك (ent_16 مُستهلَك من اختبار 16)', PGE_Replacement_Entitlements::mark_voided($ent_16['id'])['result'] ?? null, 'error');
check('24. سبب الرفض cannot_void_consumed', PGE_Replacement_Entitlements::mark_voided($ent_16['id'])['reason'] ?? null, 'cannot_void_consumed');

// ── لا Fatal عند مدخلات تالفة (25) ───────────────────────────────────────────

$garbage_inputs = [null, [], 'abc', -5, 0, '0', new stdClass(), true, false, 3.14];
$no_fatal = true;
foreach ($garbage_inputs as $g) {
    try {
        PGE_Replacement_Entitlements::create_entitlement($g, $g, $g, $g, $g);
        PGE_Replacement_Entitlements::get_entitlement($g);
        PGE_Replacement_Entitlements::get_entitlement_by_source($g, $g, $g);
        PGE_Replacement_Entitlements::count_granted($g, $g);
        PGE_Replacement_Entitlements::count_consumed($g, $g);
        PGE_Replacement_Entitlements::count_available($g, $g);
        PGE_Replacement_Entitlements::mark_consumed($g, $g);
        PGE_Replacement_Entitlements::mark_voided($g);
    } catch (\Throwable $e) {
        $no_fatal = false;
        echo "   ⚠️ استثناء غير متوقع مع مدخل تالف: " . $e->getMessage() . "\n";
    }
}
check_true('25. لا Fatal/استثناء عند تمرير مدخلات تالفة لأي دالة عامة', $no_fatal);

check('25. create_entitlement(null,...) يعيد error مرتّب', PGE_Replacement_Entitlements::create_entitlement(null, null, null, null, null)['result'] ?? null, 'error');
check('25. get_entitlement(-5) يعيد null', PGE_Replacement_Entitlements::get_entitlement(-5), null);
check('25. get_entitlement("abc") يعيد null', PGE_Replacement_Entitlements::get_entitlement('abc'), null);
check('25. count_available(0, "") يعيد 0', PGE_Replacement_Entitlements::count_available(0, ''), 0);

// ── ملخص ─────────────────────────────────────────────────────────────────────

echo "\n============================================\n";
echo "النتيجة: {$passed}/{$total} PASS\n";
if (!empty($failures)) {
    echo "الاختبارات الفاشلة:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
}
echo "============================================\n";
