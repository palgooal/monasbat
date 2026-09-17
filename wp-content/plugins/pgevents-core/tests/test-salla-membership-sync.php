<?php
/** Standalone durable Salla membership synchronization tests. No real DB/HTTP. */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code=$code; $this->message=$message; $this->data=$data; }
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
    public function get_error_data(){ return $this->data; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function add_action(...$args){}
$GLOBALS['sync_now'] = 2000000000;
function current_time($type='mysql',$gmt=false){ return ($type==='timestamp'||$type==='U') ? $GLOBALS['sync_now'] : gmdate('Y-m-d H:i:s',$GLOBALS['sync_now']); }
$GLOBALS['sync_cron'] = [];
function wp_next_scheduled($hook){ return $GLOBALS['sync_cron'][$hook] ?? false; }
function wp_schedule_single_event($timestamp,$hook,$args=[]){ $GLOBALS['sync_cron'][$hook]=$timestamp; return true; }

class Fake_Wpdb_Salla_Sync {
    public $prefix='wp_'; public $rows=[]; public $insert_id=0; public $locks=[];
    public function prepare($query,...$args){
        $i=0; return preg_replace_callback('/%[ds]/',function($m)use(&$i,$args){$v=$args[$i++]??''; return $m[0]==='%d'?(string)(int)$v:"'".addslashes((string)$v)."'";},$query);
    }
    public function get_var($sql){
        if(preg_match("/GET_LOCK\\('([^']+)'/",$sql,$m)){ if(isset($this->locks[$m[1]]))return '0'; $this->locks[$m[1]]=true; return '1'; }
        if(strpos($sql,'MIN(next_attempt_at)')!==false){$values=[];foreach($this->rows as $r)if(in_array($r['status'],['pending','retry','ambiguous'],true)&&!empty($r['next_attempt_at']))$values[]=$r['next_attempt_at'];return $values?min($values):null;}
        if(strpos($sql,'MIN(attempt_started_at)')!==false){$values=[];foreach($this->rows as $r)if($r['status']==='processing'&&!empty($r['attempt_started_at']))$values[]=$r['attempt_started_at'];return $values?min($values):null;}
        return null;
    }
    public function query($sql){ if(preg_match("/RELEASE_LOCK\\('([^']+)'/",$sql,$m)){unset($this->locks[$m[1]]);return 1;} return false; }
    public function insert($table,$data,$formats=[]){
        foreach($this->rows as $r) if($r['merchant_id']===$data['merchant_id']&&$r['salla_customer_id']===$data['salla_customer_id']&&$r['group_id']===$data['group_id']) return false;
        $this->insert_id++; $data['id']=$this->insert_id;
        $defaults=['attempt_token'=>null,'attempt_revision'=>null,'attempt_started_at'=>null,'last_attempt_at'=>null,'last_success_at'=>null,'last_error_code'=>null];
        $this->rows[$this->insert_id]=array_merge($defaults,$data); return 1;
    }
    public function update($table,$data,$where,$formats=null,$where_formats=null){
        foreach($this->rows as $id=>$row){ $match=true; foreach($where as $k=>$v) if(($row[$k]??null)!==$v){$match=false;break;} if(!$match)continue; $this->rows[$id]=array_merge($row,$data); return 1; } return 0;
    }
    public function get_row($sql,$format=ARRAY_A){
        if(preg_match('/WHERE id = (\d+)/',$sql,$m)) return $this->rows[(int)$m[1]]??null;
        if(preg_match('/merchant_id = (\d+) AND salla_customer_id = (\d+) AND group_id = (\d+)/',$sql,$m)){
            foreach($this->rows as $r) if($r['merchant_id']==(int)$m[1]&&$r['salla_customer_id']==(int)$m[2]&&$r['group_id']==(int)$m[3])return $r;
        } return null;
    }
    public function get_results($sql,$format=ARRAY_A){
        $now=$GLOBALS['sync_now']; $out=[];
        foreach($this->rows as $r){
            $due=in_array($r['status'],['pending','retry','ambiguous'],true)&& (empty($r['next_attempt_at'])||strtotime($r['next_attempt_at'].' UTC')<=$now);
            $expired=$r['status']==='processing'&&!empty($r['attempt_started_at'])&&strtotime($r['attempt_started_at'].' UTC')<=($now-300);
            if($due||$expired)$out[]=$r;
        }
        usort($out,fn($a,$b)=>$a['id']<=>$b['id']);
        if(preg_match('/LIMIT (\d+)/',$sql,$m))$out=array_slice($out,0,(int)$m[1]); return $out;
    }
}
$wpdb = new Fake_Wpdb_Salla_Sync();

class PGE_Salla_Sync_Schema { public static function table_name(){global $wpdb;return $wpdb->prefix.'pge_salla_membership_sync';} }

$GLOBALS['sync_add_result']=['success'=>true];
$GLOBALS['sync_membership_result']=['success'=>true,'is_member'=>false];
$GLOBALS['sync_add_calls']=0; $GLOBALS['sync_details_calls']=0;
class PGE_Salla_Customer_Groups_Service {
    public function add_customer_to_group($m,$c,$g){$GLOBALS['sync_add_calls']++;return $GLOBALS['sync_add_result'];}
    public function customer_is_in_group($m,$c,$g){$GLOBALS['sync_details_calls']++;return $GLOBALS['sync_membership_result'];}
}

require_once dirname(__DIR__).'/includes/class-pge-salla-membership-sync-store.php';
require_once dirname(__DIR__).'/includes/class-pge-salla-membership-sync-worker.php';

$total=0;$passed=0;
function check($label,$actual,$expected){global $total,$passed;$total++;if($actual===$expected){$passed++;echo "PASS  $label\n";}else echo "FAIL  $label (expected ".var_export($expected,true).', got '.var_export($actual,true).")\n";}
function check_true($label,$condition){check($label,(bool)$condition,true);}
function reset_sync(){global $wpdb;$wpdb=new Fake_Wpdb_Salla_Sync();$GLOBALS['sync_now']=2000000000;$GLOBALS['sync_cron']=[];$GLOBALS['sync_add_result']=['success'=>true];$GLOBALS['sync_membership_result']=['success'=>true,'is_member'=>false];$GLOBALS['sync_add_calls']=0;$GLOBALS['sync_details_calls']=0;}
function create_sync(){return PGE_Salla_Membership_Sync_Store::request_member(123,456,225189340);}
function only_row(){global $wpdb;return $wpdb->rows ? array_values($wpdb->rows)[0] : null;}
function run_error_case($status){reset_sync();create_sync();$GLOBALS['sync_add_result']=new WP_Error('salla_customer_group_http_error','safe',['http_status'=>$status]);PGE_Salla_Membership_Sync_Worker::run_batch();return only_row();}

reset_sync(); $created=create_sync();
check('first activation creates row',$created['result'],'created');
check('one durable row exists',count($wpdb->rows),1);
$row=only_row();
check('identity persisted',[$row['merchant_id'],$row['salla_customer_id'],$row['group_id']],[123,456,225189340]);
check('desired state is member',$row['desired_state'],'member');

$again=create_sync();
check('replay is idempotent',$again['result'],'pending');
check('replay creates no duplicate',count($wpdb->rows),1);

$claim1=PGE_Salla_Membership_Sync_Store::claim($row['id']);
check('due work is claimed',$claim1['result'],'claimed');
$claim_busy=PGE_Salla_Membership_Sync_Store::claim($row['id']);
check('active lease cannot be claimed',$claim_busy['result'],'in_progress');
$GLOBALS['sync_now']+=301;
$claim2=PGE_Salla_Membership_Sync_Store::claim($row['id']);
check('expired lease is reclaimed',$claim2['result'],'claimed');
check_true('reclaim creates new token',$claim2['attempt_token']!==$claim1['attempt_token']);
check('old token cannot finalize',PGE_Salla_Membership_Sync_Store::mark_satisfied($claim1),false);
check('new token finalizes',PGE_Salla_Membership_Sync_Store::mark_satisfied($claim2),true);

reset_sync();create_sync();$claim=PGE_Salla_Membership_Sync_Store::claim(1);
$wpdb->rows[1]['desired_state']='not_member';$wpdb->rows[1]['desired_revision']=2;$wpdb->rows[1]['attempt_token']=null;$wpdb->rows[1]['status']='pending';
check('older revision cannot overwrite newer desired state',PGE_Salla_Membership_Sync_Store::mark_satisfied($claim),false);
check('newer desired state remains authoritative',$wpdb->rows[1]['desired_state'],'not_member');

reset_sync();create_sync();PGE_Salla_Membership_Sync_Worker::run_batch();
check('successful API sync becomes satisfied',only_row()['status'],'satisfied');
check('successful worker calls add once',$GLOBALS['sync_add_calls'],1);

$row=run_error_case(429);check('HTTP 429 is retryable',$row['status'],'retry');check_true('429 has next attempt',!empty($row['next_attempt_at']));check_true('retry schedules next worker tick',isset($GLOBALS['sync_cron'][PGE_Salla_Membership_Sync_Worker::WORKER_HOOK]));
$row=run_error_case(500);check('HTTP 5xx is retryable',$row['status'],'retry');
foreach([400,401,403,422] as $status){$row=run_error_case($status);check("HTTP $status is not retried",$row['status'],'failed');}

reset_sync();create_sync();$GLOBALS['sync_add_result']=new WP_Error('salla_customer_group_transport_error','safe');$GLOBALS['sync_membership_result']=['success'=>true,'is_member'=>true];PGE_Salla_Membership_Sync_Worker::run_batch();
check('ambiguous transport reconciles accepted mutation',only_row()['status'],'satisfied');
check('transport reconciliation reads Customer Details',$GLOBALS['sync_details_calls'],1);

reset_sync();create_sync();$GLOBALS['sync_add_result']=new WP_Error('salla_customer_group_transport_error','safe');PGE_Salla_Membership_Sync_Worker::run_batch();
check('unconfirmed transport becomes ambiguous',only_row()['status'],'ambiguous');
$first_attempt=only_row()['attempt_count'];$GLOBALS['sync_now']=strtotime(only_row()['next_attempt_at'].' UTC');$GLOBALS['sync_add_result']=['success'=>true];PGE_Salla_Membership_Sync_Worker::run_batch();
check('ambiguous retry reconciles before POST',$GLOBALS['sync_details_calls'],2);
check('attempt count survives retry',only_row()['attempt_count'],$first_attempt+1);

check('backoff starts at 60 seconds',PGE_Salla_Membership_Sync_Worker::backoff_seconds(1),60);
check('backoff is bounded',PGE_Salla_Membership_Sync_Worker::backoff_seconds(999),3600);

reset_sync();create_sync();PGE_Salla_Membership_Sync_Store::claim(1);$GLOBALS['sync_now']+=301;
check('abandoned processing is discoverable',count(PGE_Salla_Membership_Sync_Store::find_due(10)),1);

reset_sync();create_sync();
check('first worker schedule succeeds',PGE_Salla_Membership_Sync_Worker::schedule_worker(1),true);
check('worker scheduling is deduplicated',PGE_Salla_Membership_Sync_Worker::schedule_worker(1),false);
$GLOBALS['sync_cron']=[];PGE_Salla_Membership_Sync_Worker::run_recovery();
check_true('recovery restores missing worker cron',isset($GLOBALS['sync_cron'][PGE_Salla_Membership_Sync_Worker::WORKER_HOOK]));
check_true('recovery watchdog reschedules itself',isset($GLOBALS['sync_cron'][PGE_Salla_Membership_Sync_Worker::RECOVERY_HOOK]));

$serialized=json_encode($wpdb->rows);
check_true('sync diagnostics persist no token fields',strpos($serialized,'access_token')===false&&strpos($serialized,'refresh_token')===false&&strpos($serialized,'client_secret')===false);

$schema_source=file_get_contents(dirname(__DIR__).'/includes/class-pge-salla-sync-schema.php');
$bootstrap_source=file_get_contents(dirname(__DIR__).'/pgevents-core.php');
check_true('schema declares unique membership identity',strpos($schema_source,'UNIQUE KEY membership_identity (merchant_id, salla_customer_id, group_id)')!==false);
check_true('schema declares due-work and lease indexes',strpos($schema_source,'KEY due_work (status, next_attempt_at)')!==false&&strpos($schema_source,'KEY processing_lease (status, attempt_started_at)')!==false);
$schema_pos=strpos($bootstrap_source,"require_once PGE_PATH . 'includes/class-pge-salla-sync-schema.php';");$service_pos=strpos($bootstrap_source,"require_once PGE_PATH . 'includes/class-pge-salla-customer-groups-service.php';");$store_pos=strpos($bootstrap_source,"require_once PGE_PATH . 'includes/class-pge-salla-membership-sync-store.php';");$worker_pos=strpos($bootstrap_source,"require_once PGE_PATH . 'includes/class-pge-salla-membership-sync-worker.php';");$handler_pos=strpos($bootstrap_source,"require_once PGE_PATH . 'includes/class-salla-handler.php';");
check_true('bootstrap loads sync dependencies before handler',$schema_pos!==false&&$schema_pos<$service_pos&&$service_pos<$store_pos&&$store_pos<$worker_pos&&$worker_pos<$handler_pos);

echo "\n============================================\nTotal: $total | Passed: $passed | Failed: ".($total-$passed)."\n";
exit($total===$passed?0:1);
