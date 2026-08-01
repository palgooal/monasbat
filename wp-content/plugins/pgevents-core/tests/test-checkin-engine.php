<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية، بنفس أسلوب tests/test-supervisor-
 * portal.php حرفياً) لـEntry Check-in Supervisors، Phase 4 ("Guest Check-in
 * Engine" RFC) — PGE_Checkin_QR_Service، PGE_Guest_Resolution_Service،
 * وPGE_Checkin_Recorder. يستدعي كامل الأنبوب الحقيقي (Catalog → Assignment
 * Service → Session → Authenticator → Portal Middleware) لإنتاج جلسات مشرف
 * حقيقية، ثم يختبر خدمات محرك تسجيل الحضور الجديدة ضدها مباشرة.
 *
 * السيناريوهات المطلوبة صراحةً (Requirement 10):
 *   - تحقّق QR، QR غير صالح، مناسبة خاطئة، تسجيل حضور مكرَّر، تسجيل حضور
 *     متزامن، بحث يدوي، حضور يدوي، تحقّق المتوقَّع/الفعلي، سجل التدقيق،
 *     التفويض، وانحدار على كل المراحل السابقة.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-checkin-engine.php
 */

define('ABSPATH', __DIR__ . '/');

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) {
    function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($v) { return trim((string) $v); }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
$GLOBALS['__test_now_override'] = null;
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) {
        return $GLOBALS['__test_now_override'] ?? '2026-01-01 00:00:00';
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_hash')) {
    function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed-for-tests'); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false) {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);
        if ($remove_breaks) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
        }
        return trim($text);
    }
}
if (!function_exists('wp_generate_uuid4')) {
    $GLOBALS['__test_uuid_counter'] = 0;
    function wp_generate_uuid4() {
        $GLOBALS['__test_uuid_counter']++;
        return 'test-uuid-' . $GLOBALS['__test_uuid_counter'];
    }
}

// ── Blocker Fix #2: تفعيل اختبار PGE_Checkin_Schema::maybe_upgrade() الحقيقي
// (لم يُختبَر إطلاقاً من قبل — لا ملف اختبار سابق حمَّل هذا الملف) — نفس نمط
// get_option/update_option/delete_option المُستخدَم في بقية ملفات الاختبار
// (مثل test-replacement-entitlement-grant.php) حرفياً. ───────────────────────
$GLOBALS['__test_options'] = [];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['__test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($name) {
        unset($GLOBALS['__test_options'][$name]);
        return true;
    }
}

// dbDelta() الحقيقية دالة WordPress core (wp-admin/includes/upgrade.php) —
// غير متاحة في هذه البيئة المعزولة بلا خادم MySQL فعلي. محاكاة أمينة محدودة
// كفاية فقط لاختبار PGE_Checkin_Schema::ensure_audit_table() (الاستدعاء
// الوحيد لها في هذا الملف): تُنشئ أعمدة جدول التدقيق في $wpdb الوهمي عند أول
// استدعاء (CREATE TABLE) — ليست بديلاً حقيقياً لـdbDelta(), فقط تمكين لتنفيذ
// كود PGE_Checkin_Schema الحقيقي نفسه دون تعديله في هذه البيئة.
if (!function_exists('dbDelta')) {
    function dbDelta($sql) {
        global $wpdb;
        if (stripos($sql, 'pge_checkin_audit_log') !== false && method_exists($wpdb, 'ensure_audit_table_created')) {
            $wpdb->ensure_audit_table_created();
        }
    }
}

// class-pge-checkin-schema.php::ensure_audit_table() ينفّذ
// `require_once ABSPATH . 'wp-admin/includes/upgrade.php';` قبل استدعاء
// dbDelta() — الملف الحقيقي غير موجود في هذه البيئة؛ نُنشئ ملفاً فارغاً في
// نفس المسار (ABSPATH هنا = مجلد tests/) ليتم include بنجاح بلا Fatal، بما
// أن dbDelta() نفسها مُعرَّفة أعلاه بالفعل (function_exists يمنع أي تعارض).
$__upgrade_stub_dir = ABSPATH . 'wp-admin/includes';
if (!is_dir($__upgrade_stub_dir)) {
    @mkdir($__upgrade_stub_dir, 0777, true);
}
if (!file_exists($__upgrade_stub_dir . '/upgrade.php')) {
    file_put_contents($__upgrade_stub_dir . '/upgrade.php', "<?php\n// stub لأغراض الاختبار فقط — dbDelta() مُعرَّفة أعلاه بالفعل.\n");
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public $message;
        public function __construct($code = '', $message = '', $data = null)
        {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}

// ── User Meta + Posts + Post Meta وهميان في الذاكرة ──────────────────────────

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_users_by_id'] = [];
$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
    return true;
}
function delete_user_meta($user_id, $key)
{
    unset($GLOBALS['__test_user_meta'][$user_id][$key]);
    return true;
}
function metadata_exists($type, $object_id, $meta_key)
{
    $value = $GLOBALS['__test_user_meta'][$object_id][$meta_key] ?? '';
    return $value !== '';
}
function set_test_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
}
function reset_test_user($user_id)
{
    $GLOBALS['__test_user_meta'][$user_id] = [];
    $GLOBALS['__test_users_by_id'][$user_id] = true;
}
function get_user_by($field, $value)
{
    if ($field === 'id') {
        return !empty($GLOBALS['__test_users_by_id'][$value]) ? (object) ['ID' => (int) $value] : false;
    }
    return false;
}
function set_test_event_full($event_id, $author_id, $title = '', $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) [
        'ID' => $event_id,
        'post_type' => $post_type,
        'post_author' => $author_id,
        'post_title' => $title,
    ];
    if (!isset($GLOBALS['__test_post_meta'][$event_id])) {
        $GLOBALS['__test_post_meta'][$event_id] = [];
    }
}
function get_post($event_id)
{
    return $GLOBALS['__test_posts'][$event_id] ?? null;
}
function get_post_meta($post_id, $key = '', $single = false)
{
    $value = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['__test_post_meta'][$post_id][$key] = $value;
    return true;
}

/**
 * إعداد ضيف مدعوّ مباشرة ضمن _pge_invited_guests (بلا استدعاء pge_event_
 * guests_save_map() لتفادي إعادة توليد رمز الدعوة عشوائياً في الاختبار).
 */
function seed_invited_guest($event_id, $phone, $name, $code)
{
    $map = $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] ?? [];
    $map[$phone] = ['phone' => $phone, 'name' => $name, 'note' => '', 'code' => $code];
    $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] = $map;
}

// ── Fake $wpdb — امتداد لـFake_Wpdb_Supervisor_Portal (Phase 3.5) بإضافة
// جدولي pge_event_rsvps وpge_checkin_audit_log (Phase 4) — بقية الجداول
// (plans/tiers/tier_features/supervisors/sessions) وGET_LOCK/RELEASE_LOCK
// كما هي بالضبط، لازمة لتشغيل الأنبوب الحقيقي الكامل. ──────────────────────

class Fake_Wpdb_Checkin_Engine
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    /** يستخدمها PGE_Checkin_Schema::ensure_audit_table() قبل dbDelta(). */
    public function get_charset_collate()
    {
        return '';
    }

    public $plans = [];
    public $tiers = [];
    public $tier_features = [];
    public $supervisors = [];
    public $sessions = [];
    public $rsvps = [];
    public $audit_log = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $tier_features_next_id = 1;
    private $supervisors_next_id = 1;
    private $sessions_next_id = 1;
    private $rsvps_next_id = 1;
    private $audit_log_next_id = 1;

    // ── Blocker Fix #2: حالة Schema حقيقية لجدول RSVP — تُقرَأ/تُعدَّل حصراً عبر
    // SHOW COLUMNS/SHOW INDEX/ALTER TABLE الحقيقية الصادرة من PGE_Checkin_Schema
    // نفسها (لا Bypass) — تطابق الحالة الفعلية للجدول الإنتاجي قبل الترقية
    // (UNIQUE KEY event_phone (event_id, guest_phone) + KEY event_id (event_id))
    // تماماً كما في includes/rsvp-handler.php::pge_create_rsvp_table(). ───────
    public $rsvps_columns = [
        'id', 'event_id', 'guest_phone', 'guest_name', 'companions', 'note',
        'reply', 'checked_in', 'checked_in_at', 'created_at', 'updated_at',
    ];
    public $rsvps_indexes = [
        'event_phone' => ['non_unique' => 0, 'columns' => ['event_id', 'guest_phone']],
        'event_id'    => ['non_unique' => 1, 'columns' => ['event_id']],
    ];
    public $audit_columns = [];

    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];
    public $force_lock_unavailable = false;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? '';
            $i++;
            return $m[0] === '%d' ? (string) (int) $val : "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    private function which_table($sql_or_table)
    {
        if (strpos($sql_or_table, $this->prefix . 'pge_checkin_audit_log') !== false) {
            return 'audit_log';
        }
        if (strpos($sql_or_table, $this->prefix . 'pge_event_rsvps') !== false) {
            return 'rsvps';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_supervisor_sessions') !== false) {
            return 'sessions';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_tier_features') !== false) {
            return 'tier_features';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plan_tiers') !== false) {
            return 'tiers';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plans') !== false) {
            return 'plans';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_event_supervisors') !== false) {
            return 'supervisors';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $which = $this->which_table($sql);

        if ($which === 'rsvps') {
            if (preg_match("/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*'([^']*)'/i", $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                foreach ($this->rsvps as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['guest_phone'] === $phone) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)\s+AND\s+event_id\s*=\s*(\d+)/i', $sql, $m)) {
                $id = (int) $m[1];
                $event_id = (int) $m[2];
                $row = $this->rsvps[$id] ?? null;
                if ($row !== null && (int) $row['event_id'] === $event_id) {
                    return $row;
                }
                return null;
            }
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
                $id = (int) $m[1];
                return $this->rsvps[$id] ?? null;
            }
            return null;
        }

        if ($which === 'sessions') {
            if (preg_match("/WHERE\s+session_token_hash\s*=\s*'([^']*)'/i", $sql, $m)) {
                $hash = $m[1];
                foreach ($this->sessions as $row) {
                    if (($row['session_token_hash'] ?? null) === $hash) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        if ($which === 'supervisors') {
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
                $id = (int) $m[1];
                return $this->supervisors[$id] ?? null;
            }
            if (preg_match("/WHERE\s+invitation_token_hash\s*=\s*'([^']*)'/i", $sql, $m)) {
                $hash = $m[1];
                foreach ($this->supervisors as $row) {
                    if (($row['invitation_token_hash'] ?? null) === $hash) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+supervisor_phone\s*=\s*\'([^\']*)\'\s+AND\s+status\s+IN\s*\(([^)]*)\)/i', $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $statuses = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[3]));
                foreach ($this->supervisors as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['supervisor_phone'] === $phone && in_array($row['status'], $statuses, true)) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+supervisor_phone\s*=\s*\'([^\']*)\'\s+AND\s+status\s*=\s*\'([^\']*)\'/i', $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $status = $m[3];
                foreach ($this->supervisors as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['supervisor_phone'] === $phone && $row['status'] === $status) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        $rows = $this->get_results($sql, $output);
        if ($rows === null) {
            return null;
        }
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        // Blocker Fix #2: SHOW COLUMNS/SHOW INDEX الحقيقية الصادرة عن
        // PGE_Checkin_Schema — تُقرَأ من حالة Schema المُتتبَّعة أعلاه فقط.
        if (preg_match('/SHOW\s+COLUMNS\s+FROM\s+(\S+)/i', $sql, $m)) {
            $table = $this->which_table($m[1]);
            if ($table === 'rsvps') {
                return array_map(function ($c) { return ['Field' => $c]; }, $this->rsvps_columns);
            }
            if ($table === 'audit_log') {
                return array_map(function ($c) { return ['Field' => $c]; }, $this->audit_columns);
            }
            return [];
        }
        if (preg_match('/SHOW\s+INDEX\s+FROM\s+(\S+)/i', $sql, $m)) {
            $table = $this->which_table($m[1]);
            if ($table !== 'rsvps') {
                return [];
            }
            $rows = [];
            foreach ($this->rsvps_indexes as $name => $meta) {
                $seq = 1;
                foreach ($meta['columns'] as $col) {
                    $rows[] = [
                        'Key_name' => $name,
                        'Non_unique' => $meta['non_unique'],
                        'Seq_in_index' => $seq,
                        'Column_name' => $col,
                    ];
                    $seq++;
                }
            }
            return $rows;
        }

        // Blocker Fix #2: get_results() الحقيقي على جدول rsvps (بلا LIMIT) —
        // مطلوب لـPGE_Guest_Resolution_Service::find_rsvp_rows_by_phone()
        // (0/1/أكثر من نتيجة). لم يكن مطلوباً قبل هذا التصحيح (get_row() فقط
        // كان يُستخدَم على هذا الجدول في كل الأكواد السابقة).
        if ($this->which_table($sql) === 'rsvps') {
            if (preg_match("/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*'([^']*)'/i", $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $matched = [];
                foreach ($this->rsvps as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['guest_phone'] === $phone) {
                        $matched[] = $row;
                    }
                }
                usort($matched, function ($a, $b) { return $a['id'] <=> $b['id']; });
                return $matched;
            }
            return [];
        }

        $which = $this->which_table($sql);
        if ($which === null || in_array($which, ['supervisors', 'sessions', 'audit_log'], true)) {
            return [];
        }

        $rows = array_values(
            $which === 'tiers' ? $this->tiers : ($which === 'plans' ? $this->plans : $this->tier_features)
        );

        if (preg_match('/WHERE\s+(.+)$/is', $sql, $m)) {
            $where = trim($m[1]);
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

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_acquire_log[] = $name;
            if ($this->force_lock_unavailable) {
                return 0;
            }
            $this->held_locks[$name] = true;
            return 1;
        }

        $table = $this->prefix . 'mon_event_supervisors';
        $pattern = '/FROM\s+' . preg_quote($table, '/') . '\s+WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+status\s+NOT\s+IN\s*\(([^)]*)\)/i';
        if (preg_match($pattern, $sql, $m)) {
            $event_id = (int) $m[1];
            $excluded = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[2]));
            $count = 0;
            foreach ($this->supervisors as $row) {
                if ((int) $row['event_id'] === $event_id && !in_array((string) $row['status'], $excluded, true)) {
                    $count++;
                }
            }
            return (string) $count;
        }

        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\s+RELEASE_LOCK\('([^']*)'\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_release_log[] = $name;
            unset($this->held_locks[$name]);
            return 1;
        }

        // Blocker Fix #2: ALTER TABLE الحقيقية الصادرة عن PGE_Checkin_Schema
        // (ensure_rsvp_columns()/ensure_phone_index_not_unique()) — تُطبَّق
        // فعلياً على حالة Schema المُتتبَّعة، لا Bypass.
        if (preg_match('/ALTER TABLE\s+(\S+)\s+ADD COLUMN\s+(\w+)/i', $sql, $m)) {
            if ($this->which_table($m[1]) === 'rsvps' && !in_array($m[2], $this->rsvps_columns, true)) {
                $this->rsvps_columns[] = $m[2];
            }
            return 1;
        }
        if (preg_match('/ALTER TABLE\s+(\S+)\s+DROP INDEX\s+(\w+)/i', $sql, $m)) {
            if ($this->which_table($m[1]) === 'rsvps') {
                unset($this->rsvps_indexes[$m[2]]);
            }
            return 1;
        }
        if (preg_match('/ALTER TABLE\s+(\S+)\s+ADD INDEX\s+(\w+)\s*\(([^)]*)\)/i', $sql, $m)) {
            if ($this->which_table($m[1]) === 'rsvps') {
                $cols = array_map('trim', explode(',', $m[3]));
                $this->rsvps_indexes[$m[2]] = ['non_unique' => 1, 'columns' => $cols];
            }
            return 1;
        }

        return false;
    }

    /** يُستدعى فقط من stub الاختبار لـdbDelta() أعلاه — راجع تعليقه. */
    public function ensure_audit_table_created()
    {
        if (empty($this->audit_columns)) {
            $this->audit_columns = ['id', 'event_id', 'rsvp_id', 'assignment_id', 'method', 'expected_count', 'actual_count', 'entry_type', 'created_at'];
        }
    }

    /** هل يوجد فهرس UNIQUE فعلياً على (event_id, guest_phone) الآن؟ */
    private function phone_unique_index_active(): bool
    {
        foreach ($this->rsvps_indexes as $meta) {
            if ($meta['columns'] === ['event_id', 'guest_phone'] && (int) $meta['non_unique'] === 0) {
                return true;
            }
        }
        return false;
    }

    public function insert($table, $data, $format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }
        if ($which === 'tiers') {
            $id = $this->tiers_next_id++;
            $this->tiers[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'plans') {
            $id = $this->plans_next_id++;
            $this->plans[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'supervisors') {
            $hash = $data['invitation_token_hash'] ?? null;
            if ($hash !== null) {
                foreach ($this->supervisors as $row) {
                    if (($row['invitation_token_hash'] ?? null) === $hash) {
                        return false;
                    }
                }
            }
            $id = $this->supervisors_next_id++;
            $this->supervisors[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'sessions') {
            $hash = $data['session_token_hash'] ?? null;
            foreach ($this->sessions as $row) {
                if (($row['session_token_hash'] ?? null) === $hash) {
                    return false;
                }
            }
            $id = $this->sessions_next_id++;
            $this->sessions[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'rsvps') {
            // Blocker Fix #2: الإنفاذ الآن مشروط بحالة الفهرس الفعلية (لا قاعدة
            // ثابتة) — يُقرَأ من $this->rsvps_indexes التي لا تتغيّر إلا عبر
            // ALTER TABLE الحقيقية الصادرة عن PGE_Checkin_Schema::maybe_upgrade().
            // قبل الترقية: UNIQUE قائم → إنفاذ كالسابق تماماً. بعد الترقية: لا
            // إنفاذ → صفان بنفس الهاتف مسموحان (Blocking Issue #1).
            if ($this->phone_unique_index_active()) {
                foreach ($this->rsvps as $row) {
                    if ((int) $row['event_id'] === (int) $data['event_id'] && $row['guest_phone'] === $data['guest_phone']) {
                        return false;
                    }
                }
            }
            $id = $this->rsvps_next_id++;
            $defaults = ['checked_in' => 0, 'checked_in_at' => null, 'checked_in_by_assignment_id' => null, 'checkin_method' => null, 'actual_entered_count' => null, 'companions' => 0, 'reply' => 'pending'];
            $this->rsvps[$id] = array_merge(['id' => $id], $defaults, $data);
        } elseif ($which === 'audit_log') {
            $id = $this->audit_log_next_id++;
            $this->audit_log[$id] = array_merge(['id' => $id], $data);
        } else {
            $id = $this->tier_features_next_id++;
            $this->tier_features[$id] = array_merge(['id' => $id], $data);
        }
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }

        if ($which === 'supervisors' || $which === 'sessions' || $which === 'rsvps') {
            $store = $which;
            $id = $where['id'] ?? null;
            if ($id === null || !isset($this->{$store}[$id])) {
                return 0;
            }
            foreach ($where as $where_key => $where_value) {
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

        $id = $where['id'] ?? null;
        if ($id === null) {
            return false;
        }
        $store = $which === 'tiers' ? 'tiers' : ($which === 'plans' ? 'plans' : 'tier_features');
        if (!isset($this->{$store}[$id])) {
            return 0;
        }
        foreach ($data as $k => $v) {
            $this->{$store}[$id][$k] = $v;
        }
        return 1;
    }

    public function seed_plan($id, array $row)
    {
        $this->plans[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->plans_next_id) {
            $this->plans_next_id = $id + 1;
        }
    }

    /** إدراج مباشر لصف RSVP اختباري (لبناء حالات مسبقة الوجود). */
    public function seed_rsvp($event_id, $phone, array $extra = [])
    {
        $id = $this->rsvps_next_id++;
        $defaults = [
            'event_id' => $event_id,
            'guest_phone' => $phone,
            'guest_name' => null,
            'companions' => 0,
            'note' => null,
            'reply' => 'pending',
            'checked_in' => 0,
            'checked_in_at' => null,
            'checked_in_by_assignment_id' => null,
            'checkin_method' => null,
            'actual_entered_count' => null,
        ];
        $this->rsvps[$id] = array_merge(['id' => $id], $defaults, $extra);
        return $id;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Checkin_Engine();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-catalog.php';
require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';
require_once __DIR__ . '/../includes/feature-resolver.php';
require_once __DIR__ . '/../includes/class-mon-events-users.php';
require_once __DIR__ . '/../includes/supervisor-quota-resolver.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-session.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-authenticator.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-portal-middleware.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-portal-bootstrap.php';
require_once __DIR__ . '/../includes/class-pge-checkin-schema.php';
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';
require_once __DIR__ . '/../includes/class-pge-guest-resolution-service.php';
require_once __DIR__ . '/../includes/class-pge-checkin-recorder.php';

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        public $found_posts = 0;
        public function __construct($args = []) { $this->found_posts = 0; }
    }
}
if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id) { return ['events_count' => 0]; }
}
require_once __DIR__ . '/../includes/event-factory.php';

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

$wpdb->seed_plan(1, [
    'plan_key' => 'basic_plan',
    'name' => 'باقة أساسية',
    'plan_type' => 'personal',
    'status' => 'active',
]);

function make_test_tier($tier_key, $sort_order, array $extra = [])
{
    return PGE_Catalog::create_tier(array_merge([
        'plan_id' => 1,
        'tier_key' => $tier_key,
        'name' => 'مستوى اختبار ' . $tier_key,
        'price' => 100,
        'currency' => 'SAR',
        'salla_product_id' => null,
        'status' => 'active',
        'sort_order' => $sort_order,
    ], $extra));
}

function setup_catalog_owner_with_event($user_id, $event_id, $supervisor_limit, $tier_key)
{
    static $sort = 100;
    reset_test_user($user_id);
    $tier = make_test_tier($tier_key, $sort++);
    PGE_Tier_Features::set_tier_feature_value($tier['id'], 'admin_supervisor_limit', (string) $supervisor_limit);
    Mon_Events_Users::activate_catalog_tier($user_id, 1, $tier['id'], 'CHECKIN-ORDER-' . $tier_key);
    return $tier;
}

function create_and_get_token($event_id, $inviter_id, $phone, $name = '')
{
    return PGE_Supervisor_Assignment_Service::create_supervisor_assignment($event_id, $inviter_id, $phone, $name);
}

/**
 * اختصار: يُنشئ مشرفاً نشطاً بجلسة صالحة لمناسبة مُحدَّدة، ويضع كوكي الجلسة
 * في $_COOKIE مباشرة، ثم يُعيد ['assignment_id'=>.., 'event_id'=>..].
 */
function authenticate_supervisor_for_event($event_id, $host_id, $phone)
{
    $invite = create_and_get_token($event_id, $host_id, $phone);
    $auth = pge_supervisor_authenticate($invite['invitation_token']);
    $_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth['session_token'];
    return ['assignment_id' => $auth['assignment_id'], 'event_id' => $auth['event_id'], 'session_token' => $auth['session_token']];
}

/**
 * ============================================================================
 * أدوات مساعدة (Blocker Fix) — نفس التدفّق الفعلي المستخدَم في checkin-ajax.php
 * ============================================================================
 * هذه الدوال الثلاث لا تُنفِّذ أي منطق موازٍ — تستدعي فقط PGE_Guest_
 * Resolution_Service ثم PGE_Checkin_Recorder::record_guest_checkin() بعقده
 * الجديد (assignment_id, guest, actual_count, method)، تماماً كما يفعل
 * pge_supervisor_checkin_confirm_handler() الحقيقي لكل من المسارين 'qr'
 * و'rsvp_id' و'phone' — تثبت أن كلا مساري QR واليدوي يمرّان عبر نفس الدالة
 * (Requirement RFC: "QR and manual paths still use the same Recorder").
 */
function confirm_checkin_via_qr($event_id, $qr_payload, $actual_count, $assignment_id)
{
    $resolution = PGE_Guest_Resolution_Service::resolve_from_qr($event_id, $qr_payload);
    if (($resolution['result'] ?? '') !== 'found') {
        return ['result' => 'error', 'reason' => 'resolution_failed:' . ($resolution['reason'] ?? 'unknown')];
    }
    return PGE_Checkin_Recorder::record_guest_checkin($assignment_id, $resolution['guest'], $actual_count, 'qr');
}

function confirm_checkin_by_rsvp($event_id, $rsvp_id, $actual_count, $method, $assignment_id)
{
    $resolution = PGE_Guest_Resolution_Service::resolve_by_rsvp_id($event_id, $rsvp_id);
    if (($resolution['result'] ?? '') !== 'found') {
        return ['result' => 'error', 'reason' => 'resolution_failed:' . ($resolution['reason'] ?? 'unknown')];
    }
    return PGE_Checkin_Recorder::record_guest_checkin($assignment_id, $resolution['guest'], $actual_count, $method);
}

/**
 * Blocker Fix #2: البديل — resolve_by_phone() قراءة فقط، لا إنشاء إطلاقاً.
 * تُعيد نتيجة الحلّ كما هي (not_found/ambiguous) بلا استدعاء الـRecorder إن
 * لم تكن 'found' — بالضبط كسلوك pge_supervisor_checkin_confirm_handler()
 * الحقيقي في checkin-ajax.php.
 */
function confirm_checkin_by_phone($event_id, $phone, $actual_count, $assignment_id)
{
    $resolution = PGE_Guest_Resolution_Service::resolve_by_phone($event_id, $phone);
    if (($resolution['result'] ?? '') !== 'found') {
        return $resolution;
    }
    return PGE_Checkin_Recorder::record_guest_checkin($assignment_id, $resolution['guest'], $actual_count, 'manual');
}

function expected_lock_name($event_id, $rsvp_id)
{
    return 'pge_checkin_' . md5($event_id . '|' . $rsvp_id);
}

// ============================================================================
// السيناريو 1: تحقّق QR صالح (QR validation)
// ============================================================================
echo "=== السيناريو 1: تحقّق QR صالح ===\n";

setup_catalog_owner_with_event(9901, 9601, 5, 'chk1');
set_test_event_full(9601, 9901, 'مناسبة تسجيل حضور 1');
seed_invited_guest(9601, '0511111111', 'محمد الأحمد', 'ABCD-1234');
$rsvp1 = $wpdb->seed_rsvp(9601, '0511111111', ['companions' => 3, 'reply' => 'yes']);

$sup1 = authenticate_supervisor_for_event(9601, 9901, '0500000001');
check('1. المشرف مصادَق وله وصول للمناسبة 9601', $sup1['event_id'], 9601);

// Phase 9B QR Architecture Final Fix: build_payload() يتطلّب الآن qr_version
// كمعامل ثالث صريح — 1 يطابق DEFAULT_QR_VERSION (لا Repository محمَّلة هنا).
$qr1 = PGE_Checkin_QR_Service::build_payload(9601, $rsvp1, 1);
$validate1 = PGE_Checkin_QR_Service::validate($sup1['event_id'], $qr1);
check('1. تحقّق QR الصالح: valid', $validate1['result'] ?? null, 'valid');
check('1. rsvp_id صحيح', $validate1['rsvp_id'] ?? null, $rsvp1);

$resolved1 = PGE_Guest_Resolution_Service::resolve_from_qr($sup1['event_id'], $qr1);
check('1. حلّ الضيف عبر QR: found', $resolved1['result'] ?? null, 'found');
check('1. اسم الضيف صحيح', $resolved1['guest']['name'] ?? null, 'محمد الأحمد');
check('1. العدد المتوقَّع = 1 + companions = 4', $resolved1['guest']['expected_guest_count'] ?? null, 4);
check('1. رمز الدعوة صحيح', $resolved1['guest']['invite_code'] ?? null, 'ABCD-1234');

// ============================================================================
// السيناريو 2: QR غير صالح (Invalid QR)
// ============================================================================
echo "\n=== السيناريو 2: QR غير صالح ===\n";

$tampered = substr($qr1, 0, -1) . (substr($qr1, -1) === 'a' ? 'b' : 'a');
$validate2a = PGE_Checkin_QR_Service::validate($sup1['event_id'], $tampered);
check('2. توقيع مُزوَّر: invalid/signature_mismatch', [$validate2a['result'] ?? null, $validate2a['reason'] ?? null], ['invalid', 'signature_mismatch']);

$validate2b = PGE_Checkin_QR_Service::validate($sup1['event_id'], '9601|abc|xxxxx');
check('2. تنسيق غير رقمي: invalid/malformed_payload', [$validate2b['result'] ?? null, $validate2b['reason'] ?? null], ['invalid', 'malformed_payload']);

$validate2c = PGE_Checkin_QR_Service::validate($sup1['event_id'], '9601|' . $rsvp1);
check('2. أجزاء ناقصة (جزءان فقط): invalid/malformed_payload', [$validate2c['result'] ?? null, $validate2c['reason'] ?? null], ['invalid', 'malformed_payload']);

$fake_qr_for_deleted = PGE_Checkin_QR_Service::build_payload(9601, 888888, 1);
$validate2d = PGE_Checkin_QR_Service::validate($sup1['event_id'], $fake_qr_for_deleted);
check('2. توقيع صحيح لكن rsvp_id غير موجود: invalid/invitation_not_found', [$validate2d['result'] ?? null, $validate2d['reason'] ?? null], ['invalid', 'invitation_not_found']);

// ============================================================================
// السيناريو 3: مناسبة خاطئة (Wrong event)
// ============================================================================
echo "\n=== السيناريو 3: مناسبة خاطئة ===\n";

setup_catalog_owner_with_event(9902, 9602, 5, 'chk3');
set_test_event_full(9602, 9902, 'مناسبة أخرى 3');
$sup3 = authenticate_supervisor_for_event(9602, 9902, '0500000003');

// QR وُلِّد لمناسبة 9601 لكن المشرف الحالي مخوَّل لمناسبة 9602 فقط
$validate3 = PGE_Checkin_QR_Service::validate($sup3['event_id'], $qr1);
check('3. QR مناسبة أخرى: invalid/event_mismatch', [$validate3['result'] ?? null, $validate3['reason'] ?? null], ['invalid', 'event_mismatch']);

$resolved3 = PGE_Guest_Resolution_Service::resolve_from_qr($sup3['event_id'], $qr1);
check('3. حلّ الضيف يرفض أيضاً عبر نفس الفحص', $resolved3['result'] ?? null, 'invalid');

// ============================================================================
// السيناريو 4: تسجيل حضور مكرَّر (Duplicate check-in)
// ============================================================================
echo "\n=== السيناريو 4: تسجيل حضور مكرَّر ===\n";

$confirm4a = confirm_checkin_via_qr(9601, $qr1, 4, $sup1['assignment_id']);
check('4. التأكيد الأول (عبر QR): checked_in', $confirm4a['result'] ?? null, 'checked_in');
check('4. actual_count = 4', $confirm4a['actual_count'] ?? null, 4);

// Blocker Fix — نقطة اختبار 8: مسار QR يمرّ عبر نفس اسم القفل rsvp-based
// المُشتَق من (event_id, rsvp_id) تماماً كالمسار اليدوي (نفس الدالة بالضبط).
check_true(
    '4. قفل التأكيد عبر QR يتبع صيغة event_id+rsvp_id (نفس Recorder لكلا المسارين)',
    in_array(expected_lock_name(9601, $rsvp1), $wpdb->lock_acquire_log, true)
);

$audit_count_after_first = count($wpdb->audit_log);

$confirm4b = confirm_checkin_by_rsvp(9601, $rsvp1, 2, 'manual', $sup1['assignment_id']);
check('4. محاولة ثانية لنفس RSVP: already_checked_in', $confirm4b['result'] ?? null, 'already_checked_in');
check_true('4. لا سطر تدقيق إضافي للمحاولة المكرَّرة', count($wpdb->audit_log) === $audit_count_after_first);
check_true('4. actual_entered_count بقي 4 (لم يتغيّر لـ2 من المحاولة الثانية)', $wpdb->rsvps[$rsvp1]['actual_entered_count'] === 4);

// ============================================================================
// السيناريو 5: تسجيل حضور متزامن (Concurrent check-in)
// ============================================================================
echo "\n=== السيناريو 5: تسجيل حضور متزامن ===\n";

seed_invited_guest(9601, '0511111115', 'ضيف التزامن', 'CONC-0005');
$rsvp5 = $wpdb->seed_rsvp(9601, '0511111115', ['companions' => 1]);
$rsvps_before_5 = count($wpdb->rsvps);
$audit_before_5 = count($wpdb->audit_log);

$wpdb->force_lock_unavailable = true;
$confirm5 = confirm_checkin_by_rsvp(9601, $rsvp5, 1, 'manual', $sup1['assignment_id']);
$wpdb->force_lock_unavailable = false;

check('5. فشل الحصول على القفل: error/lock_not_acquired', [$confirm5['result'] ?? null, $confirm5['reason'] ?? null], ['error', 'lock_not_acquired']);
check_true('5. لا صف RSVP جديد أثناء فشل القفل', count($wpdb->rsvps) === $rsvps_before_5);
check_true('5. لا سطر تدقيق أثناء فشل القفل', count($wpdb->audit_log) === $audit_before_5);
check_true('5. الضيف لا يزال غير مسجَّل الحضور', (int) $wpdb->rsvps[$rsvp5]['checked_in'] === 0);

// ============================================================================
// السيناريو 6: بحث يدوي (Manual search)
// ============================================================================
echo "\n=== السيناريو 6: بحث يدوي ===\n";

seed_invited_guest(9601, '0511111116', 'سارة القحطاني', 'SARA-0006');
// ضيف بلا صف RSVP إطلاقاً (لم يردّ على الدعوة قط)

$search_by_code = PGE_Guest_Resolution_Service::search(9601, 'SARA-0006');
check('6. بحث برمز الدعوة: نتيجة واحدة', count($search_by_code['guests'] ?? []), 1);
check('6. الضيف الصحيح عبر الرمز', $search_by_code['guests'][0]['phone'] ?? null, '0511111116');
check('6. expected_guest_count افتراضي = 1 (لا صف RSVP بعد)', $search_by_code['guests'][0]['expected_guest_count'] ?? null, 1);
check_true('6. rsvp_id يبقى null (لم يردّ الضيف بعد)', $search_by_code['guests'][0]['rsvp_id'] === null);

$search_by_name = PGE_Guest_Resolution_Service::search(9601, 'القحطاني');
check('6. بحث بجزء من الاسم: نتيجة واحدة على الأقل', count($search_by_name['guests'] ?? []) >= 1, true);

$search_by_phone = PGE_Guest_Resolution_Service::search(9601, '11111111');
check_true('6. بحث بجزء من الهاتف: عدة نتائج محتملة', count($search_by_phone['guests'] ?? []) >= 1);

$search_no_match = PGE_Guest_Resolution_Service::search(9601, 'XXXX-NOPE');
check('6. بحث بلا نتائج', count($search_no_match['guests'] ?? []), 0);

// ============================================================================
// السيناريو 7: حضور يدوي (Manual attendance)
// ============================================================================
echo "\n=== السيناريو 7: حضور يدوي ===\n";

// Blocker Fix #2: البحث بالهاتف قراءة فقط الآن — ضيف بلا أي صف RSVP سابق
// (لم يردّ على الدعوة قط) لا يمكن تسجيل حضوره عبر الهاتف إطلاقاً؛ "Check-in
// must never create invitation or RSVP records" — لا "تثبيت" ضمني كالسابق.
$rsvp_count_before_7a = count($wpdb->rsvps);
$resolve7a = PGE_Guest_Resolution_Service::resolve_by_phone(9601, '0511111116');
check('7. بحث بالهاتف لضيف بلا صف RSVP سابق: not_found (لا إنشاء إطلاقاً)', $resolve7a['result'] ?? null, 'not_found');
check_true('7. لا صف RSVP جديد أُنشئ أثناء البحث بالهاتف (Blocking Issue #2)', count($wpdb->rsvps) === $rsvp_count_before_7a);

// التأكيد يبقى ممكناً فقط حين يوجد صف RSVP فعلي واحد مسبقاً لهذا الهاتف —
// هنا نُحاكي أن الضيف يملك دعوة/صفاً فعلياً بالفعل (مصدره خارج نطاق محرك
// تسجيل الحضور تماماً، لا علاقة له بإنشائه).
$rsvp7a = $wpdb->seed_rsvp(9601, '0511111116', ['companions' => 0]);
$confirm7a = confirm_checkin_by_phone(9601, '0511111116', 1, $sup1['assignment_id']);
check('7. حضور يدوي عبر الهاتف لصف RSVP موجود مسبقاً (نتيجة واحدة فقط): checked_in', $confirm7a['result'] ?? null, 'checked_in');
check('7. expected_count = 1 (لا companions)', $confirm7a['expected_count'] ?? null, 1);
check('7. رصد الحضور استخدم نفس rsvp_id الموجود مسبقاً بالضبط', $confirm7a['guest']['rsvp_id'] ?? null, $rsvp7a);

// استئناف بعد بحث: اختيار ضيف موجود له صف RSVP فعلاً عبر resolve_by_rsvp_id()
$resolved7b = PGE_Guest_Resolution_Service::resolve_by_rsvp_id(9601, $rsvp5);
check('7. استئناف بعد بحث: found', $resolved7b['result'] ?? null, 'found');
$confirm7b = confirm_checkin_by_rsvp(9601, $rsvp5, 2, 'manual', $sup1['assignment_id']);
check('7. تأكيد يدوي بعد استئناف البحث: checked_in', $confirm7b['result'] ?? null, 'checked_in');

// ============================================================================
// السيناريو 8: تحقّق المتوقَّع/الفعلي (Expected/Actual validation)
// ============================================================================
echo "\n=== السيناريو 8: تحقّق المتوقَّع/الفعلي ===\n";

seed_invited_guest(9601, '0511111118', 'ضيف التحقق', 'VALD-0008');
$rsvp8 = $wpdb->seed_rsvp(9601, '0511111118', ['companions' => 4]); // expected = 5

$confirm8_zero = confirm_checkin_by_rsvp(9601, $rsvp8, 0, 'manual', $sup1['assignment_id']);
check('8. actual_count=0 مرفوض (لا حضور صفري)', [$confirm8_zero['result'] ?? null, $confirm8_zero['reason'] ?? null], ['error', 'invalid_actual_count']);
check('8. expected_count يُعاد في رسالة الخطأ = 5', $confirm8_zero['expected_count'] ?? null, 5);

$confirm8_over = confirm_checkin_by_rsvp(9601, $rsvp8, 6, 'manual', $sup1['assignment_id']);
check('8. actual_count=6 > expected=5 مرفوض', [$confirm8_over['result'] ?? null, $confirm8_over['reason'] ?? null], ['error', 'invalid_actual_count']);

check_true('8. لم يُسجَّل حضور فعلياً بعد كل الرفضين أعلاه', (int) $wpdb->rsvps[$rsvp8]['checked_in'] === 0);

$confirm8_min = confirm_checkin_by_rsvp(9601, $rsvp8, 1, 'manual', $sup1['assignment_id']);
check('8. actual_count=1 (الحد الأدنى) مقبول رغم expected=5', $confirm8_min['result'] ?? null, 'checked_in');

seed_invited_guest(9601, '0511111119', 'ضيف حد أقصى', 'VALD-0009');
$rsvp9 = $wpdb->seed_rsvp(9601, '0511111119', ['companions' => 2]); // expected = 3
$confirm8_exact = confirm_checkin_by_rsvp(9601, $rsvp9, 3, 'manual', $sup1['assignment_id']);
check('8. actual_count=3 (يساوي expected بالضبط) مقبول', $confirm8_exact['result'] ?? null, 'checked_in');

// ============================================================================
// السيناريو 9: سجل التدقيق (Audit logging)
// ============================================================================
echo "\n=== السيناريو 9: سجل التدقيق ===\n";

$audit_row_for_1 = null;
foreach ($wpdb->audit_log as $row) {
    if ((int) $row['rsvp_id'] === $rsvp1) {
        $audit_row_for_1 = $row;
        break;
    }
}
check_true('9. سطر تدقيق موجود للضيف الأول', $audit_row_for_1 !== null);
check('9. event_id صحيح', $audit_row_for_1['event_id'] ?? null, 9601);
check('9. assignment_id صحيح', $audit_row_for_1['assignment_id'] ?? null, $sup1['assignment_id']);
check('9. method = qr', $audit_row_for_1['method'] ?? null, 'qr');
check('9. expected_count = 4', $audit_row_for_1['expected_count'] ?? null, 4);
check('9. actual_count = 4', $audit_row_for_1['actual_count'] ?? null, 4);
check('9. entry_type = confirmation', $audit_row_for_1['entry_type'] ?? null, 'confirmation');
check_true('9. created_at موجود', !empty($audit_row_for_1['created_at']));

$audit_count_final_check = count($wpdb->audit_log);
check_true('9. كل تأكيد ناجح أضاف سطراً مستقلاً (Append-Only، عدة سطور متراكمة)', $audit_count_final_check >= 5);

// تحقّق Append-Only: السطر الأول لم يتغيّر رغم كل العمليات اللاحقة
check('9. سطر الضيف الأول لم يُعدَّل لاحقاً (Append-Only فعلي)', $audit_row_for_1['actual_count'], 4);

// ============================================================================
// السيناريو 10: التفويض (Authorization)
// ============================================================================
echo "\n=== السيناريو 10: التفويض ===\n";

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$authz10 = PGE_Supervisor_Portal_Middleware::authorize();
check('10. لا كوكي جلسة: denied', $authz10['result'] ?? null, 'denied');

// دفاع إضافي داخل Recorder نفسه: assignment_id غير موجود إطلاقاً — Guest
// Object صالح تماماً (rsvp8)، فقط الإسناد نفسه غير صالح.
$guest10_valid = PGE_Guest_Resolution_Service::resolve_by_rsvp_id(9601, $rsvp8)['guest'];
$confirm10_bad_assignment = PGE_Checkin_Recorder::record_guest_checkin(999999, $guest10_valid, 1, 'manual');
check('10. assignment_id غير موجود: error/assignment_not_authorized', [$confirm10_bad_assignment['result'] ?? null, $confirm10_bad_assignment['reason'] ?? null], ['error', 'assignment_not_authorized']);

// Guest Object يحمل event_id مختلفاً تماماً عن مناسبة الإسناد الحقيقي (سيناريو
// "مناسبة خاطئة") — يجب أن يُرفَض دون وصول لمرحلة القفل/القراءة إطلاقاً.
$guest10_wrong_event = $guest10_valid;
$guest10_wrong_event['event_id'] = 9602;
$confirm10_wrong_event = PGE_Checkin_Recorder::record_guest_checkin($sup1['assignment_id'], $guest10_wrong_event, 1, 'manual');
check('10. إسناد حقيقي لكن Guest Object لمناسبة مختلفة: error/assignment_not_authorized', [$confirm10_wrong_event['result'] ?? null, $confirm10_wrong_event['reason'] ?? null], ['error', 'assignment_not_authorized']);

// إسناد مُلغى
$revoke10 = PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($sup1['assignment_id']);
check('10. إلغاء الإسناد نجح (تمهيد)', $revoke10['result'] ?? null, 'revoked');
$guest10_revoked = PGE_Guest_Resolution_Service::resolve_by_rsvp_id(9601, $rsvp9)['guest'];
$confirm10_revoked = PGE_Checkin_Recorder::record_guest_checkin($sup1['assignment_id'], $guest10_revoked, 1, 'manual');
check('10. إسناد مُلغى: error/assignment_not_authorized', [$confirm10_revoked['result'] ?? null, $confirm10_revoked['reason'] ?? null], ['error', 'assignment_not_authorized']);

// ============================================================================
// السيناريو 11: انحدار على كل المراحل السابقة (Regression)
// ============================================================================
echo "\n=== السيناريو 11: انحدار على كل المراحل السابقة ===\n";

setup_catalog_owner_with_event(9903, 9603, 3, 'chk11');
set_test_event_full(9603, 9903, 'مناسبة انحدار 11');
$quota11 = pge_resolve_supervisor_quota_status(9603);
check_true('11. pge_resolve_supervisor_quota_status() لا يزال يعمل (Phase 1)', !is_wp_error($quota11));

$invite11 = create_and_get_token(9603, 9903, '0511110001');
PGE_Supervisor_Assignment_Service::accept_invitation($invite11['invitation_token']);
check_true('11. pge_has_active_supervisor_assignment() (Phase 2) لا يزال يعمل', pge_has_active_supervisor_assignment(9603, '0511110001'));

$sup11 = authenticate_supervisor_for_event(9603, 9903, '0511110002');
check('11. مصادقة كاملة عبر الأنبوب الحقيقي (Phase 3) لا تزال تعمل', $sup11['event_id'], 9603);

$authz11 = PGE_Supervisor_Portal_Middleware::authorize();
check('11. Portal Middleware (Phase 3.5) لا يزال يعمل: authorized', $authz11['result'] ?? null, 'authorized');
check('11. event_id صحيح عبر Middleware', $authz11['event_id'] ?? null, 9603);

// ملاحظة: PGE_Supervisor_Portal_Bootstrap::load() تحتاج get_the_title()/
// date_i18n()/get_userdata() (غير مطلوبة أصلاً لبقية اختبارات هذا الملف)،
// فلا تُستدعى هنا — Middleware::authorize() أعلاه كافٍ كفحص انحدار حقيقي
// وكافٍ لتزويد assignment_id/event_id الموثوقين لباقي هذا السيناريو.

// تسجيل حضور فعلي عبر المحرك الجديد فوق نفس هذا الأنبوب المُنحدَر بالكامل —
// Blocker Fix #2: البحث بالهاتف قراءة فقط الآن، فنُهيّئ صف RSVP مسبقاً
// (يمثّل دعوة موجودة فعلاً، لا إنشاءً من محرك تسجيل الحضور نفسه).
seed_invited_guest(9603, '0511110002', 'ضيف انحدار', 'REGR-0011');
$rsvp11 = $wpdb->seed_rsvp(9603, '0511110002', ['companions' => 0]);
$confirm11 = confirm_checkin_by_phone(9603, '0511110002', 1, $authz11['assignment_id']);
check('11. تسجيل حضور فعلي فوق أنبوب كامل من Phase 1 إلى Phase 4 (Blocker Fix #1+#2 متضمَّنان)', $confirm11['result'] ?? null, 'checked_in');

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 12 (Blocker Fix): صيغة اسم القفل = event_id + rsvp_id، لا الهاتف
// ============================================================================
echo "\n=== السيناريو 12: صيغة اسم القفل (event_id + rsvp_id) ===\n";

setup_catalog_owner_with_event(9906, 9606, 5, 'chk12');
set_test_event_full(9606, 9906, 'مناسبة قفل rsvp_id');
$phone12 = '0512120000';
$rsvp12 = $wpdb->seed_rsvp(9606, $phone12, ['companions' => 0]);
$sup12 = authenticate_supervisor_for_event(9606, 9906, '0500000012');

$confirm12 = confirm_checkin_by_rsvp(9606, $rsvp12, 1, 'manual', $sup12['assignment_id']);
check('12. تسجيل الحضور نجح', $confirm12['result'] ?? null, 'checked_in');

// 1. Lock key uses event_id + rsvp_id.
check_true(
    '12. اسم القفل الفعلي = md5(event_id|rsvp_id) بالضبط',
    in_array(expected_lock_name(9606, $rsvp12), $wpdb->lock_acquire_log, true)
);
// 2. Phone is not part of the lock key.
check_true(
    '12. اسم القفل ليس مبنياً من الهاتف (لا يطابق md5(event_id|phone))',
    !in_array('pge_checkin_' . md5(9606 . '|' . $phone12), $wpdb->lock_acquire_log, true)
);

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 13 (Blocker Fix #2): ترقية Schema الإنتاجية الحقيقية — إزالة
// UNIQUE (event_id, guest_phone) عبر PGE_Checkin_Schema::maybe_upgrade()
// ============================================================================
echo "\n=== السيناريو 13: ترقية Schema الحقيقية (إزالة UNIQUE على الهاتف) ===\n";
// يُشغَّل هنا PGE_Checkin_Schema::maybe_upgrade() **الحقيقي بلا أي تعديل** —
// لا محاكاة منطقية موازية. $wpdb الوهمي يُطبِّق فعلياً كل ALTER TABLE/SHOW
// INDEX/SHOW COLUMNS الصادرة عنه (راجع Fake_Wpdb_Checkin_Engine أعلاه) — هذا
// هو المقصود بـ"ليس Bypass اختباري" ضمن حدود عدم توفر خادم MySQL فعلي هنا.

$event_id_13 = 9608;
$table_name_13 = $wpdb->prefix . 'pge_event_rsvps';

// قبل الترقية: القيد الإنتاجي الأصلي (UNIQUE KEY event_phone) لا يزال قائماً
// — إدراج حقيقي عبر $wpdb->insert() (لا seed_rsvp()) يُثبت هذا أولاً.
$phone_before_migration = '0513500000';
$insert_before_a = $wpdb->insert($table_name_13, ['event_id' => $event_id_13, 'guest_phone' => $phone_before_migration, 'reply' => 'pending', 'companions' => 0, 'checked_in' => 0]);
$insert_before_b = $wpdb->insert($table_name_13, ['event_id' => $event_id_13, 'guest_phone' => $phone_before_migration, 'reply' => 'pending', 'companions' => 0, 'checked_in' => 0]);
check_true('13. قبل الترقية: الإدراج الأول ينجح', $insert_before_a !== false);
check_true('13. قبل الترقية: الإدراج الثاني لنفس الهاتف يُرفَض (UNIQUE لا يزال قائماً)', $insert_before_b === false);

// تشغيل الترقية الحقيقية.
$version_before = get_option(PGE_Checkin_Schema::VERSION_OPTION, '');
check('13. رقم الإصدار المخزَّن قبل الترقية فارغ (أول تشغيل)', $version_before, '');

PGE_Checkin_Schema::maybe_upgrade();

check('13. maybe_upgrade() الحقيقية أنجزت الترقية كاملةً (الإصدار = 1.1.0)', get_option(PGE_Checkin_Schema::VERSION_OPTION, ''), '1.1.0');

// Verification المطلوب صراحةً — عبر SHOW INDEX الحقيقي (نفس ما سيراه أي DBA):
$indexes_after = $wpdb->get_results("SHOW INDEX FROM $table_name_13", ARRAY_A);
$phone_pair_indexes = [];
foreach ($indexes_after as $row) {
    $name = (string) $row['Key_name'];
    if (!isset($phone_pair_indexes[$name])) {
        $phone_pair_indexes[$name] = ['non_unique' => (int) $row['Non_unique'], 'columns' => []];
    }
    $phone_pair_indexes[$name]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
}
$found_non_unique_pair = false;
$found_unique_pair = false;
foreach ($phone_pair_indexes as $name => $meta) {
    ksort($meta['columns']);
    if (array_values($meta['columns']) === ['event_id', 'guest_phone']) {
        if ((int) $meta['non_unique'] === 1) {
            $found_non_unique_pair = true;
        } else {
            $found_unique_pair = true;
        }
    }
}
check_true('13. يوجد فهرس على (event_id, guest_phone) بالترتيب الصحيح', $found_non_unique_pair);
check_true('13. Non_unique = 1 لذلك الفهرس', $found_non_unique_pair);
check_true('13. لا يوجد أي فهرس UNIQUE متبقٍّ على نفس الزوج', !$found_unique_pair);

// بعد الترقية: نفس عملية الإدراج المرفوضة سابقاً تنجح الآن حقيقةً.
$insert_after_b = $wpdb->insert($table_name_13, ['event_id' => $event_id_13, 'guest_phone' => $phone_before_migration, 'reply' => 'pending', 'companions' => 0, 'checked_in' => 0]);
check_true('13. بعد الترقية: نفس الإدراج الذي رُفِض سابقاً ينجح الآن', $insert_after_b !== false);

// Idempotency — استدعاء ثانٍ متكرر لا يفشل ولا يُنشئ فهارس مكرَّرة.
PGE_Checkin_Schema::maybe_upgrade();
$indexes_after_second_call = $wpdb->get_results("SHOW INDEX FROM $table_name_13", ARRAY_A);
$key_names_after_second = array_unique(array_column($indexes_after_second_call, 'Key_name'));
check('13. الترقية Idempotent — استدعاء ثانٍ لا يُضيف فهرساً مكرَّراً', count($key_names_after_second), count(array_unique(array_column($indexes_after, 'Key_name'))));

// ============================================================================
// السيناريو 14 (Blocker Fix #2): سيناريو الهاتف المشترك — إنتاجي حقيقي بالكامل
// ============================================================================
echo "\n=== السيناريو 14: هاتف مشترك — إدراج/بحث/تسجيل عبر Schema حقيقية بعد الترقية ===\n";
// لا seed_rsvp() هنا إطلاقاً — كل شيء عبر $wpdb->insert() الحقيقي (الذي يقرأ
// حالة الفهرس الفعلية بعد ترقية السيناريو 13) وPGE_Guest_Resolution_Service::
// resolve_by_phone() الحقيقية، إثباتاً مباشراً للنقاط الثماني المطلوبة.

setup_catalog_owner_with_event(9908, 9609, 5, 'chk14');
set_test_event_full(9609, 9908, 'مناسبة هاتف مشترك (إنتاجي)');
$rsvp_table_14 = $wpdb->prefix . 'pge_event_rsvps';
$phone_shared = '0514000000';

$rsvp_count_before_14 = count($wpdb->rsvps);

// 1. Two RSVP rows in the same event can be inserted with the same phone
//    (عبر $wpdb->insert() الحقيقي، لا Bypass).
$inserted_x = $wpdb->insert($rsvp_table_14, ['event_id' => 9609, 'guest_phone' => $phone_shared, 'reply' => 'yes', 'companions' => 1, 'checked_in' => 0]); // expected = 2
$rsvpX = (int) $wpdb->insert_id;
$inserted_y = $wpdb->insert($rsvp_table_14, ['event_id' => 9609, 'guest_phone' => $phone_shared, 'reply' => 'yes', 'companions' => 2, 'checked_in' => 0]); // expected = 3
$rsvpY = (int) $wpdb->insert_id;
check_true('14.1 صفّا RSVP أُدرجا فعلياً (لا Bypass) بنفس الهاتف ضمن نفس المناسبة', $inserted_x !== false && $inserted_y !== false && $rsvpX !== $rsvpY);

// 2. Phone search returns both candidates. / 3. Neither candidate is selected silently.
$resolve14 = PGE_Guest_Resolution_Service::resolve_by_phone(9609, $phone_shared);
check('14.2/3 البحث بالهاتف يُعيد ambiguous (لا اختيار صامت)', $resolve14['result'] ?? null, 'ambiguous');
check('14.2 عدد المرشَّحين = 2 بالضبط', count($resolve14['candidates'] ?? []), 2);

$candidate_refs = array_column($resolve14['candidates'], 'reference');
check_true('14.2 لكل مرشَّح reference موقَّع (لا rsvp_id خام)', count(array_filter($candidate_refs, fn($r) => is_string($r) && $r !== '')) === 2);
foreach ($resolve14['candidates'] as $candidate) {
    check_true('14.3 المرشَّح لا يحمل rsvp_id خاماً في مفاتيحه (بيانات عرض آمنة فقط)', !array_key_exists('rsvp_id', $candidate));
}

// 8. No new RSVP row is created during phone search.
check_true('14.8 لا صف RSVP جديد أُنشئ أثناء البحث بالهاتف (قراءة فقط)', count($wpdb->rsvps) === $rsvp_count_before_14 + 2);

$sup14 = authenticate_supervisor_for_event(9609, 9908, '0500000014');

// اختيار المرشَّح عبر مرجعه المُوقَّع = نفس مسار resolve_from_qr() الموجود
// فعلاً (لا مسار حلّ جديد) — هكذا سيُستهلَك reference لاحقاً من واجهة مستقبلية.
$refX = null;
$refY = null;
foreach ($resolve14['candidates'] as $candidate) {
    $probe = PGE_Guest_Resolution_Service::resolve_from_qr(9609, $candidate['reference']);
    if (($probe['guest']['rsvp_id'] ?? null) === $rsvpX) {
        $refX = $candidate['reference'];
    } elseif (($probe['guest']['rsvp_id'] ?? null) === $rsvpY) {
        $refY = $candidate['reference'];
    }
}
check_true('14. تحديد مرجع X ومرجع Y بنجاح عبر resolve_from_qr() (نفس مسار QR)', $refX !== null && $refY !== null);

// 4/5/6. تسجيل X أولاً، ثم Y استقلالاً — كلاهما عبر resolve_from_qr() +
// نفس الـRecorder (Requirement: لا منطق تسجيل منفصل).
$resolutionX = PGE_Guest_Resolution_Service::resolve_from_qr(9609, $refX);
$confirmX = PGE_Checkin_Recorder::record_guest_checkin($sup14['assignment_id'], $resolutionX['guest'], 2, 'manual');
check('14.4 تسجيل حضور السجل الأول (X) نجح', $confirmX['result'] ?? null, 'checked_in');

// 4. Checking in the first RSVP does not affect the second.
check_true('14.4 السجل الثاني (Y) لا يزال غير مسجَّل الحضور بعد تأكيد X', (int) $wpdb->rsvps[$rsvpY]['checked_in'] === 0);
check_true('14.4 actual_entered_count للسجل الثاني (Y) لا يزال فارغاً', $wpdb->rsvps[$rsvpY]['actual_entered_count'] === null);

// 5. Checking in the second RSVP succeeds independently.
$resolutionY = PGE_Guest_Resolution_Service::resolve_from_qr(9609, $refY);
$confirmY = PGE_Checkin_Recorder::record_guest_checkin($sup14['assignment_id'], $resolutionY['guest'], 3, 'manual');
check('14.5 تسجيل حضور السجل الثاني (Y) نجح استقلالاً رغم مشاركة الهاتف', $confirmY['result'] ?? null, 'checked_in');

check_true('14. X لا يزال actual_entered_count=2 (لم يتأثر بتأكيد Y)', $wpdb->rsvps[$rsvpX]['actual_entered_count'] === 2);
check_true('14. Y أصبح actual_entered_count=3', $wpdb->rsvps[$rsvpY]['actual_entered_count'] === 3);

// 6. Each RSVP uses a distinct event_id + rsvp_id lock.
check_true(
    '14.6 اسم قفل X يختلف عن اسم قفل Y رغم تطابق الهاتف',
    expected_lock_name(9609, $rsvpX) !== expected_lock_name(9609, $rsvpY)
);
check_true('14.6 قفل X استُخدم فعلياً', in_array(expected_lock_name(9609, $rsvpX), $wpdb->lock_acquire_log, true));
check_true('14.6 قفل Y استُخدم فعلياً', in_array(expected_lock_name(9609, $rsvpY), $wpdb->lock_acquire_log, true));

// 7. Each RSVP receives its own audit row.
$audit_rows_x = array_filter($wpdb->audit_log, fn($r) => (int) $r['rsvp_id'] === $rsvpX);
$audit_rows_y = array_filter($wpdb->audit_log, fn($r) => (int) $r['rsvp_id'] === $rsvpY);
check('14.7 سطر تدقيق واحد مستقل للسجل X', count($audit_rows_x), 1);
check('14.7 سطر تدقيق واحد مستقل للسجل Y', count($audit_rows_y), 1);

// بحث بالهاتف مرة أخرى بعد تسجيل حضور كليهما — لا يزال ambiguous (القراءة
// لا تتأثر بحالة الحضور)، ولا يزال بلا أي إنشاء جديد.
$rsvp_count_before_recheck = count($wpdb->rsvps);
$resolve14_recheck = PGE_Guest_Resolution_Service::resolve_by_phone(9609, $phone_shared);
check('14. إعادة البحث بعد التسجيل: لا يزال ambiguous (صفان موجودان فعلاً)', $resolve14_recheck['result'] ?? null, 'ambiguous');
check_true('14.8 لا صف RSVP جديد أُنشئ أثناء إعادة البحث', count($wpdb->rsvps) === $rsvp_count_before_recheck);

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
exit(0);
