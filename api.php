<?php

declare(strict_types=1);

require __DIR__ . '/index.php';

$config = loadConfig();
$query = trim((string)($_GET['q'] ?? $_GET['model'] ?? $_GET['device'] ?? ''));

if ($query === '') {
    jsonResponse([
        'ok' => false,
        'error' => 'Missing query. Use api.php?q=iphone%2014%20pro',
        'examples' => [
            'api.php?q=iphone%2014%20pro',
            'api.php?q=iPhone15,2',
        ],
    ], 400);
}

try {
    $result = checkSignedFirmwares($query, $config);
    jsonResponse([
        'ok' => true,
        'device' => [
            'name' => $result['device']['name'] ?? '',
            'identifier' => $result['device']['identifier'] ?? '',
            'releaseYear' => $result['device']['releaseYear'] ?? null,
        ],
        'signed' => $result['firmwares'],
        'count' => count($result['firmwares']),
        'source' => IPSW_DEV_BASE_URL . '/' . rawurlencode((string)($result['device']['identifier'] ?? '')),
    ]);
} catch (UserError $error) {
    jsonResponse([
        'ok' => false,
        'error' => $error->getMessage(),
    ], 404);
} catch (Throwable $error) {
    error_log((string)$error);
    jsonResponse([
        'ok' => false,
        'error' => 'Internal error',
    ], 500);
}
