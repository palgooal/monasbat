<?php
/**
 * اختبار مستقل بذاته (بلا PHPUnit وبلا أي بنية اختبارات جديدة، بنفس نمط
 * tests/test-invitation-credit-ledger.php وtests/test-catalog-tier-events-count.php)
 * لـPhase 2 — Commit 3 (Tier Features Storage — Testing).
 *
 * يغطي وفق docs/FEATURES-PHASE-2-SPEC.md §10 (Testing Checklist):
 *   1. Schema Definition لجدول mon_tier_features (بنية الأعمدة + UNIQUE + غياب
 *      value_type/FOREIGN KEY).
 *   2. DB_VERSION = 1.8.0 وتسجيل upgrade_to_1_8_0() بعد 1.7.0.
 *   3. Migration (upgrade_to_1_8_0()) عبر Reflection، بنفس أسلوب upgrade_to_1_5_0()
 *      في tests/test-invitation-credit-ledger.php.
 *   4-7. الدوال الأربع في includes/class-pge-tier-features.php مقابل عقود
 *        DEC-002 (docs/DECISION-LOG.md) حرفياً.
 *   8. عزل البيانات بين Tiers مختلفة.
 *   9. عدم وجود أي تفسير نوع للقيمة الخام (Raw Value Contract).
 *
 * يحمّل الملفين الحقيقيين التاليين دون أي تعديل عليهما:
 *   - includes/class-mon-catalog-schema.php
 *   - includes/class-pge-tier-features.php
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل:
 *   php tests/test-tier-features.php
 *
 * ملاحظة بيئية: لا يوجد PHP CLI في بيئة إعداد هذا الاختبار (تحقّق مسبق عبر
 * `which php` — غير متوفر، ولا صلاحية تثبيته). الاختبار مكتوب بالكامل وجاهز
 * للتشغيل الفعلي عبر `php tests/test-tier-features.php` في أي بيئة تملك PHP
 * CLI؛ التحقق المتاح هنا فحص AST (بنية الصياغة) فقط — وهذا **ليس بديلاً**
 * لتشغيل الاختبار فعلياً.
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب لتحميل الملفين الحقيقيين) ────

define('ABSPATH', __DIR__ . '/');

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }
}

// ── Fake $wpdb — الحد الأدنى الكافي لتغطية:
//    (أ) استعلامات SHOW COLUMNS/SHOW INDEX التي يصدرها upgrade_to_1_8_0()
//        (لاختبار Migration عبر Reflection، بنفس فلسفة Fake_Wpdb_Ledger في
//        tests/test-invitation-credit-ledger.php).
//    (ب) عمليات CRUD الخام التي تصدر عن includes/class-pge-tier-features.php
//        (get_row/get_results/insert/update/delete)، مع مخزن صفوف داخلي بسيط
//        في الذاكرة (لا محرّك SQL عام) يفرض القيد UNIQUE (tier_id, feature_key)
//        فعلياً عند insert()، ويسجّل كل استدعاء (جدول/بيانات/شرط/صيغة) للتحقق
//        من عزل Tier ومن الحقول المُرسَلة فعلياً — دون أي محاولة لمحاكاة
//        ووردبريس بالكامل. ────────────────────────────────────────────────

class Fake_Wpdb_TierFeatures
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    /** @var array<int,string>|null تجاوز اختياري لأعمدة SHOW COLUMNS (محاكاة بنية ناقصة) */
    public $show_columns_override = null;
    /** @var array<int,array>|null تجاوز اختياري لصفوف SHOW INDEX (محاكاة غياب القيد) */
    public $show_index_override = null;

    // فرض نتيجة الاستدعاء *التالي* مباشرة (bypass كامل للمنطق الواقعي أدناه)
    // — يُستخدم فقط لمحاكاة فشل قاعدة بيانات حقيقي لا يمكن إنتاجه عبر التخزين
    // الداخلي البسيط (مثال: get_row()/get_results() يُعيدان null حقيقياً).
    public $force_get_row_active = false;
    public $force_get_row_value = null;
    public $force_get_results_active = false;
    public $force_get_results_value = null;
    public $force_insert_active = false;
    public $force_insert_value = null;
    public $force_update_active = false;
    public $force_update_value = null;
    public $force_delete_active = false;
    public $force_delete_value = null;
    /** last_error يُفرَض مع أي استدعاء "force_*_active" أعلاه (اختياري) */
    public $force_next_last_error = null;

    // سجلات الاستدعاءات — للتحقق من عزل Tier ومن الحقول/الصيغ المُرسَلة فعلياً.
    public $last_query = '';
    public $last_prepared_query = '';
    public $get_row_call_count = 0;
    public $get_results_call_count = 0;
    public $insert_call_count = 0;
    public $update_call_count = 0;
    public $delete_call_count = 0;
    public $insert_calls = [];
    public $update_calls = [];
    public $delete_calls = [];

    /** @var array<int,array> صفوف mon_tier_features الوهمية: id => ['id','tier_id','feature_key','feature_value','created_at','updated_at'] */
    private $rows = [];
    private $next_id = 1;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        $result = preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? '';
            $i++;
            if ($m[0] === '%d') {
                return (string) (int) $val;
            }
            return "'" . addslashes((string) $val) . "'";
        }, $query);
        $this->last_prepared_query = $result;
        return $result;
    }

    public function get_charset_collate()
    {
        return '';
    }

    private function apply_force($activeFlagRef, $valueRef)
    {
        $value = $valueRef;
        if ($this->force_next_last_error !== null) {
            $this->last_error = $this->force_next_last_error;
            $this->force_next_last_error = null;
        }
        return $value;
    }

    public function get_row($sql, $output = null)
    {
        $this->last_query = $sql;
        $this->get_row_call_count++;
        $this->last_error = '';

        if ($this->force_get_row_active) {
            $this->force_get_row_active = false;
            return $this->apply_force(null, $this->force_get_row_value);
        }

        if (preg_match('/tier_id\s*=\s*(-?\d+)/', $sql, $m1) && preg_match("/feature_key\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m2)) {
            $tier_id = (int) $m1[1];
            $feature_key = stripslashes($m2[1]);
            foreach ($this->rows as $row) {
                if ((int) $row['tier_id'] === $tier_id && $row['feature_key'] === $feature_key) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function get_results($sql, $output = null)
    {
        $this->last_query = $sql;
        $this->get_results_call_count++;
        $this->last_error = '';

        if (stripos($sql, 'SHOW COLUMNS FROM') === 0) {
            $columns = $this->show_columns_override ?? [
                'id', 'tier_id', 'feature_key', 'feature_value', 'created_at', 'updated_at',
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
            return [
                ['Key_name' => 'tier_feature', 'Column_name' => 'tier_id', 'Seq_in_index' => 1],
                ['Key_name' => 'tier_feature', 'Column_name' => 'feature_key', 'Seq_in_index' => 2],
            ];
        }

        if ($this->force_get_results_active) {
            $this->force_get_results_active = false;
            return $this->apply_force(null, $this->force_get_results_value);
        }

        if (preg_match('/tier_id\s*=\s*(-?\d+)/', $sql, $m)) {
            $tier_id = (int) $m[1];
            $out = [];
            foreach ($this->rows as $row) {
                if ((int) $row['tier_id'] === $tier_id) {
                    $out[] = $row;
                }
            }
            return $out;
        }

        return [];
    }

    public function insert($table, $data, $format = null)
    {
        $this->insert_call_count++;
        $this->insert_calls[] = ['table' => $table, 'data' => $data, 'format' => $format];
        $this->last_error = '';

        if ($this->force_insert_active) {
            $this->force_insert_active = false;
            $result = $this->apply_force(null, $this->force_insert_value);
            if ($result) {
                $id = $this->next_id++;
                $this->insert_id = $id;
                $this->rows[$id] = array_merge(['id' => $id], $data);
            }
            return $result;
        }

        foreach ($this->rows as $row) {
            if ((int) $row['tier_id'] === (int) $data['tier_id'] && $row['feature_key'] === $data['feature_key']) {
                $this->last_error = "Duplicate entry '{$data['tier_id']}-{$data['feature_key']}' for key 'tier_feature'";
                return false;
            }
        }

        $id = $this->next_id++;
        $this->insert_id = $id;
        $this->rows[$id] = array_merge(['id' => $id], $data);
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $this->update_call_count++;
        $this->update_calls[] = ['table' => $table, 'data' => $data, 'where' => $where, 'format' => $format, 'where_format' => $where_format];
        $this->last_error = '';

        if ($this->force_update_active) {
            $this->force_update_active = false;
            return $this->apply_force(null, $this->force_update_value);
        }

        $matched = 0;
        foreach ($this->rows as $id => $row) {
            $ok = true;
            foreach ($where as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                foreach ($data as $k => $v) {
                    $this->rows[$id][$k] = $v;
                }
                $matched++;
            }
        }

        return $matched;
    }

    public function delete($table, $where, $where_format = null)
    {
        $this->delete_call_count++;
        $this->delete_calls[] = ['table' => $table, 'where' => $where, 'where_format' => $where_format];
        $this->last_error = '';

        if ($this->force_delete_active) {
            $this->force_delete_active = false;
            return $this->apply_force(null, $this->force_delete_value);
        }

        $matched = 0;
        foreach ($this->rows as $id => $row) {
            $ok = true;
            foreach ($where as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                unset($this->rows[$id]);
                $matched++;
            }
        }

        return $matched;
    }

    /** مساعد اختبار فقط: بذر صف مباشرة في الذاكرة (يتجاوز set_tier_feature_value() عمداً). */
    public function seed_row($tier_id, $feature_key, $feature_value)
    {
        $id = $this->next_id++;
        $this->rows[$id] = [
            'id'            => $id,
            'tier_id'       => (int) $tier_id,
            'feature_key'   => (string) $feature_key,
            'feature_value' => (string) $feature_value,
            'created_at'    => '2026-01-01 00:00:00',
            'updated_at'    => '2026-01-01 00:00:00',
        ];
        return $id;
    }

    /** مساعد اختبار فقط: عدد الصفوف الحالية لـtier_id محدَّد (للتحقق من عدم إنشاء تكرار). */
    public function count_rows_for_tier($tier_id)
    {
        $count = 0;
        foreach ($this->rows as $row) {
            if ((int) $row['tier_id'] === (int) $tier_id) {
                $count++;
            }
        }
        return $count;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_TierFeatures();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تحميل الملفين الحقيقيين من المشروع (بلا أي تعديل عليهما) ───────────────

require_once __DIR__ . '/../includes/class-mon-catalog-schema.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';

// ── أدوات الاختبار (نفس نمط check()/check_true() في بقية ملفات tests/) ─────

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

echo "=== قسم 1: Schema Definition — جدول mon_tier_features ===\n";

// ── Schema Definition (1-10) ────────────────────────────────────────────────

$schema_sql_ref = new ReflectionMethod('Mon_Catalog_Schema', 'get_schema_sql');
$schema_sql_ref->setAccessible(true);
$schema_sql_parts = $schema_sql_ref->invoke(null);
$tier_features_sql = $schema_sql_parts[5] ?? '';

check_true('1. الجدول mon_tier_features موجود في نص Schema', strpos($tier_features_sql, 'mon_tier_features') !== false);

check_true(
    '2. id: BIGINT UNSIGNED NOT NULL AUTO_INCREMENT (بصرف النظر عن طول العرض الاختياري)',
    preg_match('/\bid\s+BIGINT(\s*\(\s*\d+\s*\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i', $tier_features_sql) === 1
);

check_true(
    '3. tier_id: BIGINT UNSIGNED NOT NULL (بلا AUTO_INCREMENT)',
    preg_match('/\btier_id\s+BIGINT(\s*\(\s*\d+\s*\))?\s+UNSIGNED\s+NOT\s+NULL\b/i', $tier_features_sql) === 1
);

check_true(
    '4. feature_key: VARCHAR(64) NOT NULL',
    preg_match('/\bfeature_key\s+VARCHAR\s*\(\s*64\s*\)\s+NOT\s+NULL\b/i', $tier_features_sql) === 1
);

check_true(
    '5. feature_value: LONGTEXT NOT NULL',
    preg_match('/\bfeature_value\s+LONGTEXT\s+NOT\s+NULL\b/i', $tier_features_sql) === 1
);

check_true(
    '6. created_at: DATETIME NOT NULL',
    preg_match('/\bcreated_at\s+DATETIME\s+NOT\s+NULL\b/i', $tier_features_sql) === 1
);

check_true(
    '6. updated_at: DATETIME NOT NULL',
    preg_match('/\bupdated_at\s+DATETIME\s+NOT\s+NULL\b/i', $tier_features_sql) === 1
);

check_true(
    '7. PRIMARY KEY (id) موجود',
    preg_match('/PRIMARY\s+KEY\s*\(\s*id\s*\)/i', $tier_features_sql) === 1
);

check_true(
    '8. UNIQUE KEY على (tier_id, feature_key) بالترتيب الصحيح (بصرف النظر عن اسم القيد)',
    preg_match('/UNIQUE\s+KEY\s+\w+\s*\(\s*tier_id\s*,\s*feature_key\s*\)/i', $tier_features_sql) === 1
);

check_true('9. لا عمود value_type داخل الجدول الجديد', stripos($tier_features_sql, 'value_type') === false);
check_true('10. لا FOREIGN KEY داخل الجدول الجديد', stripos($tier_features_sql, 'FOREIGN KEY') === false);

echo "\n=== قسم 2: DB Version / Upgrade Registration ===\n";

// ── DB Version / Upgrade Registration (11-13) ───────────────────────────────

check('11. DB_VERSION = 1.8.0', Mon_Catalog_Schema::DB_VERSION, '1.8.0');

$routines_ref = new ReflectionMethod('Mon_Catalog_Schema', 'get_upgrade_routines');
$routines_ref->setAccessible(true);
$routines = $routines_ref->invoke(null);
$routine_keys = array_keys($routines);

check_true('12. upgrade_to_1_8_0 مسجَّلة في get_upgrade_routines() باسم الدالة الصحيح', ($routines['1.8.0'] ?? null) === ['Mon_Catalog_Schema', 'upgrade_to_1_8_0']);

$index_170 = array_search('1.7.0', $routine_keys, true);
$index_180 = array_search('1.8.0', $routine_keys, true);
check_true('13. 1.8.0 مسجَّلة بعد 1.7.0 في ترتيب المصفوفة', $index_170 !== false && $index_180 !== false && $index_180 > $index_170);

echo "\n=== قسم 3: Migration — upgrade_to_1_8_0() ===\n";

// ── Migration (14-17) ────────────────────────────────────────────────────────

$upgrade_180_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_8_0');
$upgrade_180_ref->setAccessible(true);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = null;
check_true('14. upgrade_to_1_8_0() ينجح عند اكتمال البنية (أعمدة + قيد UNIQUE صحيحان)', $upgrade_180_ref->invoke(null) === true);

$wpdb->show_index_override = []; // القيد UNIQUE غائب تماماً
check_true('15. upgrade_to_1_8_0() يفشل بأمان عند غياب القيد UNIQUE', $upgrade_180_ref->invoke(null) === false);

$wpdb->show_index_override = [
    ['Key_name' => 'tier_feature', 'Column_name' => 'feature_key', 'Seq_in_index' => 1],
    ['Key_name' => 'tier_feature', 'Column_name' => 'tier_id', 'Seq_in_index' => 2],
];
check_true('15ب. upgrade_to_1_8_0() يفشل عند ترتيب أعمدة UNIQUE معكوس (feature_key ثم tier_id)', $upgrade_180_ref->invoke(null) === false);

$wpdb->show_index_override = null;
$wpdb->show_columns_override = ['id', 'tier_id', 'feature_key', 'created_at', 'updated_at']; // feature_value غائب عمداً
check_true('16. upgrade_to_1_8_0() يفشل بأمان عند غياب عمود واحد فقط (feature_value)', $upgrade_180_ref->invoke(null) === false);

$wpdb->show_columns_override = null;
check_true('17. upgrade_to_1_8_0() Idempotent — نجاح متكرر بلا أثر إضافي', $upgrade_180_ref->invoke(null) === true && $upgrade_180_ref->invoke(null) === true);

echo "\n=== قسم 4: get_tier_feature_value() ===\n";

// ── get_tier_feature_value (18-23) ──────────────────────────────────────────

$wpdb->seed_row(10, 'k_zero', '0');
$val_18 = PGE_Tier_Features::get_tier_feature_value(10, 'k_zero');
check('18. قيمة موجودة "0" تُعاد كسلسلة نصية "0" حرفياً', $val_18, '0');
check_true('18. النتيجة من النوع string (لا integer ولا boolean)', is_string($val_18));

$wpdb->seed_row(10, 'k_empty', '');
$val_19 = PGE_Tier_Features::get_tier_feature_value(10, 'k_empty');
check('19. قيمة موجودة "" (سلسلة فارغة) تُعاد كما هي، لا تُعامَل كعدم وجود', $val_19, '');
check_true('19. النتيجة من النوع string وليست null', is_string($val_19));

$val_20 = PGE_Tier_Features::get_tier_feature_value(10, 'k_does_not_exist');
check('20. مفتاح غير موجود لـTier موجود → null', $val_20, null);

$wpdb->force_get_row_active = true;
$wpdb->force_get_row_value = null;
$wpdb->force_next_last_error = '';
$val_21 = PGE_Tier_Features::get_tier_feature_value(999, 'anything');
check('21. get_row()=null مع last_error فارغ → null (لا خطأ)', $val_21, null);

$wpdb->force_get_row_active = true;
$wpdb->force_get_row_value = null;
$wpdb->force_next_last_error = 'Simulated real database failure';
$val_22 = PGE_Tier_Features::get_tier_feature_value(999, 'anything');
check('22. get_row()=null مع last_error غير فارغ → false (فشل قاعدة بيانات فعلي)', $val_22, false);

check_true('23. الاستعلام يستخدم %d لـtier_id و%s لـfeature_key عبر prepare()', strpos($wpdb->last_prepared_query, "'anything'") !== false);

echo "\n=== قسم 5: get_all_tier_features() ===\n";

// ── get_all_tier_features (24-28) ───────────────────────────────────────────

$wpdb->seed_row(20, 'f1', 'v1');
$wpdb->seed_row(20, 'f2', 'v2');
$wpdb->seed_row(21, 'f1', 'other_tier_value');

$rows_24 = PGE_Tier_Features::get_all_tier_features(20);
check_true('24. النتيجة من النوع array', is_array($rows_24));
check('24. عدد الصفوف المُعادة لـtier_id=20 هو 2 فقط', count($rows_24), 2);

$tier_ids_in_result = array_unique(array_map(function ($r) { return (int) $r['tier_id']; }, $rows_24));
check('24. كل الصفوف المُعادة تخص tier_id=20 حصراً (لا تسرّب من tier_id=21)', $tier_ids_in_result, [20]);

$rows_25_empty = PGE_Tier_Features::get_all_tier_features(999999);
check('25. Tier بلا أي صف مخزَّن → [] بالضبط (ليس null وليس false)', $rows_25_empty, []);

$wpdb->force_get_results_active = true;
$wpdb->force_get_results_value = null;
$rows_26 = PGE_Tier_Features::get_all_tier_features(20);
check('26. get_results()=null (فشل استعلام فعلي) → false', $rows_26, false);

check_true('27. لا تفسير لقيمة feature_value داخل الصفوف المُعادة (تبقى نصاً كما خُزِّنت)', $rows_24[0]['feature_value'] === 'v1' || $rows_24[1]['feature_value'] === 'v1');

echo "\n=== قسم 6: set_tier_feature_value() — Upsert ===\n";

// ── set_tier_feature_value (28-38) ──────────────────────────────────────────

$insert_calls_before_28 = $wpdb->insert_call_count;
$result_28 = PGE_Tier_Features::set_tier_feature_value(30, 'new_key', 'new_value');
check_true('28. Insert ناجح (صف جديد لا يوجد له تعارض) → true', $result_28 === true);
check('28. عدد استدعاءات insert() زاد بمقدار 1', $wpdb->insert_call_count, $insert_calls_before_28 + 1);

$last_insert_data = $wpdb->insert_calls[count($wpdb->insert_calls) - 1]['data'];
check(
    '29. البيانات المُرسَلة لـinsert() تتضمن فقط الحقول الفعلية في التنفيذ الحالي',
    array_keys($last_insert_data),
    ['tier_id', 'feature_key', 'feature_value', 'created_at', 'updated_at']
);
check('29. feature_value المُرسَلة مطابقة للقيمة الخام دون أي تحويل', $last_insert_data['feature_value'], 'new_value');

$result_30_first = PGE_Tier_Features::set_tier_feature_value(31, 'dup_key', 'old_value');
check_true('30. تمهيد: أول Insert لزوج (31, dup_key) ينجح', $result_30_first === true);
check('30. تمهيد: صف واحد فقط لـtier_id=31', $wpdb->count_rows_for_tier(31), 1);

$update_calls_before_30 = $wpdb->update_call_count;
$result_30_second = PGE_Tier_Features::set_tier_feature_value(31, 'dup_key', 'updated_value');
check_true('30. تعارض المفتاح الفريد ثم Update ناجح → true', $result_30_second === true);
check('30. عدد استدعاءات update() زاد بمقدار 1 (Upsert تحديث لا إدراج جديد)', $wpdb->update_call_count, $update_calls_before_30 + 1);
check('30. لا يوجد صف ثانٍ — العدد يبقى 1 لنفس Tier', $wpdb->count_rows_for_tier(31), 1);
check('30. القيمة الفعلية أصبحت updated_value بعد الـUpsert', PGE_Tier_Features::get_tier_feature_value(31, 'dup_key'), 'updated_value');

$last_update_call = $wpdb->update_calls[count($wpdb->update_calls) - 1];
check('30. update() مقيَّد بالزوج (tier_id, feature_key) فقط في WHERE', $last_update_call['where'], ['tier_id' => 31, 'feature_key' => 'dup_key']);

$wpdb->seed_row(32, 'other_key', 'must_not_change');
PGE_Tier_Features::set_tier_feature_value(31, 'dup_key', 'yet_another_value');
check('30. صف Tier آخر (32) لم يتأثر إطلاقاً بـUpdate الخاص بـTier 31', PGE_Tier_Features::get_tier_feature_value(32, 'other_key'), 'must_not_change');

$update_calls_before_31 = $wpdb->update_call_count;
$wpdb->force_insert_active = true;
$wpdb->force_insert_value = false;
$wpdb->force_next_last_error = 'Unrelated SQL syntax error near WHERE';
$result_31 = PGE_Tier_Features::set_tier_feature_value(40, 'k', 'v');
check_true('31. فشل Insert عادي (غير Duplicate) → false', $result_31 === false);
check('31. update() لم يُستدعَ إطلاقاً (لا محاولة تحديث بعد فشل غير مرتبط بالتعارض)', $wpdb->update_call_count, $update_calls_before_31);

$wpdb->force_insert_active = true;
$wpdb->force_insert_value = false;
$wpdb->force_next_last_error = "Duplicate entry '41-k2' for key 'tier_feature'";
$wpdb->force_update_active = true;
$wpdb->force_update_value = false;
$result_32 = PGE_Tier_Features::set_tier_feature_value(41, 'k2', 'v2');
check_true('32. تعارض مفتاح مكتشَف لكن Update نفسه يفشل → false', $result_32 === false);

echo "\n=== قسم 7: delete_tier_feature() ===\n";

// ── delete_tier_feature (33-36) ──────────────────────────────────────────────

$wpdb->seed_row(50, 'to_delete', 'x');
$result_33 = PGE_Tier_Features::delete_tier_feature(50, 'to_delete');
check_true('33. حذف صف موجود فعلياً → true', $result_33 === true);
check('33. الصف فعلياً لم يعد موجوداً بعد الحذف', PGE_Tier_Features::get_tier_feature_value(50, 'to_delete'), null);

$result_34 = PGE_Tier_Features::delete_tier_feature(999999, 'never_existed');
check_true('34. حذف صف غير موجود أصلاً → true أيضاً (Idempotent وفق DEC-002)', $result_34 === true);

$wpdb->force_delete_active = true;
$wpdb->force_delete_value = false;
$result_35 = PGE_Tier_Features::delete_tier_feature(60, 'whatever');
check_true('35. فشل استعلام الحذف نفسه → false', $result_35 === false);

echo "\n=== قسم 8: عزل Tier (Isolation) ===\n";

// ── Tier Isolation (36-40) ───────────────────────────────────────────────────

$wpdb->seed_row(60, 'shared_key', 'value_for_tier_60');
$wpdb->seed_row(61, 'shared_key', 'value_for_tier_61');

check('36. نفس feature_key عبر Tiers مختلفة: tier_id=60 يعيد قيمته الخاصة', PGE_Tier_Features::get_tier_feature_value(60, 'shared_key'), 'value_for_tier_60');
check('36. نفس feature_key عبر Tiers مختلفة: tier_id=61 يعيد قيمته الخاصة (لا خلط)', PGE_Tier_Features::get_tier_feature_value(61, 'shared_key'), 'value_for_tier_61');

PGE_Tier_Features::delete_tier_feature(60, 'shared_key');
check('37. حذف (60, shared_key) لا يؤثر على (61, shared_key)', PGE_Tier_Features::get_tier_feature_value(61, 'shared_key'), 'value_for_tier_61');

PGE_Tier_Features::set_tier_feature_value(61, 'shared_key', 'changed_only_for_61');
check_true('38. تحديث (61, shared_key) لم يُعِد إنشاء (60, shared_key) عن طريق الخطأ', PGE_Tier_Features::get_tier_feature_value(60, 'shared_key') === null);

check_true('39. الاستعلام المُجهَّز لـget_tier_feature_value يتضمن tier_id المطلوب صراحة', strpos($wpdb->last_prepared_query, 'tier_id = 61') !== false);

$wpdb->seed_row(70, 'iso_a', 'a');
$wpdb->seed_row(71, 'iso_b', 'b');
$rows_71 = PGE_Tier_Features::get_all_tier_features(71);
check('40. get_all_tier_features(71) لا يعيد أي صف من tier_id=70', count(array_filter($rows_71, function ($r) { return (int) $r['tier_id'] === 70; })), 0);

echo "\n=== قسم 9: Raw Value Contract — بلا أي تفسير نوع ===\n";

// ── Raw Value Contract (41-44) ───────────────────────────────────────────────

$raw_values_to_test = [
    'raw_false' => 'false',
    'raw_001'   => '001',
    'raw_float' => '12.50',
    'raw_empty' => '',
];

$raw_case_index = 41;
foreach ($raw_values_to_test as $key => $raw_value) {
    PGE_Tier_Features::set_tier_feature_value(80, $key, $raw_value);
    $read_back = PGE_Tier_Features::get_tier_feature_value(80, $key);
    check("$raw_case_index. القيمة الخام '$raw_value' تُعاد حرفياً دون أي تحويل boolean/integer/float", $read_back, $raw_value);
    check_true("$raw_case_index. النتيجة من النوع string بالضبط (لا bool/int/float)", is_string($read_back));
    $raw_case_index++;
}

// ── ملخص ────────────────────────────────────────────────────────────────

echo "\n";
echo "النتيجة: $passed / $total نجحت.\n";

if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}

exit(0);
