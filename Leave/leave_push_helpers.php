<?php
declare(strict_types=1);

function bp_is_safe_identifier(string $value): bool
{
    return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
}

function bp_table_columns(string $table): array
{
    static $cache = [];

    if (!bp_is_safe_identifier($table)) {
        return [];
    }

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    global $pdo;

    try {
        $res = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
    } catch (Throwable $e) {
        $cache[$table] = [];
        return $cache[$table];
    }

    if (!$res || !($res->status ?? false) || !is_array($res->data ?? null)) {
        $cache[$table] = [];
        return $cache[$table];
    }

    $set = [];
    foreach ($res->data as $row) {
        $name = trim((string)($row['Field'] ?? ''));
        if ($name !== '' && bp_is_safe_identifier($name)) {
            $set[$name] = true;
        }
    }

    $cache[$table] = $set;
    return $cache[$table];
}

function bp_insert_row_raw(string $table, array $columns): object
{
    if (!bp_is_safe_identifier($table)) {
        return (object) [
            'status' => 0,
            'error' => 'Invalid table name',
        ];
    }

    $names = [];
    $params = [];
    foreach ($columns as $name => $value) {
        $name = trim((string) $name);
        if ($name === '' || !bp_is_safe_identifier($name)) {
            continue;
        }
        $names[] = $name;
        $params[$name] = $value;
    }

    if (empty($names)) {
        return (object) [
            'status' => 0,
            'error' => 'No valid columns to insert',
        ];
    }

    $quotedNames = array_map(static function (string $name): string {
        return '`' . $name . '`';
    }, $names);
    $placeholders = array_map(static function (string $name): string {
        return ':' . $name;
    }, $names);

    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedNames) . ')'
        . ' VALUES (' . implode(', ', $placeholders) . ')';

    global $pdo;

    try {
        $res = $pdo->query($sql, $params);
        if (is_object($res) && property_exists($res, 'status')) {
            return $res;
        }

        return (object) [
            'status' => 1,
            'error' => '',
            'data' => $res,
        ];
    } catch (Throwable $e) {
        return (object) [
            'status' => 0,
            'error' => $e->getMessage(),
        ];
    }
}

function bp_query_rows_raw(string $sql, array $params = []): array
{
    global $pdo;

    try {
        $res = $pdo->query($sql, $params);
    } catch (Throwable $e) {
        return [];
    }

    if (!$res || !($res->status ?? false) || !is_array($res->data ?? null)) {
        return [];
    }

    return $res->data;
}

function bp_query_one_raw(string $sql, array $params = []): ?array
{
    $rows = bp_query_rows_raw($sql, $params);
    return $rows[0] ?? null;
}

function bp_update_row_raw(string $table, array $columns, array $whereColumns): object
{
    if (!bp_is_safe_identifier($table)) {
        return (object) [
            'status' => 0,
            'error' => 'Invalid table name',
        ];
    }

    $setParts = [];
    $whereParts = [];
    $params = [];

    foreach ($columns as $name => $value) {
        $name = trim((string) $name);
        if ($name === '' || !bp_is_safe_identifier($name)) {
            continue;
        }

        $paramName = 'set_' . $name;
        $setParts[] = '`' . $name . '` = :' . $paramName;
        $params[$paramName] = $value;
    }

    foreach ($whereColumns as $name => $value) {
        $name = trim((string) $name);
        if ($name === '' || !bp_is_safe_identifier($name)) {
            continue;
        }

        $paramName = 'where_' . $name;
        $whereParts[] = '`' . $name . '` = :' . $paramName;
        $params[$paramName] = $value;
    }

    if (empty($setParts) || empty($whereParts)) {
        return (object) [
            'status' => 0,
            'error' => 'No valid columns to update',
        ];
    }

    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $setParts)
        . ' WHERE ' . implode(' AND ', $whereParts);

    global $pdo;

    try {
        $res = $pdo->query($sql, $params);
        if (is_object($res) && property_exists($res, 'status')) {
            return $res;
        }

        return (object) [
            'status' => 1,
            'error' => '',
            'data' => $res,
        ];
    } catch (Throwable $e) {
        return (object) [
            'status' => 0,
            'error' => $e->getMessage(),
        ];
    }
}

function bp_notification_route_from_deep_link(string $deepLink): string
{
    $deepLink = trim($deepLink);
    if ($deepLink === '') {
        return '/notifications';
    }

    $path = parse_url($deepLink, PHP_URL_PATH);
    $path = is_string($path) ? trim($path) : '';
    if ($path === '') {
        $path = $deepLink;
    }

    if (strpos($path, '/attendance-approval') === 0) {
        return '/attendance-approval';
    }
    if (strpos($path, '/attendance-summary') === 0) {
        return '/attendance-summary';
    }
    if (strpos($path, '/leave-approval') === 0) {
        return '/leave-approval';
    }
    if (strpos($path, '/leave') === 0) {
        return '/leave';
    }

    return '/notifications';
}

function bp_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 20): array
{
    if (!function_exists('curl_init')) {
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => 'cURL extension is unavailable',
        ];
    }

    $body = json_encode($payload);
    if ($body === false) {
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => 'Failed to encode JSON payload',
        ];
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => $body,
    ]);

    $raw = curl_exec($curl);
    if ($raw === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => $error !== '' ? $error : 'HTTP request failed',
        ];
    }

    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $json = json_decode((string) $raw, true);
    return [
        'status' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'body' => (string) $raw,
        'json' => is_array($json) ? $json : null,
        'error' => $httpCode >= 200 && $httpCode < 300 ? '' : ('HTTP ' . $httpCode),
    ];
}

function bp_http_post_form(string $url, array $payload, array $headers = [], int $timeout = 20): array
{
    if (!function_exists('curl_init')) {
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => 'cURL extension is unavailable',
        ];
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/x-www-form-urlencoded',
        ], $headers),
        CURLOPT_POSTFIELDS => http_build_query($payload),
    ]);

    $raw = curl_exec($curl);
    if ($raw === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => $error !== '' ? $error : 'HTTP request failed',
        ];
    }

    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $json = json_decode((string) $raw, true);
    return [
        'status' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'body' => (string) $raw,
        'json' => is_array($json) ? $json : null,
        'error' => $httpCode >= 200 && $httpCode < 300 ? '' : ('HTTP ' . $httpCode),
    ];
}

function bp_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function bp_error_text($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_string($value)) {
        return trim($value);
    }

    if ($value instanceof Throwable) {
        return trim($value->getMessage());
    }

    if (is_scalar($value)) {
        return trim((string) $value);
    }

    if (is_object($value)) {
        if (method_exists($value, '__toString')) {
            return trim((string) $value);
        }

        return trim(get_class($value));
    }

    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? 'array' : $json;
    }

    return '';
}

function bp_firebase_service_account(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $rawJson = trim((string) getenv('BP_FIREBASE_SERVICE_ACCOUNT_JSON'));
    $config = null;
    $source = '';

    if ($rawJson !== '') {
        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) {
            $config = $decoded;
            $source = 'env:BP_FIREBASE_SERVICE_ACCOUNT_JSON';
        }
    }

    if (!is_array($config)) {
        $envFile = trim((string) getenv('BP_FIREBASE_SERVICE_ACCOUNT_FILE'));
        // Look for a downloaded Firebase admin SDK key (e.g.
        // *firebase-adminsdk*.json) not only beside this file but also in the
        // app root and a /config folder, so a slightly misplaced upload on the
        // server still works. macOS resource-fork stubs (__MACOSX/._*) are
        // never matched because we glob explicit directories, not __MACOSX.
        $downloadedServiceAccounts = array_merge(
            glob(__DIR__ . '/*firebase-adminsdk*.json') ?: [],
            glob(dirname(__DIR__) . '/*firebase-adminsdk*.json') ?: [],
            glob(dirname(__DIR__) . '/config/*firebase-adminsdk*.json') ?: []
        );
        $candidates = array_filter(array_unique(array_merge([
            $envFile,
            __DIR__ . '/firebase-service-account.json',
            dirname(__DIR__) . '/config/firebase-service-account.json',
            dirname(__DIR__, 2) . '/config/firebase-service-account.json',
            dirname(__DIR__, 3) . '/firebase-service-account.json',
        ], $downloadedServiceAccounts)));

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($candidate), true);
            if (is_array($decoded)) {
                $config = $decoded;
                $source = $candidate;
                break;
            }
        }
    }

    if (!is_array($config)) {
        $cache = [
            'status' => false,
            'error' => 'Firebase service account JSON not found. Set BP_FIREBASE_SERVICE_ACCOUNT_FILE or BP_FIREBASE_SERVICE_ACCOUNT_JSON.',
        ];
        return $cache;
    }

    $projectId = trim((string) ($config['project_id'] ?? getenv('BP_FIREBASE_PROJECT_ID') ?? ''));
    $clientEmail = trim((string) ($config['client_email'] ?? ''));
    $privateKey = (string) ($config['private_key'] ?? '');

    if ($projectId === '' || $clientEmail === '' || $privateKey === '') {
        $cache = [
            'status' => false,
            'error' => 'Firebase service account JSON is missing project_id, client_email, or private_key.',
            'source' => $source,
        ];
        return $cache;
    }

    $cache = [
        'status' => true,
        'error' => '',
        'source' => $source,
        'data' => [
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
        ],
    ];
    return $cache;
}

function bp_firebase_access_token(): array
{
    static $cache = [
        'access_token' => '',
        'expires_at' => 0,
    ];

    if ($cache['access_token'] !== '' && (int) $cache['expires_at'] > time() + 60) {
        return [
            'status' => true,
            'error' => '',
            'access_token' => (string) $cache['access_token'],
            'expires_at' => (int) $cache['expires_at'],
        ];
    }

    $serviceAccount = bp_firebase_service_account();
    if (empty($serviceAccount['status'])) {
        return [
            'status' => false,
            'error' => (string) ($serviceAccount['error'] ?? 'Missing Firebase service account'),
        ];
    }

    $data = (array) ($serviceAccount['data'] ?? []);
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $issuedAt = time();
    $expiresAt = $issuedAt + 3600;
    $claims = [
        'iss' => (string) $data['client_email'],
        'sub' => (string) $data['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $issuedAt,
        'exp' => $expiresAt,
    ];

    $jwtPayload = bp_base64url_encode(json_encode($header) ?: '{}')
        . '.'
        . bp_base64url_encode(json_encode($claims) ?: '{}');
    $signature = '';
    $signed = openssl_sign(
        $jwtPayload,
        $signature,
        (string) $data['private_key'],
        OPENSSL_ALGO_SHA256
    );

    if (!$signed) {
        return [
            'status' => false,
            'error' => 'Failed to sign Firebase access token request',
        ];
    }

    $assertion = $jwtPayload . '.' . bp_base64url_encode($signature);
    $response = bp_http_post_form(
        'https://oauth2.googleapis.com/token',
        [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]
    );

    if (empty($response['status'])) {
        return [
            'status' => false,
            'error' => (string) ($response['error'] ?? 'Failed to request Firebase access token'),
            'details' => $response['json'] ?? $response['body'] ?? null,
        ];
    }

    $json = is_array($response['json'] ?? null) ? $response['json'] : [];
    $accessToken = trim((string) ($json['access_token'] ?? ''));
    $tokenTtl = (int) ($json['expires_in'] ?? 3600);
    if ($accessToken === '') {
        return [
            'status' => false,
            'error' => 'Firebase OAuth response did not include access_token',
            'details' => $json,
        ];
    }

    $cache = [
        'access_token' => $accessToken,
        'expires_at' => $issuedAt + max(60, $tokenTtl),
    ];

    return [
        'status' => true,
        'error' => '',
        'access_token' => $accessToken,
        'expires_at' => (int) $cache['expires_at'],
    ];
}

function bp_filter_table_columns(string $table, array $columns): array
{
    $tableColumns = bp_table_columns($table);
    if (empty($tableColumns)) {
        return [];
    }

    return array_filter(
        $columns,
        static function ($_, $key) use ($tableColumns): bool {
            return isset($tableColumns[(string) $key]);
        },
        ARRAY_FILTER_USE_BOTH
    );
}

function bp_upsert_device_token(string $staffId, string $fcmToken, string $platform): array
{
    $staffId = trim($staffId);
    $fcmToken = trim($fcmToken);
    $platform = strtolower(trim($platform));

    if ($staffId === '' || $fcmToken === '') {
        return [
            'status' => false,
            'error' => 'staff_id and fcm_token are required',
        ];
    }

    $tableColumns = bp_table_columns('bp_device_tokens');
    if (empty($tableColumns)) {
        return [
            'status' => false,
            'error' => 'bp_device_tokens table is missing',
        ];
    }

    if (!in_array($platform, ['android', 'ios'], true)) {
        $platform = 'android';
    }

    $now = bp_now();
    $existing = bp_query_one_raw(
        'SELECT `unique_id` FROM `bp_device_tokens` WHERE `fcm_token` = :fcm_token LIMIT 1',
        ['fcm_token' => $fcmToken]
    );

    if ($existing) {
        $update = bp_filter_table_columns('bp_device_tokens', [
            'staff_id' => $staffId,
            'platform' => $platform,
            'last_seen_at' => $now,
            'updated' => $now,
            'is_active' => 1,
            'is_delete' => 0,
        ]);

        $res = bp_update_row_raw('bp_device_tokens', $update, ['fcm_token' => $fcmToken]);

        return [
            'status' => (bool) ($res->status ?? false),
            'error' => bp_error_text($res->error ?? ''),
            'action' => 'updated',
        ];
    }

    $insert = bp_filter_table_columns('bp_device_tokens', [
        'unique_id' => bp_unique_id(),
        'staff_id' => $staffId,
        'platform' => $platform,
        'fcm_token' => $fcmToken,
        'last_seen_at' => $now,
        'created' => $now,
        'updated' => $now,
        'is_active' => 1,
        'is_delete' => 0,
    ]);

    $res = bp_insert_row_raw('bp_device_tokens', $insert);
    return [
        'status' => (bool) ($res->status ?? false),
        'error' => bp_error_text($res->error ?? ''),
        'action' => 'inserted',
    ];
}

function bp_deactivate_device_token(string $fcmToken, string $staffId = ''): array
{
    $fcmToken = trim($fcmToken);
    $staffId = trim($staffId);

    if ($fcmToken === '') {
        return [
            'status' => false,
            'error' => 'fcm_token is required',
            'updated' => 0,
        ];
    }

    $tableColumns = bp_table_columns('bp_device_tokens');
    if (empty($tableColumns)) {
        return [
            'status' => false,
            'error' => 'bp_device_tokens table is missing',
            'updated' => 0,
        ];
    }

    $where = ['fcm_token' => $fcmToken];
    if ($staffId !== '') {
        $where['staff_id'] = $staffId;
    }

    $update = bp_filter_table_columns('bp_device_tokens', [
        'is_active' => 0,
        'is_delete' => 0,
        'updated' => bp_now(),
    ]);
    $res = bp_update_row_raw('bp_device_tokens', $update, $where);

    return [
        'status' => (bool) ($res->status ?? false),
        'error' => bp_error_text($res->error ?? ''),
        'updated' => (bool) ($res->status ?? false) ? 1 : 0,
    ];
}

function bp_fetch_device_tokens(string $staffId): array
{
    $staffId = trim($staffId);
    if ($staffId === '') {
        return [];
    }

    $rows = bp_query_rows_raw(
        'SELECT `fcm_token` FROM `bp_device_tokens` WHERE `staff_id` = :staff_id AND `is_active` = :is_active AND `is_delete` = :is_delete',
        [
            'staff_id' => $staffId,
            'is_active' => 1,
            'is_delete' => 0,
        ]
    );

    $tokens = [];
    foreach ($rows as $row) {
        $token = trim((string) ($row['fcm_token'] ?? ''));
        if ($token !== '') {
            $tokens[] = $token;
        }
    }

    return array_values(array_unique($tokens));
}

function bp_send_push_to_token(string $fcmToken, string $title, string $message, array $data = []): array
{
    $tokenResult = bp_firebase_access_token();
    if (empty($tokenResult['status'])) {
        return [
            'status' => false,
            'error' => (string) ($tokenResult['error'] ?? 'Missing Firebase access token'),
        ];
    }

    $serviceAccount = bp_firebase_service_account();
    $serviceData = (array) ($serviceAccount['data'] ?? []);
    $projectId = trim((string) ($serviceData['project_id'] ?? ''));
    if ($projectId === '') {
        return [
            'status' => false,
            'error' => 'Firebase project_id is missing',
        ];
    }

    $normalizedData = [];
    foreach ($data as $key => $value) {
        $key = trim((string) $key);
        if ($key === '' || $value === null) {
            continue;
        }
        $normalizedData[$key] = (string) $value;
    }

    $payload = [
        'message' => [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body' => $message,
            ],
            'data' => $normalizedData,
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'channel_id' => 'bp_high_importance_notifications',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                    ],
                ],
            ],
        ],
    ];

    $response = bp_http_post_json(
        'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send',
        $payload,
        ['Authorization: Bearer ' . (string) $tokenResult['access_token']]
    );

    $json = is_array($response['json'] ?? null) ? $response['json'] : [];
    $errorText = '';
    if (!$response['status']) {
        $firebaseError = is_array($json['error'] ?? null) ? $json['error'] : [];
        $errorText = trim((string) ($firebaseError['status'] ?? ''));
        $messageText = trim((string) ($firebaseError['message'] ?? ''));
        $detailCode = '';
        $details = is_array($firebaseError['details'] ?? null) ? $firebaseError['details'] : [];
        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $detailCode = trim((string) ($detail['errorCode'] ?? ''));
            if ($detailCode !== '') {
                break;
            }
        }
        if ($messageText !== '') {
            $errorText = $errorText !== '' ? ($errorText . ': ' . $messageText) : $messageText;
        }
        if ($detailCode !== '') {
            $errorText .= ($errorText !== '' ? ' ' : '') . '(' . $detailCode . ')';
        }
        if ($errorText === '') {
            $errorText = (string) ($response['error'] ?? 'FCM send failed');
        }
    }

    return [
        'status' => (bool) ($response['status'] ?? false),
        'error' => $errorText,
        'http_code' => (int) ($response['http_code'] ?? 0),
        'response' => $json,
    ];
}

function bp_send_push_notification_to_staff(string $staffId, string $title, string $message, array $data = []): array
{
    $staffId = trim($staffId);
    if ($staffId === '') {
        return [
            'attempted' => false,
            'sent' => false,
            'token_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'invalidated_count' => 0,
            'error' => 'Missing staff_id',
        ];
    }

    $tokens = bp_fetch_device_tokens($staffId);
    if (empty($tokens)) {
        return [
            'attempted' => false,
            'sent' => false,
            'token_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'invalidated_count' => 0,
            'error' => 'No active device tokens registered',
        ];
    }

    $successCount = 0;
    $failureCount = 0;
    $invalidatedCount = 0;
    $errors = [];

    foreach ($tokens as $token) {
        $result = bp_send_push_to_token($token, $title, $message, $data);
        if (!empty($result['status'])) {
            $successCount++;
            continue;
        }

        $failureCount++;
        $errorText = trim((string) ($result['error'] ?? 'FCM send failed'));
        if ($errorText !== '') {
            $errors[] = $errorText;
        }

        $normalizedError = strtoupper($errorText);
        if (strpos($normalizedError, 'UNREGISTERED') !== false ||
            strpos($normalizedError, 'INVALID_ARGUMENT') !== false) {
            $deactivate = bp_deactivate_device_token($token, $staffId);
            if (!empty($deactivate['status'])) {
                $invalidatedCount++;
            }
        }
    }

    return [
        'attempted' => true,
        'sent' => $successCount > 0,
        'token_count' => count($tokens),
        'success_count' => $successCount,
        'failure_count' => $failureCount,
        'invalidated_count' => $invalidatedCount,
        'error' => empty($errors) ? null : implode(' | ', array_unique($errors)),
    ];
}

function bp_deliver_leave_notification_result(
    string $toStaffId,
    string $fromStaffId,
    string $leaveUniqueId,
    string $title,
    string $message,
    string $deepLink = '/leave-approval',
    array $pushData = []
): array {
    $notification = bp_insert_notification_result(
        $toStaffId,
        $fromStaffId,
        $leaveUniqueId,
        $title,
        $message,
        $deepLink
    );

    $route = bp_notification_route_from_deep_link($deepLink);
    $payload = $pushData;
    if (!isset($payload['route'])) {
        $payload['route'] = $route;
    }
    if (!isset($payload['deepLink'])) {
        $payload['deepLink'] = $deepLink;
    }
    if ($leaveUniqueId !== '' && !isset($payload['leaveId'])) {
        $payload['leaveId'] = $leaveUniqueId;
    }

    $push = bp_send_push_notification_to_staff(
        $toStaffId,
        $title,
        $message,
        $payload
    );

    return [
        'notification' => $notification,
        'push' => $push,
    ];
}
