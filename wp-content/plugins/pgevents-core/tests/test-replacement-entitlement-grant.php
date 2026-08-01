<?php
/**
 * ============================================================================
 * اختبارات ذاتية الاكتفاء — المرحلة 4B: Replacement Entitlement Grant from RSVP
 * ============================================================================
 * يحمّل الملفات الحقيقية التالية دون أي تعديل عليها وقت التحميل:
 *   - includes/class-pge-invitation-credit-ledger.php
 *   - includes/class-pge-replacement-entitlements.php
 *   - includes/replacement-entitlement-grant.php   (الدالة المركزية الجديدة)
 *   - includes/rsvp-handler.php                    (pge_save_rsvp_response)
 *   - includes/class-cartat-handler.php             (Mon_Cartat_Handler::record_rsvp عبر Reflection)
 *
 * Fake $wpdb واحد يحاكي ثلاثة جداول معاً: mon_invitation_credit_ledger،
 * mon_replacement_entitlements، wp_pge_event_rsvps — بما يكفي بالضبط لتشغيل
 * المسارات الثلاثة الحقيقية أعلاه دون أي محاكاة زائدة.
 *
 * قيد بيئي مُكرَّر من كل ملف اختبار سابق في هذا المشروع: لا مفسّر PHP CLI في
 * بيئة العمل — التحقق تم عبر فاحص AST (php-parser/Node.js) + تتبّع يدوي دقيق،
 * وليس تنفيذاً حقيقياً. راجع التقرير النهائي المرفق.
 */

// ── Stubs عامة لووردبريس (بنفس نمط test-cartat-credits-queue.php) ──────────

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', __DIR__ . '/../');

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }
function register_rest_route(...$args) { /* no-op */ }

if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($v) { return trim((string) $v); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
}

$GLOBALS['__test_now_override'] = null;
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0)
    {
        return $GLOBALS['__test_now_override'] ?? '2026-01-01 00:00:00';
    }
}

function wp_verify_nonce($nonce, $action) { return true; }
function is_user_logged_in() { return true; }
function current_user_can($cap, ...$rest) { return false; }
function get_current_user_id() { return 0; }
function pge_is_host_or_admin($event_id) { return true; }

if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string) $v)); }
}

// ── مخزن wp_options (غير مستخدَم فعلياً هنا، لكن get_option/update_option
//    مطلوبتان لبناء Mon_Cartat_Handler دون فشل — نفس نمط الملفات الأخرى) ───

$GLOBALS['__test_options'] = [
    'pge_cartat_api_token'    => 'TEST-TOKEN',
    'pge_cartat_country_code' => '966',
];

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$name] : $default;
}
function update_option($name, $value, $autoload = null)
{
    $GLOBALS['__test_options'][$name] = $value;
    return true;
}
function delete_option($name)
{
    unset($GLOBALS['__test_options'][$name]);
    return true;
}

// ── مخزن Post/Post Meta — لتحديد post_type/post_author المناسبة ────────────

$GLOBALS['__test_posts'] = [];

function seed_event(int $event_id, int $owner_user_id, string $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = ['post_type' => $post_type, 'post_author' => $owner_user_id];
}

function get_post_type($post_id)
{
    return $GLOBALS['__test_posts'][$post_id]['post_type'] ?? false;
}

function get_post_field($field, $post_id)
{
    return (string) ($GLOBALS['__test_posts'][$post_id][$field] ?? '');
}

function get_post_meta($post_id, $key, $single = false)
{
    return $single ? '' : [];
}

// ── دوال المدعوين (غير مؤثرة على منطق المنح — نمرّر is_host_or_admin=true
//    دائماً في هذه الاختبارات لتجاوز فحص قائمة المدعوين تماماً) ────────────

function pge_get_user_plan_limits_for_events($user_id)
{
    return ['guest_limit' => 0]; // 0 = بلا حد سعة، لا يؤثر على منطق المنح محل الاختبار
}

/**
 * ============================================================================
 * Fake $wpdb — يحاكي ثلاثة جداول: ledger (قراءة فقط، تُزرَع صفوفه مباشرة)،
 * entitlements (الجدول الحقيقي عبر PGE_Replacement_Entitlements)، وrsvp
 * (الجدول الحقيقي عبر pge_save_rsvp_response()/record_rsvp()).
 * ============================================================================
 */
class Fake_Wpdb_Grant
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $force_entitlement_insert_failure = false;

    private $ledger_rows = [];
    private $entitlement_rows = [];
    private $entitlement_unique_index = [];
    private $rsvp_rows = [];
    private $rsvp_unique_index = [];

    private $ledger_next_id = 1;
    private $entitlement_next_id = 1;
    private $rsvp_next_id = 1;

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
        if (strpos($sql_or_table, $this->prefix . 'pge_event_rsvps') !== false) {
            return 'rsvp';
        }
        return null;
    }

    private function rows_for($which)
    {
        if ($which === 'entitlements') return array_values($this->entitlement_rows);
        if ($which === 'rsvp') return array_values($this->rsvp_rows);
        return array_values($this->ledger_rows);
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        if (empty($rows)) {
            return null;
        }
        return $output === ARRAY_A ? $rows[0] : (object) $rows[0];
    }

    public function get_var($sql)
    {
        if (stripos(ltrim($sql), 'SELECT COALESCE(SUM(1 + companions), 0)') === 0) {
            $which = $this->which_table($sql);
            if ($which !== 'rsvp') {
                return '0';
            }
            $rows = $this->apply_where($this->rows_for('rsvp'), $sql);
            $sum = 0;
            foreach ($rows as $r) {
                $sum += 1 + (int) ($r['companions'] ?? 0);
            }
            return (string) $sum;
        }

        if (stripos(ltrim($sql), 'SELECT COUNT(*)') === 0) {
            $which = $this->which_table($sql);
            if ($which === null) {
                return null;
            }
            $rows = $this->apply_where($this->rows_for($which), $sql);
            return (string) count($rows);
        }

        return null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which === null) {
            return [];
        }

        $rows = $this->apply_where($this->rows_for($which), $sql);

        if ($which === 'ledger' && stripos($sql, 'ORDER BY consumed_at DESC, id DESC') !== false) {
            $rows = $this->order_by_consumed_at_id_desc($rows);
        }

        return $rows;
    }

    /**
     * ترتيب "consumed_at DESC ثم id DESC" — يحاكي سلوك MySQL: NULL تُعامَل
     * كأصغر قيمة (فتأتي آخراً في DESC)، وعند تساوي consumed_at (بما في ذلك
     * تساوي NULL مع NULL) يُحسَم الترتيب بـid DESC. هذا يطابق حرفياً القرار
     * الموثَّق في includes/replacement-entitlement-grant.php.
     */
    private function order_by_consumed_at_id_desc(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $ca = $a['consumed_at'] ?? null;
            $cb = $b['consumed_at'] ?? null;

            if ($ca !== $cb) {
                if ($ca === null) return 1;
                if ($cb === null) return -1;
                $cmp = strcmp((string) $cb, (string) $ca);
                if ($cmp !== 0) return $cmp;
            }

            return (int) $b['id'] - (int) $a['id'];
        });

        return $rows;
    }

    private function apply_where(array $rows, $sql)
    {
        $sql_no_trailing_limit = preg_replace('/\s+LIMIT\s+\d+\s*$/i', '', $sql);
        $sql_no_order = preg_replace('/\s+ORDER\s+BY\s+.+$/i', '', $sql_no_trailing_limit);

        if (preg_match('/WHERE\s+(.+)$/is', $sql_no_order, $m)) {
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

        if ($which === 'rsvp') {
            $key = ($data['event_id'] ?? '') . '|' . ($data['guest_phone'] ?? '');
            if (isset($this->rsvp_unique_index[$key])) {
                $this->last_error = "Duplicate entry for key 'event_phone'";
                return false;
            }
            $id = $this->rsvp_next_id++;
            $row = array_merge([
                'id' => $id, 'guest_name' => null, 'checked_in' => 0, 'checked_in_at' => null,
                'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
            ], $data);
            $this->rsvp_rows[$id] = $row;
            $this->rsvp_unique_index[$key] = $id;
            $this->insert_id = $id;
            return 1;
        }

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

        $store = ($which === 'entitlements') ? 'entitlement_rows' : (($which === 'rsvp') ? 'rsvp_rows' : 'ledger_rows');

        if (!isset($this->{$store}[$id])) {
            return 0;
        }
        foreach ($where as $where_key => $where_value) {
            if ($where_key === 'id') {
                continue;
            }
            $current_value = $this->{$store}[$id][$where_key] ?? null;
            if ((string) $current_value !== (string) $where_value) {
                return 0;
            }
        }
        foreach ($data as $k => $v) {
            $this->{$store}[$id][$k] = $v;
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

    /**
     * أداة اختبار: تفريغ جدول Entitlements الوهمي بالكامل (بلا مساس بجدولي
     * Ledger أو RSVP). ضرورية قبل أي اختبار يعتمد على قيمة **مطلقة** لـ
     * count_granted()/count_available() (لا فرق تراكمي نسبي) — فهذا الملف لا
     * يُعيد إنشاء $wpdb بين الاختبارات (نمط ثابت في كل ملفات اختبار هذا
     * المشروع)، فأي استحقاقات created من اختبارات سابقة (وهي كثيرة فعلياً في
     * القسم 1-21 أعلاه: 1، 2، 3، 11، 15، 18a، 18c) تبقى في الجدول الوهمي
     * وتُحتسَب ضمن أي عدّ لاحق لنفس (user_id=8801, credit_cycle_id=
     * 'GRANT-CYCLE-A') ما لم تُفرَّغ صراحةً.
     */
    public function reset_entitlements(): void
    {
        $this->entitlement_rows = [];
        $this->entitlement_unique_index = [];
        $this->entitlement_next_id = 1;
    }

    /** أداة اختبار: قراءة مباشرة لصف RSVP بمفتاحه الفريد */
    public function raw_rsvp_row_by_key(int $event_id, string $phone)
    {
        $key = $event_id . '|' . $phone;
        $id = $this->rsvp_unique_index[$key] ?? null;
        return $id !== null ? $this->rsvp_rows[$id] : null;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Grant();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تحميل الملفات الحقيقية من المشروع (بلا أي تعديل عليها) ─────────────────

require_once __DIR__ . '/../includes/class-pge-invitation-credit-ledger.php';
require_once __DIR__ . '/../includes/class-pge-replacement-entitlements.php';
require_once __DIR__ . '/../includes/replacement-entitlement-grant.php';
require_once __DIR__ . '/../includes/rsvp-handler.php';
// Supervisor Invitation Delivery via Cartat — تنفيذ (Option B): class-cartat-
// handler.php أصبح يعتمد على PGE_Cartat_Transport داخلياً — يجب تحميلها قبله.
require_once __DIR__ . '/../includes/class-pge-cartat-transport.php';
require_once __DIR__ . '/../includes/class-cartat-handler.php';

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
        echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

/** زرع صف primary/consumed جاهز في Ledger مع دمج تجاوزات اختيارية */
function seed_primary_consumed(Fake_Wpdb_Grant $wpdb, int $id, array $overrides = []): void
{
    $wpdb->seed_ledger_row($id, array_merge([
        'user_id'         => 8801,
        'credit_cycle_id' => 'GRANT-CYCLE-A',
        'event_id'        => 9001,
        'guest_phone'     => '0501111111',
        'credit_type'     => 'primary',
        'status'          => 'consumed',
        'consumed_at'     => '2026-01-01 10:00:00',
        'created_at'      => '2026-01-01 09:00:00',
    ], $overrides));
}

$handler = new Mon_Cartat_Handler();
$record_rsvp_ref = new ReflectionMethod('Mon_Cartat_Handler', 'record_rsvp');
$record_rsvp_ref->setAccessible(true);
function call_record_rsvp(ReflectionMethod $ref, $handler, int $event_id, string $phone, string $reply): void
{
    $ref->invoke($handler, $event_id, $phone, $reply);
}

echo "=== المرحلة 4B: دالة pge_maybe_grant_replacement_entitlement() المركزية (1-21) ===\n";

// 1) pending → no مع primary consumed → created
seed_event(9001, 8801);
seed_primary_consumed($wpdb, 6001);
$g1 = pge_maybe_grant_replacement_entitlement(9001, '0501111111', 'pending', 'no');
check('1. pending→no مع primary consumed → created', $g1['result'] ?? null, 'created');

// 2) yes → no → created
seed_event(9002, 8801);
seed_primary_consumed($wpdb, 6002, ['event_id' => 9002, 'guest_phone' => '0502222222']);
$g2 = pge_maybe_grant_replacement_entitlement(9002, '0502222222', 'yes', 'no');
check('2. yes→no → created', $g2['result'] ?? null, 'created');

// 3) null → no → created إذا وُجد primary consumed
seed_event(9003, 8801);
seed_primary_consumed($wpdb, 6003, ['event_id' => 9003, 'guest_phone' => '0503333333']);
$g3 = pge_maybe_grant_replacement_entitlement(9003, '0503333333', null, 'no');
check('3. null→no مع primary consumed → created', $g3['result'] ?? null, 'created');

// 4) no → no → لا استدعاء إنشاء، transition_not_eligible
seed_event(9004, 8801);
seed_primary_consumed($wpdb, 6004, ['event_id' => 9004, 'guest_phone' => '0504444444']);
$g4 = pge_maybe_grant_replacement_entitlement(9004, '0504444444', 'no', 'no');
check('4. no→no → skipped', $g4['result'] ?? null, 'skipped');
check('4. no→no → reason=transition_not_eligible', $g4['reason'] ?? null, 'transition_not_eligible');

// 5) no → yes → لا منح ولا void
seed_event(9005, 8801);
seed_primary_consumed($wpdb, 6005, ['event_id' => 9005, 'guest_phone' => '0505555555']);
$g5 = pge_maybe_grant_replacement_entitlement(9005, '0505555555', 'no', 'yes');
check('5. no→yes → skipped/transition_not_eligible', $g5['reason'] ?? null, 'transition_not_eligible');

// 6) pending → yes → لا منح
$g6 = pge_maybe_grant_replacement_entitlement(9005, '0505555555', 'pending', 'yes');
check('6. pending→yes → skipped/transition_not_eligible', $g6['reason'] ?? null, 'transition_not_eligible');

// 7) لا يوجد primary consumed إطلاقاً → skipped/no_consumed_primary
seed_event(9007, 8801);
$g7 = pge_maybe_grant_replacement_entitlement(9007, '0507777777', 'pending', 'no');
check('7. لا primary مستهلك → skipped', $g7['result'] ?? null, 'skipped');
check('7. reason=no_consumed_primary', $g7['reason'] ?? null, 'no_consumed_primary');

// 8) primary reserved فقط → لا منح
seed_event(9008, 8801);
seed_primary_consumed($wpdb, 6008, ['event_id' => 9008, 'guest_phone' => '0508888888', 'status' => 'reserved']);
$g8 = pge_maybe_grant_replacement_entitlement(9008, '0508888888', 'pending', 'no');
check('8. primary reserved فقط → skipped/no_consumed_primary', $g8['reason'] ?? null, 'no_consumed_primary');

// 9) primary failed فقط → لا منح
seed_event(9009, 8801);
seed_primary_consumed($wpdb, 6009, ['event_id' => 9009, 'guest_phone' => '0509999999', 'status' => 'failed']);
$g9 = pge_maybe_grant_replacement_entitlement(9009, '0509999999', 'pending', 'no');
check('9. primary failed فقط → skipped/no_consumed_primary', $g9['reason'] ?? null, 'no_consumed_primary');

// 10) primary refunded فقط → لا منح
seed_event(9010, 8801);
seed_primary_consumed($wpdb, 6010, ['event_id' => 9010, 'guest_phone' => '0501010101', 'status' => 'refunded']);
$g10 = pge_maybe_grant_replacement_entitlement(9010, '0501010101', 'pending', 'no');
check('10. primary refunded فقط → skipped/no_consumed_primary', $g10['reason'] ?? null, 'no_consumed_primary');

// 11) primary consumed + replacement consumed لنفس الهاتف → المصدر primary فقط
seed_event(9011, 8801);
seed_primary_consumed($wpdb, 6011, ['event_id' => 9011, 'guest_phone' => '0501111100']);
$wpdb->seed_ledger_row(6111, [
    'user_id' => 8801, 'credit_cycle_id' => 'GRANT-CYCLE-A', 'event_id' => 9011,
    'guest_phone' => '0501111100', 'credit_type' => 'replacement', 'status' => 'consumed',
    'consumed_at' => '2026-01-02 10:00:00', 'created_at' => '2026-01-02 09:00:00',
]);
$g11 = pge_maybe_grant_replacement_entitlement(9011, '0501111100', 'pending', 'no');
check('11. created رغم وجود replacement consumed أيضاً', $g11['result'] ?? null, 'created');
$ent_11 = PGE_Replacement_Entitlements::get_entitlement($g11['id'] ?? 0);
check('11. source_ledger_id المختار هو primary (6011) لا replacement (6111)', $ent_11['source_ledger_id'] ?? null, 6011);

// 12) اختلاف user_id — صف Ledger يخص مستخدماً آخر غير مالك المناسبة الفعلي
seed_event(9012, 8801); // المالك الفعلي 8801
$wpdb->seed_ledger_row(6012, [ // لكن الصف المزروع يخص مستخدماً آخر (8899)
    'user_id' => 8899, 'credit_cycle_id' => 'GRANT-CYCLE-A', 'event_id' => 9012,
    'guest_phone' => '0501212121', 'credit_type' => 'primary', 'status' => 'consumed',
    'consumed_at' => '2026-01-01 10:00:00', 'created_at' => '2026-01-01 09:00:00',
]);
$g12 = pge_maybe_grant_replacement_entitlement(9012, '0501212121', 'pending', 'no');
check('12. لا يُستخدم صف مستخدم آخر → skipped/no_consumed_primary', $g12['reason'] ?? null, 'no_consumed_primary');

// 13) اختلاف event_id — صف Ledger يخص مناسبة أخرى
seed_event(9013, 8801);
$wpdb->seed_ledger_row(6013, [
    'user_id' => 8801, 'credit_cycle_id' => 'GRANT-CYCLE-A', 'event_id' => 777777,
    'guest_phone' => '0501313131', 'credit_type' => 'primary', 'status' => 'consumed',
    'consumed_at' => '2026-01-01 10:00:00', 'created_at' => '2026-01-01 09:00:00',
]);
$g13 = pge_maybe_grant_replacement_entitlement(9013, '0501313131', 'pending', 'no');
check('13. لا يُستخدم صف مناسبة أخرى → skipped/no_consumed_primary', $g13['reason'] ?? null, 'no_consumed_primary');

// 14) اختلاف phone — صف Ledger يخص هاتفاً آخر
seed_event(9014, 8801);
seed_primary_consumed($wpdb, 6014, ['event_id' => 9014, 'guest_phone' => '0501414141']);
$g14 = pge_maybe_grant_replacement_entitlement(9014, '0509999998', 'pending', 'no');
check('14. لا يُستخدم صف هاتف آخر → skipped/no_consumed_primary', $g14['reason'] ?? null, 'no_consumed_primary');

// 15) تطبيع الهاتف قبل البحث والمنح
seed_event(9015, 8801);
seed_primary_consumed($wpdb, 6015, ['event_id' => 9015, 'guest_phone' => '0501515151']);
$g15 = pge_maybe_grant_replacement_entitlement(9015, '050-1515151', 'pending', 'no'); // تنسيق مختلف لنفس الرقم
check('15. تطبيع الهاتف يسمح بالمطابقة رغم اختلاف التنسيق', $g15['result'] ?? null, 'created');

// 16) صفوف consumed عبر دورتين بتوقيتين مختلفين → اختيار الأحدث (consumed_at DESC)
seed_event(9016, 8801);
$wpdb->seed_ledger_row(6016, [ // دورة قديمة، consumed_at أقدم، id أقل
    'user_id' => 8801, 'credit_cycle_id' => 'CYCLE-OLD', 'event_id' => 9016,
    'guest_phone' => '0501616161', 'credit_type' => 'primary', 'status' => 'consumed',
    'consumed_at' => '2026-01-01 08:00:00', 'created_at' => '2026-01-01 07:00:00',
]);
$wpdb->seed_ledger_row(6017, [ // دورة جديدة، consumed_at أحدث، id أعلى
    'user_id' => 8801, 'credit_cycle_id' => 'CYCLE-NEW', 'event_id' => 9016,
    'guest_phone' => '0501616161', 'credit_type' => 'primary', 'status' => 'consumed',
    'consumed_at' => '2026-01-05 08:00:00', 'created_at' => '2026-01-05 07:00:00',
]);
$g16 = pge_maybe_grant_replacement_entitlement(9016, '0501616161', 'pending', 'no');
$ent_16 = PGE_Replacement_Entitlements::get_entitlement($g16['id'] ?? 0);
check('16. الدورة المختارة هي الأحدث (CYCLE-NEW) بـconsumed_at DESC', $ent_16['credit_cycle_id'] ?? null, 'CYCLE-NEW');

// 17) consumed_at متساوٍ → الحسم بـid DESC
seed_event(9017, 8801);
$wpdb->seed_ledger_row(6018, [ // id أقل، نفس consumed_at
    'user_id' => 8801, 'credit_cycle_id' => 'CYCLE-TIE-LOW', 'event_id' => 9017,
    'guest_phone' => '0501717171', 'credit_type' => 'primary', 'status' => 'consumed',
    'consumed_at' => '2026-01-03 08:00:00', 'created_at' => '2026-01-03 07:00:00',
]);
$wpdb->seed_ledger_row(6019, [ // id أعلى، نفس consumed_at بالضبط
    'user_id' => 8801, 'credit_cycle_id' => 'CYCLE-TIE-HIGH', 'event_id' => 9017,
    'guest_phone' => '0501717171', 'credit_type' => 'primary', 'status' => 'consumed',
    'consumed_at' => '2026-01-03 08:00:00', 'created_at' => '2026-01-03 07:00:00',
]);
$g17 = pge_maybe_grant_replacement_entitlement(9017, '0501717171', 'pending', 'no');
$ent_17 = PGE_Replacement_Entitlements::get_entitlement($g17['id'] ?? 0);
check('17. تساوي consumed_at → الحسم بـid DESC (CYCLE-TIE-HIGH، id=6019)', $ent_17['credit_cycle_id'] ?? null, 'CYCLE-TIE-HIGH');

// 18) تكرار التنفيذ من Web ثم WhatsApp
// (أ) تسلسلي حقيقي: الحالة القديمة الفعلية تتحدث بين الاستدعاءين
seed_event(9018, 8801);
seed_primary_consumed($wpdb, 6020, ['event_id' => 9018, 'guest_phone' => '0501818181']);
$g18a = pge_maybe_grant_replacement_entitlement(9018, '0501818181', 'yes', 'no'); // مثل Web
check('18a. أول استدعاء (Web) → created', $g18a['result'] ?? null, 'created');
$g18b = pge_maybe_grant_replacement_entitlement(9018, '0501818181', 'no', 'no'); // مثل WhatsApp لاحقاً — old الفعلي أصبح no
check('18b. ثانٍ (WhatsApp) بالحالة القديمة الفعلية no → skipped/transition_not_eligible', $g18b['reason'] ?? null, 'transition_not_eligible');
// (ب) محاكاة سباق: كلا الاستدعاءين يريان old=yes (نادر لكن الـRepository يحسمه ذرياً)
seed_event(9118, 8801);
seed_primary_consumed($wpdb, 6021, ['event_id' => 9118, 'guest_phone' => '0501919191']);
$g18c = pge_maybe_grant_replacement_entitlement(9118, '0501919191', 'yes', 'no');
$g18d = pge_maybe_grant_replacement_entitlement(9118, '0501919191', 'yes', 'no'); // كلاهما "رأى" yes قبل التحديث
check('18c. محاكاة سباق: الأول created', $g18c['result'] ?? null, 'created');
check('18d. محاكاة سباق: الثاني already_exists (لا صف مكرر، عبر UNIQUE في الـRepository)', $g18d['result'] ?? null, 'already_exists');
check('18. نفس id في كلا محاولتي السباق', $g18c['id'] ?? null, $g18d['id'] ?? null);

// 19) خطأ من الـRepository → RSVP (خارج هذا الاختبار) يبقى، والدالة تُمرِّر error
seed_event(9019, 8801);
seed_primary_consumed($wpdb, 6022, ['event_id' => 9019, 'guest_phone' => '0502020202']);
$wpdb->force_entitlement_insert_failure = true;
$g19 = pge_maybe_grant_replacement_entitlement(9019, '0502020202', 'pending', 'no');
check('19. فشل Repository يُمرَّر كـerror', $g19['result'] ?? null, 'error');
check_true('19. reason موجود', !empty($g19['reason']));

// 20) مناسبة غير صالحة → لا انهيار، skipped/invalid_event
$g20 = pge_maybe_grant_replacement_entitlement(999999, '0502121212', 'pending', 'no');
check('20. event_id غير موجود → skipped/invalid_event', $g20['reason'] ?? null, 'invalid_event');

// 21) مالك غير صالح → لا Fatal ولا منح
seed_event(9021, 0); // post_author = 0 (غير صالح)
$g21 = pge_maybe_grant_replacement_entitlement(9021, '0502222233', 'pending', 'no');
check('21. post_author=0 → skipped/invalid_owner', $g21['reason'] ?? null, 'invalid_owner');

echo "\n=== اختبارات الربط مع pge_save_rsvp_response() (22-26) ===\n";

// عزل بيانات هذا القسم: الأقسام 1-21 أعلاه أنشأت بالفعل عدة استحقاقات حقيقية
// لنفس (user_id=8801, credit_cycle_id='GRANT-CYCLE-A') — عبر اختبارات
// 1، 2، 3، 11، 15، 18a، 18c تحديداً (كل منها result='created'). دون تفريغ
// الجدول الوهمي هنا، أي تحقّق **مطلق** لاحق على count_granted() (كاختبار 22
// أدناه) سيقارن بقيمة تراكمية من كل الاختبارات السابقة، لا بقيمة هذا القسم
// وحده. تفريغ صريح لجدول Entitlements فقط (بلا مساس بجدولي Ledger أو RSVP —
// اختبارات 23/24 اللاحقة تعتمد أصلاً على قراءة "قبل/بعد" نسبية فتبقى صحيحة
// بصرف النظر عن هذا التفريغ) يضمن أن اختبار 22 يبدأ من رصيد صفري حقيقي.
$wpdb->reset_entitlements();

// 22) pending → no: يحفظ no ثم يمنح
seed_event(9022, 8801);
seed_primary_consumed($wpdb, 6030, ['event_id' => 9022, 'guest_phone' => '0503030303']);
$r22 = pge_save_rsvp_response(9022, '0503030303', 'no', 0, '', true);
check_true('22. حفظ RSVP نجح', $r22['success'] ?? false);
check('22. reply=no محفوظة', $wpdb->raw_rsvp_row_by_key(9022, '0503030303')['reply'] ?? null, 'no');
check('22. entitlement مُنِح (count_granted=1 بعد عزل بيانات هذا القسم)', PGE_Replacement_Entitlements::count_granted(8801, 'GRANT-CYCLE-A'), 1);

// 23) no → no: يبقى no، ولا يمنح ثانية
$granted_before_23 = PGE_Replacement_Entitlements::count_granted(8801, 'GRANT-CYCLE-A');
$r23 = pge_save_rsvp_response(9022, '0503030303', 'no', 0, '', true);
check_true('23. حفظ RSVP الثاني نجح أيضاً', $r23['success'] ?? false);
check('23. reply تبقى no', $wpdb->raw_rsvp_row_by_key(9022, '0503030303')['reply'] ?? null, 'no');
check('23. لا استحقاق إضافي (العدد لم يتغيّر)', PGE_Replacement_Entitlements::count_granted(8801, 'GRANT-CYCLE-A'), $granted_before_23);

// 24) yes → no: يمنح
seed_event(9024, 8801);
seed_primary_consumed($wpdb, 6031, ['event_id' => 9024, 'guest_phone' => '0504040404']);
pge_save_rsvp_response(9024, '0504040404', 'yes', 0, '', true); // تمهيد: yes أولاً
$granted_before_24 = PGE_Replacement_Entitlements::count_granted(8801, 'GRANT-CYCLE-A');
$r24 = pge_save_rsvp_response(9024, '0504040404', 'no', 0, '', true);
check_true('24. حفظ RSVP نجح', $r24['success'] ?? false);
check('24. استحقاق جديد مُنِح', PGE_Replacement_Entitlements::count_granted(8801, 'GRANT-CYCLE-A'), $granted_before_24 + 1);

// 25) no → yes: لا يسحب entitlement
$ent_25 = PGE_Replacement_Entitlements::get_entitlement_by_source('GRANT-CYCLE-A', 9024, '0504040404');
check('25. تمهيد: الاستحقاق موجود بحالة granted', $ent_25['status'] ?? null, 'granted');
$r25 = pge_save_rsvp_response(9024, '0504040404', 'yes', 0, '', true);
check_true('25. حفظ RSVP (تراجع لـyes) نجح', $r25['success'] ?? false);
$ent_25b = PGE_Replacement_Entitlements::get_entitlement_by_source('GRANT-CYCLE-A', 9024, '0504040404');
check('25. الاستحقاق يبقى granted (لم يُسحَب)', $ent_25b['status'] ?? null, 'granted');

// 26) فشل/تخطي المنح لا يغيّر نتيجة نجاح RSVP (بلا primary consumed إطلاقاً)
seed_event(9026, 8801);
$r26 = pge_save_rsvp_response(9026, '0506060606', 'no', 0, '', true);
check_true('26. حفظ RSVP ينجح رغم عدم وجود primary consumed (لا يُوقَف الحفظ)', $r26['success'] ?? false);
check('26. reply=no محفوظة رغم تخطي المنح', $wpdb->raw_rsvp_row_by_key(9026, '0506060606')['reply'] ?? null, 'no');

echo "\n=== اختبارات record_rsvp() عبر Cartat (27-32) ===\n";

// 27) WhatsApp pending → no: يحفظ no ثم يمنح
seed_event(9027, 8801);
seed_primary_consumed($wpdb, 6040, ['event_id' => 9027, 'guest_phone' => '0507070707']);
call_record_rsvp($record_rsvp_ref, $handler, 9027, '0507070707', 'no');
check('27. reply=no محفوظة عبر WhatsApp', $wpdb->raw_rsvp_row_by_key(9027, '0507070707')['reply'] ?? null, 'no');
check('27. entitlement مُنِح عبر WhatsApp', PGE_Replacement_Entitlements::get_entitlement_by_source('GRANT-CYCLE-A', 9027, '0507070707')['status'] ?? null, 'granted');

// 28) WhatsApp yes → no: يمنح
seed_event(9028, 8801);
seed_primary_consumed($wpdb, 6041, ['event_id' => 9028, 'guest_phone' => '0508080808']);
call_record_rsvp($record_rsvp_ref, $handler, 9028, '0508080808', 'yes'); // تمهيد
$granted_before_28 = PGE_Replacement_Entitlements::count_granted(8801, 'GRANT-CYCLE-A');
call_record_rsvp($record_rsvp_ref, $handler, 9028, '0508080808', 'no');
check('28. entitlement جديد مُنِح بعد yes→no عبر WhatsApp', PGE_Replacement_Entitlements::count_granted(8801, 'GRANT-CYCLE-A'), $granted_before_28 + 1);

// 29) WhatsApp no → no: لا يمنح ثانية
$granted_before_29 = PGE_Replacement_Entitlements::count_granted(8801, 'GRANT-CYCLE-A');
call_record_rsvp($record_rsvp_ref, $handler, 9028, '0508080808', 'no'); // تكرار نفس الرد
check('29. لا استحقاق إضافي عند no→no عبر WhatsApp', PGE_Replacement_Entitlements::count_granted(8801, 'GRANT-CYCLE-A'), $granted_before_29);

// 30) فشل Repository لا يمنع تخزين الرد نفسه (وبالتبعية لا يغيّر أي سلوك لاحق
//     في المستدعي الحقيقي — رسالة التأكيد/الـWebhook response تُبنى بعد
//     عودة record_rsvp() بلا أي علاقة بنتيجة المنح)
seed_event(9030, 8801);
seed_primary_consumed($wpdb, 6042, ['event_id' => 9030, 'guest_phone' => '0500000030']);
$wpdb->force_entitlement_insert_failure = true;
$no_exception_30 = true;
try {
    call_record_rsvp($record_rsvp_ref, $handler, 9030, '0500000030', 'no');
} catch (\Throwable $e) {
    $no_exception_30 = false;
}
check_true('30. لا استثناء من record_rsvp() رغم فشل الـRepository', $no_exception_30);
check('30. الرد محفوظ رغم فشل المنح', $wpdb->raw_rsvp_row_by_key(9030, '0500000030')['reply'] ?? null, 'no');

// 31) لا تغيير في parse_rsvp_reply()
$parse_ref = new ReflectionMethod('Mon_Cartat_Handler', 'parse_rsvp_reply');
$parse_ref->setAccessible(true);
check('31. parse_rsvp_reply("1") = yes (بلا تغيير)', $parse_ref->invoke($handler, '1'), 'yes');
check('31. parse_rsvp_reply("2") = no (بلا تغيير)', $parse_ref->invoke($handler, '2'), 'no');
check('31. parse_rsvp_reply("نعم") = yes (بلا تغيير)', $parse_ref->invoke($handler, 'نعم'), 'yes');
check('31. parse_rsvp_reply("لا") = no (بلا تغيير)', $parse_ref->invoke($handler, 'لا'), 'no');
check('31. parse_rsvp_reply("xyz") = "" (بلا تغيير)', $parse_ref->invoke($handler, 'xyz'), '');

// 32) لا تغيير في pending token behavior — record_rsvp() لا تلمس أي مفتاح
// pge_wa_pending_* إطلاقاً (منطق pending موجود حصراً في handle_webhook()،
// غير المُعدَّلة). تحقّق بنيوي: التوقيع العام لـrecord_rsvp() ونوع إرجاعها
// (void) لم يتغيّرا — تأكيد إضافي موثَّق يدوياً عبر المقارنة المباشرة
// (Diff) للتعديل الفعلي في التقرير النهائي، وليس اختباراً تنفيذياً هنا.
check('32. توقيع record_rsvp(): 3 معاملات كما هي', $record_rsvp_ref->getNumberOfParameters(), 3);
check_true('32. record_rsvp() تُعيد void (لا نوع إرجاع كائن جديد)', $record_rsvp_ref->hasReturnType() && (string) $record_rsvp_ref->getReturnType() === 'void');

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
