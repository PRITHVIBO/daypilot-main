<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('X-DayPilot-Version: 2.4.0');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Vary: Cookie');

try {
    $u = user();
    $providers = [];
    try { $providers = ai_provider_plan(); } catch (Throwable $e) { error_log('[DayPilot boot AI] '.$e->getMessage()); }
    json_response([
        'ok' => true,
        'version' => '2.4.0',
        'user' => $u,
        'csrf' => csrf_token(),
        'vapid_public_key' => (string)cfg('push.public_key'),
        'features' => [
            'ai' => !empty($providers),
            'push' => (string)cfg('push.public_key') !== '',
            'google_calendar' => (string)cfg('google.client_id') !== '',
        ],
        'ai_orchestrator' => [
            'mode' => (string)cfg('ai.provider','auto'),
            'providers' => array_map(static fn($p) => $p['provider'].':'.$p['model'], $providers),
            'local_fallback' => true,
            'rag_semantic' => (bool)cfg('ai.rag.semantic_queries', false),
        ],
        'memory' => $u ? memory_stats($u['id']) : null,
    ]);
} catch (Throwable $e) {
    error_log('[DayPilot boot] '.$e->getMessage().'\n'.$e->getTraceAsString());
    json_response(['error'=>'Boot failed.','detail'=>'Check the DayPilot server log.'],500);
}
