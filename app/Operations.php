<?php
declare(strict_types=1);
namespace SafariLink;
final class Operations {
 public static function createTrip(int $route,int $bus,string $departure,array $crew,array $actor):int {
  if(!can($actor,'trips.write'))throw new \InvalidArgumentException('Trip management permission required.');
  $date=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$departure,new \DateTimeZone('Africa/Nairobi'));if(!$date)throw new \InvalidArgumentException('Invalid departure date.');$start=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  return tx(function()use($route,$bus,$start,$crew,$actor){
   $b=q('SELECT * FROM buses WHERE id=? FOR UPDATE',[$bus])->fetch();if(!$b||$b['status']!=='available')throw new \InvalidArgumentException('Bus unavailable.');
   $stops=q('SELECT * FROM stops WHERE route_id=? ORDER BY position',[$route])->fetchAll();if(count($stops)<2||array_column($stops,'position')!=range(0,count($stops)-1))throw new \InvalidArgumentException('Route needs consecutive stop positions starting at zero.');
   $end=(new \DateTimeImmutable($start))->modify('+'.(int)$stops[count($stops)-1]['minutes_from_start'].' minutes')->format('Y-m-d H:i:s');if($end<=$start||$start<=now())throw new \InvalidArgumentException('Trip timing invalid.');
   if(q("SELECT 1 FROM trips WHERE bus_id=? AND status<>'cancelled' AND departure_at<? AND arrival_at>?",[$bus,$end,$start])->fetchColumn())throw new \InvalidArgumentException('Bus has an overlapping trip.');
   $crew=array_unique(array_map('intval',$crew));sort($crew);foreach($crew as $id){if(!q('SELECT id FROM crew WHERE id=? AND active=1 FOR UPDATE',[$id])->fetchColumn())throw new \InvalidArgumentException('Crew unavailable.');if(q("SELECT 1 FROM trip_crew tc JOIN trips t ON t.id=tc.trip_id WHERE tc.crew_id=? AND t.status<>'cancelled' AND t.departure_at<? AND t.arrival_at>?",[$id,$end,$start])->fetchColumn())throw new \InvalidArgumentException('Crew has an overlapping assignment.');}
   $layout=q('SELECT id,version FROM layouts WHERE bus_id=? ORDER BY version DESC LIMIT 1',[$bus])->fetch();if(!$layout||!q('SELECT 1 FROM layout_seats WHERE layout_id=?',[$layout['id']])->fetchColumn())throw new \InvalidArgumentException('Seat layout required.');
   q('INSERT INTO trips(route_id,bus_id,departure_at,arrival_at,sales_close_at,layout_version) VALUES(?,?,?,?,TIMESTAMPADD(MINUTE,-30,?),?)',[$route,$bus,$start,$end,$start,$layout['version']]);$id=(int)db()->lastInsertId();
   foreach($stops as $s)q('INSERT INTO trip_stops(trip_id,position,town,terminal,scheduled_at) VALUES(?,?,?,?,TIMESTAMPADD(MINUTE,?,?))',[$id,$s['position'],$s['town'],$s['terminal'],$s['minutes_from_start'],$start]);
   q('INSERT INTO trip_seats(trip_id,label,deck,seat_row,column_number,seat_class,blocked) SELECT ?,label,deck,seat_row,column_number,seat_class,blocked FROM layout_seats WHERE layout_id=?',[$id,$layout['id']]);foreach($crew as $c)q('INSERT INTO trip_crew(trip_id,crew_id) VALUES(?,?)',[$id,$c]);audit((int)$actor['id'],'trip.create','trip',$id);return $id;
  });
 }
 public static function tripStatus(int $id,string $status,array $actor):void {
  if(!can($actor,'trips.write'))throw new \InvalidArgumentException('Dispatch permission required.');
  tx(function()use($id,$status,$actor){$t=Inventory::lock($id);$transitions=['scheduled'=>['boarding','delayed','cancelled'],'delayed'=>['boarding','cancelled'],'boarding'=>['departed','delayed','cancelled'],'departed'=>['completed'],'completed'=>[],'cancelled'=>[]];if(!in_array($status,$transitions[$t['status']],true))throw new \InvalidArgumentException('Invalid trip status transition.');q('UPDATE trips SET status=? WHERE id=?',[$status,$id]);
   if($status==='cancelled'){
    Inventory::expire($id);$bookings=q("SELECT * FROM bookings WHERE trip_id=? AND status IN ('held','confirmed') FOR UPDATE",[$id])->fetchAll();foreach($bookings as $b){q("UPDATE bookings SET status='cancelled',payment_status=IF(paid_cents>0,'refund_pending',payment_status) WHERE id=?",[$b['id']]);q('DELETE FROM segment_allocations WHERE booking_id=?',[$b['id']]);q("UPDATE tickets tk JOIN passengers p ON p.id=tk.passenger_id SET tk.status='cancelled' WHERE p.booking_id=?",[$b['id']]);if((int)$b['paid_cents']>0)q('INSERT IGNORE INTO refunds(booking_id,amount_cents,fee_cents,requested_by) VALUES(?,?,0,?)',[$b['id'],$b['paid_cents'],$actor['id']]);job('trip-cancel-'.$id.'-'.$b['id'],'notify',['booking_id'=>(int)$b['id'],'message'=>'Operator cancelled this trip. Any paid booking has a refund request awaiting confirmed processing.']);}
   }
   if($status==='completed'){q("UPDATE passengers p JOIN bookings b ON b.id=p.booking_id SET p.boarding_status='no_show' WHERE b.trip_id=? AND b.status='confirmed' AND p.boarding_status='not_boarded'",[$id]);q("UPDATE tickets tk JOIN passengers p ON p.id=tk.passenger_id JOIN bookings b ON b.id=p.booking_id SET tk.status='no_show' WHERE b.trip_id=? AND tk.status='valid'",[$id]);}
   audit((int)$actor['id'],'trip.status.'.$status,'trip',$id);
  });
 }
 public static function refund(int $id,string $action,string $reference,array $actor):void {
  if(!can($actor,'refunds.manage'))throw new \InvalidArgumentException('Finance approval required.');
  tx(function()use($id,$action,$reference,$actor){$r=q('SELECT * FROM refunds WHERE id=? FOR UPDATE',[$id])->fetch();if(!$r)throw new \InvalidArgumentException('Refund unavailable.');
   if($action==='approve'&&$r['status']==='requested'){if((int)$r['requested_by']===(int)$actor['id'])throw new \InvalidArgumentException('A different finance officer must approve this refund.');q("UPDATE refunds SET status='approved',approved_by=? WHERE id=?",[$actor['id'],$id]);}
   elseif($action==='complete'&&$r['status']==='approved'){
    if((int)$r['approved_by']===(int)$actor['id'])throw new \InvalidArgumentException('A different finance officer must verify processing.');if(!preg_match('/^[A-Za-z0-9_\/-]{3,100}$/',$reference))throw new \InvalidArgumentException('Verified external refund reference required.');q("UPDATE refunds SET status='completed',completed_at=UTC_TIMESTAMP(),processed_by=?,provider_reference=? WHERE id=?",[$actor['id'],$reference,$id]);q("UPDATE bookings SET payment_status='refunded' WHERE id=?",[$r['booking_id']]);job('refund-'.$id,'notify',['booking_id'=>(int)$r['booking_id'],'message'=>'Finance confirmed the refund. Reference '.$reference]);
   }else throw new \InvalidArgumentException('Refund transition unavailable.');audit((int)$actor['id'],'refund.'.$action,'refund',$id);
  });
 }
 public static function replaceBus(int $trip,int $bus,array $mapping,array $actor):void {
  if(!can($actor,'trips.write'))throw new \InvalidArgumentException('Dispatch permission required.');
  tx(function()use($trip,$bus,$mapping,$actor){$t=Inventory::lock($trip);Inventory::expire($trip);if(!in_array($t['status'],['scheduled','delayed'],true))throw new \InvalidArgumentException('Replace only before boarding.');$new=q("SELECT * FROM buses WHERE id=? AND status='available' FOR UPDATE",[$bus])->fetch();if(!$new)throw new \InvalidArgumentException('Replacement bus unavailable.');
   if(q("SELECT 1 FROM trips WHERE id<>? AND bus_id=? AND status<>'cancelled' AND departure_at<? AND arrival_at>?",[$trip,$bus,$t['arrival_at'],$t['departure_at']])->fetchColumn())throw new \InvalidArgumentException('Replacement bus has a conflicting trip.');
   $layout=q('SELECT * FROM layouts WHERE bus_id=? ORDER BY version DESC LIMIT 1',[$bus])->fetch();if(!$layout)throw new \InvalidArgumentException('Replacement layout missing.');$seats=q('SELECT * FROM layout_seats WHERE layout_id=?',[$layout['id']])->fetchAll();$valid=[];foreach($seats as $s)if(!$s['blocked'])$valid[$s['label']]=$s;
   $active=q("SELECT p.*,b.origin_position,b.destination_position FROM passengers p JOIN bookings b ON b.id=p.booking_id WHERE b.trip_id=? AND b.status IN ('held','confirmed') ORDER BY p.id",[$trip])->fetchAll();$used=[];
   foreach($active as $p){$target=$mapping[$p['seat_label']]??null;if(!$target||!isset($valid[$target]))throw new \InvalidArgumentException('Unresolved seat '.$p['seat_label'].'. Supply an explicit valid mapping; original trip unchanged.');foreach(segmentPositions((int)$p['origin_position'],(int)$p['destination_position']) as $seg){$key=$target.':'.$seg;if(isset($used[$key]))throw new \InvalidArgumentException('Replacement mapping overlaps or has insufficient capacity.');$used[$key]=true;}}
   // Remove old allocation FKs before replacing the trip snapshot; all changes commit together.
   q('DELETE FROM segment_allocations WHERE trip_id=?',[$trip]);q('DELETE FROM trip_seats WHERE trip_id=?',[$trip]);foreach($seats as $s)q('INSERT INTO trip_seats(trip_id,label,deck,seat_row,column_number,seat_class,blocked) VALUES(?,?,?,?,?,?,?)',[$trip,$s['label'],$s['deck'],$s['seat_row'],$s['column_number'],$s['seat_class'],$s['blocked']]);
   // Temporary passenger labels prevent unique collisions during swaps.
   foreach($active as $p)q('UPDATE passengers SET seat_label=? WHERE id=?',['~'.$p['id'],$p['id']]);foreach($active as $p){$target=$mapping[$p['seat_label']];q('UPDATE passengers SET seat_label=? WHERE id=?',[$target,$p['id']]);foreach(segmentPositions((int)$p['origin_position'],(int)$p['destination_position']) as $seg)q('INSERT INTO segment_allocations VALUES(?,?,?,?)',[$trip,$target,$seg,$p['booking_id']]);}
   q('UPDATE trips SET bus_id=?,layout_version=? WHERE id=?',[$bus,$layout['version'],$trip]);foreach(array_unique(array_column($active,'booking_id')) as $booking)job('bus-replace-'.$trip.'-'.$bus.'-'.$booking,'notify',['booking_id'=>(int)$booking,'message'=>'Your bus/seat assignment has changed. Retrieve the current ticket before travel.']);audit((int)$actor['id'],'trip.replace_bus','trip',$trip);
  });
 }
}
final class Worker {
 public static function once():bool {
  $job=tx(function(){q("UPDATE jobs SET status='pending',locked_at=NULL WHERE status='processing' AND locked_at<TIMESTAMPADD(MINUTE,-5,UTC_TIMESTAMP()) AND attempts<5");q("UPDATE jobs SET status='failed',last_error='Retry limit exceeded' WHERE status IN ('pending','processing') AND attempts>=5 AND (locked_at IS NULL OR locked_at<TIMESTAMPADD(MINUTE,-5,UTC_TIMESTAMP()))");$j=q("SELECT * FROM jobs WHERE status='pending' AND available_at<=UTC_TIMESTAMP() AND attempts<5 ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();if($j)q("UPDATE jobs SET status='processing',attempts=attempts+1,locked_at=UTC_TIMESTAMP() WHERE id=?",[$j['id']]);return $j;});if(!$job)return false;
  try{tx(function()use($job){$payload=json_decode($job['payload'],true,512,JSON_THROW_ON_ERROR);$b=q('SELECT * FROM bookings WHERE id=? FOR UPDATE',[$payload['booking_id']])->fetch();if(!$b)throw new \RuntimeException('Booking missing.');if($job['kind']==='tickets')TicketService::issue((int)$b['id']);elseif($job['kind']==='notify'){if(config('NOTIFICATION_DRIVER','local')!=='local')throw new \RuntimeException('Only local notification logging is configured.');q('INSERT IGNORE INTO notifications(job_id,booking_id,message) VALUES(?,?,?)',[$job['id'],$b['id'],$payload['message']]);}else throw new \RuntimeException('Unknown job.');q("UPDATE jobs SET status='done',locked_at=NULL,last_error=NULL WHERE id=?",[$job['id']]);});}
  catch(\Throwable $e){$attempt=(int)$job['attempts']+1;q("UPDATE jobs SET status=?,locked_at=NULL,last_error='Processing failed; inspect job ID',available_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP()) WHERE id=?",[$attempt>=5?'failed':'pending',min(1800,30*(2**$attempt)),$job['id']]);throw $e;}return true;
 }
}
