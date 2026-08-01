<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـEntry Check-in Supervisors، Phase 5
 * ("Attendance Statistics Engine" RFC) — PGE_Attendance_Statistics_Service
 * وPGE_Attendance_Dashboard_Provider. يستدعي الأنبوب الحقيقي الكامل
 * (Catalog → Assignment Service → Session → Authenticator → Portal
 * Middleware → QR Service → Guest Resolution Service → Recorder) لسيناريو
 * "مختلط QR/يدوي"، ويستخدم بذر RSVP/Audit Log مباشرة (بلا Bypass لأي قيد
 * إنتاجي — لا قيد يتعلق بهذه المرحلة أصلاً) لبناء خلفيات بيانات كبيرة/متنوعة
 * لبقية السيناريوهات، تماماً كما تفعل بقية ملفات tests/ (مثل test-catalog-
 * plan-limits.php) عند تمهيد حالة قراءة فقط لا تختبر مسار الكتابة نفسه.
 *
 * السيناريوهات المطلوبة صراحةً (Requirement 10):
 *   - لا حضور، حضور جزئي، حضور كامل، حضور مختلط QR/يدوي، حسابات المتوقَّع
 *     مقابل الفعلي، مشرفون متعددون، تجميع مجموعة بيانات كبيرة، التفويض،
 *     عزل عبر المناسبات، وانحدار على المراحل 1-4.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-attendance-statistics.php
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

// ── تفويض (Requirement 8): current_user_can/get_current_user_id/get_post_field
// — نفس أسلوب test-event-quota-ui-integration.php حرفياً. ────────────────────
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function current_user_can($cap) {
    if ($cap === 'administrator') {
        return $GLOBALS['__test_user_is_admin'];
    }
    return false;
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

// ── User Meta + Posts + Post Meta + Userdata وهمية في الذاكرة ───────────────

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_users_by_id'] = [];
$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];
$GLOBALS['__test_userdata'] = [];

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
function get_post_field($field, $post_id)
{
    $post = $GLOBALS['__test_posts'][$post_id] ?? null;
    if ($post === null) {
        return '';
    }
    return $post->$field ?? '';
}
function get_the_title($post_id)
{
    $post = $GLOBALS['__test_posts'][$post_id] ?? null;
    return $post ? (string) $post->post_title : '';
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
function set_test_userdata($user_id, $display_name)
{
    $GLOBALS['__test_userdata'][$user_id] = (object) ['display_name' => $display_name];
}
function get_userdata($user_id)
{
    return $GLOBALS['__test_userdata'][$user_id] ?? false;
}
function seed_invited_guest($event_id, $phone, $name, $code)
{
    $map = $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] ?? [];
    $map[$phone] = ['phone' => $phone, 'name' => $name, 'note' => '', 'code' => $code];
    $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] = $map;
}

// ── Fake $wpdb — نفس عائلة Fake_Wpdb_Checkin_Engine (Phase 4)، مع إضافة صيغ
// استعلامات Phase 5 الجديدة (تجميع Attendance Summary، دفعة Recent Check-ins،
// تجميع Supervisor Summary، قائمة list_assignments_for_event). ─────────────

class Fake_Wpdb_Attendance_Stats
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public function get_charset_collate() { return ''; }

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

    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];
    public $force_lock_unavailable = false;

    /**
     * عدّاد تجسّسي (spy) لعدد استعلامات "محرّك الإحصاء" الفعلية المُنفَّذة —
     * يُزاد فقط داخل الفروع الثلاثة الخاصة بـPhase 5 (ملخّص الحضور المُجمَّع،
     * آخر عمليات التسجيل + دفعة RSVP المرافقة لها، ملخّص المشرفين المُجمَّع +
     * قائمة الإسنادات المرافقة لها) — لا يُزاد أبداً بواسطة استعلامات التفويض
     * (بحث مشرف/جلسة/Tier/Catalog). يُستخدَم لإثبات "Query Execution Guard":
     * صفر استعلامات محرّك عند فشل التفويض، قبل تنفيذ أي حساب.
     */
    public $engine_query_count = 0;

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

        // Phase 5: استعلام Attendance Summary المُجمَّع الواحد.
        if ($which === 'rsvps' && stripos($sql, 'total_invitations') !== false) {
            $this->engine_query_count++;
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $total = 0;
            $checked_in = 0;
            $expected = 0;
            $actual = 0;
            foreach ($this->rsvps as $row) {
                if ((int) $row['event_id'] !== $event_id) {
                    continue;
                }
                $total++;
                $companions = (int) ($row['companions'] ?? 0);
                $expected += 1 + $companions;
                if ((int) ($row['checked_in'] ?? 0) === 1) {
                    $checked_in++;
                    $actual += (int) ($row['actual_entered_count'] ?? 0);
                }
            }
            return [
                'total_invitations' => $total,
                'checked_in_invitations' => $checked_in,
                'expected_guests' => $expected,
                'actual_attendees' => $actual,
            ];
        }

        if ($which === 'rsvps') {
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
        $which = $this->which_table($sql);

        // Phase 5: آخر عمليات تسجيل حضور (audit_log، محدود، مُرتَّب).
        if ($which === 'audit_log' && stripos($sql, 'ORDER BY created_at DESC') !== false) {
            $this->engine_query_count++;
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $me);
            preg_match('/LIMIT\s+(\d+)/i', $sql, $ml);
            $event_id = isset($me[1]) ? (int) $me[1] : 0;
            $limit = isset($ml[1]) ? (int) $ml[1] : 10;

            $matched = array_values(array_filter($this->audit_log, function ($r) use ($event_id) {
                return (int) $r['event_id'] === $event_id;
            }));
            usort($matched, function ($a, $b) {
                $cmp = strcmp((string) $b['created_at'], (string) $a['created_at']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return $b['id'] <=> $a['id'];
            });
            return array_slice($matched, 0, $limit);
        }

        // Phase 5: تجميع Supervisor Summary (GROUP BY assignment_id).
        if ($which === 'audit_log' && stripos($sql, 'GROUP BY assignment_id') !== false) {
            $this->engine_query_count++;
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $grouped = [];
            foreach ($this->audit_log as $row) {
                if ((int) $row['event_id'] !== $event_id) {
                    continue;
                }
                $aid = (int) $row['assignment_id'];
                if (!isset($grouped[$aid])) {
                    $grouped[$aid] = ['assignment_id' => $aid, 'checkins_recorded' => 0, 'guests_recorded' => 0];
                }
                $grouped[$aid]['checkins_recorded']++;
                $grouped[$aid]['guests_recorded'] += (int) $row['actual_count'];
            }
            return array_values($grouped);
        }

        // Phase 5: دفعة RSVP بمعرّفات (WHERE id IN (...)).
        if ($which === 'rsvps' && preg_match('/\bIN\s*\(([^)]*)\)/i', $sql, $m)) {
            $this->engine_query_count++;
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
            $matched = [];
            foreach ($ids as $id) {
                if (isset($this->rsvps[$id])) {
                    $matched[] = $this->rsvps[$id];
                }
            }
            return $matched;
        }

        // Phase 5: PGE_Supervisor_Assignment_Service::list_assignments_for_event().
        if ($which === 'supervisors' && stripos($sql, 'ORDER BY id ASC') !== false) {
            $this->engine_query_count++;
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $matched = array_values(array_filter($this->supervisors, function ($r) use ($event_id) {
                return (int) $r['event_id'] === $event_id;
            }));
            usort($matched, function ($a, $b) { return $a['id'] <=> $b['id']; });
            return $matched;
        }

        if ($which === null || in_array($which, ['supervisors', 'sessions', 'rsvps', 'audit_log'], true)) {
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

    /** إدراج مباشر لصف RSVP اختباري (تمهيد قراءة فقط — لا يختبر مسار الكتابة). */
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

    /** إدراج مباشر لسطر تدقيق اختباري (تمهيد قراءة فقط). */
    public function seed_audit_row($event_id, $rsvp_id, $assignment_id, $method, $expected_count, $actual_count, $created_at)
    {
        $id = $this->audit_log_next_id++;
        $this->audit_log[$id] = [
            'id' => $id,
            'event_id' => $event_id,
            'rsvp_id' => $rsvp_id,
            'assignment_id' => $assignment_id,
            'method' => $method,
            'expected_count' => $expected_count,
            'actual_count' => $actual_count,
            'entry_type' => 'confirmation',
            'created_at' => $created_at,
        ];
        return $id;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Attendance_Stats();
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
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';
require_once __DIR__ . '/../includes/class-pge-guest-resolution-service.php';
require_once __DIR__ . '/../includes/class-pge-checkin-recorder.php';
require_once __DIR__ . '/../includes/class-pge-attendance-statistics-service.php';
require_once __DIR__ . '/../includes/class-pge-attendance-dashboard-provider.php';

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

/**
 * آلية اختبار فقط (test-only) — لا تُحمَّل في وقت تشغيل الإضافة الفعلي إطلاقاً
 * (هذا الملف تحت tests/ ولا يُحمَّل عبر pgevents-core.php). تستخدم Reflection
 * محلياً داخل ملف الاختبار حصراً، تماماً كما يُوجِّه الـRFC ("Reflection only
 * inside the test file")، لبناء كائن محرّك معزول لأغراض اختبار حسابات الأرقام
 * بمعزل عن طبقة التفويض (السيناريوهات 1-7) — وهذا لا يعني أن الإنتاج يسمح
 * بهذا؛ PGE_Attendance_Dashboard_Provider هو الآلية الإنتاجية الوحيدة
 * المخوَّلة فعلياً (مُختبَرة صراحة في سيناريوهات التفويض 8-10 وقسم "التحقّق من
 * حدود الوصول" أدناه دون أي Reflection).
 */
function test_construct_engine(): PGE_Attendance_Statistics_Service
{
    $reflection = new ReflectionClass('PGE_Attendance_Statistics_Service');
    return $reflection->newInstanceWithoutConstructor();
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
    Mon_Events_Users::activate_catalog_tier($user_id, 1, $tier['id'], 'STATS-ORDER-' . $tier_key);
    return $tier;
}

function create_and_get_token($event_id, $inviter_id, $phone, $name = '')
{
    return PGE_Supervisor_Assignment_Service::create_supervisor_assignment($event_id, $inviter_id, $phone, $name);
}

function authenticate_supervisor_for_event($event_id, $host_id, $phone, $name = '')
{
    $invite = create_and_get_token($event_id, $host_id, $phone, $name);
    $auth = pge_supervisor_authenticate($invite['invitation_token']);
    $_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth['session_token'];
    return ['assignment_id' => $auth['assignment_id'], 'event_id' => $auth['event_id'], 'session_token' => $auth['session_token']];
}

/** تمهيد صف RSVP غير مسجَّل الحضور (قراءة فقط — لا يختبر مسار الكتابة). */
function seed_pending_rsvp($event_id, $phone, $companions = 0, $reply = 'yes')
{
    global $wpdb;
    return $wpdb->seed_rsvp($event_id, $phone, ['companions' => $companions, 'reply' => $reply]);
}

/** تمهيد صف RSVP مسجَّل الحضور + سطر تدقيق مطابق (قراءة فقط). */
function seed_checked_in_rsvp($event_id, $phone, $companions, $actual_count, $method, $assignment_id, $created_at)
{
    global $wpdb;
    $rsvp_id = $wpdb->seed_rsvp($event_id, $phone, [
        'companions' => $companions,
        'reply' => 'yes',
        'checked_in' => 1,
        'checked_in_at' => $created_at,
        'checked_in_by_assignment_id' => $assignment_id,
        'checkin_method' => $method,
        'actual_entered_count' => $actual_count,
    ]);
    $wpdb->seed_audit_row($event_id, $rsvp_id, $assignment_id, $method, 1 + $companions, $actual_count, $created_at);
    return $rsvp_id;
}

// ============================================================================
// السيناريو 1: لا حضور (No attendance)
// ============================================================================
echo "=== السيناريو 1: لا حضور ===\n";

setup_catalog_owner_with_event(9501, 9701, 5, 'stat1');
set_test_event_full(9701, 9501, 'مناسبة بلا حضور');
seed_pending_rsvp(9701, '0530000001', 2);
seed_pending_rsvp(9701, '0530000002', 0);

$summary1 = test_construct_engine()->get_attendance_summary(9701);
check('1. total_invitations = 2', $summary1['total_invitations'], 2);
check('1. checked_in_invitations = 0', $summary1['checked_in_invitations'], 0);
check('1. pending_invitations = 2', $summary1['pending_invitations'], 2);
check('1. expected_guests = 4 (3+1، دون حضور)', $summary1['expected_guests'], 4);
check('1. actual_attendees = 0 (لا حضور بعد)', $summary1['actual_attendees'], 0);
check('1. attendance_rate = 0.0', $summary1['attendance_rate'], 0.0);
check('1. average_guests_per_invitation = 2.0', $summary1['average_guests_per_invitation'], 2.0);

$recent1 = test_construct_engine()->get_recent_checkins(9701);
check('1. لا آخر عمليات تسجيل حضور (مصفوفة فارغة)', $recent1, []);

// مناسبة غير موجودة في RSVP إطلاقاً — يجب أن تُعيد ملخّصاً فارغاً بلا Fatal.
$summary_empty = test_construct_engine()->get_attendance_summary(999999);
check('1. مناسبة بلا أي صفوف RSVP: total=0', $summary_empty['total_invitations'], 0);
check('1. مناسبة بلا أي صفوف RSVP: attendance_rate=0.0 (لا قسمة على صفر)', $summary_empty['attendance_rate'], 0.0);

// ============================================================================
// السيناريو 2: حضور جزئي (Partial attendance)
// ============================================================================
echo "\n=== السيناريو 2: حضور جزئي ===\n";

setup_catalog_owner_with_event(9502, 9702, 5, 'stat2');
set_test_event_full(9702, 9502, 'مناسبة حضور جزئي');
$sup2 = authenticate_supervisor_for_event(9702, 9502, '0500000021', 'مشرف الجزئي');

seed_checked_in_rsvp(9702, '0530000010', 1, 2, 'manual', $sup2['assignment_id'], '2026-02-01 10:00:00'); // expected=2, actual=2
seed_checked_in_rsvp(9702, '0530000011', 0, 1, 'manual', $sup2['assignment_id'], '2026-02-01 10:05:00'); // expected=1, actual=1
seed_pending_rsvp(9702, '0530000012', 3); // expected=4, لم يُسجَّل
seed_pending_rsvp(9702, '0530000013', 0); // expected=1, لم يُسجَّل

$summary2 = test_construct_engine()->get_attendance_summary(9702);
check('2. total_invitations = 4', $summary2['total_invitations'], 4);
check('2. checked_in_invitations = 2', $summary2['checked_in_invitations'], 2);
check('2. pending_invitations = 2', $summary2['pending_invitations'], 2);
check('2. expected_guests = 2+1+4+1 = 8', $summary2['expected_guests'], 8);
check('2. actual_attendees = 2+1 = 3 (فقط المسجَّلين)', $summary2['actual_attendees'], 3);
check('2. attendance_rate = 0.5 (2/4)', $summary2['attendance_rate'], 0.5);
check('2. average_guests_per_invitation = 2.0 (8/4)', $summary2['average_guests_per_invitation'], 2.0);

// ============================================================================
// السيناريو 3: حضور كامل (Full attendance)
// ============================================================================
echo "\n=== السيناريو 3: حضور كامل ===\n";

setup_catalog_owner_with_event(9503, 9703, 5, 'stat3');
set_test_event_full(9703, 9503, 'مناسبة حضور كامل');
$sup3 = authenticate_supervisor_for_event(9703, 9503, '0500000031', 'مشرف الكامل');

seed_checked_in_rsvp(9703, '0530000020', 2, 3, 'qr', $sup3['assignment_id'], '2026-02-02 09:00:00'); // expected=3, actual=3
seed_checked_in_rsvp(9703, '0530000021', 0, 1, 'qr', $sup3['assignment_id'], '2026-02-02 09:10:00'); // expected=1, actual=1
seed_checked_in_rsvp(9703, '0530000022', 1, 1, 'manual', $sup3['assignment_id'], '2026-02-02 09:20:00'); // expected=2, actual=1 (أقل من المتوقَّع، مقبول)

$summary3 = test_construct_engine()->get_attendance_summary(9703);
check('3. total_invitations = 3', $summary3['total_invitations'], 3);
check('3. checked_in_invitations = 3 (الكل)', $summary3['checked_in_invitations'], 3);
check('3. pending_invitations = 0', $summary3['pending_invitations'], 0);
check('3. expected_guests = 3+1+2 = 6', $summary3['expected_guests'], 6);
check('3. actual_attendees = 3+1+1 = 5', $summary3['actual_attendees'], 5);
check('3. attendance_rate = 1.0 (3/3، حضور كامل)', $summary3['attendance_rate'], 1.0);

// ============================================================================
// السيناريو 4: حضور مختلط QR/يدوي — عبر المحرك الحقيقي الكامل
// ============================================================================
echo "\n=== السيناريو 4: حضور مختلط QR/يدوي (عبر Recorder الحقيقي) ===\n";

setup_catalog_owner_with_event(9504, 9704, 5, 'stat4');
set_test_event_full(9704, 9504, 'مناسبة مختلطة');
$sup4 = authenticate_supervisor_for_event(9704, 9504, '0500000041', 'مشرف المختلط');

$rsvp4a = $wpdb->seed_rsvp(9704, '0530000030', ['companions' => 1, 'reply' => 'yes']); // expected=2
$rsvp4b = $wpdb->seed_rsvp(9704, '0530000031', ['companions' => 0, 'reply' => 'yes']); // expected=1

// تأكيد أول عبر QR الحقيقي (build_payload → resolve_from_qr → Recorder).
// Phase 9B QR Architecture Final Fix: qr_version=1 صريح (PGE_Invitation_
// Repository غير مُحمَّلة في هذا الملف أصلاً، فـis_qr_version_current()
// تفترض 1 افتراضياً — راجع docs/INVITATION-QR-ARCHITECTURE.md قسم 8).
$qr4a = PGE_Checkin_QR_Service::build_payload(9704, $rsvp4a, 1);
$resolved4a = PGE_Guest_Resolution_Service::resolve_from_qr(9704, $qr4a);
$confirm4a = PGE_Checkin_Recorder::record_guest_checkin($sup4['assignment_id'], $resolved4a['guest'], 2, 'qr');
check('4. تأكيد عبر QR نجح فعلياً (نفس المحرك الحقيقي)', $confirm4a['result'] ?? null, 'checked_in');

// تأكيد ثانٍ عبر بحث يدوي حقيقي (resolve_by_rsvp_id → Recorder).
$resolved4b = PGE_Guest_Resolution_Service::resolve_by_rsvp_id(9704, $rsvp4b);
$confirm4b = PGE_Checkin_Recorder::record_guest_checkin($sup4['assignment_id'], $resolved4b['guest'], 1, 'manual');
check('4. تأكيد عبر بحث يدوي نجح فعلياً (نفس المحرك الحقيقي)', $confirm4b['result'] ?? null, 'checked_in');

// Requirement 6: النتيجة يجب أن تكون متطابقة بصرف النظر عن طريقة التسجيل —
// لا فرق بين qr وmanual في أي حساب أدناه.
$summary4 = test_construct_engine()->get_attendance_summary(9704);
check('4. total_invitations = 2 (مختلط QR+يدوي)', $summary4['total_invitations'], 2);
check('4. checked_in_invitations = 2 (كلاهما، بصرف النظر عن الطريقة)', $summary4['checked_in_invitations'], 2);
check('4. expected_guests = 2+1 = 3', $summary4['expected_guests'], 3);
check('4. actual_attendees = 2+1 = 3 (qr و manual تُجمَعان معاً بلا تمييز)', $summary4['actual_attendees'], 3);

$recent4 = test_construct_engine()->get_recent_checkins(9704);
check('4. سطرا تدقيق مستقلّان في آخر العمليات', count($recent4), 2);
$methods4 = array_map(fn($r) => $r['method'], $recent4);
sort($methods4);
check('4. الطريقتان (manual وqr) ظاهرتان معاً في نفس القائمة', $methods4, ['manual', 'qr']);

// ============================================================================
// السيناريو 5: حسابات المتوقَّع مقابل الفعلي (Expected vs Actual)
// ============================================================================
echo "\n=== السيناريو 5: المتوقَّع مقابل الفعلي (استقلال تام) ===\n";

setup_catalog_owner_with_event(9505, 9705, 5, 'stat5');
set_test_event_full(9705, 9505, 'مناسبة اختبار المتوقَّع/الفعلي');
$sup5 = authenticate_supervisor_for_event(9705, 9505, '0500000051', 'مشرف الخامس');

// 100 دعوة مسجَّلة الحضور، لكل منها actual_entered_count مختلف عن companions —
// المجموع الكلي actual_attendees=286 يجب ألا "يُدمَج" أو يُخلَط مع عدد الدعوات
// المسجَّلة (100) في أي حقل — القيمتان منفصلتان تماماً في نتيجة واحدة.
for ($i = 1; $i <= 100; $i++) {
    seed_checked_in_rsvp(9705, '05400' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 1, 3, 'manual', $sup5['assignment_id'], '2026-02-03 10:00:00');
}
$summary5 = test_construct_engine()->get_attendance_summary(9705);
check('5. Invitations Checked In = 100', $summary5['checked_in_invitations'], 100);
// ملاحظة: 100 دعوة × 3 حضور فعلي لكل دعوة = 300 حاضر فعلي. الرقم "286" في
// مثال الـRFC توضيحي بحت (لا قيمة حرفية مطلوب مطابقتها) — الجوهر المُختبَر
// هنا هو استقلال Actual Attendees عن Invitations Checked In تماماً (لا دمج).
check('5. Actual Attendees = 300 (100 دعوة × 3 حضور فعلي، مستقل عن عدد الدعوات)', $summary5['actual_attendees'], 300);
check_true('5. Actual Attendees (300) لم يُستبدَل بعدد الدعوات (100) ولم يُدمَج معه', $summary5['actual_attendees'] !== $summary5['checked_in_invitations']);
check('5. expected_guests = 100×2 = 200 (منفصل تماماً عن actual_attendees)', $summary5['expected_guests'], 200);

// ============================================================================
// السيناريو 6: مشرفون متعددون (Multiple supervisors)
// ============================================================================
echo "\n=== السيناريو 6: مشرفون متعددون ===\n";

setup_catalog_owner_with_event(9506, 9706, 5, 'stat6');
set_test_event_full(9706, 9506, 'مناسبة عدة مشرفين');
$supA = authenticate_supervisor_for_event(9706, 9506, '0500000061', 'المشرف أ');
$supB = authenticate_supervisor_for_event(9706, 9506, '0500000062', 'المشرف ب');
$supC = authenticate_supervisor_for_event(9706, 9506, '0500000063', 'المشرف ج (بلا تسجيل)');

seed_checked_in_rsvp(9706, '0530000060', 1, 2, 'qr', $supA['assignment_id'], '2026-02-04 08:00:00');
seed_checked_in_rsvp(9706, '0530000061', 0, 1, 'manual', $supA['assignment_id'], '2026-02-04 08:05:00');
seed_checked_in_rsvp(9706, '0530000062', 2, 3, 'qr', $supB['assignment_id'], '2026-02-04 08:10:00');

$sup_summary6 = test_construct_engine()->get_supervisor_summary(9706);
check('6. ثلاثة مشرفين في الملخّص (حتى من لم يسجّل حضوراً)', count($sup_summary6), 3);

$by_assignment6 = [];
foreach ($sup_summary6 as $row) {
    $by_assignment6[$row['assignment_id']] = $row;
}
check('6. المشرف أ: عمليتا تسجيل حضور', $by_assignment6[$supA['assignment_id']]['checkins_recorded'], 2);
check('6. المشرف أ: 3 ضيوف (2+1)', $by_assignment6[$supA['assignment_id']]['guests_recorded'], 3);
check('6. المشرف ب: عملية تسجيل واحدة', $by_assignment6[$supB['assignment_id']]['checkins_recorded'], 1);
check('6. المشرف ب: 3 ضيوف', $by_assignment6[$supB['assignment_id']]['guests_recorded'], 3);
check('6. المشرف ج: صفر عمليات (ظاهر رغم عدم التسجيل)', $by_assignment6[$supC['assignment_id']]['checkins_recorded'], 0);
check('6. المشرف ج: اسم العرض صحيح', $by_assignment6[$supC['assignment_id']]['supervisor_name'], 'المشرف ج (بلا تسجيل)');
check_true('6. هاتف كل مشرف مقنَّع (لا رقم خام)', strpos($by_assignment6[$supA['assignment_id']]['masked_phone'], '•') !== false);

// ============================================================================
// السيناريو 7: تجميع مجموعة بيانات كبيرة (Large dataset aggregation)
// ============================================================================
echo "\n=== السيناريو 7: تجميع مجموعة بيانات كبيرة ===\n";

setup_catalog_owner_with_event(9507, 9707, 5, 'stat7');
set_test_event_full(9707, 9507, 'مناسبة كبيرة');
$sup7 = authenticate_supervisor_for_event(9707, 9507, '0500000071', 'مشرف الكبيرة');

for ($i = 1; $i <= 500; $i++) {
    if ($i % 3 === 0) {
        seed_checked_in_rsvp(9707, '0550' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 1, 2, ($i % 2 === 0 ? 'qr' : 'manual'), $sup7['assignment_id'], sprintf('2026-02-05 %02d:%02d:00', (int) intdiv($i, 60) % 24, $i % 60));
    } else {
        seed_pending_rsvp(9707, '0550' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 0);
    }
}
$expected_checked_in_7 = (int) floor(500 / 3);
$summary7 = test_construct_engine()->get_attendance_summary(9707);
check('7. total_invitations = 500', $summary7['total_invitations'], 500);
check('7. checked_in_invitations صحيح على 500 صف', $summary7['checked_in_invitations'], $expected_checked_in_7);
check('7. actual_attendees = checked_in × 2', $summary7['actual_attendees'], $expected_checked_in_7 * 2);

$recent7 = test_construct_engine()->get_recent_checkins(9707, 5);
check('7. get_recent_checkins() يحترم الحد الأقصى (5) حتى مع 500 صف', count($recent7), 5);

// ============================================================================
// السيناريو 8: التفويض (Authorization)
// ============================================================================
echo "\n=== السيناريو 8: التفويض ===\n";

setup_catalog_owner_with_event(9508, 9708, 5, 'stat8');
set_test_event_full(9708, 9508, 'مناسبة تفويض');
seed_checked_in_rsvp(9708, '0530000080', 0, 1, 'manual', 1, '2026-02-06 07:00:00');

// 8.أ: لا مستخدم مسجَّل دخول، لا جلسة مشرف — رفض.
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$dash8a = PGE_Attendance_Dashboard_Provider::get_dashboard(9708);
check('8.أ لا هوية إطلاقاً: denied', $dash8a['result'] ?? null, 'denied');

// 8.ب: مضيف المناسبة نفسه (post_author) — مصرَّح.
$GLOBALS['__test_current_user_id'] = 9508;
$dash8b = PGE_Attendance_Dashboard_Provider::get_dashboard(9708);
check('8.ب مضيف المناسبة: authorized', $dash8b['result'] ?? null, 'authorized');
check('8.ب عبر المضيف تحديداً', $dash8b['via'] ?? null, 'host');
check('8.ب attendance_summary موجود في البيانات', $dash8b['data']['attendance_summary']['total_invitations'] ?? null, 1);

// 8.ج: مستخدم آخر مسجَّل دخول (ليس مضيفاً ولا أدمن) — رفض.
$GLOBALS['__test_current_user_id'] = 555555;
$dash8c = PGE_Attendance_Dashboard_Provider::get_dashboard(9708);
check('8.ج مستخدم غريب: denied', $dash8c['result'] ?? null, 'denied');

// 8.د: أدمن (current_user_can('administrator')) — مصرَّح حتى لغير المضيف.
$GLOBALS['__test_user_is_admin'] = true;
$dash8d = PGE_Attendance_Dashboard_Provider::get_dashboard(9708);
check('8.د أدمن: authorized', $dash8d['result'] ?? null, 'authorized');
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_current_user_id'] = 0;

// 8.هـ: جلسة مشرف حقيقية صالحة لنفس المناسبة — مصرَّح.
$sup8 = authenticate_supervisor_for_event(9708, 9508, '0500000081', 'مشرف الثامن');
$dash8e = PGE_Attendance_Dashboard_Provider::get_dashboard(9708);
check('8.هـ جلسة مشرف صالحة لنفس المناسبة: authorized', $dash8e['result'] ?? null, 'authorized');
check('8.هـ عبر المشرف تحديداً', $dash8e['via'] ?? null, 'supervisor');

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 9: عزل عبر المناسبات (Cross-event isolation)
// ============================================================================
echo "\n=== السيناريو 9: عزل عبر المناسبات ===\n";

setup_catalog_owner_with_event(9509, 9709, 5, 'stat9a');
setup_catalog_owner_with_event(9510, 9710, 5, 'stat9b');
set_test_event_full(9709, 9509, 'مناسبة أ (تسع)');
set_test_event_full(9710, 9510, 'مناسبة ب (عشر)');

seed_checked_in_rsvp(9709, '0530000090', 0, 1, 'manual', 1, '2026-02-07 06:00:00');
seed_checked_in_rsvp(9710, '0530000091', 4, 5, 'manual', 2, '2026-02-07 06:00:00');
seed_checked_in_rsvp(9710, '0530000092', 4, 5, 'manual', 2, '2026-02-07 06:01:00');

$summary9709 = test_construct_engine()->get_attendance_summary(9709);
$summary9710 = test_construct_engine()->get_attendance_summary(9710);
check('9. مناسبة 9709 معزولة: total=1', $summary9709['total_invitations'], 1);
check('9. مناسبة 9710 معزولة: total=2', $summary9710['total_invitations'], 2);
check_true('9. أرقام المناسبتين مختلفتان فعلياً (لا تسريب)', $summary9709['expected_guests'] !== $summary9710['expected_guests']);

// مشرف مصادَق لمناسبة 9709 يحاول قراءة إحصاء 9710 — يجب أن يُرفَض صراحة.
$sup9 = authenticate_supervisor_for_event(9709, 9509, '0500000091', 'مشرف تسع');
$dash9_own = PGE_Attendance_Dashboard_Provider::get_dashboard(9709);
check('9. المشرف يقرأ إحصاء مناسبته الخاصة: authorized', $dash9_own['result'] ?? null, 'authorized');

$dash9_other = PGE_Attendance_Dashboard_Provider::get_dashboard(9710);
check('9. نفس المشرف يحاول قراءة إحصاء مناسبة أخرى: denied', $dash9_other['result'] ?? null, 'denied');
check('9. السبب الصريح: event_mismatch', $dash9_other['reason'] ?? null, 'event_mismatch');
check('9. كود HTTP = 403', $dash9_other['http_status'] ?? null, 403);

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 10: انحدار على المراحل 1-4
// ============================================================================
echo "\n=== السيناريو 10: انحدار على المراحل 1-4 ===\n";

setup_catalog_owner_with_event(9511, 9711, 3, 'stat10');
set_test_event_full(9711, 9511, 'مناسبة انحدار');

$quota10 = pge_resolve_supervisor_quota_status(9711);
check_true('10. pge_resolve_supervisor_quota_status() (Phase 1) لا يزال يعمل', !is_wp_error($quota10));

$invite10 = create_and_get_token(9711, 9511, '0511110010');
PGE_Supervisor_Assignment_Service::accept_invitation($invite10['invitation_token']);
check_true('10. pge_has_active_supervisor_assignment() (Phase 2) لا يزال يعمل', pge_has_active_supervisor_assignment(9711, '0511110010'));

$sup10 = authenticate_supervisor_for_event(9711, 9511, '0511110011');
check('10. مصادقة كاملة (Phase 3) لا تزال تعمل', $sup10['event_id'], 9711);

$authz10 = PGE_Supervisor_Portal_Middleware::authorize();
check('10. Portal Middleware (Phase 3.5) لا يزال يعمل', $authz10['result'] ?? null, 'authorized');

// تسجيل حضور حقيقي عبر محرك Phase 4 الكامل (rsvp_id-based)، ثم قراءة إحصاءات
// Phase 5 فوقه مباشرة — إثبات تكامل كامل من Phase 1 إلى Phase 5.
$rsvp10 = $wpdb->seed_rsvp(9711, '0511110011', ['companions' => 0, 'reply' => 'yes']);
$resolved10 = PGE_Guest_Resolution_Service::resolve_by_rsvp_id(9711, $rsvp10);
$confirm10 = PGE_Checkin_Recorder::record_guest_checkin($authz10['assignment_id'], $resolved10['guest'], 1, 'manual');
check('10. تسجيل حضور فعلي عبر محرك Phase 4 لا يزال يعمل', $confirm10['result'] ?? null, 'checked_in');

$summary10 = test_construct_engine()->get_attendance_summary(9711);
check('10. إحصاء Phase 5 يعكس الكتابة الحقيقية عبر Phase 4 فوراً', $summary10['checked_in_invitations'], 1);

$dash10 = PGE_Attendance_Dashboard_Provider::get_dashboard(9711);
check('10. Dashboard Provider يعمل فوق أنبوب كامل من Phase 1 إلى Phase 5', $dash10['result'] ?? null, 'authorized');
check('10. event_summary.title صحيح', $dash10['data']['event_summary']['title'] ?? null, 'مناسبة انحدار');

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 11: حدود الوصول المُنفَّذة في الكود (Phase 5 Final Fix)
// ============================================================================
echo "\n=== السيناريو 11: حدود الوصول المُنفَّذة (Enforced Statistics Access Boundary) ===\n";

// --- المتطلَّب 1: الإنشاء المباشر للمحرّك يُرفَض ------------------------------
$direct_construction_rejected = false;
try {
    /** @noinspection PhpExpressionResultUnusedInspection */
    new PGE_Attendance_Statistics_Service();
} catch (\Error $e) {
    $direct_construction_rejected = true;
}
check_true('11.1 new PGE_Attendance_Statistics_Service() مباشرةً يُرفَض بخطأ PHP فادح (باني خاص)', $direct_construction_rejected);

// --- المتطلَّب 2: الاستدعاء العام المباشر لدوال الحساب الخام يُرفَض/مستحيل ----
$static_call_rejected = false;
try {
    /** @phpstan-ignore-next-line */
    PGE_Attendance_Statistics_Service::get_attendance_summary(1);
} catch (\Error $e) {
    $static_call_rejected = true;
}
check_true('11.2 استدعاء get_attendance_summary() Statically بلا كائن يُرفَض بخطأ PHP فادح', $static_call_rejected);

// --- المتطلَّب 3: Provider يصل للمحرّك عبر الآلية الداخلية المعتمَدة فقط -----
// إثبات سلوكي (لا Reflection على Provider هنا): طلب مُخوَّل فعلياً يُعيد بيانات
// إحصاء صحيحة، ما يثبت أن Provider استطاع بناء واستدعاء المحرّك بنجاح رغم أن
// المحرّك نفسه مرفوض الإنشاء من أي مكان آخر (11.1/11.2 أعلاه) — الآلية الوحيدة
// القادرة على ذلك هي engine() الداخلية الخاصة بـProvider.
setup_catalog_owner_with_event(9513, 9713, 3, 'stat11c');
set_test_event_full(9713, 9513, 'مناسبة اختبار الآلية الداخلية');
seed_checked_in_rsvp(9713, '0511130001', 1, 2, 'manual', 1, '2026-02-08 09:00:00');
$GLOBALS['__test_current_user_id'] = 9513;
$dash11c = PGE_Attendance_Dashboard_Provider::get_dashboard(9713);
check('11.3 Provider يبني ويستدعي المحرّك بنجاح عبر آليته الداخلية فقط', $dash11c['result'] ?? null, 'authorized');
check('11.3 البيانات المُعادة صحيحة فعلياً (المحرّك نفَّذ الحساب الحقيقي)', $dash11c['data']['attendance_summary']['total_invitations'] ?? null, 1);
$GLOBALS['__test_current_user_id'] = 0;

// إثبات تكميلي عبر Reflection **على Provider فقط، داخل ملف الاختبار حصراً**
// (مسموح صراحةً في الـRFC: "Reflection only inside the test file") — للتحقّق
// أن engine() ليست عامة (private) ولا تظهر ضمن أي API عام لـProvider.
$provider_reflection = new ReflectionClass('PGE_Attendance_Dashboard_Provider');
$engine_method = $provider_reflection->getMethod('engine');
check_true('11.3 دالة engine() الداخلية في Provider خاصة (private) فعلياً', $engine_method->isPrivate());

// --- المتطلَّب 4: بوابة تنفيذ الاستعلام — صفر استعلامات محرّك قبل نجاح التفويض ---
setup_catalog_owner_with_event(9514, 9714, 3, 'stat11d');
set_test_event_full(9714, 9514, 'مناسبة بوابة الاستعلام');
seed_checked_in_rsvp(9714, '0511140001', 0, 1, 'manual', 1, '2026-02-08 09:00:00');

$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$wpdb->engine_query_count = 0;
$dash_guard_denied = PGE_Attendance_Dashboard_Provider::get_dashboard(9714);
check('11.4 طلب غير مُخوَّل: النتيجة denied', $dash_guard_denied['result'] ?? null, 'denied');
check('11.4 طلب غير مُخوَّل: صفر استعلامات محرّك نُفِّذت قبل رفض التفويض', $wpdb->engine_query_count, 0);

$GLOBALS['__test_current_user_id'] = 9514;
$wpdb->engine_query_count = 0;
$dash_guard_allowed = PGE_Attendance_Dashboard_Provider::get_dashboard(9714);
check('11.4 طلب مُخوَّل (لنفس المناسبة بعد ذلك): النتيجة authorized', $dash_guard_allowed['result'] ?? null, 'authorized');
check_true('11.4 طلب مُخوَّل: استعلامات محرّك فعلية نُفِّذت هذه المرة (> 0)', $wpdb->engine_query_count > 0);
$GLOBALS['__test_current_user_id'] = 0;

// --- المتطلَّبات 5-8: مضيف/مشرف مُخوَّل ينجح، غريب/بلا هوية يُرفَض، عزل مناسبات ---
// (مُغطاة بالفعل بالتفصيل في السيناريوهين 8 و9 أعلاه بنفس المسار الإنتاجي
// get_dashboard() — نُعيد هنا تأكيداً صريحاً مباشراً تحت عنوان هذه المتطلَّبات
// تحديداً، دون تكرار منطق الإعداد).
check('11.5 مضيف مُخوَّل يحصل على الإحصاء (نفس نتيجة 11.3)', $dash11c['result'] ?? null, 'authorized');
check('11.6 مشرف مُخوَّل لمناسبته يحصل على الإحصاء (نفس نتيجة السيناريو 9)', $dash9_own['result'] ?? null, 'authorized');
check('11.7 مشرف لا يحصل على إحصاء مناسبة أخرى (نفس نتيجة السيناريو 9)', $dash9_other['result'] ?? null, 'denied');
check('11.8 طلب بلا هوية إطلاقاً يُرفَض (نفس نتيجة 11.4)', $dash_guard_denied['result'] ?? null, 'denied');

// --- المتطلَّب 9: لا دالة عامة تُعيد كائن المحرّك الخام -----------------------
$no_public_getter_exposes_engine = true;
foreach ($provider_reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    $return_type = $method->getReturnType();
    if ($return_type !== null && strpos((string) $return_type, 'PGE_Attendance_Statistics_Service') !== false) {
        $no_public_getter_exposes_engine = false;
        break;
    }
}
check_true('11.9 لا دالة عامة واحدة في Provider تُعلن إرجاع كائن المحرّك الخام', $no_public_getter_exposes_engine);
check_true('11.9 خرج get_dashboard() لا يحتوي إطلاقاً كائناً من نوع المحرّك (بيانات مصفوفة بحتة)', !($dash11c['data'] ?? null) instanceof PGE_Attendance_Statistics_Service);

// --- المتطلَّب 10: نتائج مقاييس Phase 5 لم تتغيّر ---------------------------
// مُثبَت ضمنياً: كل فحوصات السيناريوهات 1-10 أعلاه (70 فحصاً) استخدمت نفس صيغ
// الحساب بعد إعادة الهيكلة (دوال كائن بدل static) وما زالت جميعها ناجحة بنفس
// القيم الرقمية بالضبط — لا تغيير في أي صيغة حساب هنا.
check_true('11.10 كل نتائج Phase 5 الرقمية (السيناريوهات 1-7) لم تتغيّر بعد إعادة الهيكلة', $failures === array_values(array_filter($failures, function ($f) {
    return strpos($f, '11.') === 0;
})));

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
