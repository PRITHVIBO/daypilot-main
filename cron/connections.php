<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../lib.php';require __DIR__.'/../connections.php';
connections_schema();
$q=db()->query('SELECT user_id,settings FROM integration_settings');$ok=0;$failed=0;
foreach($q->fetchAll() as $r){$s=json_decode($r['settings'],true)?:[];if(empty($s['enabled']))continue;
 try{connections_refresh($r['user_id']);
   $tasks=db()->prepare('SELECT id,title,due_at FROM tasks WHERE user_id=? AND status="open" AND due_at<NOW() ORDER BY due_at LIMIT 20');$tasks->execute([$r['user_id']]);
   foreach($tasks->fetchAll() as $task)connections_alert($r['user_id'],'overdue:'.$task['id'].':'.$task['due_at'],'Overdue task: '.$task['title']);
   $ok++;}catch(Throwable $e){$failed++;error_log('[DayPilot scheduled connections] '.$e->getMessage());}}
echo json_encode(['accounts_checked'=>$ok,'accounts_failed'=>$failed]).PHP_EOL;
