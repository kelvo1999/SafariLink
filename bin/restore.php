<?php
declare(strict_types=1);
namespace SafariLink;
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/bootstrap.php';
try{
 if(($argv[2]??'')!=='--empty-db-confirmed')throw new \InvalidArgumentException('Usage: php bin/restore.php trusted-backup.sql --empty-db-confirmed');
 if(q('SHOW TABLES')->fetchColumn())throw new \RuntimeException('Refusing restoration into a non-empty database.');
 $path=$argv[1]??'';if(!is_file($path)||!str_ends_with($path,'.sql'))throw new \InvalidArgumentException('Trusted .sql backup required.');
 $sql=file_get_contents($path);if(!str_starts_with($sql,'-- SafariLink trusted database backup.'))throw new \InvalidArgumentException('Not a SafariLink backup. Only restore trusted, integrity-checked backups.');
 // DDL is non-transactional. A failed restore must be investigated in this isolated target.
 db()->exec($sql);echo "Restoration completed. Validate counts, financial totals and authorization before use.\n";
}catch(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
