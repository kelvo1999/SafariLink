<?php
declare(strict_types=1);
namespace SafariLink;
final class Reports {
 public static function bounds(string $from,string $to):array {
  $zone=new \DateTimeZone('Africa/Nairobi');$a=\DateTimeImmutable::createFromFormat('!Y-m-d',$from,$zone);$b=\DateTimeImmutable::createFromFormat('!Y-m-d',$to,$zone);
  if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||$b<$a||$b>$a->modify('+366 days'))throw new \InvalidArgumentException('Use a valid date range of at most 367 days.');
  return [$a->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$b->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
 }
 public static function summary(array $actor,string $from,string $to):array {
  $all=can($actor,'reports.all');if(!$all&&!can($actor,'reports.agent'))throw new \InvalidArgumentException('Reporting permission required.');[$start,$end]=self::bounds($from,$to);$scope=$all?'':' AND b.sold_by=?';$params=$all?[$start,$end]:[$start,$end,$actor['id']];
  $charged=(int)q("SELECT COALESCE(SUM(b.total_cents),0) FROM bookings b WHERE b.status='confirmed' AND b.created_at>=? AND b.created_at<?".$scope,$params)->fetchColumn();
  $collected=(int)q('SELECT COALESCE(SUM(p.amount_cents),0) FROM payments p JOIN bookings b ON b.id=p.booking_id WHERE p.created_at>=? AND p.created_at<?'.$scope,$params)->fetchColumn();
  $refunded=(int)q("SELECT COALESCE(SUM(r.amount_cents),0) FROM refunds r JOIN bookings b ON b.id=r.booking_id WHERE r.status='completed' AND r.completed_at>=? AND r.completed_at<?".$scope,$params)->fetchColumn();
  return ['charged_cents'=>$charged,'collected_cents'=>$collected,'refunded_cents'=>$refunded,'net_cents'=>$collected-$refunded];
 }
 public static function occupancy(array $actor,string $from,string $to):array {
  if(!can($actor,'reports.all'))throw new \InvalidArgumentException('Global occupancy permission required.');[$start,$end]=self::bounds($from,$to);
  return q("SELECT t.id AS trip_id,s.position,s.town AS origin,d.town AS destination,(SELECT COUNT(*) FROM trip_seats ts WHERE ts.trip_id=t.id AND ts.blocked=0) AS sellable_seats,(SELECT COUNT(*) FROM segment_allocations a JOIN bookings b ON b.id=a.booking_id WHERE a.trip_id=t.id AND a.segment_position=s.position AND b.status='confirmed') AS occupied_seats FROM trips t JOIN trip_stops s ON s.trip_id=t.id JOIN trip_stops d ON d.trip_id=t.id AND d.position=s.position+1 WHERE t.departure_at>=? AND t.departure_at<? ORDER BY t.departure_at,t.id,s.position LIMIT 1000",[$start,$end])->fetchAll();
 }
 public static function csv(array $rows):void {
  header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="safarilink-report.csv"');$f=fopen('php://output','w');if(!$rows){fputcsv($f,['No records']);return;}fputcsv($f,array_keys($rows[0]));foreach($rows as $row){$safe=[];foreach($row as $v){$v=(string)$v;$safe[]=preg_match('/^[=+@\-\t\r]/',$v)?"'".$v:$v;}fputcsv($f,$safe);}fclose($f);
 }
}
