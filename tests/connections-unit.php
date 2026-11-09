<?php
declare(strict_types=1);
require __DIR__.'/../connections.php';
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function rejects(callable $f): void {try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid input was accepted.');}
check(connections_origin('https://tracker.example/')==='https://tracker.example','Origin canonicalization failed');
check(connections_origin('http://localhost:8000')==='http://localhost:8000','Localhost rejected');
foreach(['null','file:///tmp/tracker','https://user:password@example.com','https://example.com/path','http://example.com','https://example.com?q=1','https://example.com#x','*'] as $bad)rejects(fn()=>connections_origin($bad));
$m=connections_metrics(['total'=>5,'completed'=>2,'in_progress'=>1,'notes'=>'private','solution'=>'private']);
check(!isset($m['notes'])&&!isset($m['solution']),'Private content retained');
rejects(fn()=>connections_metrics(['total'=>1,'completed'=>2]));
rejects(fn()=>connections_metrics(['total'=>1,'minutes'=>-3]));
rejects(fn()=>connections_metrics(['total'=>'5']));
check(connections_needs_alert(['conclusion'=>'failure','updated_at'=>gmdate('c')]),'Recent failure missed');
check(!connections_needs_alert(['conclusion'=>'failure','updated_at'=>'2020-01-01']),'Historical failure would notify');
check(!connections_needs_alert(['conclusion'=>'success','updated_at'=>gmdate('c')]),'Successful build would notify');
echo "Connection validation, privacy, and alert checks passed.\n";
