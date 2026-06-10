<?php

declare(strict_types=1);

require __DIR__ . '/index.php';

$config = loadConfig();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonResponse([
        'ok' => true,
        'service' => 'ipsw-telegram-webhook',
        'mode' => 'direct-reply',
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

jsonResponse(buildWebhookResponse($update, $config));

function buildWebhookResponse(array $update, array $config): array
{
    $message = $update['message'] ?? $update['edited_message'] ?? null;

    if (!is_array($message)) {
        return ['ok' => true];
    }

    $text = trim((string)($message['text'] ?? ''));
    $chatId = $message['chat']['id'] ?? null;
    $messageId = $message['message_id'] ?? null;
    $threadId = $message['message_thread_id'] ?? null;

    if ($text === '' || $chatId === null) {
        return ['ok' => true];
    }

    if (isCommand($text, 'start') || isCommand($text, 'help')) {
        return telegramSendMessagePayload($chatId, helpText(), $messageId, $threadId);
    }

    $query = getCommandArgument($text, ['check', 'signed', 'ipsw']);

    if ($query === null) {
        // Thử lệnh /get để decrypt IPA
        $appStoreLink = getCommandArgument($text, ['get']);
        if ($appStoreLink !== null) {
            if ($appStoreLink === '') {
                return telegramSendMessagePayload($chatId, 'Mày phải dán link App Store vào. Ví dụ: /get https://apps.apple.com/app/xxx/id123456789', $messageId, $threadId);
            }
            $appId = extractAppStoreId($appStoreLink);
            if ($appId === null) {
                return telegramSendMessagePayload($chatId, 'Link App Store không đúng định dạng. Gửi link có dạng: https://apps.apple.com/app/xxx/id123456789', $messageId, $threadId);
            }
            try {
                triggerGitHubWorkflow($config, $appId, (string)$chatId, (string)($messageId ?? ''));
                return telegramSendMessagePayload($chatId, '⏳ Đang decrypt IPA cho App ID: <code>' . $appId . '</code>... Chờ tao vài phút nha.', $messageId, $threadId);
            } catch (Throwable $error) {
                error_log('GitHub workflow trigger failed: ' . (string)$error);
                return telegramSendMessagePayload($chatId, '❌ Không trigger được decrypt. Kiểm tra lại github_token trong config.php.', $messageId, $threadId);
            }
        }
        return ['ok' => true];
    }

    if ($query === '') {
        return telegramSendMessagePayload($chatId, 'mày phải gõ : /check iphone 14 pro hoặc /check iPhone15,2', $messageId, $threadId);
    }

    try {
        $result = checkSignedFirmwares($query, $config);
        return telegramSendMessagePayload($chatId, formatResult($result), $messageId, $threadId);
    } catch (UserError $error) {
        return telegramSendMessagePayload($chatId, $error->getMessage(), $messageId, $threadId);
    } catch (Throwable $error) {
        error_log('webhook direct reply failed: ' . (string)$error);
        return telegramSendMessagePayload($chatId, 'Tao đang lỗi. chờ tao chút. hihi.', $messageId, $threadId);
    }
}

function telegramSendMessagePayload(int|string $chatId, string $text, int|string|null $replyToMessageId = null, int|string|null $threadId = null): array
{
    if (trim($text) === '') {
        $text = 'Tao đang lỗi. chờ tao chút. hihi.';
    }

    $payload = [
        'method' => 'sendMessage',
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if ($threadId !== null) {
        $payload['message_thread_id'] = $threadId;
    }

    return $payload;
}
