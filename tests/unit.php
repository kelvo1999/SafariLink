<?php
declare(strict_types=1);
namespace SafariLink;
require __DIR__.'/../app/Domain.php';
require __DIR__.'/../app/Auth.php';
$count=0;
function eq(mixed $a,mixed $b,string $label):void{global $count;if($a!==$b)throw new \RuntimeException('FAIL '.$label.': '.var_export($a,true));$count++;echo 'PASS '.$label."\n";}
eq(Money::parse('1200.05'),120005,'minor units');eq(Money::format(120005),'1200.05','format cents');eq(Money::format(-1),'-0.01','negative report amount');
foreach(['-1','1e3','1.001','01.20','1000000000000'] as $bad){try{Money::parse($bad);throw new \RuntimeException('Accepted invalid money');}catch(\InvalidArgumentException $e){$count++;}}
eq(phone('0712 345 678'),'+254712345678','Kenyan 07');eq(phone('0112345678'),'+254112345678','Kenyan 01');eq(phone('254712345678'),'+254712345678','international digits');
eq(segmentPositions(0,2),[0,1],'adjacent segments');eq(segmentPositions(1,2),[1],'second segment');
try{segmentPositions(2,1);throw new \RuntimeException('Accepted reversed journey');}catch(\InvalidArgumentException $e){$count++;}
$secret=Totp::base32('12345678901234567890');eq(Totp::decode($secret),'12345678901234567890','base32 roundtrip');eq(Totp::code($secret,0),'755224','RFC 4226 counter 0');eq(Totp::code($secret,1),'287082','RFC 4226 counter 1');eq(Totp::verify($secret,'287082',null,59),1,'TOTP verification');eq(Totp::verify($secret,'287082',1,59),null,'MFA replay rejected');
eq(strlen(secureToken()),64,'256-bit token');echo "$count unit checks passed.\n";
