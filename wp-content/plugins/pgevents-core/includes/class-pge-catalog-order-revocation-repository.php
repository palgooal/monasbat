<?php
if (!defined('ABSPATH')) exit;

/** Durable terminal tombstones keyed only by permanent provider order identity. */
final class PGE_Catalog_Order_Revocation_Repository
{
    public static function find($provider, $merchant_id, $external_order_id)
    {
        $identity=self::identity($provider,$merchant_id,$external_order_id);if(!$identity)return null;global$wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.PGE_Catalog_Activation_Schema::revocations_table().' WHERE provider=%s AND merchant_id=%d AND external_order_id=%s LIMIT 1',$identity[0],$identity[1],$identity[2]),ARRAY_A);
    }

    public static function create($provider, $merchant_id, $external_order_id, $reason)
    {
        $identity=self::identity($provider,$merchant_id,$external_order_id);$reason=is_scalar($reason)&&!is_bool($reason)?sanitize_key((string)$reason):'';
        if(!$identity||$reason==='')return['result'=>'invalid'];global$wpdb;
        $ok=$wpdb->insert(PGE_Catalog_Activation_Schema::revocations_table(),['provider'=>$identity[0],'merchant_id'=>$identity[1],'external_order_id'=>$identity[2],'reason'=>$reason,'created_at'=>current_time('mysql',true)],['%s','%d','%s','%s','%s']);
        if($ok)return['result'=>'created','id'=>(int)$wpdb->insert_id,'record'=>self::find(...$identity)];
        $existing=self::find(...$identity);return is_array($existing)?['result'=>'existing','id'=>(int)$existing['id'],'record'=>$existing]:['result'=>'db_error'];
    }

    private static function identity($provider,$merchant_id,$order_id){$provider=is_scalar($provider)&&!is_bool($provider)?sanitize_key((string)$provider):'';$merchant_id=absint($merchant_id);$order_id=is_scalar($order_id)&&!is_bool($order_id)?trim((string)$order_id):'';return $provider&&$merchant_id&&$order_id!==''?[$provider,$merchant_id,$order_id]:null;}
}
