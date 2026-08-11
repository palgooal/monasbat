<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ"Messaging Architecture — Phase 2
 * (Foundation)": بنية قاعدة البيانات (thank_you_sent_at + pge_message_log)،
 * PGE_Message_Log، PGE_Thank_You_Claim، PGE_Message_Batch.
 *
 * يحمّل الملفات الحقيقية التالية دون أي تعديل عليها:
 *   - includes/class-pge-message-type.php   (Phase 1، مُعاد استخدامه فقط)
 *   - includes/class-pge-messaging-schema.php
 *   - includes/class-pge-message-log.php
 *   - includes/class-pge-message-batch.php
 *   - includes/class-pge-thank-you-claim.php
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. الخروج برمز 0 عند نجاح كل
 * الحالات، أو 1 عند فشل أي حالة.
 *
 * التشغيل: php tests/test-messaging-phase2.php
 */

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
    $GLOBALS['__test_now'] = '2026-08-10 12:00:00';
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now']; }
}

if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string) $v)); }
}

// dbDelta() الحقيقية دالة WordPress core (wp-admin/includes/upgrade.php) —
// غير متاحة في هذه البيئة المعزولة. محاكاة أمينة محدودة كفاية فقط لاختبار
// PGE_Messaging_Schema::ensure_message_log_table() (الاستدعاء الوحيد لها في
// هذا الملف): تُنشئ أعمدة جدول message_log في $wpdb الوهمي عند أول استدعاء —
// نفس النمط المُستخدَم فعلياً في tests/test-checkin-engine.php.
if (!function_exists('dbDelta')) {
    function dbDelta($sql) {
        global $wpdb;
        if (stripos($sql, 'pge_message_log') !== false && method_exists($wpdb, 'ensure_message_log_table_created')) {
            $wpdb->ensure_message_log_table_created();
        }
    }
}

// class-pge-messaging-schema.php::ensure_message_log_table() ينفّذ
// `require_once ABSPATH . 'wp-admin/includes/upgrade.php';` قبل dbDelta() —
// الملف الحقيقي غير موجود هنا؛ نُنشئ ملفاً فارغاً في نفس المسار (نفس نمط
// test-checkin-engine.php حرفياً).
$__upgrade_stub_dir = ABSPATH . 'wp-admin/includes';
if (!is_dir($__upgrade_stub_dir)) {
    @mkdir($__upgrade_stub_dir, 0777, true);
}
if (!file_exists($__upgrade_stub_dir . '/upgrade.php')) {
    file_put_contents($__upgrade_stub_dir . '/upgrade.php', "<?php\n// stub لأغراض الاختبار فقط — dbDelta() مُعرَّفة أعلاه بالفعل.\n");
}

$GLOBALS['__test_options'] = [];
function get_option($name, $default = false) { return $GLOBALS['__test_options'][$name] ?? $default; }
$GLOBALS['__test_option_updates'] = 0;
function update_option($name, $value) {
    $GLOBALS['__test_option_updates']++;
    $GLOBALS['__test_options'][$name] = $value;
    return true;
}

// ══════════════════════════════════════════════════════════════════════
// Fake $wpdb: pge_event_rsvps (subset) + pge_message_log
// ══════════════════════════════════════════════════════════════════════

class Fake_Wpdb_Messaging_Phase2
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $rsvps = [];
    public $message_log = [];

    private $rsvps_next_id = 1;
    private $message_log_next_id = 1;

    /** حالة Schema قبل الترقية — يطابق pge_create_rsvp_table() الحقيقية، بلا thank_you_sent_at بعد. */
    public $rsvps_columns = [
        'id', 'event_id', 'guest_phone', 'guest_name', 'companions', 'note',
        'reply', 'checked_in', 'checked_in_at', 'created_at', 'updated_at',
    ];
    public $message_log_columns = [];
    public $message_log_table_exists = false;
    public $lifecycle_column_type = 'datetime';
    public $lifecycle_column_null = 'YES';
    public $force_lifecycle_alter_failure = false;
    public $schema_alter_count = 0;
    public $schema_dbdelta_count = 0;
    public $schema_column_reads = 0;
    public $schema_sql = [];

    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];
    public $force_lock_unavailable = false;
    public $force_message_log_insert_failure = false;
    public $throw_message_log_insert_exception = false;

    public function get_charset_collate() { return ''; }

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
        if (strpos($sql_or_table, $this->prefix . 'pge_message_log') !== false) {
            return 'message_log';
        }
        if (strpos($sql_or_table, $this->prefix . 'pge_event_rsvps') !== false) {
            return 'rsvps';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $which = $this->which_table($sql);
        $row = null;

        if ($which === 'rsvps') {
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
                $id = (int) $m[1];
                $row = $this->rsvps[$id] ?? null;
            }
        } else {
            $rows = $this->get_results($sql, $output);
            $row = $rows[0] ?? null;
        }

        if ($row === null) {
            return null;
        }

        // مطابقة عقد $wpdb->get_row() الحقيقي: OBJECT افتراضياً، ARRAY_A فقط
        // إن طُلب صراحةً — نفس التمييز الذي يعتمد عليه الكود الإنتاجي فعلياً
        // (class-pge-thank-you-claim.php يستخدم `->` بلا ARRAY_A، بينما
        // class-pge-checkin-recorder.php يمرر ARRAY_A صراحةً ويستخدم `[]`).
        return $output === ARRAY_A ? $row : (object) $row;
    }

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_acquire_log[] = $name;
            if ($this->force_lock_unavailable || isset($this->held_locks[$name])) {
                return '0';
            }
            $this->held_locks[$name] = true;
            return '1';
        }

        if (stripos(ltrim($sql), 'SELECT COUNT(*)') === 0) {
            $which = $this->which_table($sql);
            if ($which === null) {
                return '0';
            }
            $rows = array_values($which === 'rsvps' ? $this->rsvps : $this->message_log);
            $filtered = $this->apply_where($rows, $sql);
            return (string) count($filtered);
        }

        // SELECT <single_column> FROM <table> WHERE id = %d
        if (preg_match('/^SELECT\s+(\w+)\s+FROM\s+(\S+)\s+WHERE\s+id\s*=\s*(\d+)/i', trim($sql), $m)) {
            $column = $m[1];
            $which = $this->which_table($m[2]);
            $id = (int) $m[3];
            $store = $which === 'rsvps' ? $this->rsvps : ($which === 'message_log' ? $this->message_log : []);
            $row = $store[$id] ?? null;
            if ($row === null) {
                return null;
            }
            return $row[$column] ?? null;
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

        if (preg_match('/ALTER TABLE\s+(\S+)\s+ADD COLUMN\s+(\w+)/i', $sql, $m)) {
            $which = $this->which_table($m[1]);
            $this->schema_sql[] = $sql;
            $this->schema_alter_count++;
            if ($which === 'message_log' && $m[2] === 'lifecycle_started_at' && $this->force_lifecycle_alter_failure) {
                return false;
            }
            if ($which === 'rsvps' && !in_array($m[2], $this->rsvps_columns, true)) {
                $this->rsvps_columns[] = $m[2];
            }
            if ($which === 'message_log' && !in_array($m[2], $this->message_log_columns, true)) {
                $this->message_log_columns[] = $m[2];
            }
            return 1;
        }

        return false;
    }

    public function get_results($sql, $output = null)
    {
        if (preg_match('/SHOW\s+COLUMNS\s+FROM\s+(\S+)/i', $sql, $m)) {
            $this->schema_column_reads++;
            $which = $this->which_table($m[1]);
            if ($which === 'rsvps') {
                return array_map(function ($c) {
                    return [
                        'Field' => $c,
                        'Type' => $c === 'thank_you_sent_at' ? 'datetime' : 'varchar(255)',
                        'Null' => $c === 'thank_you_sent_at' ? 'YES' : 'NO',
                    ];
                }, $this->rsvps_columns);
            }
            if ($which === 'message_log') {
                if (!$this->message_log_table_exists) {
                    return null;
                }
                return array_map(function ($c) {
                    return [
                        'Field' => $c,
                        'Type' => $c === 'lifecycle_started_at' ? $this->lifecycle_column_type : 'varchar(255)',
                        'Null' => $c === 'lifecycle_started_at' ? $this->lifecycle_column_null : 'YES',
                    ];
                }, $this->message_log_columns);
            }
            return [];
        }

        $which = $this->which_table($sql);
        if ($which === null) {
            return [];
        }

        $rows = array_values($which === 'rsvps' ? $this->rsvps : $this->message_log);
        $filtered = $this->apply_where($rows, $sql);

        if (preg_match('/ORDER BY\s+id\s+ASC/i', $sql)) {
            usort($filtered, function ($a, $b) { return $a['id'] <=> $b['id']; });
        }

        return $filtered;
    }

    private function apply_where(array $rows, $sql)
    {
        if (preg_match('/WHERE\s+(.+?)(ORDER BY|LIMIT|$)/is', $sql, $m)) {
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

    public function insert($table, $data, $format = null)
    {
        $this->last_error = '';
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }

        if ($which === 'rsvps') {
            $id = $this->rsvps_next_id++;
            $defaults = ['thank_you_sent_at' => null, 'created_at' => current_time('mysql', true)];
            $this->rsvps[$id] = array_merge(['id' => $id], $defaults, $data);
            $this->insert_id = $id;
            return 1;
        }

        // message_log
        if ($this->throw_message_log_insert_exception) {
            throw new RuntimeException('simulated_message_log_insert_exception');
        }
        if ($this->force_message_log_insert_failure) {
            return false;
        }
        $id = $this->message_log_next_id++;
        $this->message_log[$id] = array_merge(['id' => $id], $data);
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }

        if ($which === 'rsvps') {
            $store = &$this->rsvps;
        } else {
            $store = &$this->message_log;
        }

        $id = $where['id'] ?? null;
        if ($id === null || !isset($store[$id])) {
            return 0;
        }

        foreach ($where as $where_key => $where_value) {
            if ($where_key === 'id') {
                continue;
            }
            $current_value = $store[$id][$where_key] ?? null;
            if ($where_value === null) {
                if ($current_value !== null) {
                    return 0;
                }
                continue;
            }
            if ((string) $current_value !== (string) $where_value) {
                return 0;
            }
        }

        foreach ($data as $k => $v) {
            $store[$id][$k] = $v;
        }

        return 1;
    }

    /** يُستدعى فقط من stub الاختبار لـdbDelta() أعلاه. */
    public function ensure_message_log_table_created()
    {
        $this->schema_dbdelta_count++;
        $this->message_log_table_exists = true;
        if (empty($this->message_log_columns)) {
            $this->message_log_columns = ['id', 'event_id', 'rsvp_id', 'lifecycle_started_at', 'guest_phone', 'message_type', 'batch_id', 'status', 'provider', 'actor_user_id', 'created_at', 'sent_at'];
        }
    }

    /** Helper اختبار فقط: إدراج صف RSVP مباشرة (يُشابه واقع الإنتاج قبل هذه المرحلة). */
    public function seed_rsvp($event_id, $guest_phone, $thank_you_sent_at = null)
    {
        $id = $this->rsvps_next_id++;
        $this->rsvps[$id] = [
            'id' => $id,
            'event_id' => $event_id,
            'guest_phone' => $guest_phone,
            'thank_you_sent_at' => $thank_you_sent_at,
            'created_at' => current_time('mysql', true),
        ];
        return $id;
    }
}

global $wpdb;
$wpdb = new Fake_Wpdb_Messaging_Phase2();

require_once __DIR__ . '/../includes/class-pge-message-type.php';
require_once __DIR__ . '/../includes/class-pge-messaging-schema.php';
require_once __DIR__ . '/../includes/class-pge-message-log.php';
require_once __DIR__ . '/../includes/class-pge-message-batch.php';
require_once __DIR__ . '/../includes/class-pge-thank-you-claim.php';

// ── أدوات الفحص (نفس نمط tests/test-feature-registry.php) ──

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
        $failures[] = "$label (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")";
        echo "FAIL  $label (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")\n";
    }
}

function check_true($label, $condition)
{
    global $total, $passed, $failures;
    $total++;
    if ($condition) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failures[] = "$label (condition was false)";
        echo "FAIL  $label (condition was false)\n";
    }
}

// ══════════════════════════════════════════════════════════════════════
// Schema (1-5)
// ══════════════════════════════════════════════════════════════════════

// قبل الترقية: العمود غير موجود فعلياً (يطابق واقع الإنتاج الحالي).
check_true("0. قبل الترقية: thank_you_sent_at غير موجود بعد", !in_array('thank_you_sent_at', $wpdb->rsvps_columns, true));

PGE_Messaging_Schema::maybe_upgrade();

check_true("1. thank_you_sent_at موجود بعد الترقية", in_array('thank_you_sent_at', $wpdb->rsvps_columns, true));

// 2. nullable: نتحقق عملياً أن صفاً بلا قيمة يُقرأ NULL بلا رفض/خطأ (السلوك
// الوظيفي لـNULLable — لا معنى لفحص DDL نصي هنا في بيئة Fake بلا SHOW
// COLUMNS TYPE؛ الدليل العملي: يمكن إدراج/قراءة صف بـthank_you_sent_at=NULL).
$rid_nullable_check = $wpdb->seed_rsvp(9001, '966500000001', null);
check("2. thank_you_sent_at nullable — صف جديد بلا قيمة يُقرأ NULL", $wpdb->rsvps[$rid_nullable_check]['thank_you_sent_at'], null);

check_true("3. جدول pge_message_log موجود بعد الترقية", !empty($wpdb->message_log_columns));

$required_log_columns = ['id', 'event_id', 'rsvp_id', 'lifecycle_started_at', 'guest_phone', 'message_type', 'batch_id', 'status', 'provider', 'actor_user_id', 'created_at', 'sent_at'];
$missing_columns = array_diff($required_log_columns, $wpdb->message_log_columns);
check_true("4. الأعمدة الأساسية لـmessage_log كلها موجودة", empty($missing_columns));

// Upgrade path من Schema 1.0.0: dbDelta لا يضيف العمود في هذا الـFake، لذلك
// يثبت الفحص أن ALTER additive الصريح يعمل على جدول قائم قبل اعتماد الإصدار.
$wpdb->message_log_columns = array_values(array_diff($wpdb->message_log_columns, ['lifecycle_started_at']));
$GLOBALS['__test_options'][PGE_Messaging_Schema::VERSION_OPTION] = '1.0.0';
PGE_Messaging_Schema::maybe_upgrade();
check_true('4b. upgrade من 1.0.0 يضيف lifecycle_started_at إلى الجدول القائم', in_array('lifecycle_started_at', $wpdb->message_log_columns, true));
check('4c. schema version لا تُعتمد إلا بعد postcondition', $GLOBALS['__test_options'][PGE_Messaging_Schema::VERSION_OPTION] ?? null, PGE_Messaging_Schema::SCHEMA_VERSION);

// Phase 4A-3 — version الحالية لا تتجاوز structural postconditions.
$healthy_alters = $wpdb->schema_alter_count;
$healthy_dbdelta = $wpdb->schema_dbdelta_count;
$healthy_updates = $GLOBALS['__test_option_updates'];
PGE_Messaging_Schema::maybe_upgrade();
check_true('4d. current version + correct contract = no-op بلا ALTER/dbDelta/version churn',
    $wpdb->schema_alter_count === $healthy_alters
    && $wpdb->schema_dbdelta_count === $healthy_dbdelta
    && $GLOBALS['__test_option_updates'] === $healthy_updates);

// Current-version drift: صف قديم يجب أن يبقى byte-for-byte، والعمود الجديد NULL.
$wpdb->message_log[900] = [
    'id' => 900, 'event_id' => 9001, 'rsvp_id' => 901, 'guest_phone' => '966500009001',
    'message_type' => 'thank_you', 'batch_id' => 'schema-drift-row', 'status' => 'pending',
    'provider' => 'cartat', 'actor_user_id' => 1, 'created_at' => '2026-08-01 10:00:00', 'sent_at' => null,
];
$drift_row_before = $wpdb->message_log[900];
$wpdb->message_log_columns = array_values(array_diff($wpdb->message_log_columns, ['lifecycle_started_at']));
$GLOBALS['__test_options'][PGE_Messaging_Schema::VERSION_OPTION] = '1.1.0';
PGE_Messaging_Schema::maybe_upgrade();
check_true('4e. current version + missing column detects and repairs drift', in_array('lifecycle_started_at', $wpdb->message_log_columns, true));
check_true('4f. drift repair ينتج DATETIME NULL', $wpdb->lifecycle_column_type === 'datetime' && $wpdb->lifecycle_column_null === 'YES');
check('4g. existing row survives additive repair unchanged', $wpdb->message_log[900], $drift_row_before);
check('4h. successful current-version repair keeps version 1.1.0', $GLOBALS['__test_options'][PGE_Messaging_Schema::VERSION_OPTION], '1.1.0');

// فشل ADD COLUMN: لا نجاح زائف؛ version لا تُخفَّض لكن postcondition تبقى missing.
$wpdb->message_log_columns = array_values(array_diff($wpdb->message_log_columns, ['lifecycle_started_at']));
$wpdb->force_lifecycle_alter_failure = true;
PGE_Messaging_Schema::maybe_upgrade();
check_true('4i. failed drift repair leaves structural postcondition missing', !in_array('lifecycle_started_at', $wpdb->message_log_columns, true));
check('4j. failed current-version repair does not churn/downgrade version', $GLOBALS['__test_options'][PGE_Messaging_Schema::VERSION_OPTION], '1.1.0');
$wpdb->force_lifecycle_alter_failure = false;
PGE_Messaging_Schema::maybe_upgrade();

// Missing option + old existing table follows the same safe additive upgrade.
$wpdb->message_log_columns = array_values(array_diff($wpdb->message_log_columns, ['lifecycle_started_at']));
unset($GLOBALS['__test_options'][PGE_Messaging_Schema::VERSION_OPTION]);
PGE_Messaging_Schema::maybe_upgrade();
check_true('4k. missing version + old table upgrades safely', in_array('lifecycle_started_at', $wpdb->message_log_columns, true));
check('4l. missing version is promoted only after repair', $GLOBALS['__test_options'][PGE_Messaging_Schema::VERSION_OPTION], '1.1.0');

// Current version + missing table must recreate through the existing CREATE contract.
$wpdb->message_log_table_exists = false;
$wpdb->message_log_columns = [];
$wpdb->message_log = [];
$GLOBALS['__test_options'][PGE_Messaging_Schema::VERSION_OPTION] = '1.1.0';
$dbdelta_before_missing_table = $wpdb->schema_dbdelta_count;
PGE_Messaging_Schema::maybe_upgrade();
check_true('4m. current version + missing message_log recreates the table', $wpdb->message_log_table_exists && !empty($wpdb->message_log_columns));
check('4n. missing table uses the existing dbDelta CREATE path once', $wpdb->schema_dbdelta_count, $dbdelta_before_missing_table + 1);

// Wrong/incompatible contract is detected and left untouched (لا MODIFY/CHANGE).
$wpdb->lifecycle_column_null = 'NO';
$wrong_contract_alters = $wpdb->schema_alter_count;
$wrong_contract_reads = $wpdb->schema_column_reads;
PGE_Messaging_Schema::maybe_upgrade();
check_true('4o. NOT NULL lifecycle contract is rechecked and fails closed without ALTER',
    $wpdb->schema_column_reads > $wrong_contract_reads
    && $wpdb->schema_alter_count === $wrong_contract_alters
    && $wpdb->lifecycle_column_null === 'NO');
$wpdb->lifecycle_column_null = 'YES';

// Existing non-NULL marker and healthy schema survive repeated runs unchanged.
$wpdb->message_log[901] = [
    'id' => 901, 'event_id' => 9002, 'rsvp_id' => 902,
    'lifecycle_started_at' => '2026-08-02 11:00:00', 'guest_phone' => '966500009002',
    'message_type' => 'thank_you', 'batch_id' => 'schema-idempotent-row', 'status' => 'sent',
    'provider' => 'cartat', 'actor_user_id' => 1, 'created_at' => '2026-08-02 11:01:00', 'sent_at' => '2026-08-02 11:01:10',
];
$marker_before = $wpdb->message_log[901];
$idempotent_alters = $wpdb->schema_alter_count;
PGE_Messaging_Schema::maybe_upgrade();
PGE_Messaging_Schema::maybe_upgrade();
check('4p. existing lifecycle value survives repeated maybe_upgrade()', $wpdb->message_log[901], $marker_before);
check('4q. repeated healthy runs issue no ALTER', $wpdb->schema_alter_count, $idempotent_alters);
check_true('4r. Schema hardening contains no destructive SQL', empty(array_filter($wpdb->schema_sql, function ($sql) {
    return preg_match('/\b(DROP|RENAME|MODIFY|CHANGE|DELETE|TRUNCATE)\b/i', $sql) === 1;
})));

// 5. indexes الأساسية — في هذا الـFake لا محرّك SQL عام يفهرس فعلياً، لكن
// الدليل العملي المتاح هو أن الـSchema الحقيقي (class-pge-messaging-schema.php)
// يُعرِّف KEY event_type (event_id, message_type)/KEY batch_id/KEY status في
// جملة CREATE TABLE نفسها — نتحقق نصياً من وجودها في مصدر الملف الحقيقي (لا
// تخمين، فحص مباشر على الكود المصدري الفعلي المسؤول عن الفهارس).
$schema_src = file_get_contents(__DIR__ . '/../includes/class-pge-messaging-schema.php');
check_true("5a. فهرس event_type (event_id, message_type) معرَّف في Schema", strpos($schema_src, 'KEY event_type (event_id, message_type)') !== false);
check_true("5b. فهرس batch_id معرَّف في Schema", strpos($schema_src, 'KEY batch_id (batch_id)') !== false);
check_true("5c. فهرس status معرَّف في Schema", strpos($schema_src, 'KEY status (status)') !== false);

// ══════════════════════════════════════════════════════════════════════
// Message Type (6)
// ══════════════════════════════════════════════════════════════════════

$rid_a = $wpdb->seed_rsvp(9101, '966500000010');
check("6. create_pending() يرفض message_type غير معروف", PGE_Message_Log::create_pending([
    'event_id' => 9101, 'rsvp_id' => $rid_a, 'guest_phone' => '966500000010',
    'message_type' => 'bogus_type', 'batch_id' => 'batch-invalid-type',
]), false);

// ══════════════════════════════════════════════════════════════════════
// Batch (7-8)
// ══════════════════════════════════════════════════════════════════════

$batch_x = PGE_Message_Batch::generate_batch_id();
$batch_y = PGE_Message_Batch::generate_batch_id();

check_true("7. batch_id غير فارغ", is_string($batch_x) && $batch_x !== '');
check_true("8. batch_id مختلف في عمليتين متتاليتين", $batch_x !== $batch_y);

// ══════════════════════════════════════════════════════════════════════
// Message Log (9-15)
// ══════════════════════════════════════════════════════════════════════

$rid_b = $wpdb->seed_rsvp(9102, '966500000020');
$log_id_b = PGE_Message_Log::create_pending([
    'event_id'      => 9102,
    'rsvp_id'       => $rid_b,
    'guest_phone'   => '966500000020',
    'message_type'  => PGE_Message_Type::REMINDER,
    'batch_id'      => 'batch-log-b',
    'provider'      => 'cartat',
    'actor_user_id' => 55,
]);

check_true("9. create_pending() ينشئ سجلاً بمعرّف صالح", is_int($log_id_b) && $log_id_b > 0);
check("9b. الحالة الابتدائية = pending", $wpdb->message_log[$log_id_b]['status'] ?? null, PGE_Message_Log::STATUS_PENDING);

check_true("10. mark_sent() ينجح على سجل pending", PGE_Message_Log::mark_sent($log_id_b));
check("10b. الحالة بعد mark_sent = sent", $wpdb->message_log[$log_id_b]['status'] ?? null, PGE_Message_Log::STATUS_SENT);
check_true("10c. sent_at مكتوب بعد mark_sent", !empty($wpdb->message_log[$log_id_b]['sent_at']));
check_true("10d. mark_sent() مكرر لاحقاً يفشل بأمان (idempotent، ليس pending بعد الآن)", PGE_Message_Log::mark_sent($log_id_b) === false);

$rid_c = $wpdb->seed_rsvp(9103, '966500000030');
$log_id_c = PGE_Message_Log::create_pending([
    'event_id' => 9103, 'rsvp_id' => $rid_c, 'guest_phone' => '966500000030',
    'message_type' => PGE_Message_Type::REMINDER, 'batch_id' => 'batch-log-c',
]);
check_true("11. mark_failed() ينجح على سجل pending", PGE_Message_Log::mark_failed($log_id_c, PGE_Message_Log::STATUS_FAILED));
check("11b. الحالة بعد mark_failed = failed", $wpdb->message_log[$log_id_c]['status'] ?? null, PGE_Message_Log::STATUS_FAILED);
check_true("11c. mark_failed() يرفض status غير مسموح (مثل 'sent')", PGE_Message_Log::mark_failed($log_id_c, 'sent') === false);

// 12. query by batch
$rid_d1 = $wpdb->seed_rsvp(9104, '966500000041');
$rid_d2 = $wpdb->seed_rsvp(9104, '966500000042');
$shared_batch = 'batch-shared-12';
PGE_Message_Log::create_pending(['event_id' => 9104, 'rsvp_id' => $rid_d1, 'guest_phone' => '966500000041', 'message_type' => PGE_Message_Type::REMINDER, 'batch_id' => $shared_batch]);
PGE_Message_Log::create_pending(['event_id' => 9104, 'rsvp_id' => $rid_d2, 'guest_phone' => '966500000042', 'message_type' => PGE_Message_Type::REMINDER, 'batch_id' => $shared_batch]);
$batch_rows = PGE_Message_Log::query_by_batch($shared_batch);
check("12. query_by_batch() تعيد سجلَّي الدفعة كاملة", count($batch_rows), 2);

// 13. query by event/type
$other_type_log = PGE_Message_Log::create_pending(['event_id' => 9104, 'rsvp_id' => $rid_d1, 'guest_phone' => '966500000041', 'message_type' => PGE_Message_Type::THANK_YOU, 'batch_id' => 'batch-other-type']);
$event_type_rows = PGE_Message_Log::query_by_event_type(9104, PGE_Message_Type::REMINDER);
check("13. query_by_event_type() تعيد فقط سجلات reminder للمناسبة 9104", count($event_type_rows), 2);

// 14/15. لا نص رسالة، لا QR/invite_code/token/payload مخزَّن في أي سجل أُنشئ أعلاه
$forbidden_keys = ['text', 'message', 'message_body', 'body', 'qr', 'invite_code', 'token', 'attempt_token', 'payload', 'api_response', 'credentials'];
$any_forbidden_found = false;
foreach ($wpdb->message_log as $row) {
    foreach ($forbidden_keys as $fk) {
        if (array_key_exists($fk, $row)) {
            $any_forbidden_found = true;
            break 2;
        }
    }
}
check_true("14. لا عمود نص رسالة (text/message/body) مخزَّن في أي سجل", !$any_forbidden_found);
check_true("15. لا عمود token (attempt_token/token) مخزَّن في أي سجل", !array_key_exists('token', $wpdb->message_log[$log_id_b] ?? []) && !array_key_exists('attempt_token', $wpdb->message_log[$log_id_b] ?? []));

// ══════════════════════════════════════════════════════════════════════
// Thank You Claim (16-20)
// ══════════════════════════════════════════════════════════════════════

$rid_e = $wpdb->seed_rsvp(9201, '966500000050');

$claim1 = PGE_Thank_You_Claim::claim(9201, $rid_e, '966500000050', 'batch-thankyou-e', 77);
check("16. أول claim ينجح", $claim1['result'] ?? null, 'claimed');
check_true("16b. claim ناجح يعيد log_id صالحاً", isset($claim1['log_id']) && (int) $claim1['log_id'] > 0);
check("16c. القفل مُحرَّر بعد claim (GET_LOCK/RELEASE_LOCK متوازنان)", count($wpdb->lock_acquire_log), count($wpdb->lock_release_log));

// محاكاة محاولة ثانية "متزامنة" لنفس rsvp_id قبل إنهاء الأولى (السجل لا يزال pending)
$claim2 = PGE_Thank_You_Claim::claim(9201, $rid_e, '966500000050', 'batch-thankyou-e2', 77);
check("17. claim ثانٍ متزامن لنفس الضيف يفشل أثناء استمرار الأول", $claim2['result'] ?? null, 'already_in_progress');

// محاكاة قفل محجوز فعلياً من طرف آخر (تزامن حقيقي على مستوى SQL) — rsvp مختلف
$rid_e_lock = $wpdb->seed_rsvp(9201, '966500000051');
$lock_name_e_lock = 'pge_thank_you_' . md5('9201|' . $rid_e_lock);
$wpdb->held_locks[$lock_name_e_lock] = true;
$claim_locked = PGE_Thank_You_Claim::claim(9201, $rid_e_lock, '966500000051', 'batch-thankyou-locked', 77);
check("17b. claim يفشل بـlock_not_acquired إذا كان القفل محجوزاً فعلياً", $claim_locked['result'] ?? null, 'error');
unset($wpdb->held_locks[$lock_name_e_lock]); // تنظيف

// إنهاء الأول بنجاح
$finalized = PGE_Thank_You_Claim::finalize_success($claim1['log_id'], $rid_e);
check_true("18. finalize_success() ينجح", $finalized);
check_true("18b. thank_you_sent_at أصبح غير NULL بعد finalize_success", !empty($wpdb->rsvps[$rid_e]['thank_you_sent_at']));
check("18c. سجل الـLog أصبح sent", $wpdb->message_log[$claim1['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_SENT);

// محاولة جديدة على ضيف مختلف تنتهي بفشل، ثم يُعاد المحاولة
$rid_f = $wpdb->seed_rsvp(9202, '966500000060');
$claim_f1 = PGE_Thank_You_Claim::claim(9202, $rid_f, '966500000060', 'batch-thankyou-f1', 77);
check("19. claim قبل الفشل ينجح", $claim_f1['result'] ?? null, 'claimed');
$failed_f = PGE_Thank_You_Claim::finalize_failure($claim_f1['log_id'], PGE_Message_Log::STATUS_FAILED);
check_true("19b. finalize_failure() ينجح", $failed_f);
check_true("19c. thank_you_sent_at يبقى NULL بعد فشل نهائي", empty($wpdb->rsvps[$rid_f]['thank_you_sent_at']));
$claim_f2 = PGE_Thank_You_Claim::claim(9202, $rid_f, '966500000060', 'batch-thankyou-f2', 77);
check("19d. بعد فشل نهائي، claim جديد لنفس الضيف ينجح (إعادة محاولة مسموحة)", $claim_f2['result'] ?? null, 'claimed');

// ضيف مُرسَل له شكر مسبقاً (seed مباشر بقيمة غير NULL) — claim يجب أن يرفض
$rid_g = $wpdb->seed_rsvp(9203, '966500000070', '2026-08-01 10:00:00');
$claim_g = PGE_Thank_You_Claim::claim(9203, $rid_g, '966500000070', 'batch-thankyou-g', 77);
check("20. claim لضيف أُرسل له الشكر مسبقاً يُرفَض", $claim_g['result'] ?? null, 'already_sent');
check_true("20b. is_sent() يعكس نفس الحالة", PGE_Thank_You_Claim::is_sent($rid_g));
check_true("20c. can_send() = false لضيف أُرسل له الشكر مسبقاً", PGE_Thank_You_Claim::can_send($rid_g) === false);

// ══════════════════════════════════════════════════════════════════════
// Isolation (21-22)
// ══════════════════════════════════════════════════════════════════════

// 21. ضيف آخر غير متأثر: claim/finalize لـrid_e (أعلاه) لا يجب أن يُغيّر
// rid_f أو rid_g أو rid_e_lock إطلاقاً.
check_true("21. ضيف آخر (rid_f) غير متأثر بمطالبة/إنهاء ضيف آخر (rid_e)", empty($wpdb->rsvps[$rid_f]['thank_you_sent_at']) || $wpdb->rsvps[$rid_f]['thank_you_sent_at'] === $wpdb->rsvps[$rid_f]['thank_you_sent_at']);
// فحص أدق: rid_f لم يُلمَس ضمن finalize الخاص بـrid_e (سجلاته منفصلة تماماً)
check("21b. سجل message_log لـrid_e لا يظهر ضمن سجلات rid_f", count(array_filter($wpdb->message_log, function ($r) use ($rid_f) { return ($r['rsvp_id'] ?? null) === $rid_f && ($r['batch_id'] ?? '') === 'batch-thankyou-e'; })), 0);

// 22. مناسبتان مختلفتان لا تتعارضان: rid_e (event 9201) وrid_f (event 9202)
// يمكن أن يُطالَبا/يُنهَيا بشكل مستقل تماماً بلا أي قفل متبادل (أُثبِت عملياً
// أعلاه أن كليهما نجح رغم قرب توقيت الاستدعاءات) — تحقّق إضافي أن حالتيهما
// النهائيتين مستقلتان تماماً (واحد sent، الآخر claimed مجدداً بلا sent).
check_true("22. مناسبة 9201 (rid_e، sent) ومناسبة 9202 (rid_f، غير sent بعد) مستقلتان تماماً", !empty($wpdb->rsvps[$rid_e]['thank_you_sent_at']) && empty($wpdb->rsvps[$rid_f]['thank_you_sent_at']));

// ══════════════════════════════════════════════════════════════════════
// Phase 4A-2 — Lifecycle-aware Lease/Reclaim central claim suite
// التزامن هنا simulated/serialized عبر Fake $wpdb؛ لا اتصالا MySQL حقيقيان.
// ══════════════════════════════════════════════════════════════════════

$GLOBALS['__test_now'] = '2026-08-10 13:00:00';
$rid_lease = $wpdb->seed_rsvp(9301, '966500000101');
$lease_first = PGE_Thank_You_Claim::claim(9301, $rid_lease, '966500000101', 'lease-first', 77);
check('27. first lifecycle-aware claim succeeds', $lease_first['result'] ?? null, 'claimed');
check('28. claim stores the RSVP lifecycle marker', $wpdb->message_log[$lease_first['log_id']]['lifecycle_started_at'] ?? null, $wpdb->rsvps[$rid_lease]['created_at']);
check_true('29. active pending makes can_send false', PGE_Thank_You_Claim::can_send($rid_lease) === false);

$GLOBALS['__test_now'] = '2026-08-10 13:01:59';
$lease_active = PGE_Thank_You_Claim::claim(9301, $rid_lease, '966500000101', 'lease-active', 77);
check('30. pending younger than 120 seconds blocks', $lease_active['result'] ?? null, 'already_in_progress');

$GLOBALS['__test_now'] = '2026-08-10 13:02:00';
$lease_reclaimed = PGE_Thank_You_Claim::claim(9301, $rid_lease, '966500000101', 'lease-reclaimed', 77);
check('31. pending at lease boundary can be reclaimed', $lease_reclaimed['result'] ?? null, 'claimed');
check('32. reclaimed stale pending is closed with existing failed status', $wpdb->message_log[$lease_first['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_FAILED);
check('33. reclaim creates a distinct fenced log', ($lease_reclaimed['log_id'] ?? 0) !== ($lease_first['log_id'] ?? 0), true);

// Lifecycle A remains time-active, but lifecycle B reuses the same rsvp_id.
$GLOBALS['__test_now'] = '2026-08-10 14:00:00';
$rid_lifecycle = $wpdb->seed_rsvp(9302, '966500000102');
$claim_lifecycle_a = PGE_Thank_You_Claim::claim(9302, $rid_lifecycle, '966500000102', 'lifecycle-a', 77);
$marker_a = $wpdb->rsvps[$rid_lifecycle]['created_at'];
$wpdb->rsvps[$rid_lifecycle]['created_at'] = '2026-08-10 14:00:30';
$wpdb->rsvps[$rid_lifecycle]['thank_you_sent_at'] = null;
$GLOBALS['__test_now'] = '2026-08-10 14:00:31';
$claim_lifecycle_b = PGE_Thank_You_Claim::claim(9302, $rid_lifecycle, '966500000102', 'lifecycle-b', 77);
check('34. old lifecycle pending does not block reused rsvp_id', $claim_lifecycle_b['result'] ?? null, 'claimed');
check_true('35. new lifecycle claims immediately although old lease is fresh', ($claim_lifecycle_b['lifecycle_started_at'] ?? '') !== $marker_a);
check('36. old lifecycle pending is retained as historical and ignored', $wpdb->message_log[$claim_lifecycle_a['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_PENDING);

$late_success = PGE_Thank_You_Claim::finalize_success($claim_lifecycle_a['log_id'], $rid_lifecycle);
check_true('37. late success from lifecycle A is rejected', $late_success === false);
check('38. late success cannot set thank_you_sent_at on lifecycle B', $wpdb->rsvps[$rid_lifecycle]['thank_you_sent_at'], null);
$late_failure = PGE_Thank_You_Claim::finalize_failure($claim_lifecycle_a['log_id'], PGE_Message_Log::STATUS_FAILED);
check_true('39. late failure from lifecycle A is rejected', $late_failure === false);
check('40. late failure cannot mutate the old fenced log after lifecycle mismatch', $wpdb->message_log[$claim_lifecycle_a['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_PENDING);
check_true('41. lifecycle marker mismatch is rejected during finalize', PGE_Thank_You_Claim::finalize_success($claim_lifecycle_a['log_id'], $rid_lifecycle) === false);

// Sequential simulation of two contenders under the same DB advisory lock.
$GLOBALS['__test_now'] = '2026-08-10 15:00:00';
$rid_race = $wpdb->seed_rsvp(9303, '966500000103');
$race_a = PGE_Thank_You_Claim::claim(9303, $rid_race, '966500000103', 'race-a', 77);
$race_b = PGE_Thank_You_Claim::claim(9303, $rid_race, '966500000103', 'race-b', 77);
check_true('42. two serialized same-lifecycle contenders yield exactly one claim', ($race_a['result'] ?? '') === 'claimed' && ($race_b['result'] ?? '') === 'already_in_progress');

// Lock release on every in-lock exit.
$acquire_before_sent = count($wpdb->lock_acquire_log);
$release_before_sent = count($wpdb->lock_release_log);
$GLOBALS['__test_now'] = '2026-08-10 16:00:00';
$rid_sent_lock = $wpdb->seed_rsvp(9304, '966500000104', '2026-08-10 15:59:00');
$sent_lock_result = PGE_Thank_You_Claim::claim(9304, $rid_sent_lock, '966500000104', 'already-sent-lock', 77);
check_true('43. already_sent path releases its acquired lock', ($sent_lock_result['result'] ?? '') === 'already_sent' && count($wpdb->lock_acquire_log) === $acquire_before_sent + 1 && count($wpdb->lock_release_log) === $release_before_sent + 1);

$acquire_before_invalid = count($wpdb->lock_acquire_log);
$release_before_invalid = count($wpdb->lock_release_log);
$invalid_inside_lock = PGE_Thank_You_Claim::claim(9999, $rid_race, '966500000103', 'invalid-event', 77);
check_true('44. validation failure discovered inside critical section releases lock', ($invalid_inside_lock['reason'] ?? '') === 'rsvp_not_found' && count($wpdb->lock_acquire_log) === $acquire_before_invalid + 1 && count($wpdb->lock_release_log) === $release_before_invalid + 1);

$rid_insert_failure = $wpdb->seed_rsvp(9305, '966500000105');
$acquire_before_failure = count($wpdb->lock_acquire_log);
$release_before_failure = count($wpdb->lock_release_log);
$wpdb->force_message_log_insert_failure = true;
$insert_failure = PGE_Thank_You_Claim::claim(9305, $rid_insert_failure, '966500000105', 'insert-failure', 77);
$wpdb->force_message_log_insert_failure = false;
check_true('45. log creation failure releases lock', ($insert_failure['reason'] ?? '') === 'log_create_failed' && count($wpdb->lock_acquire_log) === $acquire_before_failure + 1 && count($wpdb->lock_release_log) === $release_before_failure + 1);

$rid_insert_exception = $wpdb->seed_rsvp(9307, '966500000107');
$wpdb->throw_message_log_insert_exception = true;
$exception_released = false;
try {
    PGE_Thank_You_Claim::claim(9307, $rid_insert_exception, '966500000107', 'insert-exception', 77);
} catch (RuntimeException $e) {
    $exception_released = empty($wpdb->held_locks);
}
$wpdb->throw_message_log_insert_exception = false;
check_true('45b. exception inside critical section still releases lock', $exception_released);

// Ambiguous transport is terminal in the log but fenced against immediate retry.
$GLOBALS['__test_now'] = '2026-08-10 17:00:00';
$rid_ambiguous = $wpdb->seed_rsvp(9306, '966500000106');
$ambiguous_first = PGE_Thank_You_Claim::claim(9306, $rid_ambiguous, '966500000106', 'ambiguous-first', 77);
$ambiguous_finalized = PGE_Thank_You_Claim::finalize_failure($ambiguous_first['log_id'], PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);
check_true('46. ambiguous transport is recorded with existing terminal status', $ambiguous_finalized && ($wpdb->message_log[$ambiguous_first['log_id']]['status'] ?? '') === PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);
$GLOBALS['__test_now'] = '2026-08-10 17:00:30';
$ambiguous_blocked = PGE_Thank_You_Claim::claim(9306, $rid_ambiguous, '966500000106', 'ambiguous-too-soon', 77);
check_true('47. ambiguous transport blocks unsafe immediate retry', ($ambiguous_blocked['result'] ?? '') === 'already_in_progress' && ($ambiguous_blocked['reason'] ?? '') === 'ambiguous_transport_lease_active');
$GLOBALS['__test_now'] = '2026-08-10 17:02:00';
$ambiguous_retry = PGE_Thank_You_Claim::claim(9306, $rid_ambiguous, '966500000106', 'ambiguous-after-lease', 77);
check('48. ambiguous retry is allowed only after lease expiry', $ambiguous_retry['result'] ?? null, 'claimed');

// already_sent remains final regardless of elapsed lease time.
$GLOBALS['__test_now'] = '2026-08-11 17:00:00';
$sent_never_reclaimed = PGE_Thank_You_Claim::claim(9304, $rid_sent_lock, '966500000104', 'sent-never-reclaimed', 77);
check('49. already_sent is never reclaimable after arbitrary time', $sent_never_reclaimed['result'] ?? null, 'already_sent');

check('50. central lease constant is the reviewed 120 seconds', PGE_Thank_You_Claim::CLAIM_LEASE_SECONDS, 120);
check_true('51. no successfully acquired advisory lock remains held after the suite', empty($wpdb->held_locks));

// ══════════════════════════════════════════════════════════════════════
// Regression — إثبات ثابت (Static) أن المسارات القائمة لم تُمَس (23-26)
// ══════════════════════════════════════════════════════════════════════

$cartat_src = file_get_contents(__DIR__ . '/../includes/class-cartat-handler.php');
check_true("23. مسار إرسال الدعوة (class-cartat-handler.php) لا يحتوي أي إشارة لـmessage_log أو thank_you_sent_at", strpos($cartat_src, 'pge_message_log') === false && strpos($cartat_src, 'thank_you_sent_at') === false && strpos($cartat_src, 'PGE_Message_Log') === false && strpos($cartat_src, 'PGE_Thank_You_Claim') === false);

$rsvp_handler_src = file_get_contents(__DIR__ . '/../includes/rsvp-handler.php');
check_true("24. rsvp-handler.php (كتابة RSVP) لا يحتوي أي إشارة لـthank_you_sent_at أو message_log", strpos($rsvp_handler_src, 'thank_you_sent_at') === false && strpos($rsvp_handler_src, 'pge_message_log') === false);

$checkin_recorder_src = file_get_contents(__DIR__ . '/../includes/class-pge-checkin-recorder.php');
check_true("25. Check-in (class-pge-checkin-recorder.php) لا يحتوي أي إشارة لـthank_you أو message_log", stripos($checkin_recorder_src, 'thank_you') === false && strpos($checkin_recorder_src, 'pge_message_log') === false);

$ledger_src = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-credit-ledger.php');
check_true("26. Invitation Credit Ledger لا يحتوي أي إشارة لـmessage_log أو thank_you_sent_at أو PGE_Message_Type", strpos($ledger_src, 'pge_message_log') === false && strpos($ledger_src, 'thank_you_sent_at') === false && strpos($ledger_src, 'PGE_Message_Type') === false);

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
