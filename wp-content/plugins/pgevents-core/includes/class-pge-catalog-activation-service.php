<?php
if (!defined('ABSPATH')) exit;

/** Phase 2 durable Catalog activation saga. */
final class PGE_Catalog_Activation_Service
{
    public static function activate($user_id, $plan_id, $tier_id, array $context)
    {
        $user_id=absint($user_id); $plan_id=absint($plan_id); $tier_id=absint($tier_id); $identity=self::normalize_identity($context);
        if (!$user_id || !$plan_id || !$tier_id || is_wp_error($identity)) return is_wp_error($identity)?$identity:self::error('invalid_activation_request');
        if (!get_user_by('id',$user_id)) return self::error('user_not_found');
        global $wpdb; $lock='pge_catalog_activation_user_'.$user_id;
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)',$lock,5))!==1) return self::error('activation_lock_unavailable');
        try {
            if($identity['source']==='salla'&&class_exists('PGE_Catalog_Order_Revocation_Service')){
                $result=PGE_Catalog_Order_Revocation_Service::with_order_lock('salla',$identity['merchant_id'],$identity['external_order_id'],function()use($user_id,$plan_id,$tier_id,$identity){return self::activate_locked($user_id,$plan_id,$tier_id,$identity);});
                return in_array($result['result']??'',['lock_not_acquired','invalid'],true)?self::error('activation_order_'.$result['result']):$result;
            }
            return self::activate_locked($user_id,$plan_id,$tier_id,$identity);
        }
        finally { $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }

    private static function activate_locked($user_id,$plan_id,$tier_id,array $identity)
    {
        if($identity['source']==='salla'&&class_exists('PGE_Catalog_Order_Revocation_Repository')){
            $tomb=PGE_Catalog_Order_Revocation_Repository::find('salla',$identity['merchant_id'],$identity['external_order_id']);
            if(is_array($tomb))return['result'=>'refunded_order','state'=>PGE_Catalog_Activation_Repository::STATE_REVOKED,'replay'=>true,'tombstone_id'=>(int)$tomb['id']];
        }
        $existing=PGE_Catalog_Activation_Repository::find_by_idempotency_key($identity['idempotency_key']);
        if (is_array($existing)) return self::resume_or_replay($existing,$user_id,$plan_id,$tier_id,$identity);
        $activation_id=Mon_Events_Users::generate_catalog_activation_id();
        $projection=Mon_Events_Users::build_catalog_activation_projection($user_id,$plan_id,$tier_id,$identity['external_order_id'],$activation_id);
        if (is_wp_error($projection)) return $projection;
        $snapshot=wp_json_encode($projection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($snapshot)) return self::error('projection_encoding_failed');
        if (!self::begin()) return self::error('activation_storage_unavailable');
        $created=PGE_Catalog_Activation_Repository::create_preparing([
            'activation_id'=>$activation_id,'user_id'=>$user_id,'plan_id'=>$plan_id,'tier_id'=>$tier_id,
            'plan_key'=>(string)($projection['meta']['_mon_catalog_plan_key']??''),'tier_key'=>(string)($projection['meta']['_mon_catalog_tier_key']??''),
            'activation_source'=>$identity['source'],'idempotency_key'=>$identity['idempotency_key'],
            'external_order_id'=>$identity['external_order_id']?:null,'projection_snapshot'=>$snapshot,
        ]);
        if (($created['result']??'')!=='created') {
            if (!self::rollback()) return self::error('activation_storage_uncertain');
            if (($created['result']??'')==='conflict' && ($created['reason']??'')==='idempotency_key') {
                $row=$created['record']??PGE_Catalog_Activation_Repository::find_by_idempotency_key($identity['idempotency_key']);
                return is_array($row)?self::resume_or_replay($row,$user_id,$plan_id,$tier_id,$identity):self::error('activation_persistence_failed');
            }
            return self::error('activation_db_error');
        }
        $row=PGE_Catalog_Activation_Repository::find_by_id((int)$created['id']);
        if (!is_array($row)) return self::rollback()?self::error('activation_db_error'):self::error('activation_storage_uncertain');
        if ($identity['source']==='salla') {
            $origin=PGE_Catalog_Provider_Origin_Repository::create((int)$row['id'],'salla',$identity['merchant_id'],$identity['external_customer_id'],$identity['external_order_id']);
            if (($origin['result']??'')!=='created') {
                if (!self::rollback()) return self::error('activation_storage_uncertain');
                return self::error(($origin['result']??'')==='conflict'?'idempotency_conflict':'activation_db_error');
            }
        }
        if (!self::commit()) { self::rollback(); return self::error('activation_storage_uncertain'); }
        return self::project_and_activate($row,false);
    }

    private static function resume_or_replay(array $row,$user_id,$plan_id,$tier_id,array $identity)
    {
        if ((int)($row['user_id']??0)!==$user_id || (int)($row['plan_id']??0)!==$plan_id || (int)($row['tier_id']??0)!==$tier_id || (string)($row['activation_source']??'')!==$identity['source'] || (string)($row['external_order_id']??'')!==$identity['external_order_id']) return self::error('idempotency_conflict');
        $state=(string)($row['lifecycle_state']??'');
        if ($identity['source']==='salla') {
            $origin=PGE_Catalog_Provider_Origin_Repository::find_by_order('salla',$identity['merchant_id'],$identity['external_order_id']);
            if (!is_array($origin)) {
                if ($state!==PGE_Catalog_Activation_Repository::STATE_PREPARING) return self::error('activation_origin_missing');
                $repair=self::repair_origin($row,$identity); if (is_wp_error($repair)) return $repair;
            } elseif (!self::origin_matches($origin,$row,$identity)) return self::error('idempotency_conflict');
        }
        if (in_array($state,[PGE_Catalog_Activation_Repository::STATE_ENDED,PGE_Catalog_Activation_Repository::STATE_REVOKED],true)) return self::result('terminal_replay',$row,true);
        if (in_array($state,[PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND],true)) return self::result('replayed',$row,true);
        if ($state!==PGE_Catalog_Activation_Repository::STATE_PREPARING) return self::error('invalid_activation_state');
        return self::project_and_activate($row,true);
    }

    private static function repair_origin(array $row,array $identity)
    {
        if (!self::begin()) return self::error('activation_storage_unavailable');
        $locked=PGE_Catalog_Activation_Repository::find_by_id_for_update((int)$row['id']);
        if (!is_array($locked)||($locked['lifecycle_state']??'')!==PGE_Catalog_Activation_Repository::STATE_PREPARING) { self::rollback(); return self::error('activation_origin_missing'); }
        $by_order=PGE_Catalog_Provider_Origin_Repository::find_by_order('salla',$identity['merchant_id'],$identity['external_order_id']);
        $by_activation=PGE_Catalog_Provider_Origin_Repository::find_by_activation_and_provider((int)$row['id'],'salla');
        if (is_array($by_order)||is_array($by_activation)) {
            $candidate=is_array($by_order)?$by_order:$by_activation;
            if (!self::rollback()) return self::error('activation_storage_uncertain');
            return self::origin_matches($candidate,$row,$identity)?true:self::error('idempotency_conflict');
        }
        $made=PGE_Catalog_Provider_Origin_Repository::create((int)$row['id'],'salla',$identity['merchant_id'],$identity['external_customer_id'],$identity['external_order_id']);
        if (($made['result']??'')!=='created') {
            if (!self::rollback()) return self::error('activation_storage_uncertain');
            $winner=PGE_Catalog_Provider_Origin_Repository::find_by_order('salla',$identity['merchant_id'],$identity['external_order_id']);
            return is_array($winner)&&self::origin_matches($winner,$row,$identity)?true:self::error(($made['result']??'')==='conflict'?'idempotency_conflict':'activation_db_error');
        }
        if (!self::commit()) { self::rollback(); return self::error('activation_storage_uncertain'); }
        return true;
    }

    private static function project_and_activate(array $row,$resumed)
    {
        if (!class_exists('PGE_Salla_Plus_Eligibility_Resolver')) return self::project_and_activate_serialized($row,$resumed,null);
        $result=PGE_Salla_Plus_Eligibility_Resolver::with_activation_identity_lock((int)$row['id'],function($identity)use($row,$resumed){
            return self::project_and_activate_serialized($row,$resumed,$identity);
        });
        if (($result['result']??'')==='error') return self::error('membership_identity_'.($result['reason']??'serialization_failed'));
        return $result;
    }

    /** User activation lock is already held; membership identity lock is nested second. */
    private static function project_and_activate_serialized(array $row,$resumed,$membership_identity)
    {
        $projection=json_decode((string)($row['projection_snapshot']??''),true);
        if (!is_array($projection)) return self::record_failure($row,'invalid_projection_snapshot');
        if (!self::begin()) return self::error('activation_storage_unavailable');
        $locked=PGE_Catalog_Activation_Repository::find_by_id_for_update((int)$row['id']);
        if (!is_array($locked)) { self::rollback(); return self::error('activation_cas_missing'); }
        $state=(string)($locked['lifecycle_state']??'');
        if (in_array($state,[PGE_Catalog_Activation_Repository::STATE_ENDED,PGE_Catalog_Activation_Repository::STATE_REVOKED],true)) { self::rollback(); return self::result('terminal_replay',$locked,true); }
        if (in_array($state,[PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND],true)) { self::rollback(); return self::result('replayed',$locked,true); }
        if ($state!==PGE_Catalog_Activation_Repository::STATE_PREPARING || (int)$locked['revision']!==(int)$row['revision']) { self::rollback(); return self::error('activation_cas_stale'); }
        $applied=Mon_Events_Users::apply_catalog_activation_projection((int)$locked['user_id'],$projection);
        if (is_wp_error($applied)) {
            if (!self::rollback_user_meta((int)$locked['user_id'])) return self::error('activation_storage_uncertain');
            return self::record_failure($locked,$applied->get_error_code());
        }
        $cas=PGE_Catalog_Activation_Repository::compare_and_swap_state((int)$locked['id'],PGE_Catalog_Activation_Repository::STATE_PREPARING,(int)$locked['revision'],PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,['activated_at'=>current_time('mysql',true),'next_reconcile_at'=>null]);
        if (($cas['result']??'')!=='updated') {
            if (!self::rollback_user_meta((int)$locked['user_id'])) return self::error('activation_storage_uncertain');
            return self::error('activation_cas_'.($cas['result']??'failed'));
        }
        if (!self::commit()) { self::rollback_user_meta((int)$locked['user_id']); return self::error('activation_storage_uncertain'); }
        $locked['lifecycle_state']=PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND; $locked['revision']=$cas['revision'];
        $result=self::result($resumed?'resumed':'activated',$locked,false);
        if (is_array($membership_identity)) {
            $result['membership_projection']=PGE_Salla_Plus_Eligibility_Resolver::recompute_and_project_locked(
                (int)$membership_identity['merchant_id'],
                (int)$membership_identity['salla_customer_id']
            );
        }
        return $result;
    }

    private static function rollback_user_meta($user_id){$ok=self::rollback();if(function_exists('clean_user_cache'))clean_user_cache($user_id);return $ok;}
    private static function record_failure(array $row,$code){$r=PGE_Catalog_Activation_Repository::record_projection_failure((int)$row['id'],(int)$row['revision'],$code,gmdate('Y-m-d H:i:s',time()+300));return self::error(($r['result']??'')==='recorded'?$code:'projection_failure_'.($r['result']??'db_error'));}
    private static function begin(){global $wpdb;return $wpdb->query('START TRANSACTION')!==false;}
    private static function commit(){global $wpdb;return $wpdb->query('COMMIT')!==false;}
    private static function rollback(){global $wpdb;return $wpdb->query('ROLLBACK')!==false;}
    private static function origin_matches(array $o,array $r,array $i){return (int)($o['catalog_activation_id']??0)===(int)($r['id']??0)&&(int)($o['merchant_id']??0)===(int)$i['merchant_id']&&(string)($o['external_order_id']??'')===$i['external_order_id']&&(string)($o['external_customer_id']??'')===$i['external_customer_id'];}
    private static function normalize_identity(array $c){$s=isset($c['source'])&&is_scalar($c['source'])?trim((string)$c['source']):'';if($s==='manual'){$id=isset($c['operation_id'])&&is_scalar($c['operation_id'])?strtolower(trim((string)$c['operation_id'])):'';return self::valid_uuid($id)?['source'=>'manual','idempotency_key'=>'manual:'.$id,'external_order_id'=>'','merchant_id'=>0,'external_customer_id'=>'']:self::error('invalid_operation_id');}if($s==='salla'){$m=absint($c['merchant_id']??0);$u=isset($c['external_customer_id'])&&is_scalar($c['external_customer_id'])?trim((string)$c['external_customer_id']):'';$o=isset($c['external_order_id'])&&is_scalar($c['external_order_id'])?trim((string)$c['external_order_id']):'';return $m&&$u!==''&&$o!==''?['source'=>'salla','idempotency_key'=>'salla:'.$m.':'.$o,'external_order_id'=>$o,'merchant_id'=>$m,'external_customer_id'=>$u]:self::error('invalid_salla_origin');}return self::error('invalid_activation_source');}
    private static function valid_uuid($v){return is_string($v)&&(bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$v);}
    private static function result($s,array $r,$p){return ['result'=>$s,'activation_id'=>(string)($r['activation_id']??''),'catalog_activation_id'=>(int)($r['id']??0),'state'=>(string)($r['lifecycle_state']??''),'replay'=>(bool)$p];}
    private static function error($c){return new WP_Error($c,'تعذر إكمال عملية التفعيل بأمان.');}
}
