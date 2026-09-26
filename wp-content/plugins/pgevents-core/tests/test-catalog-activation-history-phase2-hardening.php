<?php
define('ABSPATH',__DIR__.'/');
$pass=0;$fail=0;$meta=[];$delete_fails=false;
function h2($label,$ok){global $pass,$fail;if($ok){$pass++;echo"PASS: $label\n";}else{$fail++;echo"FAIL: $label\n";}}
class WP_Error{private $c;function __construct($c,$m=''){$this->c=$c;}function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;} function absint($v){return abs((int)$v);} function sanitize_text_field($v){return trim((string)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function get_user_meta($u,$k,$single=true){global $meta;return $meta[$u][$k]??'';}
function metadata_exists($type,$u,$k){global $meta;return array_key_exists($k,$meta[$u]??[]);}
function update_user_meta($u,$k,$v){global $meta;$meta[$u][$k]=$v;return true;}
function delete_user_meta($u,$k){global $meta,$delete_fails;if($delete_fails)return false;unset($meta[$u][$k]);return true;}
function get_user_by($f,$id){return (object)['ID'=>$id];}
require_once dirname(__DIR__).'/includes/class-mon-events-users.php';

$projection=['meta'=>['_mon_package_source'=>'catalog','_mon_catalog_plan_id'=>2,'_mon_catalog_tier_id'=>3,'_mon_package_status'=>'active','_mon_credit_cycle_id'=>'cycle-a'],'credit_cycle'=>['id'=>'cycle-a','initial_used'=>['_mon_invitation_credit_used'=>0,'_mon_replacement_credit_used'=>0]],'delete'=>['_mon_last_order_id','_mon_package_deactivated_at']];
$meta[7]=['_mon_credit_cycle_id'=>'cycle-a','_mon_invitation_credit_used'=>4,'_mon_replacement_credit_used'=>2,'_mon_last_order_id'=>'old','_mon_package_deactivated_at'=>'old'];
$r=Mon_Events_Users::apply_catalog_activation_projection(7,$projection);
h2('same-cycle resume succeeds',$r===true);
h2('invitation consumption never decreases',$meta[7]['_mon_invitation_credit_used']===4);
h2('replacement consumption never decreases',$meta[7]['_mon_replacement_credit_used']===2);
h2('delete directives remove stale metadata',!isset($meta[7]['_mon_last_order_id'])&&!isset($meta[7]['_mon_package_deactivated_at']));
$meta[8]=['_mon_credit_cycle_id'=>'old','_mon_invitation_credit_used'=>9,'_mon_replacement_credit_used'=>8];
Mon_Events_Users::apply_catalog_activation_projection(8,$projection);
h2('fresh cycle initializes invitation usage',$meta[8]['_mon_invitation_credit_used']===0);
h2('fresh cycle initializes replacement usage',$meta[8]['_mon_replacement_credit_used']===0);
$meta[9]=['_mon_last_order_id'=>'stale'];$delete_fails=true;$r=Mon_Events_Users::apply_catalog_activation_projection(9,$projection);$delete_fails=false;
h2('failed deletion cannot report projection success',is_wp_error($r)&&$r->get_error_code()==='meta_delete_failed');
$before=$meta[10]??[];$r=Mon_Events_Users::activate_catalog_tier(10,2,3);
h2('contextless public activation is rejected',is_wp_error($r)&&$r->get_error_code()==='missing_activation_identity');
h2('contextless activation writes no metadata',($meta[10]??[])===$before);
echo"\n$pass passed, $fail failed\n";exit($fail?1:0);
