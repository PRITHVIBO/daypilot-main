<?php
declare(strict_types=1);


function ensure_v2_schema(): void {
    static $done = false;
    if ($done) return;
    $pdo = db();
    $tables = ['projects','work_logs','daily_reviews','memory_chunks'];
    foreach ($tables as $table) {
        $st = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?
             LIMIT 1"
        );
        $st->execute([$table]);
        $tableExists = $st->fetchColumn() !== false;
        if (!$tableExists) {
            $definitions = [
                'projects' => "CREATE TABLE IF NOT EXISTS projects (
                    id CHAR(36) PRIMARY KEY,
                    user_id CHAR(36) NOT NULL,
                    name VARCHAR(180) NOT NULL,
                    description TEXT NULL,
                    status ENUM('active','completed','archived') NOT NULL DEFAULT 'active',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_projects_user_status (user_id,status),
                    INDEX idx_projects_user_updated (user_id,updated_at),
                    CONSTRAINT fk_projects_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                'work_logs' => "CREATE TABLE IF NOT EXISTS work_logs (
                    id CHAR(36) PRIMARY KEY,
                    user_id CHAR(36) NOT NULL,
                    project_id CHAR(36) NULL,
                    title VARCHAR(255) NOT NULL,
                    content LONGTEXT NOT NULL,
                    kind ENUM('work_log','brain_dump','blocker','decision','learning','daily_summary') NOT NULL DEFAULT 'work_log',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_work_logs_user_time (user_id,created_at),
                    INDEX idx_work_logs_user_project (user_id,project_id),
                    CONSTRAINT fk_work_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_work_logs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                'daily_reviews' => "CREATE TABLE IF NOT EXISTS daily_reviews (
                    id CHAR(36) PRIMARY KEY,
                    user_id CHAR(36) NOT NULL,
                    review_date DATE NOT NULL,
                    content LONGTEXT NOT NULL,
                    generated_by VARCHAR(80) NOT NULL DEFAULT 'local',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_daily_review (user_id,review_date),
                    INDEX idx_daily_reviews_user_date (user_id,review_date),
                    CONSTRAINT fk_daily_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                'memory_chunks' => "CREATE TABLE IF NOT EXISTS memory_chunks (
                    id CHAR(36) PRIMARY KEY,
                    user_id CHAR(36) NOT NULL,
                    source_type VARCHAR(40) NOT NULL,
                    source_id CHAR(36) NOT NULL,
                    project_id CHAR(36) NULL,
                    chunk_index INT NOT NULL DEFAULT 0,
                    content TEXT NOT NULL,
                    embedding_json LONGTEXT NULL,
                    embedding_model VARCHAR(80) NULL,
                    embedding_hash CHAR(64) NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_memory_source (user_id,source_type,source_id,chunk_index),
                    INDEX idx_memory_user_updated (user_id,updated_at),
                    INDEX idx_memory_user_project (user_id,project_id),
                    INDEX idx_memory_source (user_id,source_type),
                    CONSTRAINT fk_memory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_memory_project FOREIGN KEY (user_id,project_id) REFERENCES users(id,user_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
            // memory_chunks uses the existing user/project foreign-key pattern without relying on composite uniqueness.
            if ($table === 'memory_chunks') {
                $definitions['memory_chunks'] = "CREATE TABLE IF NOT EXISTS memory_chunks (
                    id CHAR(36) PRIMARY KEY,
                    user_id CHAR(36) NOT NULL,
                    source_type VARCHAR(40) NOT NULL,
                    source_id CHAR(36) NOT NULL,
                    project_id CHAR(36) NULL,
                    chunk_index INT NOT NULL DEFAULT 0,
                    content TEXT NOT NULL,
                    embedding_json LONGTEXT NULL,
                    embedding_model VARCHAR(80) NULL,
                    embedding_hash CHAR(64) NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_memory_source (user_id,source_type,source_id,chunk_index),
                    INDEX idx_memory_user_updated (user_id,updated_at),
                    INDEX idx_memory_user_project (user_id,project_id),
                    INDEX idx_memory_source (user_id,source_type),
                    CONSTRAINT fk_memory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_memory_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            }
            $pdo->exec(daypilot_new_table_sql($definitions[$table]));
        }
    }
    $done = true;
}

function fetch_projects(string $uid): array {
    $st = db()->prepare('SELECT id,name,description,status,created_at,updated_at FROM projects WHERE user_id=? ORDER BY FIELD(status,"active","completed","archived"), name');
    $st->execute([$uid]);
    return $st->fetchAll();
}

function project_exists_for_user(string $uid, ?string $projectId): bool {
    if (!$projectId) return true;
    $st = db()->prepare('SELECT id FROM projects WHERE id=? AND user_id=?');
    $st->execute([$projectId, $uid]);
    return (bool)$st->fetch();
}

function fetch_work_logs(string $uid, int $days = 14, ?string $projectId = null, int $limit = 100): array {
    $days = max(1, min(365, $days));
    $limit = max(1, min(300, $limit));
    // MySQL/MariaDB do not consistently accept a bound parameter in INTERVAL syntax.
    // The value is clamped to an integer above, so interpolation here is safe.
    $sql = 'SELECT w.id,w.project_id,w.title,w.content,w.kind,w.created_at,w.updated_at,p.name project_name FROM work_logs w LEFT JOIN projects p ON p.id=w.project_id WHERE w.user_id=? AND w.created_at>=DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';
    $args = [$uid];
    if ($projectId) { $sql .= ' AND w.project_id=?'; $args[] = $projectId; }
    $sql .= ' ORDER BY w.created_at DESC LIMIT ' . $limit;
    $st = db()->prepare($sql); $st->execute($args);
    return $st->fetchAll();
}

function fetch_today_work_logs(string $uid, string $date): array {
    $st = db()->prepare('SELECT w.id,w.project_id,w.title,w.content,w.kind,w.created_at,w.updated_at,p.name project_name FROM work_logs w LEFT JOIN projects p ON p.id=w.project_id WHERE w.user_id=? AND DATE(w.created_at)=? ORDER BY w.created_at ASC');
    $st->execute([$uid, $date]);
    return $st->fetchAll();
}

function fetch_daily_review(string $uid, string $date): ?array {
    $st = db()->prepare('SELECT id,review_date,content,generated_by,created_at,updated_at FROM daily_reviews WHERE user_id=? AND review_date=?');
    $st->execute([$uid, $date]);
    return $st->fetch() ?: null;
}

function memory_stats(string $uid): array {
    $pdo = db();
    $a = $pdo->prepare('SELECT COUNT(*) c FROM memory_chunks WHERE user_id=?'); $a->execute([$uid]); $total = (int)$a->fetchColumn();
    $b = $pdo->prepare('SELECT COUNT(*) c FROM memory_chunks WHERE user_id=? AND embedding_json IS NOT NULL AND embedding_json<>""'); $b->execute([$uid]); $embedded = (int)$b->fetchColumn();
    $c = $pdo->prepare('SELECT COUNT(*) c FROM work_logs WHERE user_id=?'); $c->execute([$uid]); $logs = (int)$c->fetchColumn();
    $d = $pdo->prepare('SELECT COUNT(*) c FROM notes WHERE user_id=?'); $d->execute([$uid]); $notes = (int)$d->fetchColumn();
    $e = $pdo->prepare('SELECT COUNT(*) c FROM projects WHERE user_id=? AND status="active"'); $e->execute([$uid]); $projects = (int)$e->fetchColumn();
    return ['chunks'=>$total,'embedded_chunks'=>$embedded,'work_logs'=>$logs,'notes'=>$notes,'active_projects'=>$projects];
}

function split_memory_text(string $text, int $size = 1800, int $overlap = 240): array {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') return [];
    $size = max(500, $size); $overlap = max(0, min($size - 100, $overlap));
    $len = mb_strlen($text);
    if ($len <= $size) return [$text];
    $chunks = [];
    $start = 0;
    while ($start < $len && count($chunks) < 12) {
        $chunk = mb_substr($text, $start, $size);
        if ($start + mb_strlen($chunk) < $len) {
            $cut = max(mb_strrpos($chunk, '. '), mb_strrpos($chunk, ' '));
            if ($cut !== false && $cut > (int)($size * 0.55)) $chunk = mb_substr($chunk, 0, $cut + 1);
        }
        $chunks[] = trim($chunk);
        $advance = mb_strlen($chunk) - $overlap;
        if ($advance < 100) $advance = mb_strlen($chunk);
        $start += $advance;
    }
    return array_values(array_filter($chunks, static fn($v) => trim($v) !== ''));
}

function openrouter_embedding(string $text): array {
    $key = trim((string)cfg('ai.openrouter.api_key'));
    if ($key === '') throw new RuntimeException('OpenRouter API key is not configured for embeddings.');
    $model = trim((string)cfg('ai.openrouter.embedding_model', 'liquid/lfm-2.5-embedding-350m:free')) ?: 'liquid/lfm-2.5-embedding-350m:free';
    $body = ['model'=>$model,'input'=>$text,'encoding_format'=>'float'];
    $headers = ['Content-Type'=>'application/json','Accept'=>'application/json','Authorization'=>'Bearer '.$key];
    $referer = trim((string)cfg('ai.openrouter.site_url', cfg('app.base_url', 'https://projects.bhavyagupta.space')));
    $appName = trim((string)cfg('ai.openrouter.app_name', 'DayPilot'));
    if ($referer !== '') $headers['HTTP-Referer']=$referer;
    if ($appName !== '') $headers['X-Title']=$appName;
    $resp=http_json('https://openrouter.ai/api/v1/embeddings',$headers,$body,60);
    $values=$resp['data'][0]['embedding']??[];
    if(!is_array($values)||!$values)throw new RuntimeException('OpenRouter embedding response did not include values.');
    return array_map('floatval',$values);
}

function memory_source_rows(string $uid, int $maxSources = 30): array {
    $pdo = db(); $sources = [];
    $st = $pdo->prepare('SELECT id,title,content,tags,created_at,updated_at FROM notes WHERE user_id=? ORDER BY updated_at DESC LIMIT ' . max(1, min(100, $maxSources))); $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) $sources[] = ['type'=>'note','id'=>$r['id'],'title'=>$r['title'],'content'=>$r['content'],'project_id'=>null,'updated_at'=>$r['updated_at']];

    $st = $pdo->prepare('SELECT id,project_id,title,content,kind,created_at,updated_at FROM work_logs WHERE user_id=? ORDER BY created_at DESC LIMIT ' . max(1, min(100, $maxSources))); $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) $sources[] = ['type'=>'work_log','id'=>$r['id'],'title'=>$r['title'],'content'=>$r['content'],'project_id'=>$r['project_id'],'updated_at'=>$r['updated_at']];

    $st = $pdo->prepare('SELECT id,review_date,content,generated_by,created_at,updated_at FROM daily_reviews WHERE user_id=? ORDER BY review_date DESC LIMIT ' . max(1, min(60, $maxSources))); $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) $sources[] = ['type'=>'daily_review','id'=>$r['id'],'title'=>'Daily review '.$r['review_date'],'content'=>$r['content'],'project_id'=>null,'updated_at'=>$r['updated_at']];

    $st = $pdo->prepare('SELECT id,name,description,status,created_at,updated_at FROM projects WHERE user_id=? ORDER BY updated_at DESC LIMIT ' . max(1, min(50, $maxSources))); $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) $sources[] = ['type'=>'project','id'=>$r['id'],'title'=>$r['name'],'content'=>$r['description'] ?: ('Project status: '.$r['status']),'project_id'=>$r['id'],'updated_at'=>$r['updated_at']];
    usort($sources, static fn($a,$b) => strcmp((string)$b['updated_at'], (string)$a['updated_at']));
    return array_slice($sources, 0, $maxSources * 3);
}

function memory_index(string $uid, int $maxSources = 12, bool $semantic = true): array {
    $pdo = db();
    $sources = memory_source_rows($uid, max(1, min(30, $maxSources)));
    $indexed = 0; $embedded = 0; $failed = 0;
    foreach ($sources as $src) {
        $text = trim(($src['title'] ? $src['title'] . "\n" : '') . (string)$src['content']);
        if ($text === '') continue;
        $chunks = split_memory_text($text);
        if (!$chunks) continue;

        foreach ($chunks as $i => $chunkText) {
            $hash = hash('sha256', $chunkText);
            $q = $pdo->prepare('SELECT id,embedding_hash,embedding_json FROM memory_chunks WHERE user_id=? AND source_type=? AND source_id=? AND chunk_index=?');
            $q->execute([$uid,$src['type'],$src['id'],$i]); $existing = $q->fetch() ?: null;
            $embeddingJson = $existing['embedding_json'] ?? null;
            $embeddingModel = $embeddingJson ? (string)cfg('ai.openrouter.embedding_model','liquid/lfm-2.5-embedding-350m:free') : null;

            if (!$existing || (string)$existing['embedding_hash'] !== $hash || $existing['embedding_json'] === null) {
                if ($semantic && !$embeddingJson && trim((string)cfg('ai.openrouter.api_key')) !== '') {
                    try { $embeddingJson = json_encode(openrouter_embedding($chunkText), JSON_THROW_ON_ERROR); $embeddingModel=(string)cfg('ai.openrouter.embedding_model','liquid/lfm-2.5-embedding-350m:free'); $embedded++; }
                    catch (Throwable $e) { $failed++; error_log('[DayPilot memory embedding] '.$e->getMessage()); }
                }
                $now = now();
                if ($existing) {
                    $st = $pdo->prepare('UPDATE memory_chunks SET project_id=?,content=?,embedding_json=?,embedding_model=?,embedding_hash=?,updated_at=? WHERE id=? AND user_id=?');
                    $st->execute([$src['project_id'],$chunkText,$embeddingJson,$embeddingModel,$hash,$now,$existing['id'],$uid]);
                } else {
                    $id=uuid();
                    $st = $pdo->prepare('INSERT INTO memory_chunks(id,user_id,source_type,source_id,project_id,chunk_index,content,embedding_json,embedding_model,embedding_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                    $st->execute([$id,$uid,$src['type'],$src['id'],$src['project_id'],$i,$chunkText,$embeddingJson,$embeddingModel,$hash,$now,$now]);
                }
                $indexed++;
            }
        }
    }
    return ['ok'=>true,'indexed'=>$indexed,'embedded'=>$embedded,'embedding_failures'=>$failed,'stats'=>memory_stats($uid)];
}

function lexical_memory_score(string $query, string $content, string $title = ''): float {
    $q = preg_split('/\W+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $hay = mb_strtolower($title . ' ' . $content);
    if (!$q || $hay === '') return 0;
    $score = 0.0; $unique = array_values(array_unique($q));
    foreach ($unique as $term) {
        if (mb_strlen($term) < 3) continue;
        $count = substr_count($hay, $term);
        if ($count) $score += min(3.0, 0.6 + ($count * 0.15));
    }
    $phrase = mb_strtolower(trim($query));
    if ($phrase !== '' && mb_strlen($phrase) >= 6 && str_contains($hay, $phrase)) $score += 2.2;
    return $score;
}

function cosine_similarity(array $a, array $b): float {
    $n = min(count($a), count($b)); if ($n === 0) return 0;
    $dot=0.0;$aa=0.0;$bb=0.0;
    for($i=0;$i<$n;$i++){ $x=(float)$a[$i];$y=(float)$b[$i];$dot+=$x*$y;$aa+=$x*$x;$bb+=$y*$y; }
    if($aa<=0||$bb<=0)return 0; return $dot/(sqrt($aa)*sqrt($bb));
}

function search_memory(string $uid, string $query, int $limit = 8, bool $semantic = true): array {
    $limit = max(1, min(20, $limit)); $query = trim($query);
    if ($query === '') return [];
    $pdo = db();
    $st = $pdo->prepare('SELECT m.id,m.source_type,m.source_id,m.project_id,m.content,m.embedding_json,m.updated_at, COALESCE(n.title,w.title,r.review_date,p.name, m.source_type) AS title FROM memory_chunks m LEFT JOIN notes n ON n.id=m.source_id AND m.source_type="note" LEFT JOIN work_logs w ON w.id=m.source_id AND m.source_type="work_log" LEFT JOIN daily_reviews r ON r.id=m.source_id AND m.source_type="daily_review" LEFT JOIN projects p ON p.id=m.source_id AND m.source_type="project" WHERE m.user_id=? ORDER BY m.updated_at DESC LIMIT 1800');
    $st->execute([$uid]); $rows=$st->fetchAll();
    $queryEmbedding = null;
    if ($semantic && trim((string)cfg('ai.openrouter.api_key')) !== '') {
        try { $queryEmbedding = openrouter_embedding($query); } catch (Throwable $e) { error_log('[DayPilot memory query embedding] '.$e->getMessage()); }
    }
    $scored=[];
    foreach($rows as $row){
        $lex=lexical_memory_score($query,(string)$row['content'],(string)$row['title']);
        $sem=0.0;
        if($queryEmbedding && !empty($row['embedding_json'])){
            $vec=json_decode((string)$row['embedding_json'],true);
            if(is_array($vec))$sem=max(0.0,cosine_similarity($queryEmbedding,$vec));
        }
        $age=max(0,(time()-strtotime((string)$row['updated_at']))/86400);
        $recency=1/(1+$age*0.08);
        $score=($sem*8.0)+($lex*1.3)+($recency*0.25);
        if($score<=0)continue;
        $scored[]=['score'=>$score,'semantic'=>round($sem,4),'source_type'=>$row['source_type'],'source_id'=>$row['source_id'],'title'=>(string)$row['title'],'content'=>(string)$row['content'],'updated_at'=>$row['updated_at'],'project_id'=>$row['project_id']];
    }
    usort($scored,static fn($a,$b)=>$b['score']<=>$a['score']);
    return array_slice($scored,0,$limit);
}

function memory_context(string $uid, string $query, int $limit = 6): array {
    $hits = search_memory($uid,$query,$limit,(bool)cfg('ai.rag.semantic_queries',false));
    $parts=[];$sources=[];
    foreach($hits as $i=>$h){
        $parts[]='['.($i+1).'] '.$h['title'].' ('.$h['source_type'].', '.$h['updated_at'].')\n'.$h['content'];
        $sources[]=['title'=>$h['title'],'type'=>$h['source_type'],'updated_at'=>$h['updated_at']];
    }
    return ['text'=>$parts ? implode("\n\n",$parts) : 'No matching memory entries yet. Capture some work updates or index your notes.', 'sources'=>$sources, 'hits'=>$hits];
}

function daily_data_snapshot(string $uid, string $date): array {
    $pdo=db();
    $open=fetch_tasks($uid,'open',200);
    $st=$pdo->prepare('SELECT id,title,priority,estimated_minutes,completed_at FROM tasks WHERE user_id=? AND status="done" AND DATE(completed_at)=? ORDER BY completed_at DESC');$st->execute([$uid,$date]);$done=$st->fetchAll();
    $logs=fetch_today_work_logs($uid,$date);
    $review=fetch_daily_review($uid,$date);
    $due=[]; foreach($open as $t){ if(!empty($t['due_at']) && substr((string)$t['due_at'],0,10)===$date)$due[]=$t; }
    return ['date'=>$date,'open_tasks'=>$open,'completed_today'=>$done,'due_today'=>$due,'work_logs'=>$logs,'review'=>$review];
}

function deterministic_daily_review(string $uid,string $date): string {
    $data=daily_data_snapshot($uid,$date); $lines=[];
    $done=$data['completed_today'];$logs=$data['work_logs'];$due=$data['due_today'];$open=$data['open_tasks'];
    $focus=array_sum(array_map(static fn($t)=>(int)($t['estimated_minutes']??0),$done));
    $lines[]='## Daily summary';
    $lines[]='Completed **'.count($done).'** task(s) and captured **'.count($logs).'** work update(s) today, with about **'.$focus.'** minutes of estimated work completed.';
    $lines[]='';$lines[]='## Accomplished';
    foreach(array_slice($done,0,8) as $t)$lines[]='- '.$t['title'];
    if(!$done)$lines[]='- No completed tasks recorded today.';
    $lines[]='';$lines[]='## Carry forward';
    foreach(array_slice($open,0,8) as $t)$lines[]='- '.$t['title'];
    if(!$open)$lines[]='- No open tasks remain.';
    $lines[]='';$lines[]='## Due today';
    foreach(array_slice($due,0,8) as $t)$lines[]='- '.$t['title'];
    if(!$due)$lines[]='- No tasks with a due date today.';
    $lines[]='';$lines[]='## Next step';
    $lines[]='Choose one unfinished task as the first block for the next work session and resume from the latest work update.';
    return implode("\n",$lines);
}
