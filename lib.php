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

function http_json(string $url, array $headers, array $body, int $timeout = 90): array {
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Length: ' . strlen($json)]),
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

function gemini_tools(): array {
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
            'parameters' => ['type' => 'object', 'properties' => []],
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

function gemini_chat(string $message, array $u): array {
    $key = trim((string)cfg('ai.api_key'));
    if ($key === '') return ['text' => 'AI is not configured yet. Add the Gemini API key on the server.', 'tool_actions' => []];

    $model = trim((string)cfg('ai.model', 'gemini-3.8-flash')) ?: 'gemini-3.8-flash';
    $tasks = fetch_tasks($u['id'], 'open', 30);
    $events = fetch_events($u['id'], date('Y-m-d 00:00:00'), date('Y-m-d H:i:s', strtotime('+7 days')));
    $notes = fetch_notes($u['id']);
    $context = json_encode([
        'user' => ['name' => $u['name'], 'timezone' => $u['timezone']],
        'now' => now(),
        'tasks' => $tasks,
        'events' => $events,
        'notes' => array_slice($notes, 0, 10),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $system = "You are DayPilot, a practical personal work assistant. Be concise, specific and action-oriented. "
        . "Use tools whenever a request requires reading or changing the user's workspace. "
        . "Never claim an action succeeded unless its tool result says ok=true. "
        . "Use the user's timezone. For relative dates such as tomorrow, calculate them from the current date in that timezone. "
        . "When scheduling, avoid conflicts with existing calendar events and prefer reasonable working hours. "
        . "Current workspace context: {$context}";

    $pdo = db();
    $st = $pdo->prepare('SELECT id,gemini_interaction_id FROM ai_threads WHERE user_id=?');
    $st->execute([$u['id']]);
    $thread = $st->fetch() ?: null;
    $previous = $thread['gemini_interaction_id'] ?? null;
    $tools = gemini_tools();
    $actions = [];
    $lastText = '';
    $input = $message;
    $freshRetryUsed = false;

    for ($round = 0; $round < 4; $round++) {
        $body = [
            'model' => $model,
            'system_instruction' => $system,
            'input' => $input,
            'tools' => $tools,
            'generation_config' => [
                'thinking_level' => 'low',
                'max_output_tokens' => 900,
            ],
        ];
        if ($previous) $body['previous_interaction_id'] = $previous;

        try {
            $resp = http_json(
                'https://generativelanguage.googleapis.com/v1beta/interactions',
                ['Content-Type: application/json', 'Accept: application/json', 'x-goog-api-key: ' . $key],
                $body,
                90
            );
        } catch (Throwable $e) {
            // A thread created by an older deployment may contain an invalid/expired interaction.
            // Retry once without previous_interaction_id so the user does not get stuck on a 400/404.
            if ($previous && !$freshRetryUsed && preg_match('/HTTP (400|404):/i', $e->getMessage())) {
                $freshRetryUsed = true;
                $previous = null;
                $thread = null;
                $input = $message;
                $actions = [];
                $lastText = '';
                $round = -1;
                continue;
            }
            throw $e;
        }

        $previous = (string)($resp['id'] ?? $previous);
        $functionResults = [];
        $hasCall = false;

        foreach (($resp['steps'] ?? []) as $step) {
            $type = (string)($step['type'] ?? '');
            if ($type === 'model_output') {
                foreach (($step['content'] ?? []) as $part) {
                    if (isset($part['text'])) $lastText .= (string)$part['text'];
                }
            }
            if ($type === 'function_call') {
                $hasCall = true;
                $fn = trim((string)($step['name'] ?? ''));
                $args = is_array($step['arguments'] ?? null) ? $step['arguments'] : [];
                if ($fn === '') continue;

                try {
                    $result = execute_ai_tool($fn, $args, $u);
                } catch (Throwable $toolError) {
                    error_log('[DayPilot AI tool] ' . $toolError->getMessage());
                    $result = ['ok' => false, 'error' => 'Tool execution failed safely.'];
                }

                $actions[] = ['tool' => $fn, 'args' => $args, 'result' => $result];
                $functionResults[] = [
                    'type' => 'function_result',
                    'name' => $fn,
                    'call_id' => (string)($step['id'] ?? ''),
                    'result' => [[
                        'type' => 'text',
                        'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ];
            }
        }

        if ($previous) {
            $threadId = $thread['id'] ?? uuid();
            $save = $pdo->prepare(
                'INSERT INTO ai_threads(id,user_id,gemini_interaction_id,updated_at) VALUES(?,?,?,?)\n' .
                'ON DUPLICATE KEY UPDATE gemini_interaction_id=VALUES(gemini_interaction_id),updated_at=VALUES(updated_at)'
            );
            $save->execute([$threadId, $u['id'], $previous, now()]);
        }

        if (!$hasCall) break;
        $input = $functionResults;
    }

    $text = trim($lastText);
    if ($text === '' && !$actions) {
        $text = 'I could not produce a response. Please try again.';
    } elseif ($text === '') {
        $text = 'Done.';
    }

    return ['text' => $text, 'tool_actions' => $actions];
}

function gemini_text(string $prompt, array $u): string {
    $key=(string)cfg('ai.api_key');
    if($key==='') throw new RuntimeException('AI is not configured.');
    $model=(string)cfg('ai.model','gemini-3.8-flash');
    $body=['model'=>$model,'input'=>$prompt,'store'=>false];
    $resp=http_json('https://generativelanguage.googleapis.com/v1beta/interactions',["Content-Type: application/json","x-goog-api-key: {$key}"],$body,60);
    foreach(($resp['steps']??[]) as $step){if(($step['type']??'')==='model_output'){foreach(($step['content']??[]) as $part){if(isset($part['text']))return trim((string)$part['text']);}}}
    return '';
}

function analytics_data(string $uid, int $days = 30): array {
    $pdo=db();
    $st=$pdo->prepare('SELECT COUNT(*) c, SUM(status="done") done, SUM(status="open") open, SUM(CASE WHEN status="open" AND due_at < NOW() THEN 1 ELSE 0 END) overdue, SUM(CASE WHEN status="done" THEN COALESCE(estimated_minutes,0) ELSE 0 END) focus_minutes FROM tasks WHERE user_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL ? DAY)');
    $st->execute([$uid,$days]); $summary=$st->fetch() ?: [];
    $daily=$pdo->prepare('SELECT DATE(completed_at) day, COUNT(*) completed, SUM(COALESCE(estimated_minutes,0)) minutes FROM tasks WHERE user_id=? AND status="done" AND completed_at>=DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(completed_at) ORDER BY day');
    $daily->execute([$uid,$days]);
    $byPriority=$pdo->prepare('SELECT priority, COUNT(*) total, SUM(status="done") done FROM tasks WHERE user_id=? GROUP BY priority ORDER BY FIELD(priority,"high","medium","low")');$byPriority->execute([$uid]);
    $completion = ((int)($summary['c']??0))>0 ? round(((int)($summary['done']??0)/(int)$summary['c'])*100,1) : 0;
    return ['days'=>$days,'summary'=>['total'=>(int)($summary['c']??0),'done'=>(int)($summary['done']??0),'open'=>(int)($summary['open']??0),'overdue'=>(int)($summary['overdue']??0),'focus_minutes'=>(int)($summary['focus_minutes']??0),'completion_rate'=>$completion],'daily'=>$daily->fetchAll(),'by_priority'=>$byPriority->fetchAll()];
}
