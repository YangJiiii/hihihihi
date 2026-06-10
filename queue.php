<?php

declare(strict_types=1);

require __DIR__ . '/index.php';

$config = loadConfig();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Auth: secret token trong header hoặc query param ──
$auth = $_SERVER['HTTP_X_QUEUE_TOKEN'] ?? $_GET['token'] ?? '';
if (!hash_equals($config['webhook_secret'], $auth)) {
    jsonResponse(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$queueFile = __DIR__ . '/cache/decrypt-queue.json';
$lockFile = __DIR__ . '/cache/decrypt-queue.lock';

// ── GET: Lấy danh sách job pending ──
if ($method === 'GET') {
    $jobs = readQueue($queueFile);
    $pending = array_values(array_filter($jobs, fn($j) => ($j['status'] ?? '') === 'pending'));
    jsonResponse(['ok' => true, 'jobs' => $pending, 'count' => count($pending)]);
}

// ── POST: Thêm job mới hoặc cập nhật trạng thái ──
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

    // Cập nhật trạng thái job (MacBook gọi)
    $jobId = $input['job_id'] ?? null;
    $action = $input['action'] ?? '';

    if ($jobId !== null && $action === 'complete') {
        $jobs = readQueue($queueFile);
        foreach ($jobs as &$job) {
            if (($job['id'] ?? '') === $jobId) {
                $job['status'] = 'completed';
                $job['download_url'] = $input['download_url'] ?? '';
                $job['completed_at'] = date('c');
            }
        }
        unset($job);
        writeQueue($queueFile, $lockFile, $jobs);
        jsonResponse(['ok' => true, 'updated' => $jobId]);
    }

    // Thêm job mới (PHP bot gọi)
    $appId = $input['app_id'] ?? null;
    $chatId = $input['chat_id'] ?? null;
    $messageId = $input['message_id'] ?? null;

    if ($appId !== null && $chatId !== null) {
        $jobs = readQueue($queueFile);
        $jobs[] = [
            'id' => bin2hex(random_bytes(8)),
            'app_id' => (string)$appId,
            'chat_id' => (string)$chatId,
            'message_id' => (string)($messageId ?? ''),
            'status' => 'pending',
            'created_at' => date('c'),
        ];
        writeQueue($queueFile, $lockFile, $jobs);
        jsonResponse(['ok' => true, 'queued' => count($jobs)]);
    }

    jsonResponse(['ok' => false, 'error' => 'Missing app_id or chat_id'], 400);
}

jsonResponse(['ok' => false, 'error' => 'Method not allowed'], 405);

// ── Helpers ─────────────────────────────────────────

function readQueue(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = file_get_contents($path);
    if ($data === false || $data === '') {
        return [];
    }
    $jobs = json_decode($data, true);
    return is_array($jobs) ? $jobs : [];
}

function writeQueue(string $path, string $lockPath, array $jobs): void
{
    $fp = fopen($lockPath, 'w');
    if ($fp && flock($fp, LOCK_EX)) {
        file_put_contents($path, json_encode($jobs, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
