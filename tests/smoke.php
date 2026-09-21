<?php
declare(strict_types=1);
$required=['index.php','api.php','lib.php','config.example.php','database.sql','manifest.webmanifest','sw.js','assets/app.js','assets/styles.css','assets/icon-192.png','assets/icon-512.png','cron/reminders.php'];
foreach($required as $f){if(!is_file(__DIR__.'/../'.$f)){fwrite(STDERR,"Missing $f\n");exit(1);}}
$sql=file_get_contents(__DIR__.'/../database.sql');
foreach(['users','tasks','events','notes','reminders','push_subscriptions'] as $t){if(strpos($sql,'CREATE TABLE IF NOT EXISTS '.$t)===false){fwrite(STDERR,"Missing table $t\n");exit(1);}}
$manifest=json_decode(file_get_contents(__DIR__.'/../manifest.webmanifest'),true);
if(!is_array($manifest)||($manifest['display']??'')!=='standalone'){fwrite(STDERR,"Manifest invalid\n");exit(1);}
echo "DayPilot smoke checks passed.\n";
