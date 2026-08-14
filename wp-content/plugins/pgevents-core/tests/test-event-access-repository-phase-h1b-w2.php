<?php
/** Phase H1B-W2 — transactional membership and group-access writes. No real DB. */
define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

final class WP_Error { private $c; private $m; function __construct($c='', $m=''){ $this->c=$c; $this->m=$m; } function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
$GLOBALS['w2_ready']=true; $GLOBALS['w2_posts']=[10=>'pge_event']; $GLOBALS['w2_users']=[20=>true,21=>true,22=>true]; $GLOBALS['w2_upgrade']=0; $GLOBALS['w2_current']=0;
final class PGE_Event_Access_Schema { static function is_ready(){return $GLOBALS['w2_ready'];} static function maybe_upgrade(){ $GLOBALS['w2_upgrade']++; return false; } }
function get_post_type($id){return $GLOBALS['w2_posts'][$id]??false;}
function get_userdata($id){return !empty($GLOBALS['w2_users'][$id])?(object)['ID'=>$id]:false;}
function get_current_user_id(){ $GLOBALS['w2_current']++; return 999; }
function current_time($type,$gmt=false){return '2026-08-15 12:00:00';}
function wp_json_encode($v){return json_encode($v);}

final class PGE_H1BW2_WPDB {
 public $prefix='tenant7_',$last_error='',$insert_id=0,$groups=[],$memberships=[],$access=[],$audits=[],$sql=[],$fail=[],$next=[],$result_override=[],$result_calls=0;
 private $prepared=[],$seq=0,$snapshot=null;
 function prepare($sql,...$args){$t='__W2_'.(++$this->seq).'__';$this->prepared[$t]=[$sql,$args];return $t;}
 private function resolve($q){return $this->prepared[$q]??[$q,[]];}
 private function op($s){
  if($s==='START TRANSACTION')return'begin'; if($s==='COMMIT')return'commit'; if($s==='ROLLBACK')return'rollback';
  if(strpos($s,'INSERT INTO tenant7_pge_event_host_memberships')===0)return'insert_membership';
  if(strpos($s,'INSERT INTO tenant7_pge_event_host_group_access')===0)return'insert_access';
  if(strpos($s,'INSERT INTO tenant7_pge_event_access_audit_log')===0)return'insert_audit';
  if(strpos($s,'SET role = %s')!==false)return'change_role'; if(strpos($s,'SET status = %s, revoked_at')!==false)return'revoke_membership';
  if(strpos($s,'SET status = %s, role = %s')!==false)return'reactivate'; return'unknown';
 }
 function query($q){[$s,$a]=$this->resolve($q);$this->sql[]=$s;$op=$this->op($s);if(array_key_exists($op,$this->next)){return array_shift($this->next[$op]);}if(!empty($this->fail[$op])){$this->last_error='private db detail';return false;}$this->last_error='';
  if($op==='begin'){$this->snapshot=[$this->groups,$this->memberships,$this->access,$this->audits,$this->insert_id,$this->last_error];return 0;}
  if($op==='rollback'){if($this->snapshot!==null)[$this->groups,$this->memberships,$this->access,$this->audits,$this->insert_id,$this->last_error]=$this->snapshot;$this->snapshot=null;return 0;}
  if($op==='commit'){$this->snapshot=null;return 0;}
  if($op==='insert_membership'){$id=$this->memberships?max(array_keys($this->memberships))+1:1;$this->insert_id=$id;$this->memberships[$id]=w2_member($id,$a[1],$a[2],'active',null,$a[4],$a[5],$a[6],$a[7]);return 1;}
  if($op==='change_role'){$id=$a[3];if(!isset($this->memberships[$id])||$this->memberships[$id]['status']!==$a[4]||$this->memberships[$id]['role']!==$a[5])return 0;$this->memberships[$id]['role']=$a[0];$this->memberships[$id]['updated_at']=$a[1];return 1;}
  if($op==='revoke_membership'){$id=$a[4];if(!isset($this->memberships[$id])||$this->memberships[$id]['status']!==$a[5])return 0;$this->memberships[$id]['status']='revoked';$this->memberships[$id]['revoked_at']=$a[1];$this->memberships[$id]['updated_at']=$a[2];return 1;}
  if($op==='reactivate'){$id=$a[5];if(!isset($this->memberships[$id])||$this->memberships[$id]['status']!==$a[6])return 0;$this->memberships[$id]['status']='active';$this->memberships[$id]['role']=$a[1];$this->memberships[$id]['activated_at']=$a[2];$this->memberships[$id]['revoked_at']=null;$this->memberships[$id]['updated_at']=$a[3];return 1;}
  if($op==='insert_access'){$id=$this->access?max(array_keys($this->access))+1:1;$this->insert_id=$id;$this->access[$id]=w2_access($id,$a[1],$a[2],$a[3],$a[4]);return 1;}
  if($op==='insert_audit'){$meta=strpos($s,'NULL, %s)')===false?json_decode($a[5],true):null;$this->audits[]=['event_id'=>$a[0],'actor'=>$a[1],'action'=>$a[2],'entity_type'=>$a[3],'entity_id'=>$a[4],'metadata'=>$meta];return 1;}
  $this->last_error='unexpected mutation';return false;
 }
 function get_results($q,$fmt=null){[$s,$a]=$this->resolve($q);$this->sql[]=$s;$this->last_error='';$this->result_calls++;if(array_key_exists($this->result_calls,$this->result_override))return $this->result_override[$this->result_calls];
  if(strpos($s,'tenant7_pge_event_invitation_groups')!==false){$rows=array_values(array_filter($this->groups,fn($r)=>(int)$r['event_id']===$a[0]));if(isset($a[1])&&strpos($s,'id = %d')!==false)$rows=array_values(array_filter($rows,fn($r)=>(int)$r['id']===$a[1]));return $rows;}
  if(strpos($s,'tenant7_pge_event_host_memberships')!==false){$rows=array_values(array_filter($this->memberships,fn($r)=>(int)$r['event_id']===$a[0]));if(isset($a[1])&&strpos($s,'user_id = %d')!==false)$rows=array_values(array_filter($rows,fn($r)=>(int)$r['user_id']===$a[1]));elseif(isset($a[1])&&strpos($s,'id = %d')!==false)$rows=array_values(array_filter($rows,fn($r)=>(int)$r['id']===$a[1]));usort($rows,fn($x,$y)=>(int)$x['id']<=>(int)$y['id']);return $rows;}
  if(strpos($s,'tenant7_pge_event_host_group_access')!==false){$rows=array_values(array_filter($this->access,fn($r)=>(int)$r['event_id']===$a[0]));$has_member=strpos($s,'membership_id = %d')!==false;if($has_member&&isset($a[1]))$rows=array_values(array_filter($rows,fn($r)=>(int)$r['membership_id']===$a[1]));$group_arg=$has_member?2:1;if(isset($a[$group_arg])&&strpos($s,'group_id = %d')!==false)$rows=array_values(array_filter($rows,fn($r)=>(int)$r['group_id']===$a[$group_arg]));usort($rows,fn($x,$y)=>(int)$x['id']<=>(int)$y['id']);return $rows;}
  $this->last_error='unexpected read';return null;
 }
 function get_var($q){return null;}
 function delete($table,$where,$formats){$this->sql[]='WPDB DELETE '.$table;$op=count($where)===2?'delete_membership_access':'delete_access';if(array_key_exists($op,$this->next))return array_shift($this->next[$op]);if(!empty($this->fail[$op])){$this->last_error='private db detail';return false;}$n=0;foreach($this->access as $id=>$r){$match=true;foreach($where as $k=>$v)if((int)$r[$k]!==$v)$match=false;if($match){unset($this->access[$id]);$n++;}}return $n;}
}
function w2_group($id=1,$status='active',$event=10){return ['id'=>(string)$id,'event_id'=>(string)$event,'name'=>'Group '.$id,'name_key'=>$status==='active'?'group '.$id:null,'status'=>$status,'default_slot'=>null,'created_by_user_id'=>'7','created_at'=>'2026-08-14 01:00:00','updated_at'=>'2026-08-14 01:00:00','archived_at'=>$status==='archived'?'2026-08-14 02:00:00':null];}
function w2_member($id=1,$user=20,$role='manager',$status='active',$revoked=null,$creator=7,$activated='2026-08-14 01:00:00',$created='2026-08-14 01:00:00',$updated='2026-08-14 01:00:00'){return ['id'=>(string)$id,'event_id'=>'10','user_id'=>(string)$user,'role'=>$role,'status'=>$status,'created_by_user_id'=>(string)$creator,'activated_at'=>$activated,'revoked_at'=>$revoked,'created_at'=>$created,'updated_at'=>$updated];}
function w2_access($id=1,$member=1,$group=1,$actor=7,$created='2026-08-14 01:00:00'){return ['id'=>(string)$id,'event_id'=>'10','membership_id'=>(string)$member,'group_id'=>(string)$group,'granted_by_user_id'=>(string)$actor,'created_at'=>$created];}
require_once PGE_PATH.'includes/class-pge-event-access-repository.php';
$pass=0;$fail=0;function ok($l,$v){global$pass,$fail;if($v){$pass++;echo"PASS: $l\n";}else{$fail++;echo"FAIL: $l\n";}}function code($v){return$v instanceof WP_Error?$v->get_error_code():null;}function fresh($m=[],$g=[],$a=[]){global$wpdb;$wpdb=new PGE_H1BW2_WPDB;foreach($m as$r)$wpdb->memberships[(int)$r['id']]=$r;foreach($g as$r)$wpdb->groups[(int)$r['id']]=$r;foreach($a as$r)$wpdb->access[(int)$r['id']]=$r;$GLOBALS['w2_ready']=true;$GLOBALS['w2_posts']=[10=>'pge_event'];$GLOBALS['w2_users']=[20=>true,21=>true,22=>true];return$wpdb;}

$writes=[['create_membership',[10,20,'manager',7]],['change_membership_role',[10,1,'manager','viewer',7]],['revoke_membership',[10,1,7]],['reactivate_membership',[10,1,'manager',7]],['grant_group_access',[10,1,1,7]],['revoke_group_access',[10,1,1,7]]];
foreach($writes as[$m,$args]){$db=fresh();$GLOBALS['w2_ready']=false;$r=call_user_func_array(['PGE_Event_Access_Repository',$m],$args);ok("$m readiness fails before SQL",code($r)==='schema_not_ready'&&$db->sql===[]);}
$db=fresh();ok('strict IDs and roles',code(PGE_Event_Access_Repository::create_membership(10,'20','manager',7))==='invalid_input'&&code(PGE_Event_Access_Repository::create_membership(10,20,'Manager',7))==='invalid_input');
$invalid_id_calls=[['change_membership_role',[10,'1','manager','viewer',7]],['revoke_membership',[10,1,'7']],['reactivate_membership',[10,1,'viewer',false]],['grant_group_access',[10,1,'1',7]],['revoke_group_access',[10,1,1,7.0]]];foreach($invalid_id_calls as[$method,$args]){$db=fresh();ok("$method rejects non-integer IDs",code(call_user_func_array(['PGE_Event_Access_Repository',$method],$args))==='invalid_input'&&$db->sql===[]);}
$db=fresh();ok('event validation is generic and SQL-free',code(PGE_Event_Access_Repository::create_membership(11,20,'manager',7))==='not_found'&&$db->sql===[]);
$db=fresh();unset($GLOBALS['w2_users'][20]);ok('missing target user is generic not_found',code(PGE_Event_Access_Repository::create_membership(10,20,'manager',7))==='not_found'&&$db->sql===[]);
$db=fresh([w2_member(1,20,'manager','active','2026-08-14 02:00:00')]);ok('active lifecycle corruption fails a locked list',code(PGE_Event_Access_Repository::revoke_membership(10,1,7))==='database_error');
$db=fresh([w2_member(1,20,'manager','revoked')]);ok('revoked lifecycle corruption fails a locked list',code(PGE_Event_Access_Repository::revoke_membership(10,1,7))==='database_error');
$db=fresh([w2_member(),w2_member(2,21,'viewer','revoked')]);ok('one corrupt membership fails the complete locked list',code(PGE_Event_Access_Repository::revoke_membership(10,1,7))==='database_error');

foreach(['manager','viewer']as$role){$db=fresh();$r=PGE_Event_Access_Repository::create_membership(10,20,$role,7);ok("create $role",is_array($r)&&$r['membership']['role']===$role&&count($db->audits)===1&&$db->audits[0]['action']==='membership_created'&&$db->audits[0]['entity_type']==='membership'&&$db->audits[0]['metadata']===['role'=>$role]);}
$db=fresh([w2_member()]);ok('create duplicate active',code(PGE_Event_Access_Repository::create_membership(10,20,'manager',7))==='duplicate_membership');
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')]);ok('create duplicate revoked',code(PGE_Event_Access_Repository::create_membership(10,20,'manager',7))==='duplicate_membership');
$db=fresh();$db->insert_id=77;$db->next['insert_membership']=[1];ok('stale membership insert id fails and rolls back',code(PGE_Event_Access_Repository::create_membership(10,20,'manager',7))==='database_error'&&$db->memberships===[]);
$db=fresh();$db->fail['insert_membership']=true;ok('create insert failure rolls back',code(PGE_Event_Access_Repository::create_membership(10,20,'manager',7))==='database_error'&&$db->memberships===[]&&$db->audits===[]);
$db=fresh();$db->result_override[2]=null;ok('create reread failure rolls back',code(PGE_Event_Access_Repository::create_membership(10,20,'manager',7))==='database_error'&&$db->memberships===[]);
$db=fresh();$db->fail['insert_audit']=true;ok('create audit failure rolls back',code(PGE_Event_Access_Repository::create_membership(10,20,'manager',7))==='database_error'&&$db->memberships===[]&&$db->audits===[]);
$db=fresh();$db->fail['commit']=true;ok('create commit failure rolls back',code(PGE_Event_Access_Repository::create_membership(10,20,'manager',7))==='database_error'&&$db->memberships===[]&&$db->audits===[]);
$db=fresh();$one=PGE_Event_Access_Repository::create_membership(10,20,'manager',7);$two=PGE_Event_Access_Repository::create_membership(10,20,'manager',8);ok('serialized create create yields one membership',is_array($one)&&code($two)==='duplicate_membership'&&count($db->memberships)===1);

$db=fresh([w2_member()]);$r=PGE_Event_Access_Repository::change_membership_role(10,1,'manager','viewer',7);ok('role manager to viewer',is_array($r)&&$r['membership']['role']==='viewer'&&$db->audits[0]['action']==='membership_role_changed'&&$db->audits[0]['metadata']===['previous_role'=>'manager','new_role'=>'viewer']);
$db=fresh([w2_member(1,20,'viewer')]);$r=PGE_Event_Access_Repository::change_membership_role(10,1,'viewer','manager',7);ok('role viewer to manager',is_array($r)&&$r['membership']['role']==='manager');
$db=fresh([w2_member()]);$r=PGE_Event_Access_Repository::change_membership_role(10,1,'manager','manager',7);ok('same role no-op',is_array($r)&&!$r['changed']&&$db->audits===[]);
$db=fresh([w2_member()]);ok('stale role is concurrent_update',code(PGE_Event_Access_Repository::change_membership_role(10,1,'viewer','manager',7))==='concurrent_update');
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')]);ok('role change rejects revoked membership',code(PGE_Event_Access_Repository::change_membership_role(10,1,'manager','viewer',7))==='invalid_state');
$db=fresh([w2_member()]);unset($GLOBALS['w2_users'][20]);ok('role change requires surviving target user',code(PGE_Event_Access_Repository::change_membership_role(10,1,'manager','viewer',7))==='not_found');
$db=fresh([w2_member()]);$db->next['change_role']=[0];ok('role affected zero is concurrent_update',code(PGE_Event_Access_Repository::change_membership_role(10,1,'manager','viewer',7))==='concurrent_update');
$db=fresh([w2_member()]);$db->next['change_role']=[2];ok('role affected greater than one is database_error',code(PGE_Event_Access_Repository::change_membership_role(10,1,'manager','viewer',7))==='database_error');
$db=fresh([w2_member()]);$db->fail['change_role']=true;ok('role mutation failure rolls back',code(PGE_Event_Access_Repository::change_membership_role(10,1,'manager','viewer',7))==='database_error'&&$db->memberships[1]['role']==='manager');
$db=fresh([w2_member()]);$db->fail['insert_audit']=true;ok('role audit failure restores role',code(PGE_Event_Access_Repository::change_membership_role(10,1,'manager','viewer',7))==='database_error'&&$db->memberships[1]['role']==='manager'&&$db->audits===[]);

$db=fresh([w2_member()]);$r=PGE_Event_Access_Repository::revoke_membership(10,1,7);ok('revoke active without access',is_array($r)&&$r['membership']['status']==='revoked'&&$db->access===[]);
$db=fresh([w2_member()],[w2_group(),w2_group(2)],[w2_access(),w2_access(2,1,2)]);$r=PGE_Event_Access_Repository::revoke_membership(10,1,7);ok('revoke deletes all access atomically',is_array($r)&&$db->access===[]&&$db->audits[0]['metadata']['revoked_group_access_count']===2);
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')]);$r=PGE_Event_Access_Repository::revoke_membership(10,1,7);ok('revoke revoked no-op',is_array($r)&&!$r['changed']&&$db->audits===[]);
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')],[w2_group()],[w2_access()]);$r=PGE_Event_Access_Repository::revoke_membership(10,1,7);ok('revoke revoked cleans stale access',is_array($r)&&$r['changed']&&$db->access===[]&&$db->audits[0]['metadata']['status_changed']===false);
$db=fresh([w2_member()],[w2_group()],[w2_access()]);unset($GLOBALS['w2_users'][20]);ok('revoke membership permits deleted user',is_array(PGE_Event_Access_Repository::revoke_membership(10,1,7))&&$db->access===[]);
$db=fresh([w2_member()],[w2_group()],[w2_access()]);$db->next['delete_membership_access']=[0];ok('revoke detects access delete mismatch',code(PGE_Event_Access_Repository::revoke_membership(10,1,7))==='concurrent_update'&&$db->memberships[1]['status']==='active'&&count($db->access)===1);
$db=fresh([w2_member()],[w2_group()],[w2_access()]);$db->fail['insert_audit']=true;ok('revoke audit rollback restores membership and all access',code(PGE_Event_Access_Repository::revoke_membership(10,1,7))==='database_error'&&$db->memberships[1]['status']==='active'&&count($db->access)===1&&$db->audits===[]);

foreach(['manager','viewer']as$role){$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')]);$r=PGE_Event_Access_Repository::reactivate_membership(10,1,$role,7);ok("reactivate as $role",is_array($r)&&$r['membership']['status']==='active'&&$r['membership']['role']===$role&&$r['membership']['activated_at']==='2026-08-15 12:00:00'&&$db->audits[0]['metadata']===['previous_role'=>'manager','new_role'=>$role]);}
$db=fresh([w2_member()]);$r=PGE_Event_Access_Repository::reactivate_membership(10,1,'manager',7);ok('reactivate active same role no-op',is_array($r)&&!$r['changed']);
$db=fresh([w2_member()]);ok('reactivate active different role invalid_state',code(PGE_Event_Access_Repository::reactivate_membership(10,1,'viewer',7))==='invalid_state');
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')],[w2_group()],[w2_access()]);ok('reactivate refuses latent access',code(PGE_Event_Access_Repository::reactivate_membership(10,1,'manager',7))==='database_error');
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')]);unset($GLOBALS['w2_users'][20]);ok('reactivate requires target user',code(PGE_Event_Access_Repository::reactivate_membership(10,1,'manager',7))==='not_found');
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')]);$db->fail['insert_audit']=true;ok('reactivation audit failure restores revoked lifecycle',code(PGE_Event_Access_Repository::reactivate_membership(10,1,'viewer',7))==='database_error'&&$db->memberships[1]['status']==='revoked'&&$db->memberships[1]['activated_at']==='2026-08-14 01:00:00'&&$db->access===[]);

foreach(['manager','viewer']as$role){$db=fresh([w2_member(1,20,$role)],[w2_group()]);$r=PGE_Event_Access_Repository::grant_group_access(10,1,1,7);ok("grant to $role",is_array($r)&&$r['has_access']&&count($db->access)===1&&$db->audits[0]['action']==='group_access_granted'&&$db->audits[0]['entity_type']==='group_access'&&$db->audits[0]['metadata']===['membership_id'=>1,'group_id'=>1]);}
$db=fresh([w2_member()],[w2_group()],[w2_access()]);$r=PGE_Event_Access_Repository::grant_group_access(10,1,1,7);ok('grant existing no-op',is_array($r)&&!$r['changed']&&count($db->access)===1&&$db->audits===[]);
$db=fresh([w2_member()],[w2_group(1,'archived')]);ok('grant archived invalid_state',code(PGE_Event_Access_Repository::grant_group_access(10,1,1,7))==='invalid_state');
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')],[w2_group()]);ok('grant revoked invalid_state',code(PGE_Event_Access_Repository::grant_group_access(10,1,1,7))==='invalid_state');
$db=fresh([w2_member()],[w2_group()]);unset($GLOBALS['w2_users'][20]);ok('grant requires target user',code(PGE_Event_Access_Repository::grant_group_access(10,1,1,7))==='not_found');
$cross=w2_member();$cross['event_id']='11';$db=fresh([$cross],[w2_group()]);ok('grant does not resolve cross-event membership',code(PGE_Event_Access_Repository::grant_group_access(10,1,1,7))==='not_found');
$db=fresh([w2_member()],[w2_group()]);$db->result_override[1]=[w2_group(1,'active',11)];ok('unexpected cross-event locked group fails as database_error',code(PGE_Event_Access_Repository::grant_group_access(10,1,1,7))==='database_error');
$bad_access=w2_access();$bad_access['event_id']='11';$db=fresh([w2_member()],[w2_group()]);$db->result_override[3]=[$bad_access];ok('unexpected cross-event locked relation fails as database_error',code(PGE_Event_Access_Repository::grant_group_access(10,1,1,7))==='database_error');
$db=fresh([w2_member()],[w2_group()]);$db->insert_id=99;$db->next['insert_access']=[1];ok('grant rejects stale access insert id',code(PGE_Event_Access_Repository::grant_group_access(10,1,1,7))==='database_error'&&$db->access===[]);
$db=fresh([w2_member()],[w2_group()]);$db->fail['insert_audit']=true;ok('grant audit failure rolls relation back',code(PGE_Event_Access_Repository::grant_group_access(10,1,1,7))==='database_error'&&$db->access===[]&&$db->audits===[]);

$db=fresh([w2_member()],[w2_group()],[w2_access()]);$r=PGE_Event_Access_Repository::revoke_group_access(10,1,1,7);ok('revoke access exact success',is_array($r)&&!$r['has_access']&&$db->access===[]&&$db->audits[0]['action']==='group_access_revoked'&&$db->audits[0]['entity_id']===1&&$db->audits[0]['metadata']===['membership_id'=>1,'group_id'=>1]);
$db=fresh([w2_member()],[w2_group()]);$r=PGE_Event_Access_Repository::revoke_group_access(10,1,1,7);ok('revoke missing access no-op',is_array($r)&&!$r['changed']&&$db->audits===[]);
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')],[w2_group(1,'archived')],[w2_access()]);unset($GLOBALS['w2_users'][20]);$r=PGE_Event_Access_Repository::revoke_group_access(10,1,1,7);ok('revoke access permits inactive endpoints and deleted user',is_array($r)&&$db->access===[]);
$db=fresh([w2_member()],[w2_group()],[w2_access()]);$db->next['delete_access']=[0];ok('revoke access affected zero is concurrent_update',code(PGE_Event_Access_Repository::revoke_group_access(10,1,1,7))==='concurrent_update'&&count($db->access)===1);
$db=fresh([w2_member()],[w2_group()],[w2_access()]);$db->next['delete_access']=[2];ok('revoke access affected greater than one is database_error',code(PGE_Event_Access_Repository::revoke_group_access(10,1,1,7))==='database_error'&&count($db->access)===1);
$db=fresh([w2_member()],[w2_group()],[w2_access()]);$db->fail['delete_access']=true;ok('revoke access false is database_error',code(PGE_Event_Access_Repository::revoke_group_access(10,1,1,7))==='database_error'&&count($db->access)===1);
$db=fresh([w2_member()],[w2_group()],[w2_access()]);$db->next['delete_access']=[1];ok('revoke access post-delete reread detects surviving row',code(PGE_Event_Access_Repository::revoke_group_access(10,1,1,7))==='database_error'&&count($db->access)===1);
$db=fresh([w2_member()],[w2_group(1,'active',11)],[w2_access()]);ok('revoke access does not resolve cross-event group',code(PGE_Event_Access_Repository::revoke_group_access(10,1,1,7))==='not_found'&&count($db->access)===1);

$db=fresh([w2_member()],[w2_group()],[w2_access()]);$db->fail['insert_audit']=true;$r=PGE_Event_Access_Repository::revoke_group_access(10,1,1,7);ok('audit failure restores deleted access',code($r)==='database_error'&&count($db->access)===1&&$db->audits===[]);
$db=fresh([w2_member()],[w2_group()]);$first=PGE_Event_Access_Repository::grant_group_access(10,1,1,7);$second=PGE_Event_Access_Repository::grant_group_access(10,1,1,8);ok('serialized grant grant leaves one relation',is_array($first)&&is_array($second)&&!$second['changed']&&count($db->access)===1);
$db=fresh([w2_member()],[w2_group()]);PGE_Event_Access_Repository::grant_group_access(10,1,1,7);$rev=PGE_Event_Access_Repository::revoke_membership(10,1,8);ok('grant then revoke membership leaves no latent access',is_array($rev)&&$db->access===[]);
$db=fresh([w2_member()],[w2_group()]);PGE_Event_Access_Repository::grant_group_access(10,1,1,7);$db->groups[1]['status']='archived';$db->groups[1]['name_key']=null;$db->groups[1]['archived_at']='2026-08-15 12:00:00';ok('grant then archive preserves stored relation',count($db->access)===1);
$db=fresh([w2_member()],[w2_group()]);PGE_Event_Access_Repository::grant_group_access(10,1,1,7);$gone=PGE_Event_Access_Repository::revoke_group_access(10,1,1,8);ok('grant then revoke access leaves no relation',is_array($gone)&&$db->access===[]);
$db=fresh([w2_member()],[w2_group()]);PGE_Event_Access_Repository::change_membership_role(10,1,'manager','viewer',7);$gone=PGE_Event_Access_Repository::revoke_membership(10,1,8);ok('role change then revoke preserves role and removes grants',is_array($gone)&&$db->memberships[1]['role']==='viewer'&&$db->memberships[1]['status']==='revoked'&&$db->access===[]);
$db=fresh([w2_member(1,20,'manager','revoked','2026-08-14 02:00:00')],[w2_group()]);PGE_Event_Access_Repository::reactivate_membership(10,1,'manager',7);$gr=PGE_Event_Access_Repository::grant_group_access(10,1,1,8);ok('reactivate then grant succeeds',is_array($gr)&&$gr['has_access']);
$db=fresh([w2_member()],[w2_group()]);PGE_Event_Access_Repository::grant_group_access(10,1,1,7);$joined=implode("\n",$db->sql);ok('grant lock order is groups then memberships then exact access',strpos($joined,'pge_event_invitation_groups')<strpos($joined,'pge_event_host_memberships')&&strpos($joined,'pge_event_host_memberships')<strpos($joined,'pge_event_host_group_access'));
$db=fresh([w2_member()],[w2_group()],[w2_access()]);PGE_Event_Access_Repository::revoke_membership(10,1,7);$joined=implode("\n",$db->sql);ok('membership revoke lock order is memberships then access',strpos($joined,'pge_event_host_memberships')<strpos($joined,'pge_event_host_group_access'));

$source=file_get_contents(PGE_PATH.'includes/class-pge-event-access-repository.php');
ok('scope excludes auth account creation schema hooks and assignment writes',preg_match('/current_user_can|get_current_user_id|wp_create_user|wp_insert_user|add_action\s*\(|dbDelta\s*\(|maybe_upgrade\s*\(|wp_ajax|register_rest_route|update_post_meta|add_post_meta|delete_post_meta/i',$source)===0&&preg_match('/(?:INSERT|UPDATE|DELETE)[^\n]*pge_invitation_group_assignments/i',$source)===0);
ok('delete path uses scoped wpdb delete and never deletes groups or memberships',strpos($source,'$wpdb->delete($table, $where, $formats)')!==false&&preg_match('/delete_rows\(\$table[^\n]*groups|delete_rows\(\$table[^\n]*memberships/',$source)===0);
ok('transactions use no advisory or post-row locks',stripos($source,'GET_LOCK')===false&&preg_match('/wp_posts[^\n]*FOR UPDATE|FOR UPDATE[^\n]*wp_posts/i',$source)===0);
ok('repository never derives actor from current user',$GLOBALS['w2_current']===0&&$GLOBALS['w2_upgrade']===0);
echo"\nH1B-W2: $pass/".($pass+$fail)." passed\n";exit($fail?1:0);
