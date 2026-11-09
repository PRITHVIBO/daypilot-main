<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
require __DIR__.'/connections.php';
header('Cache-Control: no-store');header('Vary: Origin');
try{
 connections_schema();$method=$_SERVER['REQUEST_METHOD']??'GET';$id=(string)($_GET['connection']??'');
 $q=db()->prepare('SELECT * FROM integration_tokens WHERE id=? AND revoked_at IS NULL AND expires_at>NOW()');$q->execute([$id]);$c=$q->fetch();
 $origin=$_SERVER['HTTP_ORIGIN']??'';
 if(!$c||!hash_equals($c['origin'],$origin))json_response(['error'=>'Connection or origin not authorized.'],403);
 header('Access-Control-Allow-Origin: '.$c['origin']);header('Access-Control-Allow-Methods: POST, OPTIONS');header('Access-Control-Allow-Headers: Authorization, Content-Type');header('Access-Control-Max-Age: 600');
 if($method==='OPTIONS'){http_response_code(204);exit;}
 if($method!=='POST')json_response(['error'=>'Method not allowed'],405);
 $auth=$_SERVER['HTTP_AUTHORIZATION']??'';
 if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$auth,$m)||!hash_equals($c['token_hash'],hash('sha256',$m[1])))json_response(['error'=>'Invalid connection token.'],401);
 if($c['last_received_at']&&strtotime($c['last_received_at'])>time()-30){header('Retry-After: 30');json_response(['error'=>'Wait 30 seconds before syncing again.'],429);}
 $raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)json_response(['error'=>'Summary is too large.'],413);
 $in=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($in))throw new InvalidArgumentException('Invalid summary.');
 if(($in['source']??'')!==$c['source'])json_response(['error'=>'Token is scoped to a different tracker.'],403);
 $captured=strtotime((string)($in['captured_at']??''));if(!$captured||$captured>time()+300||$captured<time()-7*86400)throw new InvalidArgumentException('Summary timestamp is missing or outside the accepted window.');
 $metrics=connections_metrics($in);$stamp=date('Y-m-d H:i:s',$captured);
 $q=db()->prepare('SELECT MAX(captured_at) FROM integration_snapshots WHERE user_id=? AND source=?');$q->execute([$c['user_id'],$c['source']]);$previous=$q->fetchColumn();
 if($previous&&strtotime($previous)>$captured)json_response(['error'=>'An older summary cannot replace newer progress.'],409);
 connections_snapshot($c['user_id'],$c['source'],$metrics,$stamp);
 $q=db()->prepare('UPDATE integration_tokens SET last_received_at=? WHERE id=?');$q->execute([now(),$id]);
 json_response(['ok'=>true,'received_at'=>now(),'source'=>$c['source']]);
}catch(InvalidArgumentException|JsonException $e){json_response(['error'=>'Invalid progress summary: '.$e->getMessage()],422);}
catch(Throwable $e){error_log('[DayPilot tracker ingest] '.$e->getMessage());json_response(['error'=>'Tracker sync is unavailable. Try again later.'],503);}
