<?php
/** RED/GREEN hardening coverage for Phase 1 review findings. */

$upgrade_root = sys_get_temp_dir() . '/pge-phase1-hardening-' . getmypid() . '/';
@mkdir($upgrade_root . 'wp-admin/includes', 0777, true);
file_put_contents($upgrade_root . 'wp-admin/includes/upgrade.php', "<?php\n");
define('ABSPATH', $upgrade_root);
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

$test_options = [];
function register_activation_hook(...$args) {}
function add_action(...$args) {}
function current_time($type, $gmt = false) { return $type === 'timestamp' ? 1790164800 : '2026-09-23 12:00:00'; }
function get_option($key, $default = false) { global $test_options; return $test_options[$key] ?? $default; }
function update_option($key, $value, $autoload = null) { global $test_options; $test_options[$key] = $value; return true; }
function dbDelta($sql) {}

final class PGE_Hardening_WPDB
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows = [];
    public $insert_failure = null;
    public $update_failure = null;
    public $column_drift = null;
    public $index_drift = null;

    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }

    public function insert($table, array $data, array $formats = [])
    {
        if ($this->insert_failure !== null) { $this->last_error = $this->insert_failure; return false; }
        foreach ($this->rows[$table] ?? [] as $row) {
            $duplicate = ($table === 'wp_pge_catalog_activations' && ($row['activation_id'] === $data['activation_id'] || $row['idempotency_key'] === $data['idempotency_key']))
                || ($table === 'wp_pge_catalog_activation_events' && (int) $row['event_id'] === (int) $data['event_id'])
                || ($table === 'wp_pge_catalog_activation_origins' && (($row['provider'] === $data['provider'] && (int) $row['merchant_id'] === (int) $data['merchant_id'] && $row['external_order_id'] === $data['external_order_id']) || ((int) $row['catalog_activation_id'] === (int) $data['catalog_activation_id'] && $row['provider'] === $data['provider'])));
            if ($duplicate) { $this->last_error = 'duplicate'; return false; }
        }
        $this->last_error = '';
        $data['id'] = ++$this->insert_id;
        $this->rows[$table][] = $data;
        return 1;
    }

    public function update($table, array $data, array $where, array $formats = [], array $where_formats = [])
    {
        if ($this->update_failure !== null) { $this->last_error = $this->update_failure; return false; }
        $this->last_error = '';
        $updated = 0;
        if (!isset($this->rows[$table])) return 0;
        foreach ($this->rows[$table] as &$row) {
            foreach ($where as $key => $value) if ((string) ($row[$key] ?? null) !== (string) $value) continue 2;
            $row = array_merge($row, $data); $updated++;
        }
        unset($row);
        return $updated;
    }

    public function prepare($sql, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%[ds]/', static function ($m) use (&$i, $args) {
            $v = $args[$i++]; return $m[0] === '%d' ? (string) (int) $v : "'" . str_replace("'", "''", (string) $v) . "'";
        }, $sql);
    }

    public function get_row($sql, $format = ARRAY_A)
    {
        foreach ($this->rows as $table => $rows) {
            if (strpos($sql, 'FROM ' . $table) === false) continue;
            foreach ($rows as $row) if ($this->matches($sql, $row)) return $row;
        }
        return null;
    }

    public function get_results($sql, $format = ARRAY_A)
    {
        if (preg_match('/SHOW COLUMNS FROM ([a-z0-9_]+)/i', $sql, $m)) return $this->columns($m[1]);
        if (preg_match('/SHOW INDEX FROM ([a-z0-9_]+)/i', $sql, $m)) return $this->indexes($m[1]);
        return [];
    }

    private function matches($sql, array $row)
    {
        preg_match_all("/([a-z_]+) = (?:'((?:''|[^'])*)'|([0-9]+))/", $sql, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $value = $m[2] !== '' ? str_replace("''", "'", $m[2]) : $m[3];
            if ((string) ($row[$m[1]] ?? null) !== (string) $value) return false;
        }
        return true;
    }

    private function columns($table)
    {
        $contracts = [
            'wp_pge_catalog_activations' => [
                ['id','bigint(20) unsigned','NO',null,'auto_increment'], ['activation_id','varchar(64)','NO',null,''], ['user_id','bigint(20) unsigned','NO',null,''], ['plan_id','bigint(20) unsigned','NO',null,''], ['tier_id','bigint(20) unsigned','NO',null,''], ['plan_key','varchar(100)','NO',null,''], ['tier_key','varchar(100)','NO',null,''], ['activation_source','varchar(20)','NO',null,''], ['idempotency_key','varchar(191)','NO',null,''], ['external_order_id','varchar(191)','YES',null,''], ['projection_snapshot','longtext','NO',null,''], ['lifecycle_state','varchar(32)','NO',null,''], ['revision','bigint(20) unsigned','NO','1',''], ['projection_attempts','bigint(20) unsigned','NO','0',''], ['last_error_code','varchar(100)','YES',null,''], ['next_reconcile_at','datetime','YES',null,''], ['activated_at','datetime','YES',null,''], ['ended_at','datetime','YES',null,''], ['revoked_at','datetime','YES',null,''], ['last_reconciled_at','datetime','YES',null,''], ['created_at','datetime','NO',null,''], ['updated_at','datetime','NO',null,''],
            ],
            'wp_pge_catalog_activation_events' => [
                ['id','bigint(20) unsigned','NO',null,'auto_increment'], ['catalog_activation_id','bigint(20) unsigned','NO',null,''], ['event_id','bigint(20) unsigned','NO',null,''], ['bound_at','datetime','NO',null,''], ['created_at','datetime','NO',null,''], ['updated_at','datetime','NO',null,''],
            ],
            'wp_pge_catalog_activation_origins' => [
                ['id','bigint(20) unsigned','NO',null,'auto_increment'], ['catalog_activation_id','bigint(20) unsigned','NO',null,''], ['provider','varchar(32)','NO',null,''], ['merchant_id','bigint(20) unsigned','NO',null,''], ['external_customer_id','varchar(191)','NO',null,''], ['external_order_id','varchar(191)','NO',null,''], ['created_at','datetime','NO',null,''],
            ],
            'wp_pge_catalog_order_revocations' => [
                ['id','bigint(20) unsigned','NO',null,'auto_increment'], ['provider','varchar(32)','NO',null,''], ['merchant_id','bigint(20) unsigned','NO',null,''], ['external_order_id','varchar(191)','NO',null,''], ['reason','varchar(64)','NO',null,''], ['created_at','datetime','NO',null,''],
            ],
        ];
        $rows = [];
        foreach ($contracts[$table] ?? [] as [$field,$type,$null,$default,$extra]) $rows[] = ['Field'=>$field,'Type'=>$type,'Null'=>$null,'Default'=>$default,'Extra'=>$extra];
        if ($table === 'wp_pge_catalog_activations' && $this->column_drift) {
            foreach ($rows as &$row) if ($row['Field'] === $this->column_drift[0]) $row[$this->column_drift[1]] = $this->column_drift[2];
        }
        return $rows;
    }

    private function indexes($table)
    {
        $definitions = [
            'wp_pge_catalog_activations' => ['PRIMARY'=>[0,['id']], 'activation_id'=>[0,['activation_id']], 'idempotency_key'=>[0,['idempotency_key']], 'user_lifecycle'=>[1,['user_id','lifecycle_state']], 'plus_lifecycle'=>[1,['plan_key','lifecycle_state']], 'due_reconciliation'=>[1,['lifecycle_state','next_reconcile_at']]],
            'wp_pge_catalog_activation_events' => ['PRIMARY'=>[0,['id']], 'event_id'=>[0,['event_id']], 'catalog_activation_id'=>[1,['catalog_activation_id']]],
            'wp_pge_catalog_activation_origins' => ['PRIMARY'=>[0,['id']], 'provider_order'=>[0,['provider','merchant_id','external_order_id']], 'activation_provider'=>[0,['catalog_activation_id','provider']], 'provider_customer'=>[1,['provider','merchant_id','external_customer_id']]],
            'wp_pge_catalog_order_revocations' => ['PRIMARY'=>[0,['id']], 'provider_order'=>[0,['provider','merchant_id','external_order_id']]],
        ];
        if ($table === 'wp_pge_catalog_activations' && $this->index_drift === 'PRIMARY_WRONG') {
            $definitions[$table]['PRIMARY'] = [0, ['activation_id']];
        } elseif ($table === 'wp_pge_catalog_activations' && $this->index_drift) {
            unset($definitions[$table][$this->index_drift]);
        }
        $rows = [];
        foreach ($definitions[$table] ?? [] as $name => [$non_unique,$columns]) foreach ($columns as $i => $column) $rows[] = ['Key_name'=>$name,'Seq_in_index'=>$i+1,'Column_name'=>$column,'Non_unique'=>$non_unique];
        return $rows;
    }
}

require_once PGE_PATH . 'includes/class-pge-catalog-activation-schema.php';
require_once PGE_PATH . 'includes/class-pge-catalog-activation-repository.php';
require_once PGE_PATH . 'includes/class-pge-catalog-event-binding-repository.php';
require_once PGE_PATH . 'includes/class-pge-catalog-provider-origin-repository.php';

$passed=0; $total=0; $failures=[];
function hard_check($label,$actual,$expected) { global $passed,$total,$failures; $total++; if ($actual===$expected) {$passed++; echo "PASS: $label\n";} else {$failures[]=$label; echo "FAIL: $label expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . "\n";} }
function contract_holds() { $m=new ReflectionMethod(PGE_Catalog_Activation_Schema::class,'postconditions_hold'); $m->setAccessible(true); return $m->invoke(null); }
function seed_activation($id=1,$state='preparing',$revision=1) { return ['id'=>$id,'activation_id'=>'hardening-'.$id,'user_id'=>1,'plan_id'=>1,'tier_id'=>1,'plan_key'=>'halwa_plus','tier_key'=>'x','activation_source'=>'manual','idempotency_key'=>'manual:hardening-'.$id,'external_order_id'=>null,'projection_snapshot'=>'{}','lifecycle_state'=>$state,'revision'=>$revision,'projection_attempts'=>0,'last_error_code'=>null,'next_reconcile_at'=>null,'activated_at'=>null,'ended_at'=>null,'revoked_at'=>null,'last_reconciled_at'=>null,'created_at'=>'2026-09-23 12:00:00','updated_at'=>'2026-09-23 12:00:00']; }

$wpdb = new PGE_Hardening_WPDB();
hard_check('baseline schema contract', contract_holds(), true);
foreach ([['revision','Type','int(11)'],['external_order_id','Null','NO'],['revision','Default','2'],['id','Extra','']] as $case) {
    $wpdb->column_drift=$case; hard_check('schema drift rejected: '.implode('/',$case), contract_holds(), false); $wpdb->column_drift=null;
}
$wpdb->index_drift='PRIMARY'; hard_check('missing primary rejected',contract_holds(),false); $wpdb->index_drift=null;
$wpdb->index_drift='PRIMARY_WRONG'; hard_check('wrong primary rejected',contract_holds(),false); $wpdb->index_drift=null;
$test_options=[]; $wpdb->column_drift=['revision','Type','int(11)'];
hard_check('installer fails drifted contract',PGE_Catalog_Activation_Schema::maybe_upgrade(),false);
hard_check('version not installed on drift',isset($test_options[PGE_Catalog_Activation_Schema::VERSION_OPTION]),false);
$wpdb->column_drift=null;

$wpdb->rows['wp_pge_catalog_activations']=[seed_activation()];
hard_check('CAS updated result',PGE_Catalog_Activation_Repository::compare_and_swap_state(1,'preparing',1,'active_unbound')['result'] ?? null,'updated');
hard_check('CAS stale result',PGE_Catalog_Activation_Repository::compare_and_swap_state(1,'preparing',1,'active_bound')['result'] ?? null,'stale');
hard_check('CAS missing result',PGE_Catalog_Activation_Repository::compare_and_swap_state(999,'preparing',1,'active_bound')['result'] ?? null,'missing');
$wpdb->rows['wp_pge_catalog_activations'][]=seed_activation(2,'revoked',2);
hard_check('CAS terminal result',PGE_Catalog_Activation_Repository::compare_and_swap_state(2,'revoked',2,'active_unbound')['result'] ?? null,'terminal');
hard_check('CAS invalid result',PGE_Catalog_Activation_Repository::compare_and_swap_state(0,'preparing',1,'active_unbound')['result'] ?? null,'invalid');
$wpdb->update_failure='database unavailable';
hard_check('CAS db error result',PGE_Catalog_Activation_Repository::compare_and_swap_state(1,'active_unbound',2,'active_bound')['result'] ?? null,'db_error');
$wpdb->update_failure=null;

$wpdb = new PGE_Hardening_WPDB();
$projection_row = seed_activation(10, 'active_unbound', 1);
$projection_row['projection_snapshot'] = '{"exact":"  retained  "}';
$projection_row['activated_at'] = '2026-09-20 10:00:00';
$projection_row['ended_at'] = null;
$projection_row['revoked_at'] = null;
$wpdb->rows['wp_pge_catalog_activations'] = [$projection_row];
$recorded = PGE_Catalog_Activation_Repository::record_projection_failure(10, 1, 'projection_failed', '2026-09-24 12:00:00');
hard_check('projection result recorded', $recorded['result'] ?? null, 'recorded');
$projection_after = PGE_Catalog_Activation_Repository::find_by_id(10);
hard_check('projection attempts increment exactly once', (int) ($projection_after['projection_attempts'] ?? -1), 1);
hard_check('projection revision increment exactly once', (int) ($projection_after['revision'] ?? -1), 2);
hard_check('projection lifecycle unchanged', $projection_after['lifecycle_state'] ?? null, 'active_unbound');
hard_check('projection snapshot unchanged', $projection_after['projection_snapshot'] ?? null, '{"exact":"  retained  "}');
hard_check('projection activated_at unchanged', $projection_after['activated_at'] ?? null, '2026-09-20 10:00:00');
hard_check('projection ended_at unchanged', $projection_after['ended_at'] ?? null, null);
hard_check('projection revoked_at unchanged', $projection_after['revoked_at'] ?? null, null);
$stale_projection = PGE_Catalog_Activation_Repository::record_projection_failure(10, 1, 'again', '2026-09-25 12:00:00');
hard_check('projection stale result', $stale_projection['result'] ?? null, 'stale');
$projection_after_stale = PGE_Catalog_Activation_Repository::find_by_id(10);
hard_check('stale projection does not increment attempts', (int) ($projection_after_stale['projection_attempts'] ?? -1), 1);
hard_check('stale projection does not increment revision', (int) ($projection_after_stale['revision'] ?? -1), 2);
hard_check('projection missing result', PGE_Catalog_Activation_Repository::record_projection_failure(999, 1, 'missing', null)['result'] ?? null, 'missing');
$wpdb->rows['wp_pge_catalog_activations'][] = seed_activation(11, 'ended', 2);
$wpdb->rows['wp_pge_catalog_activations'][] = seed_activation(12, 'revoked', 3);
hard_check('projection ended terminal', PGE_Catalog_Activation_Repository::record_projection_failure(11, 2, 'ended', null)['result'] ?? null, 'terminal');
hard_check('projection revoked terminal', PGE_Catalog_Activation_Repository::record_projection_failure(12, 3, 'revoked', null)['result'] ?? null, 'terminal');
hard_check('projection invalid result', PGE_Catalog_Activation_Repository::record_projection_failure(0, 1, 'invalid', null)['result'] ?? null, 'invalid');
$wpdb->update_failure = 'database unavailable';
hard_check('projection DB error result', PGE_Catalog_Activation_Repository::record_projection_failure(10, 2, 'db', null)['result'] ?? null, 'db_error');
$wpdb->update_failure = null;

$wpdb->insert_failure='database unavailable';
$create=PGE_Catalog_Activation_Repository::create_preparing(['activation_id'=>'new','user_id'=>1,'plan_id'=>1,'tier_id'=>1,'plan_key'=>'x','tier_key'=>'y','activation_source'=>'manual','idempotency_key'=>'manual:new','projection_snapshot'=>'{}']);
hard_check('activation DB failure is not conflict',$create['result'] ?? null,'db_error');
hard_check('event DB failure is not conflict',PGE_Catalog_Event_Binding_Repository::create(1,99)['result'] ?? null,'db_error');
hard_check('origin DB failure is not conflict',PGE_Catalog_Provider_Origin_Repository::create(1,'salla',1,'customer','order')['result'] ?? null,'db_error');

@unlink($upgrade_root . 'wp-admin/includes/upgrade.php'); @rmdir($upgrade_root . 'wp-admin/includes'); @rmdir($upgrade_root . 'wp-admin'); @rmdir($upgrade_root);
echo "RESULT: $passed/$total PASS\n";
exit($failures ? 1 : 0);
