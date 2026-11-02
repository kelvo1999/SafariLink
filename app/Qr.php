<?php
declare(strict_types=1);
namespace SafariLink;
spl_autoload_register(function(string $class):void {
 $map=['BaconQrCode\\'=>ROOT.'/third_party/BaconQrCode/','DASPRiD\\Enum\\'=>ROOT.'/third_party/DASPRiDEnum/'];
 foreach($map as $prefix=>$root)if(str_starts_with($class,$prefix)){$file=$root.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;}
});
final class Qr {
 public static function svg(string $token):string {
  if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new \InvalidArgumentException('Invalid ticket token.');
  $matrix=\BaconQrCode\Encoder\Encoder::encode($token,\BaconQrCode\Common\ErrorCorrectionLevel::M())->getMatrix();$size=$matrix->getWidth()+8;$path='';for($y=0;$y<$matrix->getHeight();$y++)for($x=0;$x<$matrix->getWidth();$x++)if($matrix->get($x,$y))$path.='M'.($x+4).' '.($y+4).'h1v1h-1z';
  return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Secure boarding QR code" viewBox="0 0 '.$size.' '.$size.'" class="ticket-qr"><rect width="'.$size.'" height="'.$size.'" fill="white"/><path d="'.$path.'" fill="black"/></svg>';
 }
}
