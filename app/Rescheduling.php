<?php
declare(strict_types=1);
namespace SafariLink;
final class Rescheduling {
 public static function complete(int $old,int $new,array $actor):void {
  if(!can($actor,'bookings.all'))throw new \InvalidArgumentException('Operator authorization required.');
  $lookups=q('SELECT id,trip_id FROM bookings WHERE id IN (?,?)',[$old,$new])->fetchAll();if(count($lookups)!==2)throw new \InvalidArgumentException('Two different valid bookings are required.');
  tx(function()use($old,$new,$actor,$lookups){$trips=array_unique(array_map('intval',array_column($lookups,'trip_id')));sort($trips);foreach($trips as $trip){Inventory::lock($trip);Inventory::expire($trip);}
   $records=q('SELECT * FROM bookings WHERE id IN (?,?) ORDER BY id FOR UPDATE',[$old,$new])->fetchAll();$byId=[];foreach($records as $b)$byId[$b['id']]=$b;$a=$byId[$old];$b=$byId[$new];
   if($a['status']==='rescheduled'&&q('SELECT 1 FROM reschedule_transfers WHERE old_booking_id=? AND new_booking_id=?',[$old,$new])->fetchColumn())return;
   if($a['status']!=='confirmed'||!in_array($b['status'],['held','confirmed'],true)||$a['contact_phone']!==$b['contact_phone'])throw new \InvalidArgumentException('Original must be confirmed; replacement must be held/confirmed for the same contact.');
   $start=q('SELECT scheduled_at FROM trip_stops WHERE trip_id=? AND position=?',[$a['trip_id'],$a['origin_position']])->fetchColumn();$policy=json_decode($a['policy_snapshot'],true,512,JSON_THROW_ON_ERROR);if((new \DateTimeImmutable($start))->modify('-'.(int)$policy['cancel_before_minutes'].' minutes')->format('Y-m-d H:i:s')<=now())throw new \InvalidArgumentException('Rescheduling deadline passed.');
   if(q("SELECT 1 FROM tickets t JOIN passengers p ON p.id=t.passenger_id WHERE p.booking_id=? AND t.status='boarded'",[$old])->fetchColumn())throw new \InvalidArgumentException('Original travel already boarded.');
   $target=q('SELECT * FROM trips WHERE id=?',[$b['trip_id']])->fetch();if(!in_array($target['status'],['scheduled','delayed'],true)||$target['departure_at']<=now())throw new \InvalidArgumentException('Replacement trip unavailable.');
   $transfer=min((int)$a['paid_cents'],max(0,(int)$b['total_cents']-(int)$b['paid_cents']));$remaining=max(0,(int)$b['total_cents']-(int)$b['paid_cents']-$transfer);if($remaining>0)throw new \InvalidArgumentException('Record the fare difference of KES '.Money::format($remaining).' on the replacement first. Original remains valid.');
   $allocations=(int)q('SELECT COUNT(*) FROM segment_allocations WHERE booking_id=?',[$new])->fetchColumn();$expected=(int)q('SELECT COUNT(*) FROM passengers WHERE booking_id=?',[$new])->fetchColumn()*((int)$b['destination_position']-(int)$b['origin_position']);if(!$expected||$allocations!==$expected)throw new \InvalidArgumentException('Replacement seats are not secured. Original remains valid.');
   q('INSERT INTO reschedule_transfers(old_booking_id,new_booking_id,amount_cents) VALUES(?,?,?)',[$old,$new,$transfer]);q("UPDATE bookings SET paid_cents=paid_cents+?,status='confirmed',payment_status='paid',replacement_for=? WHERE id=?",[$transfer,$old,$new]);
   $surplus=(int)$a['paid_cents']-$transfer;q("UPDATE bookings SET status='rescheduled',paid_cents=?,payment_status=? WHERE id=?",[$surplus,$surplus>0?'refund_pending':'paid',$old]);q('DELETE FROM segment_allocations WHERE booking_id=?',[$old]);q("UPDATE tickets t JOIN passengers p ON p.id=t.passenger_id SET t.status='cancelled' WHERE p.booking_id=?",[$old]);
   if($surplus>0)q('INSERT INTO refunds(booking_id,amount_cents,fee_cents,requested_by) VALUES(?,?,0,?)',[$old,$surplus,$actor['id']]);job('tickets-'.$new,'tickets',['booking_id'=>$new]);job('reschedule-'.$old.'-'.$new,'notify',['booking_id'=>$new,'message'=>'Rescheduling completed. Old tickets are cancelled; replacement travel is confirmed.']);audit((int)$actor['id'],'booking.reschedule','booking',$old);
  });
 }
}
