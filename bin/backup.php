<?php
declare(strict_types=1);
namespace SafariLink;
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/bootstrap.php';
try{
 $path=$argv[1]??'';if(!$path||!str_ends_with($path,'.sql'))throw new \InvalidArgumentException('Provide a new private .sql output path outside the web root.');
 $realParent=realpath(dirname($path));if(!$realParent||str_starts_with($realParent,realpath(ROOT.'/public')))throw new \InvalidArgumentException('Output must be outside the public web root.');
 $f=fopen($path,'xb');if(!$f)throw new \RuntimeException('Cannot create backup without overwriting an existing file.');chmod($path,0600);
 db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');db()->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
 fwrite($f,"-- SafariLink trusted database backup. Treat as confidential.\nSET FOREIGN_KEY_CHECKS=0;\nSET time_zone='+00:00';\n");
 foreach(q('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table){if(!preg_match('/^\w+$/',$table))throw new \RuntimeException('Unexpected table identifier.');$quoted='`'.$table.'`';$ddl=q('SHOW CREATE TABLE '.$quoted)->fetch(\PDO::FETCH_NUM)[1];fwrite($f,$ddl.";\n");foreach(q('SELECT * FROM '.$quoted)->fetchAll() as $row){$values=[];foreach($row as $v)$values[]=$v===null?'NULL':db()->quote((string)$v);fwrite($f,'INSERT INTO '.$quoted.' VALUES('.implode(',',$values).");\n");}}
 fwrite($f,"SET FOREIGN_KEY_CHECKS=1;\n");db()->commit();fclose($f);echo "Backup written. Keep the backup and APP_KEY protected separately.\n";
}catch(\Throwable $e){if(db()->inTransaction())db()->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
