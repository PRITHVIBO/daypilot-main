<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if (!is_file(__DIR__ . '/../vendor/autoload.php')) exit("Composer dependencies not installed.\n");
require __DIR__ . '/../vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$public=(string)cfg('push.public_key');
$private=(string)cfg('push.private_key');
$subject=(string)cfg('push.subject');
if($public===''||$private==='') exit("VAPID keys not configured; nothing to send.\n");

$lockFile=fopen(__DIR__.'/reminders.lock','c');
if(!$lockFile || !flock($lockFile,LOCK_EX|LOCK_NB)) exit("Another reminder worker is already running.\n");
register_shutdown_function(function() use($lockFile){flock($lockFile,LOCK_UN);fclose($lockFile);});

$pdo=db();
$st=$pdo->prepare('SELECT r.* FROM reminders r WHERE r.status="scheduled" AND r.remind_at<=NOW() ORDER BY r.remind_at LIMIT 100');
$st->execute();
$rows=$st->fetchAll();
if(!$rows)exit("No due reminders.\n");

$push=new WebPush(['VAPID'=>['subject'=>$subject,'publicKey'=>$public,'privateKey'=>$private]],['TTL'=>3600,'urgency'=>'normal']);
$queued=[];
foreach($rows as $r){
    $ps=$pdo->prepare('SELECT endpoint,p256dh,auth FROM push_subscriptions WHERE user_id=?');
    $ps->execute([$r['user_id']]);
    $subs=$ps->fetchAll();
    if(!$subs){
        $up=$pdo->prepare('UPDATE reminders SET status="sent",sent_at=? WHERE id=? AND status="scheduled"');
        $up->execute([now(),$r['id']]);
        continue;
    }
    foreach($subs as $s){
        try{
            $sub=Subscription::create(['endpoint'=>$s['endpoint'],'keys'=>['p256dh'=>$s['p256dh'],'auth'=>$s['auth']]]);
            $push->queueNotification($sub,json_encode(['title'=>'DayPilot reminder','body'=>$r['title'],'url'=>'/'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $queued[]=$r['id'];
        }catch(Throwable $e){error_log('[DayPilot Push] queue '.$e->getMessage());}
    }
}

$failed=false;
foreach($push->flush() as $report){
    if(!$report->isSuccess()){$failed=true;error_log('[DayPilot Push] '.$report->getReason());}
}
if(!$failed){
    $ids=array_values(array_unique($queued));
    if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$up=$pdo->prepare('UPDATE reminders SET status="sent",sent_at=? WHERE status="scheduled" AND id IN ('.$marks.')');$up->execute(array_merge([now()],$ids));}
}

echo 'Processed '.count($rows).' reminders.'.PHP_EOL;
