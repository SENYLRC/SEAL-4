<?php
// Test stand-in for the missing mysqli extension: routes queries to canned rows and logs every SQL.
define('MYSQLI_REPORT_ERROR',1); define('MYSQLI_REPORT_STRICT',2); define('MYSQLI_REPORT_OFF',0);
class mysqli { public $error=''; public $affected_rows=0; function set_charset($c){return true;} function query($q){return mysqli_query($this,$q);} function close(){return true;} }
class shim_result { public $rows; public $num_rows; function __construct($r){$this->rows=$r;$this->num_rows=count($r);} }
function shim_routes(){static $r=null;if($r===null){$f=getenv('SHIM_SCENARIO');$r=$f&&is_file($f)?json_decode(file_get_contents($f),true):[];}return $r;}
if(!class_exists('mysqli_sql_exception')){class mysqli_sql_exception extends RuntimeException{}}
function mysqli_connect(...$a){ if(getenv('SHIM_FAIL_DB')!==false && getenv('SHIM_FAIL_DB')===($a[3]??'')){ if(shim_strict()) throw new mysqli_sql_exception('No such file or directory'); return false; } return new mysqli();}
function mysqli_connect_error(){return '';}
function mysqli_select_db($db,$n){return true;}
$GLOBALS['__shim_report']=MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT; // PHP 8.1+ default: errors throw
function mysqli_report($m){$GLOBALS['__shim_report']=$m;return true;}
function shim_strict(){return ($GLOBALS['__shim_report'] & MYSQLI_REPORT_STRICT)!==0;}
function mysqli_close($db){return true;}
function mysqli_error($db){return $db->error??'';}
function mysqli_real_escape_string($db,$s){return addslashes((string)$s);}
function mysqli_affected_rows($db){return $db->affected_rows;}
function mysqli_num_rows($r){return $r?$r->num_rows:0;}
function mysqli_fetch_assoc($r){return $r&&$r->rows?array_shift($r->rows):null;}
function mysqli_fetch_array($r){return mysqli_fetch_assoc($r);}
function mysqli_fetch_row($r){$a=mysqli_fetch_assoc($r);return $a===null?null:array_values($a);}
function mysqli_query($db,$sql){
  file_put_contents(getenv('SHIM_LOG'),json_encode(['sql'=>preg_replace('/\s+/',' ',trim($sql))])."\n",FILE_APPEND);
  $is_write=(bool)preg_match('/^\s*(UPDATE|INSERT|DELETE)/i',$sql);
  foreach(shim_routes() as $route){
    if(preg_match('~'.$route['match'].'~is',$sql)){
      if(!empty($route['error'])){ if(shim_strict()) throw new mysqli_sql_exception($route['error']); $db->error=$route['error'];return false;}
      $db->affected_rows=$route['affected']??1;
      return $is_write?true:new shim_result($route['rows']??[]);
    }
  }
  $db->affected_rows=0;
  return $is_write?true:new shim_result([]);
}
// ILLiad API stand-in for ill_illiad_get() in cron_config.php: returns $FAKE_CURL_DIR/<last URL segment>.json, or {}.
if(getenv('FAKE_CURL_DIR')!==false){ function ill_illiad_get(string $url,string $apikey):string{ if($apikey==='')return ''; $f=getenv('FAKE_CURL_DIR').'/'.basename((string)parse_url($url,PHP_URL_PATH)).'.json'; return is_file($f)?(string)file_get_contents($f):'{}'; } }
