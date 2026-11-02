<?php
declare(strict_types=1);
namespace SafariLink;
$dsn=getenv('SAFARILINK_TEST_DSN');if(!$dsn||!str_contains($dsn,'dbname=safarilink_test'))exit(2);putenv('DB_DSN='.$dsn);require __DIR__.'/../app/bootstrap.php';
try{$b=BookingService::hold((int)$argv[1],0,2,[['seat'=>'A1','name'=>'Race test']],['name'=>'Concurrent test','phone'=>'0712345678','email'=>'race@example.invalid'],null);echo 'ACQUIRED';}catch(\Throwable $e){echo 'REJECTED';}
