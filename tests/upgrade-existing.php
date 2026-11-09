<?php
declare(strict_types=1);
// Run only in the disposable CI database, before the HTTP integration suite.
if(getenv('DAYPILOT_UPGRADE_TEST')!=='1')throw new RuntimeException('Disposable upgrade-test database required.');
require __DIR__.'/../lib.php';require __DIR__.'/../connections.php';
$pdo=db();$pdo->exec(file_get_contents(__DIR__.'/fixtures/daypilot-main-schema.sql'));
$uid=uuid();$task=uuid();$note=uuid();$time=now();
$q=$pdo->prepare('INSERT INTO users(id,email,password_hash,name,timezone,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');$q->execute([$uid,'legacy@test.invalid',password_hash('Test-only-password-93',PASSWORD_ARGON2ID),'Legacy user','Asia/Kolkata',$time,$time]);
$q=$pdo->prepare('INSERT INTO tasks(id,user_id,title,priority,created_at,updated_at) VALUES(?,?,?,?,?,?)');$q->execute([$task,$uid,'Keep this existing task','high',$time,$time]);
$q=$pdo->prepare('INSERT INTO notes(id,user_id,title,content,created_at,updated_at) VALUES(?,?,?,?,?,?)');$q->execute([$note,$uid,'Existing notes','Keep this note content',$time,$time]);
ensure_v2_schema();connections_schema();
$checks=[['SELECT title FROM tasks WHERE id=?',$task,'Keep this existing task'],['SELECT content FROM notes WHERE id=?',$note,'Keep this note content'],['SELECT name FROM users WHERE id=?',$uid,'Legacy user']];
foreach($checks as [$sql,$id,$expected]){$q=$pdo->prepare($sql);$q->execute([$id]);if($q->fetchColumn()!==$expected)throw new RuntimeException('An existing record changed during upgrade.');}
foreach(['projects','work_logs','daily_reviews','memory_chunks','integration_settings','integration_tokens','integration_snapshots','integration_alerts'] as $table){$pdo->query('SELECT 1 FROM '.$table.' LIMIT 1');}
connections_snapshot($uid,'sql',['total'=>4,'completed'=>1,'in_progress'=>0,'attempts'=>1,'minutes'=>0,'reviews_due'=>0],now());
if(count(connections_overview($uid)['sources'])!==1)throw new RuntimeException('New connections do not work after the legacy schema upgrade.');
$previousAi=$config['ai'];$config['ai']=['provider'=>'gemini','api_key'=>'test-only-key','model'=>'existing-model'];
$plan=ai_provider_plan();if(($plan[0]['provider']??'')!=='gemini'||$plan[0]['model']!=='existing-model')throw new RuntimeException('Existing Gemini configuration was not preserved.');$config['ai']=$previousAi;
echo "Legacy database upgrade preserves users, tasks, notes and supports new tracker snapshots.\n";
