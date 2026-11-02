<?php
declare(strict_types=1);
namespace SafariLink;
final class Totp {
 public static function base32(string $bytes):string {$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($bytes) as $c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5) as $group)$out.=$alphabet[bindec(str_pad($group,5,'0'))];return $out;}
 public static function decode(string $secret):string {$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split(strtoupper($secret)) as $c){$n=strpos($alphabet,$c);if($n===false)throw new \InvalidArgumentException('Invalid MFA secret.');$bits.=str_pad(decbin($n),5,'0',STR_PAD_LEFT);}$out='';foreach(str_split($bits,8) as $group)if(strlen($group)===8)$out.=chr(bindec($group));return $out;}
 public static function code(string $secret,int $counter):string {$h=hash_hmac('sha1',pack('NN',intdiv($counter,4294967296),$counter%4294967296),self::decode($secret),true);$offset=ord($h[19])&15;$value=unpack('N',substr($h,$offset,4))[1]&0x7fffffff;return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);}
 public static function verify(string $secret,string $code,?int $last=null,?int $time=null):?int {if(!preg_match('/^\d{6}$/',$code))return null;$counter=intdiv($time??time(),30);foreach([$counter,$counter-1,$counter+1] as $c)if(($last===null||$c>$last)&&hash_equals(self::code($secret,$c),$code))return $c;return null;}
}
final class Secrets {
 public static function key():string {$key=base64_decode(config('APP_KEY',''),true);if($key===false||strlen($key)!==32)throw new \RuntimeException('Configure APP_KEY with 32 random base64-encoded bytes before staff sign-in.');return $key;}
 public static function encrypt(string $value):string {$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new \RuntimeException('Encryption failed.');return base64_encode($iv.$tag.$cipher);}
 public static function decrypt(string $value):string {$v=base64_decode($value,true);if($v===false||strlen($v)<29)throw new \RuntimeException('Secret unavailable.');$plain=openssl_decrypt(substr($v,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($v,0,12),substr($v,12,16));if($plain===false)throw new \RuntimeException('Secret unavailable.');return $plain;}
}
final class Auth {
 public static function login(string $identity,string $password):void {
  rateLimit('login',20,15);$identity=strtolower(trim($identity));if(!str_contains($identity,'@')){try{$identity=phone($identity);}catch(\InvalidArgumentException $e){$identity='invalid';}}
  $u=q('SELECT * FROM users WHERE email=? OR phone=? LIMIT 1',[$identity,$identity])->fetch();$hash=$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
  if(!password_verify($password,$hash)||!$u||!$u['active'])throw new \InvalidArgumentException('Invalid sign-in details.');
  session_regenerate_id(true);unset($_SESSION['uid'],$_SESSION['pending_uid']);
  if($u['role']==='passenger')self::complete($u);else {Secrets::key();$_SESSION['pending_uid']=(int)$u['id'];$_SESSION['pending_at']=time();if(!$u['mfa_secret'])$_SESSION['mfa_setup']=Totp::base32(random_bytes(20));}
 }
 public static function complete(array $u):void {session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];$_SESSION['auth_version']=(int)$u['auth_version'];$_SESSION['csrf']=secureToken();unset($_SESSION['pending_uid'],$_SESSION['mfa_setup'],$_SESSION['pending_at']);}
 public static function verifyMfa(string $code):void {
  rateLimit('mfa',10,10);$id=$_SESSION['pending_uid']??0;if(!$id||time()-($_SESSION['pending_at']??0)>300)throw new \InvalidArgumentException('Sign in again to verify MFA.');
  $u=tx(function()use($id,$code){$u=q('SELECT * FROM users WHERE id=? AND active=1 FOR UPDATE',[$id])->fetch();if(!$u)throw new \InvalidArgumentException('Account unavailable.');$secret=$u['mfa_secret']?Secrets::decrypt($u['mfa_secret']):($_SESSION['mfa_setup']??'');$counter=Totp::verify($secret,$code,$u['mfa_last_counter']!==null?(int)$u['mfa_last_counter']:null);if($counter===null)throw new \InvalidArgumentException('Invalid or previously used MFA code.');q('UPDATE users SET mfa_secret=?,mfa_last_counter=? WHERE id=?',[Secrets::encrypt($secret),$counter,$id]);audit((int)$id,'auth.mfa','user',(int)$id);return $u;});self::complete($u);
 }
 public static function register(array $data):void {
  rateLimit('register',8,60);$phone=phone($data['phone']);if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL)||strlen($data['password'])<12||mb_strlen($data['name'])<1||mb_strlen($data['name'])>120)throw new \InvalidArgumentException('Valid name/email and a password of at least 12 characters required.');q('INSERT INTO users(name,email,phone,password_hash) VALUES(?,?,?,?)',[$data['name'],strtolower($data['email']),$phone,password_hash($data['password'],PASSWORD_DEFAULT)]);
 }
 public static function requestReset(string $email):void {
  rateLimit('password-reset',5,60);if(config('APP_ENV','local')!=='local')throw new \RuntimeException('Reset delivery provider is not configured.');$u=q('SELECT id FROM users WHERE email=? AND active=1',[strtolower($email)])->fetch();if(!$u)return;
  // Local-only delivery is intentionally unavailable in production until a provider exists.
  if(config('APP_ENV','local')!=='local')throw new \RuntimeException('Reset delivery provider is not configured.');
  $token=secureToken();tx(function()use($u,$token){q('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$u['id']]);q('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,TIMESTAMPADD(MINUTE,15,UTC_TIMESTAMP()))',[$u['id'],hash('sha256',$token)]);});
  $path=ROOT.'/storage/private/reset-'.(int)$u['id'].'.txt';if(file_put_contents($path,'Local development reset token: '.$token,LOCK_EX)===false)throw new \RuntimeException('Local reset delivery failed.');chmod($path,0600);
 }
 public static function reset(string $token,string $password):void {
  rateLimit('reset-confirm',10,30);if(strlen($password)<12||!preg_match('/^[a-f0-9]{64}$/',$token))throw new \InvalidArgumentException('Invalid reset details or short password.');
  tx(function()use($token,$password){$r=q('SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE',[hash('sha256',$token)])->fetch();if(!$r)throw new \InvalidArgumentException('Reset token expired or unavailable.');q('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$r['user_id']]);q('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$r['user_id']]);audit((int)$r['user_id'],'auth.password_reset','user',(int)$r['user_id']);});
 }
}
