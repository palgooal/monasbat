<?php
define('ABSPATH', __DIR__ . '/');
class WP_Error { private $c,$d; function __construct($c,$m='',$d=null){$this->c=$c;$this->d=$d;} function get_error_code(){return $this->c;} function get_error_data(){return $this->d;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function current_time($kind,$gmt=false){return $kind==='timestamp'?1700000000:'2023-11-14 22:13:20';}
function add_action(){} function wp_next_scheduled(){return false;} function wp_schedule_single_event(){return true;}

class P7_WPDB { public $identity_locked=false; function prepare($q,...$a){return $q;} function get_var($q){if(strpos($q,'GET_LOCK')!==false)$this->identity_locked=true;return 1;} function query($q){if(strpos($q,'RELEASE_LOCK')!==false)$this->identity_locked=false;return 1;} }
$GLOBALS['wpdb']=new P7_WPDB();

class PGE_Salla_Membership_Sync_Store {
 const DESIRED_MEMBER='member',DESIRED_NOT_MEMBER='not_member'; static $valid=true,$snapshot=null,$snapshot_state='',$marks=[],$member_requests=0,$invalidate_after_save=false,$finalize_success=true,$finalize_stale=false;
 static function identity_lock_name($m,$c,$g){return "l:$m:$c:$g";}
 static function revalidate_claim($c){return self::$valid?['result'=>'valid','row'=>[]]:['result'=>'stale_claim'];}
 static function removal_snapshot($c){return self::$snapshot===null?['result'=>'none']:['result'=>'found','groups'=>self::$snapshot,'state'=>self::$snapshot_state];}
 static function save_removal_snapshot($c,$g,$s='prepared'){self::$snapshot=array_values($g);self::$snapshot_state=$s;self::$marks[]=['snapshot',$s,self::$snapshot];if(self::$invalidate_after_save)self::$valid=false;return['result'=>'saved','groups'=>self::$snapshot];}
 static function request_state_locked($m,$c,$g,$s){self::$member_requests++;self::$valid=false;return['result'=>'updated'];}
 static function finish($mark){self::$marks[]=$mark;if(self::$finalize_stale)self::$valid=false;return self::$finalize_success;}
 static function mark_satisfied($c){return self::finish(['satisfied']);}
 static function mark_retry($c,$e,$d,$a=false){return self::finish(['retry',$e,$a]);}
 static function mark_failed($c,$e){return self::finish(['failed',$e]);}
 static function reset(){self::$valid=true;self::$snapshot=null;self::$snapshot_state='';self::$marks=[];self::$member_requests=0;self::$invalidate_after_save=false;self::$finalize_success=true;self::$finalize_stale=false;}
}
class PGE_Salla_Plus_Eligibility_Resolver { static $eligible=false,$authoritative=true; static function resolve($m,$c){return['eligible'=>self::$eligible,'authoritative'=>self::$authoritative,'reason'=>self::$authoritative?'resolved':'eligibility_query_failed'];} }
class PGE_Salla_Not_Member_Removal_Feature { static $enabled=true; static function enabled(){return self::$enabled;} }
class PGE_Salla_Customer_Groups_Service {
 static $gets=[],$put_result,$puts=[],$lock_observations=[];
 function get_customer_details($m,$c){self::$lock_observations[]=$GLOBALS['wpdb']->identity_locked;return array_shift(self::$gets);}
 function update_customer_groups($m,$c,$f,array $g){self::$lock_observations[]=$GLOBALS['wpdb']->identity_locked;self::$puts[]=[$m,$c,$f,$g];return self::$put_result;}
 static function reset(){self::$gets=[];self::$puts=[];self::$put_result=['success'=>true];self::$lock_observations=[];}
}

require dirname(__DIR__).'/includes/class-pge-salla-membership-sync-worker.php';
$n=0;$p=0;function ok($l,$a,$e=true){global$n,$p;$n++;if($a===$e){$p++;echo"PASS $l\n";}else echo"FAIL $l expected ".var_export($e,true).' got '.var_export($a,true)."\n";}
function claim(){return['id'=>1,'desired_state'=>'not_member','desired_revision'=>4,'attempt_revision'=>4,'attempt_token'=>'tok','merchant_id'=>11,'salla_customer_id'=>22,'group_id'=>225189340,'attempt_count'=>1];}
function reset7(){PGE_Salla_Membership_Sync_Store::reset();PGE_Salla_Customer_Groups_Service::reset();PGE_Salla_Plus_Eligibility_Resolver::$eligible=false;PGE_Salla_Plus_Eligibility_Resolver::$authoritative=true;PGE_Salla_Not_Member_Removal_Feature::$enabled=true;}
function run7(){return PGE_Salla_Membership_Sync_Worker::process_claim(claim());}

reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340],'first_name'=>'N'],['exists'=>true,'groups'=>[],'first_name'=>'N']];$r=run7();ok('Plus only removed',$r['result'],'removed');ok('Plus only PUT groups empty',PGE_Salla_Customer_Groups_Service::$puts[0][3],[]);
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N'],['exists'=>true,'groups'=>[8],'first_name'=>'N']];run7();ok('one non-target preserved in PUT',PGE_Salla_Customer_Groups_Service::$puts[0][3],[8]);
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[9,225189340,8],'first_name'=>'N'],['exists'=>true,'groups'=>[8,9],'first_name'=>'N']];run7();ok('two non-target groups preserved',PGE_Salla_Customer_Groups_Service::$puts[0][3],[8,9]);
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[8],'first_name'=>'N']];$r=run7();ok('already absent result',$r['result'],'already_absent');ok('already absent has no PUT',count(PGE_Salla_Customer_Groups_Service::$puts),0);
reset7();PGE_Salla_Plus_Eligibility_Resolver::$eligible=true;$r=run7();ok('new Plus wins stale removal',$r['result'],'eligible_again');ok('eligible again requests member',PGE_Salla_Membership_Sync_Store::$member_requests,1);ok('eligible again no HTTP',count(PGE_Salla_Customer_Groups_Service::$gets)+count(PGE_Salla_Customer_Groups_Service::$puts),0);
reset7();PGE_Salla_Plus_Eligibility_Resolver::$authoritative=false;$r=run7();ok('eligibility DB uncertainty fails closed',$r['result'],'eligibility_unavailable');ok('eligibility DB uncertainty performs no HTTP',count(PGE_Salla_Customer_Groups_Service::$gets)+count(PGE_Salla_Customer_Groups_Service::$puts),0);ok('eligibility uncertainty is not satisfied',PGE_Salla_Membership_Sync_Store::$marks[0][0]??'','retry');
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N']];PGE_Salla_Membership_Sync_Store::$valid=false;$r=run7();ok('stale claim rejected',$r['result'],'stale_claim');ok('stale claim no PUT',count(PGE_Salla_Customer_Groups_Service::$puts),0);
reset7();PGE_Salla_Membership_Sync_Store::$invalidate_after_save=true;PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N']];$r=run7();ok('revision change immediately before PUT is stale',$r['result'],'stale_claim');ok('revision change before PUT prevents PUT',count(PGE_Salla_Customer_Groups_Service::$puts),0);
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N'],['exists'=>true,'groups'=>[8],'first_name'=>'N']];ok('PUT plus reconcile succeeds',run7()['result'],'removed');
ok('identity lock covers GET PUT GET',PGE_Salla_Customer_Groups_Service::$lock_observations,[true,true,true]);ok('identity lock released after finalization',$GLOBALS['wpdb']->identity_locked,false);
reset7();PGE_Salla_Customer_Groups_Service::$put_result=new WP_Error('salla_customer_update_transport_error');PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N'],['exists'=>true,'groups'=>[8],'first_name'=>'N']];ok('timeout reconciles completed mutation',run7()['result'],'removed');ok('timeout issues one PUT',count(PGE_Salla_Customer_Groups_Service::$puts),1);
foreach([['empty 2xx',true,false],['malformed 2xx',true,false],['invalid 2xx unchanged',false,false],['invalid 2xx reconciliation unavailable',false,true]] as [$label,$mutated,$unavailable]){reset7();PGE_Salla_Customer_Groups_Service::$put_result=new WP_Error('salla_customer_update_ambiguous_response','',['http_status'=>200]);PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N'],$unavailable?new WP_Error('salla_customer_details_transport_error'):['exists'=>true,'groups'=>$mutated?[8]:[225189340,8],'first_name'=>'N']];$r=run7();ok($label.' reconciles',$r['result'],$unavailable?'transport_ambiguous':($mutated?'removed':'retryable_removal'));ok($label.' uses one PUT',count(PGE_Salla_Customer_Groups_Service::$puts),1);}
reset7();PGE_Salla_Customer_Groups_Service::$put_result=new WP_Error('salla_customer_update_transport_error');PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N'],['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N']];ok('timeout without mutation is retryable',run7()['result'],'retryable_removal');
reset7();PGE_Salla_Membership_Sync_Store::$snapshot=[8];PGE_Salla_Membership_Sync_Store::$snapshot_state='ambiguous';PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[8],'first_name'=>'N']];ok('lease recovery reconciles snapshot first',run7()['result'],'removed');ok('lease recovery performs no blind PUT',count(PGE_Salla_Customer_Groups_Service::$puts),0);
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N'],['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N']];ok('target remaining is retryable',run7()['result'],'retryable_removal');ok('retryable reconciliation persists retryable state',PGE_Salla_Membership_Sync_Store::$snapshot_state,'retryable');PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N'],['exists'=>true,'groups'=>[8],'first_name'=>'N']];PGE_Salla_Customer_Groups_Service::$put_result=['success'=>true];ok('next retry performs fresh authorized removal',run7()['result'],'removed');ok('next retry performs exactly one PUT',count(PGE_Salla_Customer_Groups_Service::$puts),2);
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340,8],'first_name'=>'N'],['exists'=>true,'groups'=>[],'first_name'=>'N']];ok('lost non-target is conflict',run7()['result'],'reconciliation_conflict');ok('conflict has no repair PUT',count(PGE_Salla_Customer_Groups_Service::$puts),1);
foreach([new WP_Error('salla_customer_details_malformed_groups'),new WP_Error('salla_customer_details_missing_update_field')] as $i=>$error){reset7();PGE_Salla_Customer_Groups_Service::$gets=[$error];$r=run7();ok($i?'missing first name fails':'malformed groups fail',$r['result'],$i?'missing_update_field':'malformed_groups_response');ok('invalid pre-GET no PUT '.($i+1),count(PGE_Salla_Customer_Groups_Service::$puts),0);}
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>false,'groups'=>[],'first_name'=>null]];ok('customer 404 explicit',run7()['result'],'customer_not_found');
reset7();PGE_Salla_Customer_Groups_Service::$gets=[new WP_Error('salla_customer_details_http_error','',['http_status'=>401])];ok('pre-GET 401 follows documented result',run7()['result'],'unauthorized_after_token_recovery');ok('pre-GET 401 performs no PUT',count(PGE_Salla_Customer_Groups_Service::$puts),0);
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340],'first_name'=>'N']];PGE_Salla_Customer_Groups_Service::$put_result=new WP_Error('salla_customer_update_unauthorized','',['http_status'=>401]);ok('401 follows documented result',run7()['result'],'unauthorized_after_token_recovery');ok('401 has one PUT and no replay',count(PGE_Salla_Customer_Groups_Service::$puts),1);
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340],'first_name'=>'N'],['exists'=>true,'groups'=>[],'first_name'=>'N']];PGE_Salla_Membership_Sync_Store::$finalize_success=false;PGE_Salla_Membership_Sync_Store::$finalize_stale=true;ok('stale success finalization is not reported removed',run7()['result'],'stale_finalization');
reset7();PGE_Salla_Customer_Groups_Service::$gets=[new WP_Error('salla_customer_details_http_error','',['http_status'=>400])];PGE_Salla_Membership_Sync_Store::$finalize_success=false;ok('failed finalization is explicit',run7()['result'],'finalization_failed');
reset7();PGE_Salla_Customer_Groups_Service::$gets=[['exists'=>true,'groups'=>[225189340],'first_name'=>'N'],['exists'=>true,'groups'=>[225189340],'first_name'=>'N']];PGE_Salla_Membership_Sync_Store::$finalize_success=false;PGE_Salla_Membership_Sync_Store::$finalize_stale=true;ok('stale retry finalization is explicit',run7()['result'],'stale_finalization');
reset7();PGE_Salla_Customer_Groups_Service::$gets=[new WP_Error('salla_customer_details_http_error','',['http_status'=>400])];PGE_Salla_Membership_Sync_Store::$finalize_success=false;PGE_Salla_Membership_Sync_Store::$finalize_stale=true;ok('stale failure finalization is explicit',run7()['result'],'stale_finalization');

echo"\n$p/$n PASS\n";exit($p===$n?0:1);
