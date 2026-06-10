<?php

declare(strict_types=1);

const IPSW_DEV_DEVICES_URL = 'https://ipsw.dev/devices.json';
const IPSW_DEV_BASE_URL = 'https://ipsw.dev';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    main();
}

function main(): void
{
    $config = loadConfig();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        jsonResponse([
            'ok' => true,
            'service' => 'ipsw-telegram-bot',
            'commands' => ['/check iphone 14 pro', '/signed iPhone15,2', '/get https://apps.apple.com/...'],
        ]);
    }

    if ($method !== 'POST') {
        textResponse('Method not allowed', 405);
    }

    if (!isValidTelegramSecret($config)) {
        textResponse('Unauthorized', 401);
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $update = json_decode($rawBody, true);

    if (!is_array($update)) {
        textResponse('Bad JSON', 400);
    }

    handleTelegramUpdate($update, $config);
    jsonResponse(['ok' => true]);
}

function loadConfig(): array
{
    $configPath = __DIR__ . '/config.php';

    if (!file_exists($configPath)) {
        textResponse('Missing config.php. Copy config.example.php to config.php first.', 500);
    }

    $config = require $configPath;

    if (!is_array($config)) {
        textResponse('config.php must return an array.', 500);
    }

    foreach (['bot_token', 'webhook_secret'] as $key) {
        if (empty($config[$key]) || !is_string($config[$key])) {
            textResponse("Missing config value: {$key}", 500);
        }
    }

    $config['cache_ttl_seconds'] = (int)($config['cache_ttl_seconds'] ?? 900);
    return $config;
}

function handleTelegramUpdate(array $update, array $config): void
{
    $message = $update['message'] ?? $update['edited_message'] ?? null;

    if (!is_array($message)) {
        return;
    }

    $text = trim((string)($message['text'] ?? ''));
    $chatId = $message['chat']['id'] ?? null;
    $messageId = $message['message_id'] ?? null;
    $threadId = $message['message_thread_id'] ?? null;

    if ($text === '' || $chatId === null) {
        return;
    }

    if (isCommand($text, 'start') || isCommand($text, 'help')) {
        safeSendMessage($config, $chatId, helpText(), $messageId, $threadId);
        return;
    }

    $query = getCommandArgument($text, ['check', 'signed', 'ipsw']);

    if ($query === null) {
        // Thử lệnh /get để decrypt IPA
        $appStoreLink = getCommandArgument($text, ['get']);
        if ($appStoreLink !== null) {
            handleGetCommand($config, $chatId, $appStoreLink, $messageId, $threadId);
            return;
        }
        return;
    }

    if ($query === '') {
        safeSendMessage($config, $chatId, 'mày phải gõ : /check iphone 14 pro hoặc /check iPhone15,2', $messageId, $threadId);
        return;
    }

    try {
        safeSendChatAction($config, $chatId, 'typing', $threadId);
        $result = checkSignedFirmwares($query, $config);
        safeSendMessage($config, $chatId, formatResult($result), $messageId, $threadId);
    } catch (UserError $error) {
        safeSendMessage($config, $chatId, $error->getMessage(), $messageId, $threadId);
    } catch (Throwable $error) {
        error_log((string)$error);
        safeSendMessage($config, $chatId, 'Tao đang lỗi. chờ tao chút. hihi.', $messageId, $threadId);
    }
}

function checkSignedFirmwares(string $query, array $config): array
{
    $devices = fetchDevices($config);
    $match = findBestDevice($devices, $query);

    if ($match['device'] === null) {
        throw new UserError("Tao không thấy cái nào tên là \"{$query}\" cả. Gõ rõ ràng hơn. ví dụ : /check iphone 14 pro");
    }

    if (count($match['ambiguous']) > 1) {
        $suggestions = array_slice($match['ambiguous'], 0, 6);
        $lines = array_map(
            fn (array $device): string => '- ' . $device['name'] . ' (' . $device['identifier'] . ')',
            $suggestions
        );

        throw new UserError("Tao thấy model gần giống :\n" . implode("\n", $lines) . "\n\nMày gõ lại đầy đủ đi.");
    }

    $firmwares = fetchSignedFirmwares((string)$match['device']['identifier'], $config);
    return [
        'device' => $match['device'],
        'firmwares' => $firmwares,
    ];
}

function fetchDevices(array $config): array
{
    $json = cachedFetch(IPSW_DEV_DEVICES_URL, 86400, 'devices.json', $config);
    $devices = json_decode($json, true);

    if (!is_array($devices)) {
        throw new RuntimeException('Cannot parse devices.json');
    }

    return $devices;
}

function fetchSignedFirmwares(string $identifier, array $config): array
{
    $url = IPSW_DEV_BASE_URL . '/' . rawurlencode($identifier);
    $cacheName = 'firmware-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $identifier) . '.html';
    $html = cachedFetch($url, max(60, (int)$config['cache_ttl_seconds']), $cacheName, $config);

    return parseSignedFirmwares($html, $identifier);
}

function parseSignedFirmwares(string $html, string $identifier): array
{
    preg_match_all('/<tr\b(?=[^>]*\bclass=["\'][^"\']*\bfirmware\b)(?=[^>]*\bdata-signed=["\']true["\'])[^>]*>.*?<\/tr>/is', $html, $matches);
    $rows = $matches[0] ?? [];
    $firmwares = [];

    foreach ($rows as $row) {
        preg_match('/<div\b[^>]*class=["\'][^"\']*\bfont-semibold\b[^"\']*["\'][^>]*>(.*?)<\/div>/is', $row, $versionMatch);
        preg_match('/<div\b[^>]*class=["\'][^"\']*\bbuild-id\b[^"\']*["\'][^>]*>(.*?)<\/div>/is', $row, $buildMatch);
        preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $row, $cellMatches);
        preg_match('/window\.location\s*=\s*["\']([^"\']+)["\']/i', $row, $downloadMatch);

        $version = cleanHtml($versionMatch[1] ?? '');
        $build = cleanHtml($buildMatch[1] ?? '');
        $cells = array_map('cleanHtml', $cellMatches[1] ?? []);

        if ($version === '' || $build === '') {
            continue;
        }

        $firmwares[] = [
            'version' => $version,
            'build' => $build,
            'signed' => true,
            'released' => $cells[2] ?? '',
            'size' => $cells[3] ?? '',
            'page' => isset($downloadMatch[1])
                ? absoluteUrl($downloadMatch[1])
                : IPSW_DEV_BASE_URL . '/download/' . rawurlencode($identifier) . '/' . rawurlencode($build),
        ];
    }

    return $firmwares;
}

function findBestDevice(array $devices, string $query): array
{
    $normalizedQuery = normalize($query);
    $queryTokens = tokenSet($query);
    $scored = [];

    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }

        $name = (string)($device['name'] ?? '');
        $identifier = (string)($device['identifier'] ?? '');
        $normalizedName = normalize($name);
        $normalizedId = normalize($identifier);
        $nameTokens = tokenSet($name);
        $score = -1;

        if ($normalizedId === $normalizedQuery) {
            $score = 1000;
        } elseif ($normalizedName === $normalizedQuery) {
            $score = 950;
        } elseif ($normalizedQuery !== '' && str_contains($normalizedName, $normalizedQuery)) {
            $score = 700 - abs(strlen($normalizedName) - strlen($normalizedQuery));
        } elseif (allTokensIncluded($queryTokens, $nameTokens)) {
            $score = 600 - abs(count($nameTokens) - count($queryTokens));
        } elseif ($normalizedQuery !== '' && str_contains($normalizedId, $normalizedQuery)) {
            $score = 400;
        }

        if ($score >= 0) {
            $scored[] = ['device' => $device, 'score' => $score];
        }
    }

    usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

    if (count($scored) === 0) {
        return ['device' => null, 'ambiguous' => []];
    }

    $bestScore = $scored[0]['score'];
    $ambiguous = array_values(array_map(
        fn (array $item): array => $item['device'],
        array_filter($scored, fn (array $item): bool => $item['score'] === $bestScore)
    ));

    return [
        'device' => $scored[0]['device'],
        'ambiguous' => $bestScore >= 950 ? [$scored[0]['device']] : $ambiguous,
    ];
}

function cachedFetch(string $url, int $ttl, string $cacheName, array $config): string
{
    $cacheDir = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/' . $cacheName;

    if (is_file($cacheFile) && filemtime($cacheFile) + $ttl > time()) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false) {
            return $cached;
        }
    }

    $body = httpGet($url);

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        @file_put_contents($cacheFile, $body, LOCK_EX);
    }

    return $body;
}

function httpGet(string $url): string
{
    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: ipsw-telegram-bot/1.0\r\n",
                'timeout' => 20,
            ],
        ]);
        $body = file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException("GET {$url} failed");
        }

        return (string)$body;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'ipsw-telegram-bot/1.0',
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException("GET {$url} failed: HTTP {$status} {$error}");
    }

    return (string)$body;
}

function safeSendMessage(array $config, int|string $chatId, string $text, int|string|null $replyToMessageId = null, int|string|null $threadId = null): void
{
    try {
        sendMessage($config, $chatId, $text, null, $threadId);
    } catch (Throwable $error) {
        error_log('sendMessage failed: ' . (string)$error);
    }
}

function sendMessage(array $config, int|string $chatId, string $text, int|string|null $replyToMessageId = null, int|string|null $threadId = null): void
{
    if (trim($text) === '') {
        $text = 'Tao đang lỗi. chờ tao chút. hihi.';
    }

    telegramApi($config, 'sendMessage', [
        'chat_id' => $chatId,
        'message_thread_id' => $threadId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
}

function safeSendChatAction(array $config, int|string $chatId, string $action, int|string|null $threadId = null): void
{
    try {
        sendChatAction($config, $chatId, $action, $threadId);
    } catch (Throwable $error) {
        error_log('sendChatAction failed: ' . (string)$error);
    }
}

function sendChatAction(array $config, int|string $chatId, string $action, int|string|null $threadId = null): void
{
    telegramApi($config, 'sendChatAction', [
        'chat_id' => $chatId,
        'message_thread_id' => $threadId,
        'action' => $action,
    ]);
}

function telegramApi(array $config, string $method, array $payload): array
{
    $url = 'https://api.telegram.org/bot' . $config['bot_token'] . '/' . $method;
    $payload = array_filter($payload, fn ($value): bool => $value !== null);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 20,
            ],
        ]);
        $body = file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException("Telegram {$method} failed");
        }

        $data = json_decode((string)$body, true);
        return is_array($data) ? $data : [];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException("Telegram {$method} failed: HTTP {$status} {$error} {$body}");
    }

    $data = json_decode((string)$body, true);
    return is_array($data) ? $data : [];
}

function formatResult(array $result): string
{
    $device = $result['device'];
    $firmwares = $result['firmwares'];

    if (count($firmwares) === 0) {
        return implode("\n", [
            '<b>' . escapeHtml((string)$device['name']) . '</b>',
            '<code>' . escapeHtml((string)$device['identifier']) . '</code>',
            '',
            'Hiện tao không thấy phiên bản IOS mà mày cần. hoặc mày tìm sai máy. ok!.',
        ]);
    }

    $lines = array_map(function (array $item): string {
        $meta = implode(' · ', array_filter([(string)$item['released'], (string)$item['size']]));
        return '- <b>' . escapeHtml((string)$item['version']) . '</b> <code>' . escapeHtml((string)$item['build']) . '</code>'
            . ($meta !== '' ? "\n  " . escapeHtml($meta) : '');
    }, $firmwares);

    return implode("\n", array_merge([
        '<b>' . escapeHtml((string)$device['name']) . '</b>',
        '<code>' . escapeHtml((string)$device['identifier']) . '</code>',
        '',
        '<b>Các phiên bản mà điện thoại của mày có thể sử dụng :</b>',
    ], $lines, [
        '',
        'Nguồn : ' . IPSW_DEV_BASE_URL . '/' . escapeHtml((string)$device['identifier']),
    ]));
}

// ── /get command: Decrypt IPA qua GitHub Actions ────────────────

function handleGetCommand(array $config, int|string $chatId, string $link, int|string|null $messageId = null, int|string|null $threadId = null): void
{
    if ($link === '') {
        safeSendMessage($config, $chatId, 'Mày phải dán link App Store vào. Ví dụ: /get https://apps.apple.com/app/xxx/id123456789', $messageId, $threadId);
        return;
    }

    $appId = extractAppStoreId($link);

    if ($appId === null) {
        safeSendMessage($config, $chatId, 'Link App Store không đúng định dạng. Gửi link có dạng: https://apps.apple.com/app/xxx/id123456789', $messageId, $threadId);
        return;
    }

    safeSendChatAction($config, $chatId, 'typing', $threadId);
    safeSendMessage($config, $chatId, '⏳ Đang decrypt IPA cho App ID: <code>' . $appId . '</code>... Chờ tao vài phút nha.', $messageId, $threadId);

    try {
        triggerGitHubWorkflow($config, $appId, (string)$chatId, (string)($messageId ?? ''));
    } catch (Throwable $error) {
        error_log('GitHub workflow trigger failed: ' . (string)$error);
        safeSendMessage($config, $chatId, '❌ Không trigger được decrypt. Kiểm tra lại github_token trong config.php.', $messageId, $threadId);
    }
}

function extractAppStoreId(string $link): ?string
{
    // Hỗ trợ các dạng link App Store:
    // https://apps.apple.com/vn/app/zing-mp3/id1554463552
    // https://apps.apple.com/app/id123456789
    // https://apps.apple.com/app/xxx/id123456789?l=vi
    // id123456789

    // Nếu người dùng chỉ paste mỗi id
    if (preg_match('/^(\d{6,12})$/', trim($link), $m)) {
        return $m[1];
    }

    // Extract id từ URL
    if (preg_match('/\/id(\d{6,12})(?:[?\/#]|$)/', $link, $m)) {
        return $m[1];
    }

    return null;
}

function triggerGitHubWorkflow(array $config, string $appId, string $chatId, string $messageId): void
{
    $token = $config['github_token'] ?? '';
    $repo = $config['github_repo'] ?? '';

    if ($token === '' || $repo === '' || str_contains($token, 'xxxxxxxx')) {
        throw new RuntimeException('Thiếu github_token hoặc github_repo trong config.php');
    }

    $url = "https://api.github.com/repos/{$repo}/actions/workflows/decrypt-ipa.yml/dispatches";

    $payload = json_encode([
        'ref' => 'main',
        'inputs' => [
            'app_id' => $appId,
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ],
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'User-Agent: ipsw-telegram-bot',
            'Accept: application/vnd.github+json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        $msg = $body ?: $error;
        throw new RuntimeException("GitHub API HTTP {$status}: {$msg}");
    }
}

// ───────────────────────────────────────────────────────────────

function helpText(): string
{
    return implode("\n", [
        'Tao kiểm tra phiên bản IOS theo model iPhone/iPad.',
        '',
        'Ví dụ:',
        '/check iphone 14 pro',
        '/check iPhone15,2',
        '',
        'Hoặc Decrypt IPA từ App Store:',
        '/get https://apps.apple.com/app/...',
        '',
        'Trong group hãy dùng lệnh /check iphone 14 pro.',
    ]);
}

function getCommandArgument(string $text, array $commands): ?string
{
    foreach ($commands as $command) {
        if (preg_match('/^\/' . preg_quote($command, '/') . '(?:@\w+)?(?:\s+([\s\S]+))?$/i', $text, $matches)) {
            return trim($matches[1] ?? '');
        }
    }

    return null;
}

function isCommand(string $text, string $command): bool
{
    return (bool)preg_match('/^\/' . preg_quote($command, '/') . '(?:@\w+)?(?:\s|$)/i', $text);
}

function isValidTelegramSecret(array $config): bool
{
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    return hash_equals((string)$config['webhook_secret'], (string)$header);
}

function normalize(string $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
}

function tokenSet(string $value): array
{
    preg_match_all('/[a-z0-9]+/i', strtolower($value), $matches);
    return $matches[0] ?? [];
}

function allTokensIncluded(array $needles, array $haystack): bool
{
    if (count($needles) === 0) {
        return false;
    }

    foreach ($needles as $needle) {
        if (!in_array($needle, $haystack, true)) {
            return false;
        }
    }

    return true;
}

function cleanHtml(string $value): string
{
    return trim(html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function absoluteUrl(string $path): string
{
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return IPSW_DEV_BASE_URL . '/' . ltrim($path, '/');
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function textResponse(string $text, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

class UserError extends RuntimeException
{
}
