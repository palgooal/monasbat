<?php
/** Isolated contract tests for the hard-scoped DEC-PLUS-MIG-01 tool. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['fixture'] = [];
$GLOBALS['http_calls'] = 0;
$GLOBALS['meta_writes'] = 0;
$GLOBALS['membership_calls'] = 0;
$GLOBALS['activation_writes'] = 0;
$GLOBALS['origin_writes'] = 0;
$GLOBALS['event_creations'] = 0;
$GLOBALS['email_calls'] = 0;

class FakeBackfillWpdb
{
    public $last_error = '';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    private $snapshot;
    public function prepare($query, ...$args) { return $query . '|' . implode('|', array_map('strval', $args)); }
    public function get_var($query) {
        if (strpos($query, 'GET_LOCK') !== false) return empty($GLOBALS['fixture']['lock_fail']) ? 1 : 0;
        if (strpos($query, 'wp_posts') !== false) return $GLOBALS['fixture']['event_id'] ?? null;
        return null;
    }
    public function query($sql) {
        if ($sql === 'START TRANSACTION') {
            if (!empty($GLOBALS['fixture']['start_fail'])) return false;
            $this->snapshot = serialize([
                $GLOBALS['fixture']['activations'], $GLOBALS['fixture']['origins'],
            ]);
            return 0;
        }
        if ($sql === 'COMMIT') {
            if (!empty($GLOBALS['fixture']['commit_fail'])) return false;
            $this->snapshot = null;
            return 0;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->snapshot !== null) {
                [$GLOBALS['fixture']['activations'], $GLOBALS['fixture']['origins']] = unserialize($this->snapshot);
                $this->snapshot = null;
            }
            return 0;
        }
        return 0;
    }
}
$GLOBALS['wpdb'] = new FakeBackfillWpdb();

function get_user_by($field, $id) { return empty($GLOBALS['fixture']['user_missing']) && (int) $id === 380 ? (object) ['ID' => 380] : false; }
function get_user_meta($user_id, $key, $single = false) { return $GLOBALS['fixture']['meta'][$key] ?? ''; }
function update_user_meta() { $GLOBALS['meta_writes']++; return true; }
function delete_user_meta() { $GLOBALS['meta_writes']++; return true; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function absint($value) { return abs((int) $value); }

final class PGE_Catalog_Activation_Repository
{
    const STATE_PREPARING='preparing', STATE_ACTIVE_UNBOUND='active_unbound';
    public static function find_by_activation_id($id) { foreach ($GLOBALS['fixture']['activations'] as $r) if ($r['activation_id'] === $id) return $r; return null; }
    public static function find_by_idempotency_key($key) { foreach ($GLOBALS['fixture']['activations'] as $r) if ($r['idempotency_key'] === $key) return $r; return null; }
    public static function create_preparing(array $a) {
        $id = 501;
        $GLOBALS['activation_writes']++;
        $GLOBALS['fixture']['activations'][$id] = array_merge($a, [
            'id'=>$id,'lifecycle_state'=>'preparing','revision'=>1,'projection_attempts'=>0,
            'activated_at'=>null,'ended_at'=>null,'revoked_at'=>null,'last_error_code'=>null,
            'next_reconcile_at'=>null,'last_reconciled_at'=>null,'created_at'=>'2026-09-30 00:00:00','updated_at'=>'2026-09-30 00:00:00',
        ]);
        return ['result'=>'created','id'=>$id];
    }
    public static function compare_and_swap_state($id,$expected,$revision,$new,array $meta=[]) {
        if (!empty($GLOBALS['fixture']['cas_fail'])) return ['result'=>'stale'];
        $GLOBALS['activation_writes']++;
        $GLOBALS['fixture']['activations'][$id]['lifecycle_state']=$new;
        $GLOBALS['fixture']['activations'][$id]['revision']=$revision+1;
        $GLOBALS['fixture']['activations'][$id]['activated_at']=$meta['activated_at']??null;
        return ['result'=>'updated','revision'=>$revision+1];
    }
}
final class PGE_Catalog_Provider_Origin_Repository
{
    public static function find_by_order($p,$m,$o) { foreach ($GLOBALS['fixture']['origins'] as $r) if ($r['provider']===$p&&(int)$r['merchant_id']===(int)$m&&$r['external_order_id']===$o) return $r; return null; }
    public static function create($id,$p,$m,$c,$o) {
        if (!empty($GLOBALS['fixture']['origin_fail'])) return ['result'=>'db_error'];
        $GLOBALS['origin_writes']++;
        $GLOBALS['fixture']['origins'][]=['id'=>601,'catalog_activation_id'=>$id,'provider'=>$p,'merchant_id'=>$m,'external_customer_id'=>$c,'external_order_id'=>$o];
        return ['result'=>'created','id'=>601];
    }
}
final class PGE_Catalog_Order_Revocation_Repository
{
    public static function find($p,$m,$o) { return $GLOBALS['fixture']['tombstone'] ?? null; }
}
final class PGE_Catalog_Order_Revocation_Service
{
    public static function with_order_lock($p,$m,$o,callable $callback) {
        if (!empty($GLOBALS['fixture']['order_lock_fail'])) return ['result'=>'lock_not_acquired'];
        return $callback($p,$m,$o);
    }
}
final class PGE_Catalog_Event_Binding_Service
{
    public static function lock_name($id) { return 'event-' . $id; }
}
final class PGE_Catalog_Event_Binding_Repository
{
    public static function find_by_activation_id($id) { return $GLOBALS['fixture']['bindings'] ?? []; }
}

require_once dirname(__DIR__) . '/includes/class-pge-dec-plus-mig-01-backfill.php';

$pass=0;$total=0;
function bcheck($label,$actual,$expected){global$pass,$total;$total++;if($actual===$expected){$pass++;echo"PASS $label\n";}else echo"FAIL $label expected=".var_export($expected,true)." actual=".var_export($actual,true)."\n";}
function reset_backfill_fixture(){
    $GLOBALS['fixture']=['activations'=>[],'origins'=>[],'bindings'=>[],'meta'=>[
        '_mon_package_source'=>'catalog','_mon_catalog_plan_id'=>'2','_mon_catalog_tier_id'=>'6',
        '_mon_catalog_plan_key'=>'halwa_plus','_mon_catalog_tier_key'=>'guests_100','_mon_package_status'=>'active',
        '_mon_credit_cycle_id'=>'6daa5ee5-5bda-43a6-a392-ad75402b8c21','_mon_last_order_id'=>'1576373696',
        '_mon_salla_product_id'=>'1539650850','_mon_package_activated_at'=>'2026-09-15 20:12:37',
        '_mon_package_price'=>'0.00','_mon_package_currency'=>'SAR','_mon_guest_limit'=>'100',
        '_mon_event_quota_mode'=>'limited','_mon_event_quota_limit'=>'1',
        '_mon_invitation_credit_total'=>'100','_mon_invitation_credit_used'=>'0',
        '_mon_replacement_credit_total'=>'40','_mon_replacement_credit_used'=>'0',
    ]];
    $GLOBALS['meta_writes']=0;$GLOBALS['http_calls']=0;$GLOBALS['membership_calls']=0;
    $GLOBALS['activation_writes']=0;$GLOBALS['origin_writes']=0;$GLOBALS['event_creations']=0;$GLOBALS['email_calls']=0;
}

function durable_write_counts(){return [
    $GLOBALS['meta_writes'],$GLOBALS['activation_writes'],$GLOBALS['origin_writes'],
    $GLOBALS['membership_calls'],$GLOBALS['http_calls'],$GLOBALS['email_calls'],$GLOBALS['event_creations'],
];}

function make_backfilled_fixture(){
    reset_backfill_fixture();
    $result=PGE_DEC_Plus_MIG_01_Backfill::execute();
    if(($result['result']??'')!=='backfilled') throw new RuntimeException('Fixture backfill failed');
    return $GLOBALS['fixture']['activations'][501];
}

reset_backfill_fixture();$before=$GLOBALS['fixture']['meta'];$result=PGE_DEC_Plus_MIG_01_Backfill::execute();$row=$GLOBALS['fixture']['activations'][501];$origin=$GLOBALS['fixture']['origins'][0];
bcheck('successful historical backfill',$result['result'],'backfilled');
bcheck('activation ID is historical credit cycle',$row['activation_id'],'6daa5ee5-5bda-43a6-a392-ad75402b8c21');
bcheck('lifecycle active_unbound',$row['lifecycle_state'],'active_unbound');
bcheck('source is backfill',$row['activation_source'],'backfill');
bcheck('idempotency key exact',$row['idempotency_key'],'salla:392732220:1576373696');
bcheck('provider origin exact',[$origin['provider'],$origin['merchant_id'],$origin['external_customer_id'],$origin['external_order_id']],['salla',392732220,'1888007575','1576373696']);
bcheck('historical activated_at preserved',$row['activated_at'],'2026-09-15 20:12:37');
bcheck('no binding created',count($GLOBALS['fixture']['bindings']),0);
bcheck('all User Meta unchanged',$GLOBALS['fixture']['meta'],$before);
bcheck('no User Meta writes',$GLOBALS['meta_writes'],0);
$snapshot=json_decode($row['projection_snapshot'],true);
$expectedSnapshot=['meta'=>[
    '_mon_package_source'=>'catalog','_mon_catalog_plan_id'=>2,'_mon_catalog_tier_id'=>6,
    '_mon_catalog_plan_key'=>'halwa_plus','_mon_catalog_tier_key'=>'guests_100','_mon_package_status'=>'active',
    '_mon_credit_cycle_id'=>'6daa5ee5-5bda-43a6-a392-ad75402b8c21','_mon_last_order_id'=>'1576373696',
    '_mon_salla_product_id'=>'1539650850','_mon_package_activated_at'=>'2026-09-15 20:12:37',
    '_mon_package_price'=>'0.00','_mon_package_currency'=>'SAR','_mon_guest_limit'=>100,
    '_mon_event_quota_mode'=>'limited','_mon_event_quota_limit'=>1,
    '_mon_invitation_credit_total'=>100,'_mon_replacement_credit_total'=>40,
],'credit_cycle'=>['id'=>'6daa5ee5-5bda-43a6-a392-ad75402b8c21','initial_used'=>[
    '_mon_invitation_credit_used'=>0,'_mon_replacement_credit_used'=>0,
]],'delete'=>[]];
bcheck('complete canonical snapshot',$snapshot,$expectedSnapshot);
bcheck('snapshot invitation totals/used preserved',[$snapshot['meta']['_mon_invitation_credit_total'],$snapshot['credit_cycle']['initial_used']['_mon_invitation_credit_used']],[100,0]);
bcheck('snapshot replacement totals/used preserved',[$snapshot['meta']['_mon_replacement_credit_total'],$snapshot['credit_cycle']['initial_used']['_mon_replacement_credit_used']],[40,0]);
bcheck('snapshot contains no email marker',strpos($row['projection_snapshot'],'activation_email_sent'),false);
bcheck('no Salla HTTP',$GLOBALS['http_calls'],0);
bcheck('no membership projection',$result['membership_projection'],'not_requested');
$replay=PGE_DEC_Plus_MIG_01_Backfill::execute();
bcheck('exact replay is safe no-op',$replay['result'],'already_backfilled');
bcheck('replay creates no duplicate activation',count($GLOBALS['fixture']['activations']),1);
bcheck('replay creates no duplicate origin',count($GLOBALS['fixture']['origins']),1);

$beforeReplayWrites=durable_write_counts();
$GLOBALS['fixture']['meta']['_mon_invitation_credit_used']='7';
$GLOBALS['fixture']['meta']['_mon_replacement_credit_used']='3';
$replay=PGE_DEC_Plus_MIG_01_Backfill::execute();
bcheck('replay ignores legitimately changed mutable credit User Meta',$replay['result'],'already_backfilled');
bcheck('durable replay has zero side effects',durable_write_counts(),$beforeReplayWrites);

$immutableActivationFields=[
    'activation_id'=>'different-activation','user_id'=>381,'plan_id'=>3,'tier_id'=>7,
    'plan_key'=>'different-plan','tier_key'=>'different-tier','activation_source'=>'salla',
    'idempotency_key'=>'different-key','external_order_id'=>'different-order','lifecycle_state'=>'active_bound',
    'revision'=>3,'projection_attempts'=>1,'activated_at'=>'2026-09-16 00:00:00',
    'ended_at'=>'2026-09-20 00:00:00','revoked_at'=>'2026-09-20 00:00:00',
    'last_error_code'=>'projection_failed','next_reconcile_at'=>'2026-09-20 00:05:00',
    'last_reconciled_at'=>'2026-09-20 00:00:00',
];
foreach($immutableActivationFields as$field=>$changed){
    make_backfilled_fixture();$GLOBALS['fixture']['activations'][501][$field]=$changed;
    $beforeWrites=durable_write_counts();$r=PGE_DEC_Plus_MIG_01_Backfill::execute();
    bcheck("immutable activation field $field stops replay",in_array($r['reason']??null,['existing_state_mismatch','partial_existing_state'],true),true);
    bcheck("immutable activation field $field writes nothing",durable_write_counts(),$beforeWrites);
}

foreach(['catalog_activation_id'=>999,'provider'=>'other','merchant_id'=>1,'external_customer_id'=>'other','external_order_id'=>'other'] as$field=>$changed){
    make_backfilled_fixture();$GLOBALS['fixture']['origins'][0][$field]=$changed;
    $beforeWrites=durable_write_counts();$r=PGE_DEC_Plus_MIG_01_Backfill::execute();
    bcheck("immutable origin field $field stops replay",in_array($r['reason']??null,['existing_state_mismatch','partial_existing_state'],true),true);
    bcheck("immutable origin field $field writes nothing",durable_write_counts(),$beforeWrites);
}

make_backfilled_fixture();$decoded=json_decode($GLOBALS['fixture']['activations'][501]['projection_snapshot'],true);$decoded['meta']['_mon_guest_limit']=99;$GLOBALS['fixture']['activations'][501]['projection_snapshot']=json_encode($decoded);$beforeWrites=durable_write_counts();$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('canonical snapshot mismatch stops replay',$r['reason']??null,'projection_snapshot_mismatch');bcheck('snapshot mismatch writes nothing',durable_write_counts(),$beforeWrites);
make_backfilled_fixture();$GLOBALS['fixture']['bindings']=[['id'=>1,'catalog_activation_id'=>501,'event_id'=>77]];$beforeWrites=durable_write_counts();$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('event binding stops replay',$r['reason']??null,'event_binding_exists');bcheck('binding mismatch writes nothing',durable_write_counts(),$beforeWrites);
make_backfilled_fixture();$GLOBALS['fixture']['activations'][501]['created_at']='2030-01-01 00:00:00';$GLOBALS['fixture']['activations'][501]['updated_at']='2030-01-02 00:00:00';$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('operational row timestamps do not invalidate replay',$r['result']??null,'already_backfilled');
make_backfilled_fixture();$GLOBALS['fixture']['tombstone']=['id'=>99];$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('tombstone remains authoritative over exact durable replay',$r['reason']??null,'revocation_tombstone_exists');

reset_backfill_fixture();$GLOBALS['fixture']['tombstone']=['id'=>99];$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('tombstone hard stop',$r['reason'],'revocation_tombstone_exists');bcheck('tombstone no write',count($GLOBALS['fixture']['activations']),0);
reset_backfill_fixture();$GLOBALS['fixture']['meta']['_mon_catalog_tier_id']='999';$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('meta mismatch stops',$r['reason'],'user_meta_mismatch');
reset_backfill_fixture();$GLOBALS['fixture']['event_id']=77;$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('existing cycle event stops',$r['reason'],'event_exists_for_cycle');
reset_backfill_fixture();$GLOBALS['fixture']['activations'][700]=['id'=>700,'activation_id'=>'6daa5ee5-5bda-43a6-a392-ad75402b8c21','idempotency_key'=>'other-key'];$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('activation conflict stops without write',$r['reason'],'partial_existing_state');
reset_backfill_fixture();$GLOBALS['fixture']['activations'][701]=['id'=>701,'activation_id'=>'other-cycle','idempotency_key'=>'salla:392732220:1576373696'];$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('idempotency conflict stops without write',$r['reason'],'partial_existing_state');
reset_backfill_fixture();$GLOBALS['fixture']['origins'][]=['id'=>702,'catalog_activation_id'=>99,'provider'=>'salla','merchant_id'=>392732220,'external_customer_id'=>'1888007575','external_order_id'=>'1576373696'];$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('provider-origin conflict stops without write',$r['reason'],'partial_existing_state');
reset_backfill_fixture();$r=PGE_DEC_Plus_MIG_01_Backfill::execute();$GLOBALS['fixture']['activations'][501]['tier_id']=999;$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('mismatched replay stops explicitly',$r['reason'],'existing_state_mismatch');
reset_backfill_fixture();$GLOBALS['fixture']['lock_fail']=true;$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('concurrent user lock contention stops',$r['reason'],'user_lock_not_acquired');bcheck('user lock contention writes nothing',count($GLOBALS['fixture']['activations']),0);
reset_backfill_fixture();$GLOBALS['fixture']['order_lock_fail']=true;$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('concurrent order lock contention stops',$r['reason'],'order_lock_not_acquired');bcheck('order lock contention writes nothing',count($GLOBALS['fixture']['activations']),0);
reset_backfill_fixture();$GLOBALS['fixture']['origin_fail']=true;$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('origin failure reported',$r['reason'],'origin_create_db_error');bcheck('origin failure rolls activation back',count($GLOBALS['fixture']['activations']),0);
reset_backfill_fixture();$GLOBALS['fixture']['cas_fail']=true;$r=PGE_DEC_Plus_MIG_01_Backfill::execute();bcheck('CAS failure reported',$r['reason'],'activation_cas_stale');bcheck('CAS failure rolls activation and origin back',[count($GLOBALS['fixture']['activations']),count($GLOBALS['fixture']['origins'])],[0,0]);

echo"\n$pass/$total passed\n";exit($pass===$total?0:1);
