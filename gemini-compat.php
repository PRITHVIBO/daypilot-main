<?php
declare(strict_types=1);
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
            'parameters' => ['type' => 'object'],
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
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

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
            $timestamp = now();
            if ($thread && !empty($thread['id'])) {
                $save = $pdo->prepare(
                    'UPDATE ai_threads SET gemini_interaction_id=?, updated_at=? WHERE id=? AND user_id=?'
                );
                $save->execute([$previous, $timestamp, $thread['id'], $u['id']]);
            } else {
                $threadId = uuid();
                $save = $pdo->prepare(
                    'INSERT INTO ai_threads(id,user_id,gemini_interaction_id,updated_at) VALUES(?,?,?,?)'
                );
                $save->execute([$threadId, $u['id'], $previous, $timestamp]);
                $thread = ['id' => $threadId, 'gemini_interaction_id' => $previous];
            }
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


