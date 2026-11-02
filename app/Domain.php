<?php
declare(strict_types=1);
namespace SafariLink;
final class Money {
 public static function parse(string $v):int{if(!preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/',$v,$m))throw new \InvalidArgumentException('Use a valid amount with up to two decimal places.');return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');}
 public static function format(int $v):string{return ($v<0?'-':'').intdiv(abs($v),100).'.'.str_pad((string)(abs($v)%100),2,'0',STR_PAD_LEFT);}
}
function phone(string $v):string {$v=preg_replace('/[\s()-]/','',$v);if(preg_match('/^0([17]\d{8})$/',$v,$m))return '+254'.$m[1];if(preg_match('/^254[17]\d{8}$/',$v))return '+'.$v;if(preg_match('/^\+254[17]\d{8}$/',$v))return $v;throw new \InvalidArgumentException('Enter a Kenyan mobile number, for example 0712345678.');}
function segmentPositions(int $from,int $to):array {if($from<0||$to<=$from||$to-$from>1000)throw new \InvalidArgumentException('Destination must follow origin.');return range($from,$to-1);}
function secureToken():string {return bin2hex(random_bytes(32));}
function rateLimit(string $scope,int $limit,int $minutes):void {$bucket=hash('sha256',($_SERVER['REMOTE_ADDR']??'cli').'|'.$scope);if((int)q('SELECT COUNT(*) FROM login_attempts WHERE bucket=? AND created_at>TIMESTAMPADD(MINUTE,?,UTC_TIMESTAMP())',[$bucket,-$minutes])->fetchColumn()>=$limit)throw new \InvalidArgumentException('Too many requests. Try later.');q('INSERT INTO login_attempts(bucket) VALUES(?)',[$bucket]);}
