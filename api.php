<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? 'boot';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($action === 'csrf') json_response(['csrf'=>csrf_token()]);
    if ($action === 'health' && $method === 'GET') { db()->query('SELECT 1'); json_response(['ok'=>true,'service'=>'daypilot','database'=>'ok','time'=>now()]); }
    if ($action === 'boot') {
        $u=user();
        json_response(['ok'=>true,'user'=>$u,'csrf'=>csrf_token(),'vapid_public_key'=>(string)cfg('push.public_key'),'features'=>['ai'=>(string)cfg('ai.api_key')!=='','push'=>(string)cfg('push.public_key')!=='','google_calendar'=>(string)cfg('google.client_id')!=='']]);
    }
    require_csrf();

    if ($action === 'register' && $method === 'POST') {
        $in=input_json();$email=strtolower(clean_text((string)($in['email']??''),190));$password=(string)($in['password']??'');$name=clean_text((string)($in['name']??'User'),120);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($password)<8) json_response(['error'=>'Use a valid email and a password of at least 8 characters.'],422);
        $pdo=db();$st=$pdo->prepare('SELECT id FROM users WHERE email=?');$st->execute([$email]);if($st->fetch())json_response(['error'=>'An account with this email already exists.'],409);
        $id=uuid();$t=now();$hash=password_hash($password,PASSWORD_ARGON2ID);$st=$pdo->prepare('INSERT INTO users(id,email,password_hash,name,timezone,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');$st->execute([$id,$email,$hash,$name,(string)cfg('app.timezone','Asia/Kolkata'),$t,$t]);
        $_SESSION['user_id']=$id;$_SESSION['csrf']=bin2hex(random_bytes(24));session_regenerate_id(true);log_activity('register');json_response(['ok'=>true,'user'=>user(),'csrf'=>csrf_token()]);
    }
    if ($action === 'login' && $method === 'POST') {
        $in=input_json();$email=strtolower(clean_text((string)($in['email']??''),190));$password=(string)($in['password']??'');$st=db()->prepare('SELECT * FROM users WHERE email=?');$st->execute([$email]);$row=$st->fetch();
        if(!$row || !password_verify($password,(string)$row['password_hash']))json_response(['error'=>'Invalid email or password.'],401);
        session_regenerate_id(true);$_SESSION['user_id']=$row['id'];$_SESSION['csrf']=bin2hex(random_bytes(24));log_activity('login');json_response(['ok'=>true,'user'=>user(),'csrf'=>csrf_token()]);
    }
    if ($action === 'logout' && $method === 'POST') { $_SESSION=[]; if(ini_get('session.use_cookies')){ $p=session_get_cookie_params(); setcookie(session_name(),'',['expires'=>time()-42000,'path'=>$p['path'],'secure'=>$p['secure'],'httponly'=>$p['httponly'],'samesite'=>$p['samesite']??'Lax']); } session_destroy();json_response(['ok'=>true]); }

    $u=require_user(); $uid=$u['id']; $pdo=db();

    if ($action === 'tasks' && $method === 'GET') json_response(['tasks'=>fetch_tasks($uid, isset($_GET['status'])?(string)$_GET['status']:null, (int)($_GET['limit']??200))]);
    if ($action === 'task_create' && $method === 'POST') {
        $in=input_json();$title=clean_text((string)($in['title']??''));if($title==='')json_response(['error'=>'Task title is required.'],422);$id=(string)($in['id']??uuid());$t=now();
        $st=$pdo->prepare('INSERT INTO tasks(id,user_id,title,description,priority,due_at,estimated_minutes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');$st->execute([$id,$uid,$title,$in['description']??null,normalize_priority((string)($in['priority']??'medium')),date_sql($in['due_at']??null),isset($in['estimated_minutes'])?(int)$in['estimated_minutes']:null,$t,$t]);log_activity('create_task','task',$id);json_response(['task'=>get_task($pdo,$uid,$id)]);
    }
    if ($action === 'task_update' && $method === 'POST') {
        $in = input_json();
        $id = clean_text((string)($in['id'] ?? ''), 36);
        if ($id === '') json_response(['error' => 'Task id is required.'], 422);

        $sets = [];
        $args = [];
        if (array_key_exists('title', $in)) {
            $title = clean_text((string)$in['title'], 255);
            if ($title === '') json_response(['error' => 'Task title cannot be empty.'], 422);
            $sets[]='title=?'; $args[]=$title;
        }
        if (array_key_exists('description', $in)) { $sets[]='description=?'; $args[]=(string)$in['description']; }
        if (array_key_exists('priority', $in)) { $sets[]='priority=?'; $args[]=normalize_priority((string)$in['priority']); }
        foreach (['due_at','start_at','end_at'] as $field) {
            if (array_key_exists($field, $in)) { $sets[]=$field.'=?'; $args[]=date_sql($in[$field] === null ? null : (string)$in[$field]); }
        }
        if (array_key_exists('estimated_minutes', $in)) { $sets[]='estimated_minutes=?'; $args[]=max(0, min(480, (int)$in['estimated_minutes'])); }
        if (array_key_exists('status', $in)) {
            $status=(string)$in['status'];
            if (!in_array($status,['open','done','archived'],true)) json_response(['error'=>'Invalid task status.'],422);
            $sets[]='status=?'; $args[]=$status;
            if ($status==='done') { $sets[]='completed_at=?'; $args[]=now(); }
            elseif ($status==='open') { $sets[]='completed_at=?'; $args[]=null; }
        }
        if (!$sets) json_response(['error' => 'No fields to update.'], 422);
        $sets[]='updated_at=?'; $args[]=now(); $args[]=$id; $args[]=$uid;
        $st=$pdo->prepare('UPDATE tasks SET '.implode(',',$sets).' WHERE id=? AND user_id=?');
        $st->execute($args);
        if ($st->rowCount()===0) json_response(['error'=>'Task not found or unchanged.'],404);
        log_activity('update_task','task',$id);
        json_response(['task'=>get_task($pdo,$uid,$id)]);
    }
    if ($action === 'task_done' && $method === 'POST') { $in=input_json();$id=(string)$in['id'];$st=$pdo->prepare('UPDATE tasks SET status="done",completed_at=?,updated_at=? WHERE id=? AND user_id=?');$st->execute([now(),now(),$id,$uid]);if(!$st->rowCount())json_response(['error'=>'Task not found.'],404);log_activity('complete_task','task',$id);json_response(['ok'=>true]); }
    if ($action === 'task_delete' && $method === 'POST') { $in=input_json();$id=(string)$in['id'];$st=$pdo->prepare('DELETE FROM tasks WHERE id=? AND user_id=?');$st->execute([$id,$uid]);if(!$st->rowCount())json_response(['error'=>'Task not found.'],404);log_activity('delete_task','task',$id);json_response(['ok'=>true]); }

    if ($action === 'events' && $method === 'GET') { $from=date_sql((string)($_GET['from']??date('Y-m-01 00:00:00')));$to=date_sql((string)($_GET['to']??date('Y-m-t 23:59:59')));json_response(['events'=>fetch_events($uid,$from,$to)]); }
    if ($action === 'event_create' && $method === 'POST') { $in=input_json();$title=clean_text((string)($in['title']??''));$start=date_sql((string)($in['start_at']??''));$end=date_sql((string)($in['end_at']??''));if($title===''||!$start||!$end||strtotime($end)<=strtotime($start))json_response(['error'=>'Enter a valid event title and time range.'],422);$id=(string)($in['id']??uuid());$t=now();$st=$pdo->prepare('INSERT INTO events(id,user_id,title,description,location,start_at,end_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');$st->execute([$id,$uid,$title,$in['description']??null,$in['location']??null,$start,$end,$t,$t]);log_activity('create_event','event',$id);json_response(['ok'=>true,'event_id'=>$id]); }
    if ($action === 'event_delete' && $method === 'POST') { $in=input_json();$st=$pdo->prepare('DELETE FROM events WHERE id=? AND user_id=?');$st->execute([(string)$in['id'],$uid]);json_response(['ok'=>true]); }

    if ($action === 'notes' && $method === 'GET') json_response(['notes'=>fetch_notes($uid)]);
    if ($action === 'note_create' && $method === 'POST') { $in=input_json();$title=clean_text((string)($in['title']??''));$content=(string)($in['content']??'');if($title===''||trim($content)==='')json_response(['error'=>'Title and content are required.'],422);$id=(string)($in['id']??uuid());$t=now();$st=$pdo->prepare('INSERT INTO notes(id,user_id,title,content,tags,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');$st->execute([$id,$uid,$title,$content,$in['tags']??null,$in['source']??'manual',$t,$t]);log_activity('create_note','note',$id);json_response(['ok'=>true,'note_id'=>$id]); }
    if ($action === 'note_update' && $method === 'POST') { $in=input_json();$id=(string)($in['id']??'');$title=clean_text((string)($in['title']??''));$content=(string)($in['content']??'');if(!$id||$title===''||trim($content)==='')json_response(['error'=>'Note id, title and content are required.'],422);$st=$pdo->prepare('UPDATE notes SET title=?,content=?,tags=?,updated_at=? WHERE id=? AND user_id=?');$st->execute([$title,$content,$in['tags']??null,now(),$id,$uid]);if(!$st->rowCount())json_response(['error'=>'Note not found or unchanged.'],404);log_activity('update_note','note',$id);json_response(['ok'=>true]); }
    if ($action === 'note_delete' && $method === 'POST') { $in=input_json();$st=$pdo->prepare('DELETE FROM notes WHERE id=? AND user_id=?');$st->execute([(string)$in['id'],$uid]);json_response(['ok'=>true]); }

    if ($action === 'plan_today' && $method === 'POST') { $in=input_json();$date=preg_replace('/[^0-9-]/','',(string)($in['date']??date('Y-m-d')));$blocks=plan_day($uid,$date);log_activity('plan_today');json_response(['date'=>$date,'blocks'=>$blocks]); }
    if ($action === 'analytics' && $method === 'GET') json_response(analytics_data($uid,(int)($_GET['days']??30)));

    if ($action === 'reminders' && $method === 'GET') { $st=$pdo->prepare('SELECT id,title,remind_at,status,task_id,event_id FROM reminders WHERE user_id=? ORDER BY remind_at LIMIT 100');$st->execute([$uid]);json_response(['reminders'=>$st->fetchAll()]); }
    if ($action === 'reminder_create' && $method === 'POST') {
        $in=input_json();
        $when=date_sql((string)($in['remind_at']??''));
        if(!$when||strtotime($when)<time()+30) json_response(['error'=>'Reminder time must be at least 30 seconds in the future.'],422);
        $taskId=isset($in['task_id'])&&$in['task_id']!==''?(string)$in['task_id']:null;
        $eventId=isset($in['event_id'])&&$in['event_id']!==''?(string)$in['event_id']:null;
        if($taskId){$check=$pdo->prepare('SELECT id FROM tasks WHERE id=? AND user_id=?');$check->execute([$taskId,$uid]);if(!$check->fetch())json_response(['error'=>'Selected task does not belong to this account.'],422);}
        if($eventId){$check=$pdo->prepare('SELECT id FROM events WHERE id=? AND user_id=?');$check->execute([$eventId,$uid]);if(!$check->fetch())json_response(['error'=>'Selected event does not belong to this account.'],422);}
        $title=clean_text((string)($in['title']??'Reminder'),255);if($title==='')json_response(['error'=>'Reminder message is required.'],422);
        $id=uuid();$st=$pdo->prepare('INSERT INTO reminders(id,user_id,task_id,event_id,title,remind_at) VALUES(?,?,?,?,?,?)');$st->execute([$id,$uid,$taskId,$eventId,$title,$when]);log_activity('create_reminder','reminder',$id);json_response(['ok'=>true,'id'=>$id]);
    }
    if ($action === 'reminder_delete' && $method === 'POST') { $in=input_json();$st=$pdo->prepare('UPDATE reminders SET status="cancelled" WHERE id=? AND user_id=?');$st->execute([(string)$in['id'],$uid]);json_response(['ok'=>true]); }

    if ($action === 'push_public' && $method === 'GET') json_response(['public_key'=>(string)cfg('push.public_key')]);
    if ($action === 'push_subscribe' && $method === 'POST') { $in=input_json();$s=$in['subscription']??null;if(!is_array($s)||empty($s['endpoint'])||empty($s['keys']['p256dh'])||empty($s['keys']['auth']))json_response(['error'=>'Invalid push subscription.'],422);$id=uuid();$t=now();$st=$pdo->prepare('INSERT INTO push_subscriptions(id,user_id,endpoint,p256dh,auth,user_agent,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),p256dh=VALUES(p256dh),auth=VALUES(auth),user_agent=VALUES(user_agent),updated_at=VALUES(updated_at)');$st->execute([$id,$uid,(string)$s['endpoint'],(string)$s['keys']['p256dh'],(string)$s['keys']['auth'],substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),$t,$t]);json_response(['ok'=>true]); }

    if ($action === 'sync' && $method === 'POST') {
        $in=input_json();
        $ops=$in['operations']??[];
        if(!is_array($ops)) json_response(['error'=>'Invalid sync operations.'],422);
        $applied=[]; $conflicts=[];

        foreach($ops as $op){
            if(!is_array($op) || ($op['entity']??'')!=='task') continue;
            $row=is_array($op['data']??null)?$op['data']:[];
            $id=clean_text((string)($row['id']??''),36);
            if(!$id) continue;

            $serverSt=$pdo->prepare('SELECT * FROM tasks WHERE id=? AND user_id=?');
            $serverSt->execute([$id,$uid]);
            $server=$serverSt->fetch() ?: null;
            $localTs=date_sql(isset($row['updated_at'])?(string)$row['updated_at']:null) ?? now();
            $type=(string)($op['type']??'upsert');

            if($type==='delete'){
                if(!$server){ $applied[]=$id; continue; }
                if(strtotime($localTs) >= strtotime((string)$server['updated_at'])){
                    $del=$pdo->prepare('DELETE FROM tasks WHERE id=? AND user_id=?');$del->execute([$id,$uid]);$applied[]=$id;
                } else {
                    $conflicts[]=['id'=>$id,'reason'=>'server_newer','server'=>$server];
                }
                continue;
            }

            if($server && strtotime($localTs) < strtotime((string)$server['updated_at'])){
                $conflicts[]=['id'=>$id,'reason'=>'server_newer','server'=>$server];
                continue;
            }

            $status=(string)($row['status']??'open');
            if(!in_array($status,['open','done','archived'],true))$status='open';
            $priority=normalize_priority((string)($row['priority']??'medium'));
            $t=$localTs;
            if($server){
                $st=$pdo->prepare('UPDATE tasks SET title=?,description=?,status=?,priority=?,due_at=?,start_at=?,end_at=?,estimated_minutes=?,completed_at=?,updated_at=? WHERE id=? AND user_id=?');
                $st->execute([
                    clean_text((string)($row['title']??''),255), $row['description']??null, $status, $priority,
                    date_sql($row['due_at']??null), date_sql($row['start_at']??null), date_sql($row['end_at']??null),
                    isset($row['estimated_minutes'])?max(0,min(480,(int)$row['estimated_minutes'])):null,
                    $status==='done' ? (date_sql($row['completed_at']??null)??$t) : null,
                    $t,$id,$uid
                ]);
            } else {
                $st=$pdo->prepare('INSERT INTO tasks(id,user_id,title,description,status,priority,due_at,start_at,end_at,estimated_minutes,completed_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute([
                    $id,$uid,clean_text((string)($row['title']??''),255),$row['description']??null,$status,$priority,
                    date_sql($row['due_at']??null),date_sql($row['start_at']??null),date_sql($row['end_at']??null),
                    isset($row['estimated_minutes'])?max(0,min(480,(int)$row['estimated_minutes'])):null,
                    $status==='done' ? (date_sql($row['completed_at']??null)??$t) : null,
                    $t,$t
                ]);
            }
            $applied[]=$id;
        }

        json_response([
            'ok'=>true,
            'applied'=>$applied,
            'conflicts'=>$conflicts,
            'server_tasks'=>fetch_tasks($uid,null,200),
        ]);
    }

    if ($action === 'ai_chat' && $method === 'POST') {
        $in=input_json();
        $msg=trim((string)($in['message']??''));
        if($msg==='') json_response(['error'=>'Message is required.'],422);
        try {
            $result=gemini_chat($msg,$u);
            json_response($result);
        } catch (Throwable $e) {
            error_log('[DayPilot AI] '.$e->getMessage());
            json_response(['error'=>'AI request failed: '.$e->getMessage()],502);
        }
    }
    if ($action === 'ai_notes' && $method === 'POST') { $in=input_json();$source=trim((string)($in['source_text']??''));if($source==='')json_response(['error'=>'Paste some source text first.'],422);$prompt="Create concise study/work notes from the following source. Use a clear title, a one-paragraph summary, key points, important terms, and action items. Return readable Markdown only.\n\nSOURCE:\n".$source; $text=gemini_text($prompt,$u);json_response(['markdown'=>$text]); }

    if ($action === 'export_ics' && $method === 'GET') {
        $month=preg_match('/^\d{4}-\d{2}$/',(string)($_GET['month']??''))?(string)$_GET['month']:date('Y-m');$from=$month.'-01 00:00:00';$to=date('Y-m-d H:i:s',strtotime($from.' +1 month'));$events=fetch_events($uid,$from,$to);$tasks=fetch_tasks($uid,'open',200);header('Content-Type:text/calendar; charset=utf-8');header('Content-Disposition: attachment; filename="daypilot-'.$month.'.ics"');echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//DayPilot//EN\r\nCALSCALE:GREGORIAN\r\n";foreach($events as $e){echo 'BEGIN:VEVENT\r\nUID:'.$e['id'].'@daypilot\r\nDTSTAMP:'.gmdate('Ymd\THis\Z').'\r\nDTSTART:'.gmdate('Ymd\THis\Z',strtotime($e['start_at'])).'\r\nDTEND:'.gmdate('Ymd\THis\Z',strtotime($e['end_at'])).'\r\nSUMMARY:'.ics_escape($e['title'])."\r\nEND:VEVENT\r\n";}foreach($tasks as $t){if($t['start_at']&&$t['end_at']){echo 'BEGIN:VEVENT\r\nUID:task-'.$t['id'].'@daypilot\r\nDTSTAMP:'.gmdate('Ymd\THis\Z').'\r\nDTSTART:'.gmdate('Ymd\THis\Z',strtotime($t['start_at'])).'\r\nDTEND:'.gmdate('Ymd\THis\Z',strtotime($t['end_at'])).'\r\nSUMMARY:'.ics_escape('[Task] '.$t['title'])."\r\nEND:VEVENT\r\n";}}echo "END:VCALENDAR\r\n";exit;
    }

    json_response(['error'=>'Unknown action.'],404);
} catch (Throwable $e) {
    error_log('[DayPilot] '.$e->getMessage()."\n".$e->getTraceAsString());
    json_response(['error'=>'Server error. Check the DayPilot server log for details.'],500);
}

function get_task(PDO $pdo,string $uid,string $id): array { $st=$pdo->prepare('SELECT id,title,description,status,priority,due_at,start_at,end_at,estimated_minutes,completed_at,created_at,updated_at FROM tasks WHERE id=? AND user_id=?');$st->execute([$id,$uid]);return $st->fetch() ?: []; }
function ics_escape(string $s): string { return str_replace(["\\",";",",","\n","\r"],["\\\\","\\;","\\,","\\n",''],$s); }
