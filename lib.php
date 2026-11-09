<?php
declare(strict_types=1);

session_name('daypilot_session');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'path' => '/',
]);
session_start();

$config = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) { $local = require __DIR__ . '/config.local.php'; if (is_array($local)) { $config = array_replace_recursive($config, $local); } }
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Kolkata');

function cfg(string $key, mixed $default = null): mixed {
    global $config;
    $segments = explode('.', $key);
    $v = $config;
    foreach ($segments as $segment) {
        if (!is_array($v) || !array_key_exists($segment, $v)) return $default;
        $v = $v[$segment];
    }
    return $v;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', cfg('db.host'), (int)cfg('db.port'), cfg('db.name'), cfg('db.charset'));
    $pdo = new PDO($dsn, (string)cfg('db.user'), (string)cfg('db.pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}


function daypilot_new_table_sql(string $sql): string {
    // New foreign-key columns must inherit the existing users.id collation.
    $q=db()->query("SELECT CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id'");
    $r=$q->fetch();$charset=(string)($r['CHARACTER_SET_NAME']??'utf8mb4');$collation=(string)($r['COLLATION_NAME']??'');
    if(!preg_match('/^[a-z0-9_]+$/',$charset)||!preg_match('/^[a-z0-9_]+$/',$collation))throw new RuntimeException('Cannot determine existing account-table collation.');
    $sql=preg_replace('/DEFAULT CHARSET=utf8mb4(?: COLLATE=utf8mb4_unicode_ci)?/', 'DEFAULT CHARSET='.$charset.' COLLATE='.$collation, $sql);
    return $sql;
}

function uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function now(): string { return date('Y-m-d H:i:s'); }

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function input_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_response(['error' => 'Invalid JSON body'], 400);
    return $data;
}

function user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT id,email,name,timezone,created_at,updated_at FROM users WHERE id=?');
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: null;
}

function require_user(): array {
    $u = user();
    if (!$u) json_response(['error' => 'Authentication required'], 401);
    return $u;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function require_csrf(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $provided)) json_response(['error' => 'Invalid CSRF token'], 419);
    }
}

function log_activity(string $action, ?string $type = null, ?string $id = null, array $meta = []): void {
    $u = user(); if (!$u) return;
    $st = db()->prepare('INSERT INTO activity_log(user_id,action,entity_type,entity_id,metadata,created_at) VALUES(?,?,?,?,?,?)');
    $st->execute([$u['id'],$action,$type,$id,json_encode($meta),now()]);
}

function normalize_priority(string $p): string {
    return in_array($p, ['low','medium','high'], true) ? $p : 'medium';
}

function clean_text(string $s, int $max = 255): string {
    $s = trim($s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

function fetch_tasks(string $userId, ?string $status = null, int $limit = 200): array {
    $sql = 'SELECT id,title,description,status,priority,due_at,start_at,end_at,estimated_minutes,completed_at,created_at,updated_at FROM tasks WHERE user_id=?';
    $args = [$userId];
    if ($status && in_array($status, ['open','done','archived'], true)) { $sql .= ' AND status=?'; $args[]=$status; }
    $sql .= ' ORDER BY status="done", COALESCE(start_at,due_at) IS NULL, COALESCE(start_at,due_at), FIELD(priority,"high","medium","low"), updated_at DESC LIMIT ' . (int)$limit;
    $st = db()->prepare($sql); $st->execute($args); return $st->fetchAll();
}

function fetch_events(string $userId, string $from, string $to): array {
    $st = db()->prepare('SELECT id,title,description,location,start_at,end_at,source,external_id,created_at,updated_at FROM events WHERE user_id=? AND start_at < ? AND end_at > ? ORDER BY start_at');
    $st->execute([$userId,$to,$from]); return $st->fetchAll();
}

require_once __DIR__ . '/memory.php';
require_once __DIR__ . '/gemini-compat.php';

function fetch_notes(string $userId): array {
    $st = db()->prepare('SELECT id,title,content,tags,source,created_at,updated_at FROM notes WHERE user_id=? ORDER BY updated_at DESC LIMIT 100');
    $st->execute([$userId]); return $st->fetchAll();
}

function date_sql(?string $value): ?string {
    if ($value === null || $value === '') return null;
    $ts = strtotime($value);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

function plan_day(string $userId, string $date): array {
    $tasks = fetch_tasks($userId, 'open', 100);
    $dayStart = strtotime($date . ' 09:00:00');
    $dayEnd = strtotime($date . ' 20:00:00');
    $events = fetch_events($userId, $date . ' 00:00:00', $date . ' 23:59:59');
    $busy = [];
    foreach ($events as $event) {
        $busy[] = [strtotime($event['start_at']), strtotime($event['end_at'])];
    }
    usort($busy, fn($a,$b)=>$a[0]<=>$b[0]);
    $cursor = $dayStart;
    $blocks = [];
    $pdo = db();
    foreach ($tasks as &$task) {
        $urgency = 0;
        if ($task['due_at'] && date('Y-m-d', strtotime($task['due_at'])) < $date) $urgency = 3;
        elseif ($task['due_at'] && date('Y-m-d', strtotime($task['due_at'])) === $date) $urgency = 2;
        elseif ($task['due_at'] && strtotime($task['due_at']) < strtotime($date . ' +2 days')) $urgency = 1;
        $task['_score'] = (match($task['priority']) { 'high'=>30, 'medium'=>20, default=>10 }) + $urgency*10 + ($task['due_at'] ? 5 : 0);
    }
    unset($task);
    usort($tasks, fn($a,$b)=> ($b['_score']??0) <=> ($a['_score']??0));
    foreach ($tasks as $task) {
        $mins = max(15, min(180, (int)($task['estimated_minutes'] ?? 30)));
        $duration = $mins * 60;
        $placed = false;
        for ($attempt=0; $attempt<30 && $cursor+$duration <= $dayEnd; $attempt++) {
            $collision = null;
            foreach ($busy as $interval) {
                if ($cursor < $interval[1] && ($cursor+$duration) > $interval[0]) { $collision = $interval; break; }
            }
            if ($collision) { $cursor = $collision[1] + 10*60; continue; }
            $start = $cursor; $end = $cursor + $duration;
            $blocks[] = ['task_id'=>$task['id'],'title'=>$task['title'],'start_at'=>date('Y-m-d H:i:s',$start),'end_at'=>date('Y-m-d H:i:s',$end),'minutes'=>$mins];
            $st = $pdo->prepare('UPDATE tasks SET start_at=?, end_at=?, updated_at=? WHERE id=? AND user_id=? AND status="open"');
            $st->execute([date('Y-m-d H:i:s',$start),date('Y-m-d H:i:s',$end),now(),$task['id'],$userId]);
            $busy[] = [$start,$end];
            usort($busy, fn($a,$b)=>$a[0]<=>$b[0]);
            $cursor = $end + 10*60;
            $placed = true;
            break;
        }
        if (!$placed) break;
    }
    return $blocks;
}

function http_json(string $url, array $headers, array $body, int $timeout = 30): array {
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    // CURLOPT_HTTPHEADER requires an indexed array of complete header strings.
    // Accept both ['Header: value'] and ['Header' => 'value'] forms so provider
    // integrations cannot silently send malformed authentication headers.
    $normalizedHeaders = [];
    foreach ($headers as $key => $value) {
        if (is_int($key)) {
            $normalizedHeaders[] = (string)$value;
        } else {
            $normalizedHeaders[] = (string)$key . ': ' . (string)$value;
        }
    }
    $normalizedHeaders[] = 'Content-Length: ' . strlen($json);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $normalizedHeaders,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        throw new RuntimeException('Upstream request failed (cURL): ' . $err);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Upstream returned invalid JSON (HTTP ' . $status . ').');
    }

    if ($status >= 400) {
        $message = (string)($data['error']['message'] ?? 'AI provider request failed.');
        throw new RuntimeException('HTTP ' . $status . ': ' . $message);
    }

    return $data;
}


function ai_workspace_context(array $u, string $message = ''): array {
    $safe = static function(callable $fn, $fallback) {
        try { return $fn(); } catch (Throwable $e) { error_log('[DayPilot context] '.$e->getMessage()); return $fallback; }
    };
    $tasks = $safe(fn()=>fetch_tasks($u['id'], 'open', 50), []);
    $events = $safe(fn()=>fetch_events($u['id'], date('Y-m-d 00:00:00'), date('Y-m-d H:i:s', strtotime('+7 days'))), []);
    $notes = $safe(fn()=>fetch_notes($u['id']), []);
    $projects = $safe(fn()=>fetch_projects($u['id']), []);
    $logs = $safe(fn()=>fetch_work_logs($u['id'], 14, null, 30), []);
    $activity = $safe(fn()=>fetch_recent_activity($u['id'], 7, 30), []);
    $memory = $message !== '' ? $safe(fn()=>memory_context($u['id'], $message, 6), ['text'=>'Memory retrieval is temporarily unavailable.','sources'=>[]]) : ['text'=>'','sources'=>[]];

    $context = [
        'user' => ['name'=>$u['name'], 'timezone'=>$u['timezone']],
        'now' => now(),
        'tasks' => $tasks,
        'events' => $events,
        'projects' => array_slice($projects, 0, 20),
        'recent_work_logs' => $logs,
        'recent_activity' => $activity,
        'notes' => array_slice($notes, 0, 10),
        'rag_memory' => [
            'sources' => $memory['sources'],
            'context' => $memory['text'],
        ],
    ];

    $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $system = "You are DayPilot, a practical personal work assistant and personal work-memory layer. "
        . "Be concise, specific and action-oriented. Use tools whenever a request requires reading or changing the user's workspace. "
        . "Never claim an action succeeded unless its tool result says ok=true. Use the user's timezone. "
        . "When answering questions about prior work, learning or decisions, prefer the RAG memory context and mention uncertainty when the memory does not support a claim. "
        . "Treat recent activity and work logs as evidence of what was recorded, not as proof of unrecorded work. "
        . "For relative dates such as tomorrow, calculate them from the user's timezone and current time. "
        . "When scheduling, avoid conflicts with existing calendar events and prefer reasonable working hours. "
        . "Do not invent tasks, events, reminders, project status or completion results. Current workspace context: {$json}";

    return [$system, $context];
}

function fetch_recent_activity(string $uid, int $days = 7, int $limit = 30): array {
    $days=max(1,min(90,$days));$limit=max(1,min(100,$limit));
    // Interpolate the clamped integer for MariaDB/MySQL INTERVAL compatibility.
    $sql='SELECT action,entity_type,entity_id,metadata,created_at FROM activity_log WHERE user_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL '.$days.' DAY) ORDER BY created_at DESC LIMIT '.$limit;
    $st=db()->prepare($sql);
    $st->execute([$uid]);
    return $st->fetchAll();
}

function openrouter_tools(): array {
    $tools = [];
    foreach (ai_tools() as $tool) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => (string)$tool['name'],
                'description' => (string)($tool['description'] ?? ''),
                'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
            ],
        ];
    }
    return $tools;
}

function ai_provider_plan(): array {
    $mode = strtolower(trim((string)cfg('ai.provider', 'openrouter')));
    if ($mode === 'local') return [];
    if ($mode === 'gemini') {
        if(trim((string)cfg('ai.api_key'))==='')return [];
        return [['provider'=>'gemini','model'=>(string)cfg('ai.model','gemini-3.8-flash')]];
    }

    $key = trim((string)cfg('ai.openrouter.api_key'));
    if ($key === '') return [];

    $models = cfg('ai.openrouter.models', ['openrouter/free']);
    if (!is_array($models) || !$models) $models = ['openrouter/free'];
    $configured = [];
    foreach ($models as $model) {
        $model = trim((string)$model);
        if ($model !== '') $configured[] = ['provider' => 'openrouter', 'model' => $model];
    }
    return $configured;
}

function ai_call_openrouter(string $message, array $u, string $model): array {
    $key = trim((string)cfg('ai.openrouter.api_key'));
    if ($key === '') throw new RuntimeException('OpenRouter API key is not configured.');

    [$system] = ai_workspace_context($u, $message);
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $message],
    ];
    $tools = openrouter_tools();
    $actions = [];

    for ($round = 0; $round < 4; $round++) {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'max_completion_tokens' => 1200,
        ];
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key,
        ];
        $referer = trim((string)cfg('ai.openrouter.site_url', cfg('app.base_url', 'https://projects.bhavyagupta.space')));
        $appName = trim((string)cfg('ai.openrouter.app_name', 'DayPilot'));
        if ($referer !== '') $headers[] = 'HTTP-Referer: ' . $referer;
        if ($appName !== '') $headers[] = 'X-Title: ' . $appName;

        try {
            $resp = http_json('https://openrouter.ai/api/v1/chat/completions', $headers, $body, 90);
        } catch (Throwable $e) {
            if ($actions) {
                return [
                    'text' => 'I completed the requested workspace action, but the fallback AI provider failed before returning its final summary.',
                    'tool_actions' => $actions,
                    'provider' => 'openrouter',
                    'model' => $model,
                    'partial' => true,
                    'provider_error' => $e->getMessage(),
                ];
            }
            throw $e;
        }

        $choice = $resp['choices'][0]['message'] ?? [];
        $content = $choice['content'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) if (is_array($part) && isset($part['text'])) $parts[] = (string)$part['text'];
            $content = implode('', $parts);
        }
        $toolCalls = is_array($choice['tool_calls'] ?? null) ? $choice['tool_calls'] : [];

        if (!$toolCalls) {
            return [
                'text' => trim((string)$content) ?: 'Done.',
                'tool_actions' => $actions,
                'provider' => 'openrouter',
                'model' => $model,
                'partial' => false,
            ];
        }

        $assistantMessage = ['role' => 'assistant', 'tool_calls' => []];
        if ($content !== '') $assistantMessage['content'] = $content;
        foreach ($toolCalls as $call) {
            $assistantMessage['tool_calls'][] = [
                'id' => (string)($call['id'] ?? uuid()),
                'type' => 'function',
                'function' => [
                    'name' => (string)($call['function']['name'] ?? ''),
                    'arguments' => (string)($call['function']['arguments'] ?? '{}'),
                ],
            ];
        }
        $messages[] = $assistantMessage;

        foreach ($toolCalls as $call) {
            $fn = trim((string)($call['function']['name'] ?? ''));
            $rawArgs = (string)($call['function']['arguments'] ?? '{}');
            $args = json_decode($rawArgs, true);
            if (!is_array($args)) $args = [];
            if ($fn === '') continue;

            try {
                $result = execute_ai_tool($fn, $args, $u);
            } catch (Throwable $toolError) {
                error_log('[DayPilot AI tool] ' . $toolError->getMessage());
                $result = ['ok' => false, 'error' => 'Tool execution failed safely.'];
            }
            $actions[] = ['tool' => $fn, 'args' => $args, 'result' => $result];
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string)($call['id'] ?? ''),
                'name' => $fn,
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
    }

    return [
        'text' => 'I reached the tool-call limit for this request. Please try the instruction again.',
        'tool_actions' => $actions,
        'provider' => 'openrouter',
        'model' => $model,
        'partial' => true,
    ];
}

function fmt_local_for_ai(string $value): string {
    $ts=strtotime($value);
    return $ts===false ? $value : date('M j, g:i A',$ts);
}

function local_assistant_fallback(string $message, array $u): array {
    $text = trim(mb_strtolower($message));
    $actions = [];

    if (preg_match('/^(add|create|make)\s+(a\s+)?task\b(.+)$/i', $message, $m)) {
        $title = trim(preg_replace('/\b(?:for|on)\s+(today|tomorrow)\b.*$/i', '', trim($m[3])));
        $priority = str_contains($text, ' high') ? 'high' : (str_contains($text, ' low') ? 'low' : 'medium');
        $minutes = null;
        if (preg_match('/(\d{1,3})\s*(?:minutes?|mins?)/i', $message, $mm)) $minutes = (int)$mm[1];
        $due = null;
        if (preg_match('/\btomorrow\b/i', $message)) $due = date('Y-m-d H:i:s', strtotime('+1 day'));
        elseif (preg_match('/\btoday\b/i', $message)) $due = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $args = ['title' => clean_text($title, 255), 'priority' => $priority];
        if ($minutes !== null) $args['estimated_minutes'] = $minutes;
        if ($due !== null) $args['due_at'] = $due;
        $result = execute_ai_tool('create_task', $args, $u);
        $actions[] = ['tool' => 'create_task', 'args' => $args, 'result' => $result];
        return ['text' => ($result['ok'] ?? false) ? 'Created the task and saved it to your workspace.' : ('I could not create the task: ' . ($result['error'] ?? 'unknown error') . '.'), 'tool_actions' => $actions, 'provider' => 'local', 'model' => 'deterministic-fallback', 'partial' => false];
    }

    if (preg_match('/\b(plan|schedule)\b.*\b(today|tomorrow)\b/i', $message) || preg_match('/\bplan my day\b/i', $message)) {
        $date = preg_match('/\btomorrow\b/i', $message) ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
        $result = execute_ai_tool('plan_today', ['date' => $date], $u);
        $actions[] = ['tool' => 'plan_today', 'args' => ['date' => $date], 'result' => $result];
        $count = count($result['blocks'] ?? []);
        return ['text' => $count ? "I scheduled {$count} focus block(s) using the local planner." : 'I could not find enough open space for a focus plan today.', 'tool_actions' => $actions, 'provider' => 'local', 'model' => 'deterministic-fallback', 'partial' => false];
    }

    if (preg_match('/\b(list|show|what are|my)\b.*\b(tasks?|work)\b/i', $message)) {
        $result = execute_ai_tool('list_tasks', ['status' => 'open', 'limit' => 20], $u);
        $tasks = $result['tasks'] ?? [];
        if (!$tasks) $reply = 'You have no open tasks.';
        else {
            $lines = array_map(static fn($t) => '- ' . $t['title'] . ' (' . $t['priority'] . ')', array_slice($tasks, 0, 10));
            $reply = "Here are your open tasks:\n" . implode("\n", $lines);
        }
        return ['text' => $reply, 'tool_actions' => [], 'provider' => 'local', 'model' => 'deterministic-fallback', 'partial' => false];
    }

    if (preg_match('/\b(remind me|reminder)\b/i', $message)) {
        $whenText = null;
        if (preg_match('/\bat\s+([0-9]{1,2}(?::[0-9]{2})?\s*(?:am|pm))\b/i', $message, $mm)) $whenText=$mm[1];
        elseif (preg_match('/\b(tomorrow|today)\b/i', $message)) $whenText=preg_replace('/.*\b(today|tomorrow)\b.*/i','$1',strtolower($message));
        $when=$whenText?strtotime($whenText):false;
        if($whenText==='tomorrow')$when=strtotime('+1 day 09:00');
        elseif($whenText==='today')$when=strtotime('+2 hours');
        if(!$when || $when<time()+30) $when=strtotime('+1 hour');
        $title=preg_replace('/^.*?\b(?:remind me|reminder)\b(?:\s+(?:at\s+[^ ]+(?:\s+[^ ]+)?)?)?\s*(?:to|about)?\s*/i','',$message);
        $title=trim($title)?:'DayPilot reminder';
        $result=execute_ai_tool('create_reminder',['title'=>$title,'remind_at'=>date('Y-m-d H:i:s',$when)],$u);
        return ['text'=>($result['ok']??false)?'Reminder saved. Make sure push notifications are enabled on this device for delivery.':'I could not save the reminder: '.($result['error']??'unknown error').'.','tool_actions'=>[['tool'=>'create_reminder','args'=>['title'=>$title,'remind_at'=>date('Y-m-d H:i:s',$when)],'result'=>$result]],'provider'=>'local','model'=>'deterministic-fallback','partial'=>false];
    }

    if (preg_match('/\b(revise|revision|quiz|test me|what did i learn)\b/i', $message)) {
        $logs = fetch_work_logs($u['id'], 14, null, 40);
        $notes = fetch_notes($u['id']);
        $items = [];
        foreach (array_slice($logs, 0, 8) as $row) $items[] = '- ' . $row['title'] . ': ' . trim((string)$row['content']);
        foreach (array_slice($notes, 0, 5) as $row) $items[] = '- Note: ' . $row['title'] . ' — ' . trim((string)$row['content']);
        $reply = "Revision session (last 14 days)\n\n" . ($items ? implode("\n", $items) : '- No recent learning material recorded yet.') . "\n\nTry this now:\n1. Explain the top topic without looking at notes.\n2. Write one example or code snippet from memory.\n3. Capture the weak area as a learning update so it appears in the next revision session.";
        return ['text'=>$reply,'tool_actions'=>[],'provider'=>'local','model'=>'deterministic-fallback','partial'=>false];
    }

    if (preg_match('/\b(focus|priorities|priority)\b.*\b(today|now)\b|\bwhat should i focus on today\b/i', $message)) {
        $tasks = fetch_tasks($u['id'], 'open', 12);
        $top = array_slice($tasks, 0, 3);
        if (!$top) {
            return ['text'=>'You have no open tasks yet. Capture the next outcome you want to complete today, and DayPilot will use it for your plan.','tool_actions'=>[],'provider'=>'local','model'=>'deterministic-fallback','partial'=>false];
        }
        $lines=[];
        foreach($top as $index=>$task){
            $meta=$task['priority'];
            if(!empty($task['due_at'])) $meta.=' · due '.fmt_local_for_ai((string)$task['due_at']);
            $lines[]=(string)($index+1).'. '.$task['title'].' ('.$meta.')';
        }
        $reply="Today's suggested focus based on your recorded open work:\n\n".implode("\n",$lines)."\n\nStart with #1, then re-plan after it is complete. You can also say 'plan my day' to schedule these tasks.";
        return ['text'=>$reply,'tool_actions'=>[],'provider'=>'local','model'=>'deterministic-fallback','partial'=>false];
    }

    if (preg_match('/\b(review today|daily review|review my day|end of day review)\b/i', $message)) {
        $date = date('Y-m-d');
        $review = deterministic_daily_review($u['id'], $date);
        return ['text'=>$review,'tool_actions'=>[],'provider'=>'local','model'=>'deterministic-fallback','partial'=>false];
    }

    if (preg_match('/\b(resume|where did i stop|yesterday)\b/i', $message)) {
        $logs=fetch_work_logs($u['id'],2,null,20);
        $tasks=fetch_tasks($u['id'],'open',10);
        $lines=[];
        foreach(array_slice($logs,0,5) as $row) $lines[]='- '.$row['title'].': '.trim((string)$row['content']);
        $taskLines=[];
        foreach(array_slice($tasks,0,5) as $row) $taskLines[]='- '.$row['title'].' ('.$row['priority'].')';
        $reply="Recorded recent work:\n".($lines?implode("\n",$lines):'- No recent work log recorded.')."\n\nOpen work:\n".($taskLines?implode("\n",$taskLines):'- No open tasks.');
        return ['text'=>$reply,'tool_actions'=>[],'provider'=>'local','model'=>'deterministic-fallback','partial'=>false];
    }

    if (preg_match('/\b(weekly recap|this week|accomplished this week|what did i accomplish)\b/i', $message)) {
        $logs=fetch_work_logs($u['id'],7,null,30);
        $lines=[]; foreach(array_slice($logs,0,10) as $row) $lines[]='- '.$row['title'].': '.trim((string)$row['content']);
        $reply="Recorded work from the last 7 days:\n\n".($lines?implode("\n",$lines):'- No recent work logs recorded yet.');
        return ['text'=>$reply,'tool_actions'=>[],'provider'=>'local','model'=>'deterministic-fallback','partial'=>false];
    }

    if (preg_match('/\b(analytics|progress|productivity)\b/i', $message)) {
        $result = execute_ai_tool('get_analytics', [], $u);
        $s = $result['summary'] ?? [];
        return ['text' => sprintf('Last %d days: %d total tasks, %d open, %d done, %d overdue, %d focus minutes.', (int)($result['days'] ?? 30), (int)($s['total'] ?? 0), (int)($s['open'] ?? 0), (int)($s['done'] ?? 0), (int)($s['overdue'] ?? 0), (int)($s['focus_minutes'] ?? 0)), 'tool_actions' => [], 'provider' => 'local', 'model' => 'deterministic-fallback', 'partial' => false];
    }

    return [
        'text' => 'The AI providers are unavailable right now. I can still add tasks, list open work, plan today/tomorrow, and show basic analytics while the provider is unavailable.',
        'tool_actions' => [],
        'provider' => 'local',
        'model' => 'deterministic-fallback',
        'partial' => false,
    ];
}

function ai_orchestrate_chat(string $message, array $u): array {
    $text = trim(mb_strtolower($message));

    // Core DayPilot commands are local-first. They must remain responsive even
    // when a free online model is queued, rate-limited, or unavailable.
    $localFirst = preg_match('/(?:plan my day|plan today|schedule .*\b(today|tomorrow)\b|what should i focus on today|focus .*\b(today|now)\b|where did i stop|resume yesterday|weekly recap|what did i accomplish|this week|revise me|test me|what did i learn|remind me|create a reminder|list .*tasks?|show .*tasks?)/i', $message);
    $local = local_assistant_fallback($message, $u);
    $defaultLocal = 'The AI providers are unavailable right now. I can still add tasks, list open work, plan today/tomorrow, and show basic analytics while the provider is unavailable.';
    if ($localFirst || (($local['text'] ?? '') !== $defaultLocal && ($local['provider'] ?? '') === 'local')) {
        return $local;
    }

    // General conversation uses plain text only; tool-calling differences among
    // free models cannot break the application.
    $plan = ai_provider_plan();
    $errors = [];
    foreach (array_slice($plan, 0, 2) as $item) {
        try {
            if($item['provider']==='gemini'){
                $result=gemini_chat($message,$u);
                $result['provider']='gemini';$result['model']=$item['model'];$result['rag_sources']=[];return $result;
            }
            $result = ai_call_openrouter_text($message, $u, $item['model']);
            if ($result !== '') {
                try { $sources = memory_context($u['id'], $message, 6)['sources'] ?? []; }
                catch (Throwable $e) { $sources = []; error_log('[DayPilot RAG after AI] '.$e->getMessage()); }
                return [
                    'text' => $result,
                    'tool_actions' => [],
                    'provider' => 'openrouter',
                    'model' => $item['model'],
                    'rag_sources' => $sources,
                    'partial' => false,
                ];
            }
        } catch (Throwable $e) {
            $errors[] = $item['provider'].':'.$item['model'].' -> '.$e->getMessage();
            error_log('[DayPilot AI provider] '.end($errors));
        }
    }

    $fallback = local_assistant_fallback($message, $u);
    $fallback['provider_error_count'] = count($errors);
    $fallback['online_error'] = (bool)$errors;
    return $fallback;
}

function ai_call_openrouter_text(string $message, array $u, string $model): string {
    $key = trim((string)cfg('ai.openrouter.api_key'));
    if ($key === '') throw new RuntimeException('OpenRouter API key is not configured.');

    [$system] = ai_workspace_context($u, $message);
    // Plain-text request: no tools, so free models with uneven tool support do
    // not cause avoidable 400/422 responses.
    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $message],
        ],
        'temperature' => 0.2,
        'max_tokens' => 700,
    ];
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer '.$key,
    ];
    $referer = trim((string)cfg('ai.openrouter.site_url', 'https://projects.bhavyagupta.space'));
    $appName = trim((string)cfg('ai.openrouter.app_name', 'DayPilot'));
    if ($referer !== '') $headers[] = 'HTTP-Referer: '.$referer;
    if ($appName !== '') $headers[] = 'X-Title: '.$appName;

    $resp = http_json('https://openrouter.ai/api/v1/chat/completions', $headers, $body, 20);
    $content = $resp['choices'][0]['message']['content'] ?? '';
    if (is_array($content)) {
        $parts=[];
        foreach($content as $part) if(is_array($part) && isset($part['text'])) $parts[]=(string)$part['text'];
        $content=implode('', $parts);
    }
    return trim((string)$content);
}

function local_text_fallback(string $prompt, array $u): string {
    if (preg_match('/SOURCE:\s*(.+)$/is', $prompt, $m)) {
        $source = trim($m[1]);
        $sentences = preg_split('/(?<=[.!?])\s+/u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = preg_split('/\W+/u', mb_strtolower($source), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['the','and','for','that','this','with','from','have','will','your','into','what','when','where','about','then','they','them','were','been','are','was','you','not','but'];
        $freq = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 4 || in_array($word, $stop, true)) continue;
            $freq[$word] = ($freq[$word] ?? 0) + 1;
        }
        arsort($freq);
        $terms = array_slice(array_keys($freq), 0, 10);
        $out = ['# Work / Study Notes', '', '## Summary'];
        $out[] = $sentences ? implode(' ', array_slice($sentences, 0, 3)) : mb_substr($source, 0, 600);
        $out[] = ''; $out[] = '## Key points';
        foreach (array_slice($sentences, 0, 8) as $sentence) $out[] = '- '.trim($sentence);
        if (!$sentences) $out[] = '- '.mb_substr($source, 0, 600);
        $out[] = ''; $out[] = '## Important terms';
        $out[] = $terms ? '- '.implode(', ', $terms) : '- None extracted';
        $out[] = ''; $out[] = '## Action items';
        $out[] = '- Review the summary and turn unfinished items into DayPilot tasks.';
        return implode("\n", $out);
    }
    return 'No online model responded. DayPilot local planning and memory features remain available.';
}

function ai_orchestrate_text(string $prompt, array $u): string {
    foreach (ai_provider_plan() as $item) {
        try {
            $text = $item['provider']==='gemini' ? gemini_text($prompt,$u) : ai_call_openrouter_text($prompt, $u, $item['model']);
            if ($text !== '') return $text;
        } catch (Throwable $e) {
            error_log('[DayPilot AI text fallback] '.$item['provider'].':'.$item['model'].' -> '.$e->getMessage());
        }
    }
    return local_text_fallback($prompt, $u);
}

function ai_tools(): array {
    return [
        [
            'type' => 'function',
            'name' => 'create_task',
            'description' => 'Create a personal work task. Use only when the user clearly asks to add work.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Clear task title'],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                    'due_at' => ['type' => 'string', 'description' => 'Optional ISO 8601 date/time deadline'],
                    'estimated_minutes' => ['type' => 'integer', 'description' => 'Optional effort estimate in minutes'],
                    'description' => ['type' => 'string', 'description' => 'Optional task details'],
                ],
                'required' => ['title', 'priority'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'list_tasks',
            'description' => 'List the current user\'s tasks.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['open', 'done', 'all']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'complete_task',
            'description' => 'Mark an existing task complete.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['task_id' => ['type' => 'string']],
                'required' => ['task_id'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'schedule_task',
            'description' => 'Schedule an existing task in the user calendar.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => ['type' => 'string'],
                    'start_at' => ['type' => 'string'],
                    'end_at' => ['type' => 'string'],
                ],
                'required' => ['task_id', 'start_at', 'end_at'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'create_event',
            'description' => 'Create a local calendar event.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'start_at' => ['type' => 'string'],
                    'end_at' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'location' => ['type' => 'string'],
                ],
                'required' => ['title', 'start_at', 'end_at'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'plan_today',
            'description' => 'Build a focus plan for a specific date using existing open tasks and calendar events.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['date' => ['type' => 'string', 'description' => 'Date in YYYY-MM-DD format']],
                'required' => ['date'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'get_analytics',
            'description' => 'Get a concise 30-day work analytics summary.',
            'parameters' => ['type' => 'object'],
        ],
        [
            'type' => 'function',
            'name' => 'create_work_log',
            'description' => 'Capture a daily work update, brain dump, blocker, decision or learning entry.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type'=>'string'],
                    'content' => ['type'=>'string'],
                    'kind' => ['type'=>'string','enum'=>['work_log','brain_dump','blocker','decision','learning','daily_summary']],
                    'project_id' => ['type'=>'string'],
                ],
                'required' => ['title','content'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'search_memory',
            'description' => 'Search the user\'s stored notes and work memory for relevant prior context.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type'=>'string'],
                    'limit' => ['type'=>'integer','minimum'=>1,'maximum'=>10],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'create_reminder',
            'description' => 'Schedule a reminder for the user. Use when the user asks to be reminded about work or revision.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type'=>'string','description'=>'Reminder message'],
                    'remind_at' => ['type'=>'string','description'=>'ISO 8601 date/time in the user timezone'],
                    'task_id' => ['type'=>'string','description'=>'Optional existing DayPilot task id'],
                    'event_id' => ['type'=>'string','description'=>'Optional existing DayPilot event id'],
                ],
                'required' => ['title','remind_at'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'create_note',
            'description' => 'Create a note in the user workspace.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'tags' => ['type' => 'string'],
                ],
                'required' => ['title', 'content'],
            ],
        ],
    ];
}

function execute_ai_tool(string $name, array $args, array $u): array {
    $pdo = db();
    $uid = $u['id'];

    switch ($name) {
        case 'create_task':
            $title = clean_text((string)($args['title'] ?? ''), 255);
            if ($title === '') return ['ok' => false, 'error' => 'Task title is required.'];
            $priority = normalize_priority((string)($args['priority'] ?? 'medium'));
            $minutes = isset($args['estimated_minutes']) ? max(5, min(480, (int)$args['estimated_minutes'])) : null;
            $due = date_sql(isset($args['due_at']) ? (string)$args['due_at'] : null);
            $description = isset($args['description']) ? clean_text((string)$args['description'], 2000) : null;
            $id = uuid();
            $t = now();
            $st = $pdo->prepare('INSERT INTO tasks(id,user_id,title,description,priority,due_at,estimated_minutes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute([$id, $uid, $title, $description, $priority, $due, $minutes, $t, $t]);
            log_activity('ai_create_task', 'task', $id);
            return ['ok' => true, 'task_id' => $id, 'task' => get_task($pdo, $uid, $id)];

        case 'list_tasks':
            $status = (string)($args['status'] ?? 'open');
            $filter = $status === 'all' ? null : $status;
            $limit = max(1, min(50, (int)($args['limit'] ?? 20)));
            return ['tasks' => fetch_tasks($uid, $filter, $limit)];

        case 'complete_task':
            $taskId = clean_text((string)($args['task_id'] ?? ''), 36);
            if ($taskId === '') return ['ok' => false, 'error' => 'Task id is required.'];
            $st = $pdo->prepare('UPDATE tasks SET status="done",completed_at=?,updated_at=? WHERE id=? AND user_id=?');
            $t = now();
            $st->execute([$t, $t, $taskId, $uid]);
            if ($st->rowCount() === 0) return ['ok' => false, 'error' => 'Task not found or already completed.'];
            log_activity('ai_complete_task', 'task', $taskId);
            return ['ok' => true, 'task_id' => $taskId];

        case 'schedule_task':
            $taskId = clean_text((string)($args['task_id'] ?? ''), 36);
            $start = date_sql((string)($args['start_at'] ?? ''));
            $end = date_sql((string)($args['end_at'] ?? ''));
            if ($taskId === '' || !$start || !$end || strtotime($end) <= strtotime($start)) {
                return ['ok' => false, 'error' => 'Valid task id, start time and end time are required.'];
            }
            $st = $pdo->prepare('UPDATE tasks SET start_at=?,end_at=?,updated_at=? WHERE id=? AND user_id=? AND status="open"');
            $st->execute([$start, $end, now(), $taskId, $uid]);
            if ($st->rowCount() === 0) return ['ok' => false, 'error' => 'Open task not found.'];
            log_activity('ai_schedule_task', 'task', $taskId);
            return ['ok' => true, 'task_id' => $taskId, 'start_at' => $start, 'end_at' => $end];

        case 'create_event':
            $title = clean_text((string)($args['title'] ?? ''), 255);
            $start = date_sql((string)($args['start_at'] ?? ''));
            $end = date_sql((string)($args['end_at'] ?? ''));
            if ($title === '' || !$start || !$end || strtotime($end) <= strtotime($start)) {
                return ['ok' => false, 'error' => 'Valid event title, start time and end time are required.'];
            }
            $id = uuid();
            $t = now();
            $st = $pdo->prepare('INSERT INTO events(id,user_id,title,description,location,start_at,end_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute([$id, $uid, $title, isset($args['description']) ? clean_text((string)$args['description'], 2000) : null, isset($args['location']) ? clean_text((string)$args['location'], 255) : null, $start, $end, $t, $t]);
            log_activity('ai_create_event', 'event', $id);
            return ['ok' => true, 'event_id' => $id];

        case 'plan_today':
            $date = trim((string)($args['date'] ?? date('Y-m-d')));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['ok' => false, 'error' => 'Date must use YYYY-MM-DD.'];
            $blocks = plan_day($uid, $date);
            log_activity('ai_plan_day', null, null, ['date' => $date, 'blocks' => count($blocks)]);
            return ['ok' => true, 'date' => $date, 'blocks' => $blocks];

        case 'get_analytics':
            return analytics_data($uid, 30);

        case 'create_work_log':
            $title = clean_text((string)($args['title'] ?? ''), 255);
            $content = trim((string)($args['content'] ?? ''));
            if ($title === '' || $content === '') return ['ok'=>false,'error'=>'Work log title and content are required.'];
            $projectId = !empty($args['project_id']) ? clean_text((string)$args['project_id'],36) : null;
            if (!project_exists_for_user($uid,$projectId)) return ['ok'=>false,'error'=>'Project not found for this account.'];
            $kind=(string)($args['kind']??'work_log'); if(!in_array($kind,['work_log','brain_dump','blocker','decision','learning','daily_summary'],true))$kind='work_log';
            $id=uuid();$t=now();$st=$pdo->prepare('INSERT INTO work_logs(id,user_id,project_id,title,content,kind,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');$st->execute([$id,$uid,$projectId,$title,$content,$kind,$t,$t]);
            log_activity('ai_create_work_log','work_log',$id,['kind'=>$kind]);
            return ['ok'=>true,'work_log_id'=>$id];

        case 'search_memory':
            $query=trim((string)($args['query']??''));if($query==='')return ['ok'=>false,'error'=>'Search query is required.'];
            $limit=max(1,min(10,(int)($args['limit']??6)));
            return ['ok'=>true,'matches'=>search_memory($uid,$query,$limit,false)];

        case 'create_reminder':
            $title=clean_text((string)($args['title']??'Reminder'),255);
            $when=date_sql((string)($args['remind_at']??''));
            if($title===''||!$when||strtotime($when)<time()+30) return ['ok'=>false,'error'=>'Reminder title and a future time are required.'];
            $taskId=!empty($args['task_id'])?clean_text((string)$args['task_id'],36):null;
            $eventId=!empty($args['event_id'])?clean_text((string)$args['event_id'],36):null;
            if($taskId){$check=$pdo->prepare('SELECT id FROM tasks WHERE id=? AND user_id=?');$check->execute([$taskId,$uid]);if(!$check->fetch())$taskId=null;}
            if($eventId){$check=$pdo->prepare('SELECT id FROM events WHERE id=? AND user_id=?');$check->execute([$eventId,$uid]);if(!$check->fetch())$eventId=null;}
            $id=uuid();$st=$pdo->prepare('INSERT INTO reminders(id,user_id,task_id,event_id,title,remind_at) VALUES(?,?,?,?,?,?)');$st->execute([$id,$uid,$taskId,$eventId,$title,$when]);
            log_activity('ai_create_reminder','reminder',$id,['remind_at'=>$when]);
            return ['ok'=>true,'reminder_id'=>$id,'remind_at'=>$when,'title'=>$title];

        case 'create_note':
            $title = clean_text((string)($args['title'] ?? ''), 255);
            $content = trim((string)($args['content'] ?? ''));
            if ($title === '' || $content === '') return ['ok' => false, 'error' => 'Note title and content are required.'];
            $id = uuid();
            $t = now();
            $st = $pdo->prepare('INSERT INTO notes(id,user_id,title,content,tags,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
            $st->execute([$id, $uid, $title, $content, isset($args['tags']) ? clean_text((string)$args['tags'], 500) : null, 'ai', $t, $t]);
            log_activity('ai_create_note', 'note', $id);
            return ['ok' => true, 'note_id' => $id];

        default:
            return ['ok' => false, 'error' => 'Unknown tool: ' . $name];
    }
}


function analytics_data(string $uid, int $days = 30): array {
    $pdo=db();
    $days=max(1,min(365,$days));

    // Use literal, clamped integer intervals for broad MariaDB/MySQL compatibility.
    $summarySql='SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status="done" THEN 1 ELSE 0 END) AS done,
        SUM(CASE WHEN status="open" THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN status="open" AND due_at IS NOT NULL AND due_at < NOW() THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN status="done" THEN COALESCE(estimated_minutes,0) ELSE 0 END) AS focus_minutes
      FROM tasks
      WHERE user_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL '.$days.' DAY)';
    $st=$pdo->prepare($summarySql);$st->execute([$uid]);$summary=$st->fetch() ?: [];

    $dailySql='SELECT DATE(completed_at) AS day, COUNT(*) AS completed, SUM(COALESCE(estimated_minutes,0)) AS minutes
      FROM tasks
      WHERE user_id=? AND status="done" AND completed_at IS NOT NULL
        AND completed_at>=DATE_SUB(CURDATE(), INTERVAL '.$days.' DAY)
      GROUP BY DATE(completed_at) ORDER BY day';
    $daily=$pdo->prepare($dailySql);$daily->execute([$uid]);

    $byPriority=$pdo->prepare('SELECT priority, COUNT(*) AS total, SUM(CASE WHEN status="done" THEN 1 ELSE 0 END) AS done FROM tasks WHERE user_id=? GROUP BY priority ORDER BY FIELD(priority,"high","medium","low")');
    $byPriority->execute([$uid]);
    $total=(int)($summary['total']??0);
    $done=(int)($summary['done']??0);
    $completion=$total>0?round(($done/$total)*100,1):0;
    return [
        'days'=>$days,
        'summary'=>[
            'total'=>$total,
            'done'=>$done,
            'open'=>(int)($summary['open_count']??0),
            'overdue'=>(int)($summary['overdue']??0),
            'focus_minutes'=>(int)($summary['focus_minutes']??0),
            'completion_rate'=>$completion
        ],
        'daily'=>$daily->fetchAll(),
        'by_priority'=>$byPriority->fetchAll()
    ];
}
