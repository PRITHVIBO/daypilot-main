<?php
declare(strict_types=1);

// User-scoped connections. This module never receives ChatGPT/GitHub plugin credentials.
function connections_schema(): void {
    static $done=false; if($done)return;
    $q=db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('integration_settings','integration_tokens','integration_snapshots','integration_alerts')");
    if((int)$q->fetchColumn()!==4)foreach(explode(';',file_get_contents(__DIR__.'/migrations/003_connections.sql')) as $sql)if(trim($sql)!=='')db()->exec(daypilot_new_table_sql($sql));
    $done=true;
}
function connections_settings(string $uid): array {
    $s=db()->prepare('SELECT settings,last_sync_at,last_error FROM integration_settings WHERE user_id=?');$s->execute([$uid]);$r=$s->fetch();
    return $r ? array_merge(json_decode($r['settings'],true)?:[],['last_sync_at'=>$r['last_sync_at'],'last_error'=>$r['last_error']]) : ['owner'=>'PRITHVIBO','repos'=>['Dsa_track_app','sql-tracker-app','web-dev-tracker','cloud-project-tracker','daypilot-main'],'enabled'=>false,'last_sync_at'=>null,'last_error'=>null];
}
function connections_origin(string $origin): string {
    if(strlen($origin)>255)throw new InvalidArgumentException('Origin is too long.');
    $p=parse_url($origin);
    if(!$p||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true))throw new InvalidArgumentException('Enter an origin such as https://example.com or http://localhost:8000.');
    $scheme=strtolower($p['scheme']??'');$host=strtolower($p['host']);
    if($scheme!=='https'&&!($scheme==='http'&&in_array($host,['localhost','127.0.0.1','[::1]'],true)))throw new InvalidArgumentException('Use HTTPS, or HTTP on localhost. File URLs are not supported.');
    return $scheme.'://'.$host.(isset($p['port'])?':'.$p['port']:'');
}
function connections_metrics(array $in): array {
    $out=[];
    foreach(['total','completed','in_progress','attempts','minutes','reviews_due'] as $k){$v=$in[$k]??0;if(!is_int($v)||$v<0||$v>10000000)throw new InvalidArgumentException('Metrics must be non-negative whole numbers.');$out[$k]=$v;}
    if($out['completed']+$out['in_progress']>$out['total'])throw new InvalidArgumentException('Completed and in-progress counts exceed the total.');
    return $out; // Only aggregate fields are retained; never persist notes or solution code.
}
function connections_snapshot(string $uid,string $source,array $payload,string $captured): void {
    $day=date('Y-m-d');$stamp=now();
    $q=db()->prepare('INSERT INTO integration_snapshots(user_id,source,snapshot_day,payload,captured_at,received_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=IF(VALUES(captured_at)>=captured_at,VALUES(payload),payload),received_at=IF(VALUES(captured_at)>=captured_at,VALUES(received_at),received_at),captured_at=GREATEST(captured_at,VALUES(captured_at))');
    $q->execute([$uid,$source,$day,json_encode($payload,JSON_THROW_ON_ERROR),$captured,$stamp]);
}
function connections_github(string $path): array {
    if(!preg_match('~^/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/commits|/actions/runs)?(?:\?.*)?$~',$path))throw new RuntimeException('Unsupported GitHub resource.');
    if(!function_exists('curl_init'))throw new RuntimeException('The server needs the PHP cURL extension for GitHub sync.');
    $h=curl_init('https://api.github.com'.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28','User-Agent: DayPilot-Connections']]);
    $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
    if($body===false||$status!==200)throw new RuntimeException($status===403||$status===429?'GitHub rate limit or access restriction. Saved data is retained.':'GitHub request failed (HTTP '.$status.'). Saved data is retained.');
    $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Invalid GitHub response.');return $data;
}
function connections_needs_alert(array $run): bool {
    $updated=strtotime((string)($run['updated_at']??''));
    return $updated!==false&&$updated>=time()-86400&&$updated<=time()+300&&in_array($run['conclusion']??null,['failure','timed_out','action_required'],true);
}
function connections_alert(string $uid,string $key,string $title): void {
    $pdo=db();$pdo->beginTransaction();try{
        $q=$pdo->prepare('INSERT IGNORE INTO integration_alerts(user_id,fingerprint,title,created_at) VALUES(?,?,?,?)');$q->execute([$uid,hash('sha256',$key),substr($title,0,240),now()]);
        if($q->rowCount()){$r=$pdo->prepare('INSERT INTO reminders(id,user_id,title,remind_at) VALUES(?,?,?,?)');$r->execute([uuid(),$uid,substr($title,0,240),now()]);}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function connections_refresh(string $uid): array {
    $settings=connections_settings($uid);$last=$settings['last_sync_at'];
    if($last&&strtotime($last)>time()-3600)return ['cached'=>true,'next_refresh_at'=>date(DATE_ATOM,strtotime($last)+3600)];
    $owner=$settings['owner'];$errors=[];$synced=0;
    // A per-account advisory lock prevents overlapping cron/browser refreshes.
    $lock='daypilot-gh-'.substr(hash('sha256',$uid),0,32);$l=db()->prepare('SELECT GET_LOCK(?,0)');$l->execute([$lock]);if((int)$l->fetchColumn()!==1)return ['cached'=>true,'busy'=>true];
    try{
      foreach($settings['repos'] as $repo){
        try{
            $path='/repos/'.$owner.'/'.$repo;$meta=connections_github($path);
            if(!empty($meta['private']))throw new RuntimeException('Only public repositories are supported by this server connection.');
            $warnings=[];$daily=[];$lastRun=null;
            try{$commits=connections_github($path.'/commits?per_page=100&since='.rawurlencode(gmdate('c',time()-30*86400)));foreach($commits as $c){$day=substr($c['commit']['committer']['date']??'',0,10);if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$day))$daily[$day]=($daily[$day]??0)+1;}if(count($commits)===100)$warnings[]='Commit chart is capped at the 100 most recent commits.';}catch(Throwable $e){$warnings[]='Commit activity unavailable: '.$e->getMessage();$daily=null;}
            try{$runs=connections_github($path.'/actions/runs?per_page=1');$run=$runs['workflow_runs'][0]??null;if($run)$lastRun=['id'=>(string)$run['id'],'name'=>(string)$run['name'],'status'=>(string)$run['status'],'conclusion'=>$run['conclusion'],'url'=>$run['html_url'],'updated_at'=>$run['updated_at']];}catch(Throwable $e){$warnings[]='Build status unavailable: '.$e->getMessage();}
            $payload=['repo'=>$owner.'/'.$repo,'url'=>$meta['html_url'],'pushed_at'=>$meta['pushed_at'],'language'=>$meta['language'],'open_issues_and_prs'=>(int)$meta['open_issues_count'],'commits_by_day'=>$daily,'latest_run'=>$lastRun,'warnings'=>$warnings];
            connections_snapshot($uid,'github:'.$repo,$payload,now());$synced++;
            if(!empty($settings['enabled'])&&$lastRun&&connections_needs_alert($lastRun))connections_alert($uid,'build:'.$lastRun['id'],'Build needs attention: '.$repo.' — '.$lastRun['conclusion']);
        }catch(Throwable $e){$errors[]=$repo.': '.$e->getMessage();}
      }
      $q=db()->prepare('INSERT INTO integration_settings(user_id,settings,last_sync_at,last_error) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_sync_at=VALUES(last_sync_at),last_error=VALUES(last_error)');
      $q->execute([$uid,json_encode(['owner'=>$owner,'repos'=>$settings['repos'],'enabled'=>(bool)$settings['enabled']]),now(),$errors?implode(' | ',$errors):null]);
    }finally{$q=db()->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    return ['synced'=>$synced,'errors'=>$errors];
}
function connections_overview(string $uid): array {
    $settings=connections_settings($uid);
    $q=db()->prepare('SELECT source,snapshot_day,payload,captured_at,received_at FROM integration_snapshots WHERE user_id=? AND snapshot_day>=? ORDER BY snapshot_day,received_at');$q->execute([$uid,date('Y-m-d',time()-30*86400)]);$latest=[];$history=[];
    foreach($q->fetchAll() as $r){$p=json_decode($r['payload'],true)?:[];$latest[$r['source']]=['source'=>$r['source'],'data'=>$p,'captured_at'=>$r['captured_at'],'received_at'=>$r['received_at']];if(!str_starts_with($r['source'],'github:'))$history[]=['source'=>$r['source'],'day'=>$r['snapshot_day'],'completed'=>$p['completed']??0];}
    $latest=array_filter($latest,static fn($r)=>!str_starts_with($r['source'],'github:')||in_array(substr($r['source'],7),$settings['repos'],true));
    $q=db()->prepare('SELECT id,source,origin,expires_at,last_received_at,revoked_at FROM integration_tokens WHERE user_id=? ORDER BY created_at DESC');$q->execute([$uid]);$tokens=$q->fetchAll();
    $q=db()->prepare('SELECT title,created_at FROM integration_alerts WHERE user_id=? ORDER BY created_at DESC LIMIT 20');$q->execute([$uid]);$alerts=$q->fetchAll();
    $q=db()->prepare('SELECT title,due_at FROM tasks WHERE user_id=? AND status="open" AND due_at<NOW() ORDER BY due_at LIMIT 10');$q->execute([$uid]);$overdue=$q->fetchAll();
    return ['settings'=>$settings,'sources'=>array_values($latest),'history'=>$history,'connections'=>$tokens,'alerts'=>$alerts,'overdue_tasks'=>$overdue,'public_only'=>true];
}
function connections_dispatch(string $action,string $method,string $uid): void {
    if(!str_starts_with($action,'connections_'))return;
    connections_schema();
    if($action==='connections_overview'&&$method==='GET')json_response(connections_overview($uid));
    if($method!=='POST')json_response(['error'=>'Method not allowed'],405);
    $in=input_json();
    try{
      if($action==='connections_settings'){
        $owner=trim((string)($in['owner']??'PRITHVIBO'));$repos=$in['repos']??[];
        if(!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,38})$/',$owner)||!is_array($repos)||count($repos)<1||count($repos)>8)throw new InvalidArgumentException('Choose a valid GitHub owner and one to eight repository names.');
        foreach($repos as $r)if(!is_string($r)||!preg_match('/^[a-zA-Z0-9_.-]{1,100}$/',$r))throw new InvalidArgumentException('Repository names cannot contain URLs or slashes.');
        $s=['owner'=>$owner,'repos'=>array_values(array_unique($repos)),'enabled'=>!empty($in['enabled'])];
        $q=db()->prepare('INSERT INTO integration_settings(user_id,settings) VALUES(?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),last_sync_at=NULL,last_error=NULL');$q->execute([$uid,json_encode($s)]);json_response(['ok'=>true]);
      }
      if($action==='connections_sync')json_response(connections_refresh($uid));
      if($action==='connections_create_token'){
        $source=(string)($in['source']??'');if(!in_array($source,['dsa','sql','web','cloud'],true))throw new InvalidArgumentException('Unknown tracker.');
        $origin=connections_origin(trim((string)($in['origin']??'')));$token=bin2hex(random_bytes(32));$id=uuid();
        $q=db()->prepare('INSERT INTO integration_tokens(id,user_id,source,origin,token_hash,created_at,expires_at) VALUES(?,?,?,?,?,?,?)');$q->execute([$id,$uid,$source,$origin,hash('sha256',$token),now(),date('Y-m-d H:i:s',time()+90*86400)]);
        json_response(['id'=>$id,'token'=>$token,'source'=>$source,'expires_in_days'=>90]);
      }
      if($action==='connections_revoke_token'){$q=db()->prepare('UPDATE integration_tokens SET revoked_at=? WHERE id=? AND user_id=?');$q->execute([now(),(string)($in['id']??''),$uid]);json_response(['ok'=>true]);}
    }catch(InvalidArgumentException $e){json_response(['error'=>$e->getMessage()],422);}
    json_response(['error'=>'Unknown connection action'],404);
}
