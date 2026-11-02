<?php
declare(strict_types=1);
namespace SafariLink;
final class Inventory {
 // All mutation paths lock the trip first. This serializes overlapping inventory changes.
 public static function lock(int $trip):array {$t=q('SELECT * FROM trips WHERE id=? FOR UPDATE',[$trip])->fetch();if(!$t)throw new \InvalidArgumentException('Trip unavailable.');return $t;}
 public static function expire(int $trip):void {
  q("DELETE a FROM segment_allocations a JOIN bookings b ON b.id=a.booking_id WHERE b.trip_id=? AND b.status='held' AND b.hold_expires_at<=UTC_TIMESTAMP()",[$trip]);
  q("UPDATE bookings SET status='expired' WHERE trip_id=? AND status='held' AND hold_expires_at<=UTC_TIMESTAMP()",[$trip]);
 }
 public static function available(int $trip,int $from,int $to):array {segmentPositions($from,$to);return q("SELECT s.* FROM trip_seats s WHERE s.trip_id=? AND s.blocked=0 AND NOT EXISTS(SELECT 1 FROM segment_allocations a JOIN bookings b ON b.id=a.booking_id WHERE a.trip_id=s.trip_id AND a.seat_label=s.label AND a.segment_position>=? AND a.segment_position<? AND (b.status='confirmed' OR (b.status='held' AND b.hold_expires_at>UTC_TIMESTAMP()))) ORDER BY s.deck,s.seat_row,s.column_number",[$trip,$from,$to])->fetchAll();}
 public static function allocate(array $b):bool {
  $passengers=q('SELECT seat_label FROM passengers WHERE booking_id=? ORDER BY seat_label',[$b['id']])->fetchAll();$available=array_column(self::available((int)$b['trip_id'],(int)$b['origin_position'],(int)$b['destination_position']),'label');
  foreach($passengers as $p)if(!in_array($p['seat_label'],$available,true))return false;
  foreach($passengers as $p)foreach(segmentPositions((int)$b['origin_position'],(int)$b['destination_position']) as $seg)q('INSERT INTO segment_allocations(trip_id,seat_label,segment_position,booking_id) VALUES(?,?,?,?)',[$b['trip_id'],$p['seat_label'],$seg,$b['id']]);return true;
 }
}
final class BookingService {
 public static function hold(int $trip,int $from,int $to,array $passengers,array $contact,?array $actor,?int $replacement=null):array {
  segmentPositions($from,$to);if(count($passengers)<1||count($passengers)>8)throw new \InvalidArgumentException('Select one to eight passengers.');
  if(count(array_unique(array_column($passengers,'seat')))!==count($passengers))throw new \InvalidArgumentException('Each passenger needs a different seat.');
  $phone=phone($contact['phone']);if(!filter_var($contact['email'],FILTER_VALIDATE_EMAIL)||mb_strlen($contact['name'])<1||mb_strlen($contact['name'])>120)throw new \InvalidArgumentException('Valid contact details required.');
  foreach($passengers as $p)if(mb_strlen(trim($p['name']))<1||mb_strlen($p['name'])>120||!preg_match('/^[A-Za-z0-9-]{1,12}$/',$p['seat']))throw new \InvalidArgumentException('Valid passenger names and seat labels required.');
  return tx(function()use($trip,$from,$to,$passengers,$contact,$actor,$phone,$replacement){
   $t=Inventory::lock($trip);Inventory::expire($trip);
   if(!in_array($t['status'],['scheduled','delayed'],true)||$t['sales_close_at']<=now())throw new \InvalidArgumentException('Sales are closed for this trip.');
   if(!q('SELECT 1 FROM trip_stops WHERE trip_id=? AND position=?',[$trip,$from])->fetchColumn()||!q('SELECT 1 FROM trip_stops WHERE trip_id=? AND position=?',[$trip,$to])->fetchColumn())throw new \InvalidArgumentException('Stops unavailable.');
   $available=[];foreach(Inventory::available($trip,$from,$to) as $seat)$available[$seat['label']]=$seat;
   $total=0;$snapshot=[];foreach($passengers as $p){if(!isset($available[$p['seat']]))throw new \InvalidArgumentException('A selected seat is no longer available.');$class=$available[$p['seat']]['seat_class'];$fare=q('SELECT amount_cents FROM fares WHERE route_id=? AND origin_position=? AND destination_position=? AND seat_class=?',[$t['route_id'],$from,$to,$class])->fetchColumn();if($fare===false)throw new \InvalidArgumentException('No fare exists for this journey and class.');$total+=(int)$fare;$snapshot[]=['seat'=>$p['seat'],'class'=>$class,'amount_cents'=>(int)$fare];}
   $policy=['cancel_before_minutes'=>max(0,min(10080,(int)config('CANCEL_BEFORE_MINUTES','120'))),'cancellation_fee_cents'=>max(0,min(1000000000,(int)config('CANCEL_FEE_CENTS','0'))),'late_payment'=>'Reallocate original seats only; if unavailable create a resolution case.','reschedule'=>'Original remains valid until replacement is confirmed; fare difference handled by finance.'];
   $token=secureToken();$reference='SL-'.strtoupper(bin2hex(random_bytes(6)));$seconds=max(60,min(1800,(int)config('HOLD_SECONDS','600')));
   $channel=$actor&&$actor['role']==='agent'?'agent':($actor&&can($actor,'bookings.sell')?'counter':'web');
   q('INSERT INTO bookings(reference,access_hash,user_id,trip_id,origin_position,destination_position,contact_name,contact_phone,contact_email,hold_expires_at,total_cents,fare_snapshot,policy_snapshot,channel,sold_by,replacement_for) VALUES(?,?,?,?,?,?,?,?,?,TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP()),?,?,?,?,?,?)',[$reference,hash('sha256',$token),$actor&&$actor['role']==='passenger'?$actor['id']:null,$trip,$from,$to,$contact['name'],$phone,strtolower($contact['email']),$seconds,$total,json_encode($snapshot,JSON_THROW_ON_ERROR),json_encode($policy,JSON_THROW_ON_ERROR),$channel,$actor&&can($actor,'bookings.sell')?$actor['id']:null,$replacement]);
   $id=(int)db()->lastInsertId();foreach($passengers as $p)q('INSERT INTO passengers(booking_id,name,seat_label) VALUES(?,?,?)',[$id,trim($p['name']),$p['seat']]);
   $b=q('SELECT * FROM bookings WHERE id=?',[$id])->fetch();if(!Inventory::allocate($b))throw new \InvalidArgumentException('Seats are no longer available.');audit($actor?(int)$actor['id']:null,'booking.hold','booking',$id);return ['id'=>$id,'reference'=>$reference,'access_token'=>$token,'total_cents'=>$total];
  });
 }
 public static function cancel(int $id,?array $actor):void {
  $lookup=q('SELECT trip_id FROM bookings WHERE id=?',[$id])->fetch();if(!$lookup)throw new \InvalidArgumentException('Booking not found.');
  tx(function()use($id,$actor,$lookup){$trip=Inventory::lock((int)$lookup['trip_id']);Inventory::expire((int)$lookup['trip_id']);$b=q('SELECT * FROM bookings WHERE id=? FOR UPDATE',[$id])->fetch();if(!owned($b,$actor))throw new \InvalidArgumentException('Booking unavailable.');if(!in_array($b['status'],['held','confirmed','expired'],true))throw new \InvalidArgumentException('Booking cannot be cancelled.');
   $policy=json_decode($b['policy_snapshot'],true,512,JSON_THROW_ON_ERROR);$start=q('SELECT scheduled_at FROM trip_stops WHERE trip_id=? AND position=?',[$b['trip_id'],$b['origin_position']])->fetchColumn();$cutoff=(new \DateTimeImmutable($start))->modify('-'.(int)$policy['cancel_before_minutes'].' minutes')->format('Y-m-d H:i:s');
   if($b['status']==='confirmed'&&($cutoff<=now()||!in_array($trip['status'],['scheduled','delayed','boarding'],true)))throw new \InvalidArgumentException('Cancellation deadline has passed. Contact the operator.');
   if(q("SELECT 1 FROM tickets t JOIN passengers p ON p.id=t.passenger_id WHERE p.booking_id=? AND t.status='boarded'",[$id])->fetchColumn())throw new \InvalidArgumentException('Boarded tickets cannot be cancelled.');
   q('DELETE FROM segment_allocations WHERE booking_id=?',[$id]);q("UPDATE bookings SET status='cancelled' WHERE id=?",[$id]);q("UPDATE tickets t JOIN passengers p ON p.id=t.passenger_id SET t.status='cancelled' WHERE p.booking_id=?",[$id]);
   if((int)$b['paid_cents']>0){$fee=min((int)$b['paid_cents'],(int)$policy['cancellation_fee_cents']);q('INSERT INTO refunds(booking_id,amount_cents,fee_cents,requested_by) VALUES(?,?,?,?)',[$id,(int)$b['paid_cents']-$fee,$fee,$actor['id']??null]);q("UPDATE bookings SET payment_status='refund_pending' WHERE id=?",[$id]);}
   job('cancel-'.$id,'notify',['booking_id'=>$id,'message'=>'Booking cancelled. Any refund request is subject to finance approval and confirmed processing.']);audit($actor?(int)$actor['id']:null,'booking.cancel','booking',$id);
  });
 }
}
final class PaymentService {
 public static function manual(int $booking,int $amount,string $method,string $reference,array $actor):int {
  if(!can($actor,'payments.record'))throw new \InvalidArgumentException('Payment recording not permitted.');
  if($amount<1||!in_array($method,['cash','bank'],true)||!preg_match('/^[A-Za-z0-9_\/-]{3,100}$/',$reference))throw new \InvalidArgumentException('Use a positive verified payment and a unique reference.');
  $lookup=q('SELECT trip_id FROM bookings WHERE id=?',[$booking])->fetch();if(!$lookup)throw new \InvalidArgumentException('Booking not found.');
  return tx(function()use($booking,$amount,$method,$reference,$actor,$lookup){
   $trip=Inventory::lock((int)$lookup['trip_id']);Inventory::expire((int)$lookup['trip_id']);$b=q('SELECT * FROM bookings WHERE id=? FOR UPDATE',[$booking])->fetch();
   $old=q('SELECT * FROM payments WHERE provider=? AND provider_reference=?',[$method,$reference])->fetch();if($old){if((int)$old['booking_id']!==$booking||(int)$old['amount_cents']!==$amount)throw new \InvalidArgumentException('Transaction reference belongs to another payment.');return (int)$old['id'];}
   q('INSERT INTO payments(booking_id,provider,provider_reference,amount_cents,recorded_by) VALUES(?,?,?,?,?)',[$booking,$method,$reference,$amount,$actor['id']]);$pid=(int)db()->lastInsertId();$paid=(int)$b['paid_cents']+$amount;
   q('UPDATE bookings SET paid_cents=?,payment_status=? WHERE id=?',[$paid,$paid>=(int)$b['total_cents']?'paid':'partial',$booking]);
   if(in_array($b['status'],['cancelled','rescheduled','resolution'],true)||$trip['status']==='cancelled'){$reason='Payment received for a non-travelable booking.';self::resolution($booking,$pid,$reason);}
   elseif($paid>(int)$b['total_cents']){if($b['status']==='confirmed'){q('INSERT INTO resolution_cases(booking_id,payment_id,reason) VALUES(?,?,?)',[$booking,$pid,'Excess payment recorded against confirmed travel; finance review required.']);}else self::resolution($booking,$pid,'Overpayment recorded. Finance must resolve the excess; no automatic ticket issue.');}
   elseif($paid===(int)$b['total_cents']&&$b['status']!=='confirmed'){
    $usable=in_array($trip['status'],['scheduled','delayed','boarding'],true)&&$trip['departure_at']>now();
    if($b['status']==='expired'&&$usable)$usable=Inventory::allocate($b);
    if(!$usable)self::resolution($booking,$pid,'Late payment: original seats or trip are unavailable. Money retained for resolution/refund; no ticket issued.');
    else {q("UPDATE bookings SET status='confirmed' WHERE id=?",[$booking]);job('tickets-'.$booking,'tickets',['booking_id'=>$booking]);}
   }
   job('payment-'.$pid,'notify',['booking_id'=>$booking,'message'=>'Payment recorded: KES '.Money::format($amount).'; reference '.$reference.'. Travel eligibility is shown separately.']);audit((int)$actor['id'],'payment.record','payment',$pid);return $pid;
  });
 }
 private static function resolution(int $booking,int $payment,string $reason):void {q("UPDATE bookings SET status='resolution' WHERE id=?",[$booking]);q('DELETE FROM segment_allocations WHERE booking_id=?',[$booking]);q("UPDATE tickets t JOIN passengers p ON p.id=t.passenger_id SET t.status='cancelled' WHERE p.booking_id=? AND t.status<>'boarded'",[$booking]);q('INSERT INTO resolution_cases(booking_id,payment_id,reason) VALUES(?,?,?)',[$booking,$payment,$reason]);}
}
final class TicketService {
 public static function issue(int $booking):void {
  $b=q('SELECT * FROM bookings WHERE id=? FOR UPDATE',[$booking])->fetch();if(!$b||$b['status']!=='confirmed'||$b['payment_status']!=='paid')return;
  foreach(q('SELECT id FROM passengers WHERE booking_id=?',[$booking])->fetchAll() as $p){if(q('SELECT 1 FROM tickets WHERE passenger_id=?',[$p['id']])->fetchColumn())continue;$token=secureToken();q('INSERT INTO tickets(passenger_id,token_hash,token_value) VALUES(?,?,?)',[$p['id'],hash('sha256',$token),$token]);}
  job('confirmed-'.$booking,'notify',['booking_id'=>$booking,'message'=>'Your booking is confirmed. Retrieve your tickets securely from the booking page.']);
 }
 public static function board(string $token,int $trip,int $stop,array $actor):void {
  if(!can($actor,'boarding.scan'))throw new \InvalidArgumentException('Boarding permission required.');if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new \InvalidArgumentException('Ticket token invalid.');
  tx(function()use($token,$trip,$stop,$actor){$t=Inventory::lock($trip);if(!in_array($t['status'],['boarding','delayed'],true))throw new \InvalidArgumentException('Trip is not accepting boarding.');
   $ticket=q('SELECT t.*,p.booking_id,p.id AS passenger_id,b.trip_id,b.origin_position,b.status AS booking_status,b.payment_status FROM tickets t JOIN passengers p ON p.id=t.passenger_id JOIN bookings b ON b.id=p.booking_id WHERE t.token_hash=? FOR UPDATE',[hash('sha256',$token)])->fetch();
   if(!$ticket||(int)$ticket['trip_id']!==$trip||(int)$ticket['origin_position']!==$stop||$ticket['status']!=='valid'||$ticket['booking_status']!=='confirmed'||$ticket['payment_status']!=='paid')throw new \InvalidArgumentException('Ticket invalid, cancelled, already scanned, or wrong trip/boarding stop.');
   q('INSERT INTO boarding_events(ticket_id,actor_id,trip_id,stop_position) VALUES(?,?,?,?)',[$ticket['id'],$actor['id'],$trip,$stop]);q("UPDATE tickets SET status='boarded' WHERE id=?",[$ticket['id']]);q("UPDATE passengers SET boarding_status='boarded' WHERE id=?",[$ticket['passenger_id']]);audit((int)$actor['id'],'ticket.board','ticket',(int)$ticket['id']);
  });
 }
}
