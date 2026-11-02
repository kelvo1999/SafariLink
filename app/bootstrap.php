<?php
declare(strict_types=1);
namespace SafariLink;
const ROOT=__DIR__.'/..';
if(is_file(ROOT.'/.env'))foreach(file(ROOT.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){if(str_starts_with(trim($line),'#')||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);if(getenv(trim($k))===false)putenv(trim($k).'='.trim($v));}
date_default_timezone_set('UTC');ini_set('display_errors','0');ini_set('log_errors','1');
require_once __DIR__.'/Domain.php';
require_once __DIR__.'/Services.php';
require_once __DIR__.'/Operations.php';
require_once __DIR__.'/Auth.php';
require_once __DIR__.'/Rescheduling.php';
require_once __DIR__.'/Qr.php';
require_once __DIR__.'/Reports.php';
function db(): \PDO {static $db;if($db)return $db;$db=new \PDO(getenv('DB_DSN')?:'mysql:host=127.0.0.1;dbname=safarilink;charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASSWORD')?:'',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+00:00'");return $db;}
function q(string $sql,array $p=[]): \PDOStatement {$s=db()->prepare($sql);$s->execute($p);return $s;}
function tx(callable $fn): mixed {db()->beginTransaction();try{$result=$fn();db()->commit();return $result;}catch(\Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function now():string{return (string)q('SELECT UTC_TIMESTAMP()')->fetchColumn();}
function eat(?string $v):string {return $v?(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Nairobi'))->format('d M Y, H:i'):'—';}
function config(string $key,string $fallback):string {return getenv($key)!==false?(string)getenv($key):$fallback;}
function setting(string $name,string $fallback):string {$v=q('SELECT value FROM settings WHERE name=?',[$name])->fetchColumn();return $v===false?$fallback:(string)$v;}
function audit(?int $actor,string $action,string $entity,int $id):void {q('INSERT INTO audit_events(actor_id,action,entity_type,entity_id) VALUES(?,?,?,?)',[$actor,$action,$entity,$id]);}
function job(string $key,string $kind,array $payload):void {q('INSERT IGNORE INTO jobs(dedupe_key,kind,payload) VALUES(?,?,?)',[$key,$kind,json_encode($payload,JSON_THROW_ON_ERROR)]);}
function user():?array {if(!isset($_SESSION['uid']))return null;$u=q('SELECT id,name,email,phone,role,active,auth_version FROM users WHERE id=?',[$_SESSION['uid']])->fetch();if(!$u||!$u['active']||(int)$u['auth_version']!==($_SESSION['auth_version']??0)){unset($_SESSION['uid']);return null;}return $u;}
function can(?array $u,string $p):bool {return $u&&$u['active']&&($u['role']==='super_admin'||(bool)q('SELECT 1 FROM permissions WHERE role=? AND permission=?',[$u['role'],$p])->fetchColumn());}
function permit(?array $u,string $p):void {if(!can($u,$p)){http_response_code(403);exit('Permission denied.');}}
function owned(array $booking,?array $u):bool {
 if($u&&(int)$booking['user_id']===(int)$u['id'])return true;
 if(can($u,'bookings.all'))return true;
 if(can($u,'bookings.agent')&&(int)$booking['sold_by']===(int)$u['id'])return true;
 return isset($_SESSION['guest'][$booking['id']])&&hash_equals($booking['access_hash'],hash('sha256',$_SESSION['guest'][$booking['id']]));
}
function viewBooking(int $id,?array $u):array {$b=q('SELECT * FROM bookings WHERE id=?',[$id])->fetch();if(!$b||!owned($b,$u)){http_response_code(404);exit('Booking unavailable.');}return $b;}
function go(string $url):never{header('Location: '.$url);exit;}
function csrf():void {if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))){http_response_code(419);exit('Form expired. Reload and retry.');}}
