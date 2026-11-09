<?php
/** Account-scoped weekly plan. ChatGPT connections are not website credentials. */
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Cache-Control: private, no-store');
header('Vary: Cookie');
$u = user();
if (!$u) { header('Location: index.php'); exit; }
$uid = (string)$u['id'];
$plan = [
 ['16','GitHub','Review DayPilot and tracker integration health',90,'Review current PRs, CI and tracker summaries. Choose one issue to finish. Do not depend on Copilot review quota.'],
 ['17','Learning','DSA practice and SQL revision',90,'Complete two DSA problems and one SQL exercise; record results in connected trackers.'],
 ['18','Hostinger','Verify live dashboard and background jobs',60,'Check the live version, database health, hourly connections job and five-minute reminder job. Record verified results; deployment and cron access still require hosting access.'],
 ['19','Portfolio','Ship one portfolio improvement',90,'Finish one small project feature, validate it and prepare a reviewable PR. Add a clear result to your portfolio notes.'],
 ['20','LinkedIn','Prepare a SKIT student project update',45,'Bhavya Gupta, SKIT Jaipur: draft a short update describing the feature built and what you learned. Verify your exact LinkedIn profile before using it. Publishing and outreach remain manual.'],
 ['21','Projects','Test tracker connectivity and mobile usability',90,'Check DSA, SQL, web and cloud summaries. Verify logout, Settings, reminders and mobile navigation. Record one reproducible issue if a check fails.'],
 ['22','Review','Review the week and choose next priorities',45,'Review completed tasks, learning totals and project blockers. Carry forward unfinished work deliberately; choose three priorities for the following week.'],
];
function weekly_task_id(string $uid, int $i): string {
 $s=hash('sha256','daypilot:2026-11-16:'.$uid.':'.$i);
 return substr($s,0,8).'-'.substr($s,8,4).'-'.substr($s,12,4).'-'.substr($s,16,4).'-'.substr($s,20,12);
}
function weekly_escape(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
 if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Session expired. Reload this page.'); }
 $pdo=db();
 try {
  $pdo->beginTransaction();
  // Serialise concurrent imports for this account so no duplicate insert can race.
  $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $lock->execute([$uid]);
  $check=$pdo->prepare('SELECT id FROM tasks WHERE id=? AND user_id=?');
  $insert=$pdo->prepare('INSERT INTO tasks(id,user_id,title,description,priority,due_at,estimated_minutes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
  foreach ($plan as $i=>$item) {
   $id=weekly_task_id($uid,$i); $check->execute([$id,$uid]); if ($check->fetch()) continue;
   $due=(new DateTimeImmutable('2026-11-'.$item[0].' 21:00:00',new DateTimeZone('Asia/Kolkata')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
   $insert->execute([$id,$uid,$item[2],$item[4].' Weekly plan: 16–22 November 2026, Asia/Kolkata. Suggested daily target; no reserved calendar time.',$i===0?'high':'medium',$due,$item[3],now(),now()]);
  }
  $pdo->commit();
 } catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[DayPilot weekly import] '.$e->getMessage());
  http_response_code(500); exit('The weekly plan could not be saved. No partial import was kept. Return and try again.');
 }
 header('Location: weekly-workflow.php'); exit;
}
$statuses=[]; $q=db()->prepare('SELECT status FROM tasks WHERE id=? AND user_id=?');
foreach ($plan as $i=>$item) { $q->execute([weekly_task_id($uid,$i),$uid]); $statuses[$i]=$q->fetchColumn() ?: 'not imported'; }
$done=count(array_filter($statuses,static fn($s)=>$s==='done'));
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Weekly workflow · DayPilot</title>
<style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#0c1519;color:#eaf3f0}*{box-sizing:border-box}body{margin:0}main{max-width:1100px;margin:auto;padding:32px 20px}a{color:#92ebc8}header{padding:32px 0}h1{font-size:clamp(32px,6vw,60px);line-height:1.08;margin:14px 0}h2{font-size:23px}.eyebrow{color:#92ebc8;letter-spacing:.15em;font-size:12px}p{color:#b8cbc7;line-height:1.6}button{background:#92ebc8;color:#10241e;border:0;border-radius:10px;padding:14px 20px;font-weight:700;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px}.card{padding:22px;background:#142329;border:1px solid #2c4146;border-radius:16px}.meta{display:flex;justify-content:space-between;gap:12px;color:#92ebc8;font-size:13px}.badge{font-size:12px;padding:5px 10px;border:1px solid #57736c;border-radius:20px}progress{width:100%;accent-color:#92ebc8;height:16px}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:14px 8px;border-bottom:1px solid #2c4146;vertical-align:top;font-size:14px}.table-wrap{overflow:auto}section{margin:24px 0}small{color:#b8cbc7}.row{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
</style></head><body><main><a href="index.php">← DayPilot dashboard</a><header><div class="eyebrow">WEEKLY WORKFLOW / 16–22 NOVEMBER 2026</div><h1>Build. Learn.<br>Make the week count.</h1><p>Bhavya Gupta · SKIT, Jaipur · India time (Asia/Kolkata)</p><p>Seven daily priorities with flexible work windows. No calendar slots are reserved. The primary calendar was empty when checked on 9 November; recheck it each morning.</p><div class="row"><form method="post"><input type="hidden" name="csrf" value="<?=weekly_escape(csrf_token())?>"><button>Add missing weekly tasks</button></form><a href="index.php">Manage tasks and reminders →</a></div><p>Imports into the signed-in account only. Repeating the import preserves existing task edits and completion states. Suggested targets are 9 PM IST; adjust them in Tasks.</p></header>
<section class="card"><div class="meta"><strong>Weekly progress</strong><span><?=$done?> / 7 completed</span></div><progress max="7" value="<?=$done?>" aria-label="Weekly tasks completed"></progress><p>Progress comes from your saved DayPilot tasks. Complete tasks in the main dashboard, then refresh this page.</p></section>
<section class="grid"><?php foreach($plan as $i=>$item): ?><article class="card"><div class="meta"><span>November <?=weekly_escape($item[0])?> · <?=weekly_escape($item[1])?></span><span class="badge"><?=weekly_escape($statuses[$i])?></span></div><h2><?=weekly_escape($item[2])?></h2><p><?=weekly_escape($item[4])?></p><small><?= (int)$item[3] ?> minutes · flexible window</small></article><?php endforeach ?></section>
<section class="card"><div class="eyebrow">DAILY LOOP</div><h2>Check → Choose → Focus → Record</h2><p>Morning: review calendar conflicts, actionable emails and GitHub changes; select three priorities. Work: complete the daily focus task plus a short practice session when time permits. Evening: mark completed tasks, log blockers and update tracker summaries.</p><p>The scheduled ChatGPT morning brief runs for this week. It is separate from this website: it cannot silently use your ChatGPT connections as server credentials or write into your private DayPilot account.</p></section>
<section class="card"><h2>Connection readiness</h2><div class="table-wrap"><table><thead><tr><th>Source</th><th>Verified capability</th><th>Website follow-through</th></tr></thead><tbody>
<tr><td>GitHub</td><td>Repository confirmed; no open DayPilot PRs on 9 November.</td><td>Configure the public repository watchlist in Connections; private account access stays in ChatGPT.</td></tr>
<tr><td>Gmail</td><td>Recent project notifications read; review quota blocker identified.</td><td>Briefs surface action items in ChatGPT. No mailbox contents are published here.</td></tr>
<tr><td>Google Calendar</td><td>Primary calendar checked for 16–22 November; no events found.</td><td>Check live conflicts daily; website Calendar requires its own Google setup.</td></tr>
<tr><td>Hostinger</td><td>Account operations unavailable in this session.</td><td>Live deployment and cron verification pending hosting access.</td></tr>
<tr><td>LinkedIn</td><td>People search available; several name matches, identity unverified.</td><td>Prepare a project update. Select your verified profile and publish manually.</td></tr>
</tbody></table></div></section></main></body></html>
