<?php
declare(strict_types=1);
namespace SafariLink;
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/bootstrap.php';
$command=$argv[1]??'help';
try{
 if($command==='key')echo base64_encode(random_bytes(32))."\n";
 elseif($command==='migrate'){
  q('CREATE TABLE IF NOT EXISTS migrations(name VARCHAR(190) PRIMARY KEY,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
  foreach(glob(ROOT.'/database/migrations/*.sql') as $file){$name=basename($file);if(q('SELECT 1 FROM migrations WHERE name=?',[$name])->fetchColumn())continue;foreach(explode(';',file_get_contents($file)) as $sql)if(trim($sql))db()->exec($sql);q('INSERT INTO migrations(name) VALUES(?)',[$name]);echo 'Applied '.$name."\n";}
  $map=['operations'=>['routes.write','fleet.write','trips.write','bookings.all','bookings.sell','reports.all','boarding.scan'],'dispatcher'=>['trips.write','bookings.all','boarding.scan'],'clerk'=>['bookings.sell','bookings.all'],'agent'=>['bookings.sell','bookings.agent','reports.agent'],'finance'=>['bookings.all','payments.record','refunds.manage','reports.all'],'boarding'=>['boarding.scan']];foreach($map as $role=>$ps)foreach($ps as $p)q('INSERT IGNORE INTO permissions VALUES(?,?)',[$role,$p]);
 }
 elseif($command==='staff'){
  $d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$roles=['super_admin','operations','dispatcher','clerk','agent','finance','boarding'];
  if(!in_array($d['role']??'', $roles,true)||!filter_var($d['email']??'',FILTER_VALIDATE_EMAIL)||strlen($d['password']??'')<12||trim($d['name']??'')==='')throw new \InvalidArgumentException('Valid staff JSON with name, email, phone, role and a 12+ character password required.');
  q('INSERT INTO users(name,email,phone,password_hash,role) VALUES(?,?,?,?,?)',[$d['name'],strtolower($d['email']),phone($d['phone']),password_hash($d['password'],PASSWORD_DEFAULT),$d['role']]);echo "Staff account created. MFA enrollment required at first sign-in.\n";
 }
 elseif($command==='worker')echo Worker::once()?"Processed one job.\n":"Queue empty.\n";
 elseif($command==='schedule'){
  $trips=q("SELECT DISTINCT trip_id FROM bookings WHERE status='held' AND hold_expires_at<=UTC_TIMESTAMP()")->fetchAll();foreach($trips as $t)tx(function()use($t){Inventory::lock((int)$t['trip_id']);Inventory::expire((int)$t['trip_id']);});echo "Expired holds released.\n";
 }
 elseif($command==='demo'){
  if(config('APP_ENV','local')!=='local')throw new \RuntimeException('Demo seed requires APP_ENV=local.');
  if((int)q('SELECT COUNT(*) FROM routes')->fetchColumn()!==0)throw new \RuntimeException('Demo seeding requires no existing routes.');
  tx(function(){q("INSERT INTO routes(name,distance_km,duration_minutes) VALUES('DEMO · Nairobi → Nakuru',160,180)");$route=(int)db()->lastInsertId();foreach([[0,'Nairobi','DEMO Central terminal',0],[1,'Naivasha','DEMO Main stop',100],[2,'Nakuru','DEMO Main terminal',180]] as $s)q('INSERT INTO stops(route_id,position,town,terminal,minutes_from_start) VALUES(?,?,?,?,?)',[$route,...$s]);foreach([[0,1,50000],[1,2,30000],[0,2,80000]] as $f)q('INSERT INTO fares(route_id,origin_position,destination_position,amount_cents) VALUES(?,?,?,?)',[$route,...$f]);q("INSERT INTO buses(registration,model,amenities) VALUES('DEMO-001','DEMO Coach','Demonstration bus; no actual service')");$bus=(int)db()->lastInsertId();q('INSERT INTO layouts(bus_id,version) VALUES(?,1)',[$bus]);$layout=(int)db()->lastInsertId();for($row=1;$row<=6;$row++)for($col=1;$col<=4;$col++)q('INSERT INTO layout_seats(layout_id,label,seat_row,column_number) VALUES(?,?,?,?)',[$layout,(string)(($row-1)*4+$col),$row,$col]);});
  echo "Demo route, fares and 24-seat bus created; schedule a dated trip in dispatch. No user or fake payment was created.\n";
 }
 elseif($command==='health'){
  q('SELECT 1');echo "Database: reachable\nPending jobs: ".q("SELECT COUNT(*) FROM jobs WHERE status='pending'")->fetchColumn()."\nFailed jobs: ".q("SELECT COUNT(*) FROM jobs WHERE status='failed'")->fetchColumn()."\nPayments: manual only; Daraja unavailable\nNotifications: local log only\n";Secrets::key();echo "Encryption key: configured\n";
 }
 else echo "Commands: key, migrate, staff (JSON stdin), demo, worker (one job), schedule, health\n";
}catch(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
