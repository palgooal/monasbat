<?php
if (!defined('ABSPATH')) exit;

/** Trusted Salla refund/cancel orchestration. No transport or user-meta authority. */
final class PGE_Catalog_Order_Revocation_Service
{
    public static function lock_name($provider,$merchant_id,$order_id){return'pge_catalog_order_'.md5(sanitize_key((string)$provider).'|'.absint($merchant_id).'|'.trim((string)$order_id));}

    public static function with_order_lock($provider,$merchant_id,$order_id,callable$callback)
    {
        $provider=sanitize_key((string)$provider);$merchant_id=absint($merchant_id);$order_id=trim((string)$order_id);
        if(!$provider||!$merchant_id||$order_id==='')return['result'=>'invalid'];global$wpdb;$lock=self::lock_name($provider,$merchant_id,$order_id);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1)return['result'=>'lock_not_acquired'];
        try{return$callback($provider,$merchant_id,$order_id);}finally{$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }

    public static function process_trusted($merchant_id,$order_id,$customer_id,$reason)
    {
        return self::with_order_lock('salla',$merchant_id,$order_id,function($provider,$merchant,$order)use($customer_id,$reason){return self::process_locked($provider,$merchant,$order,$customer_id,$reason);});
    }

    private static function process_locked($provider,$merchant,$order,$customer_id,$reason)
    {
        $origin=PGE_Catalog_Provider_Origin_Repository::find_by_order($provider,$merchant,$order);$customer_id=absint($customer_id);
        if(is_array($origin)&&$customer_id&&$customer_id!==(int)$origin['external_customer_id'])return['result'=>'identity_conflict'];
        $tomb=PGE_Catalog_Order_Revocation_Repository::create($provider,$merchant,$order,$reason);
        if(in_array($tomb['result']??'',['invalid','db_error'],true))return$tomb;
        if(!is_array($origin))return['result'=>'tombstoned','tombstone_id'=>(int)$tomb['id']];
        $activation=PGE_Catalog_Activation_Repository::find_by_id((int)$origin['catalog_activation_id']);
        if(!is_array($activation))return['result'=>'activation_missing','tombstone_id'=>(int)$tomb['id']];
        $work=function($identity)use($activation,$tomb){global$wpdb;
            if($wpdb->query('START TRANSACTION')===false)return['result'=>'db_error'];
            $fresh=PGE_Catalog_Activation_Repository::find_by_id_for_update((int)$activation['id']);
            if(!is_array($fresh)){$wpdb->query('ROLLBACK');return['result'=>'activation_missing','tombstone_id'=>(int)$tomb['id']];}
            $state=(string)($fresh['lifecycle_state']??'');
            if($state===PGE_Catalog_Activation_Repository::STATE_ENDED){$wpdb->query('ROLLBACK');return['result'=>'ended','state'=>$state,'tombstone_id'=>(int)$tomb['id']];}
            if($state===PGE_Catalog_Activation_Repository::STATE_REVOKED){$wpdb->query('ROLLBACK');return['result'=>'revoked','state'=>$state,'tombstone_id'=>(int)$tomb['id'],'idempotent'=>true];}
            if(!in_array($state,[PGE_Catalog_Activation_Repository::STATE_PREPARING,PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND],true)){$wpdb->query('ROLLBACK');return['result'=>'invalid_state'];}
            $cas=PGE_Catalog_Activation_Repository::compare_and_swap_state((int)$fresh['id'],$state,(int)$fresh['revision'],PGE_Catalog_Activation_Repository::STATE_REVOKED,['revoked_at'=>current_time('mysql',true),'next_reconcile_at'=>null]);
            if(($cas['result']??'')!=='updated'){$wpdb->query('ROLLBACK');return$cas;}
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return['result'=>'storage_uncertain','tombstone_id'=>(int)$tomb['id']];}
            $result=['result'=>'revoked','state'=>'revoked','tombstone_id'=>(int)$tomb['id'],'revision'=>$cas['revision']];
            if(is_array($identity))$result['membership_projection']=PGE_Salla_Plus_Eligibility_Resolver::recompute_and_project_locked($identity['merchant_id'],$identity['salla_customer_id']);return$result;};
        if((string)$activation['plan_key']==='halwa_plus'&&class_exists('PGE_Salla_Plus_Eligibility_Resolver'))return PGE_Salla_Plus_Eligibility_Resolver::with_activation_identity_lock((int)$activation['id'],$work);
        return$work(null);
    }
}
