<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

date_default_timezone_set('Asia/Kolkata');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function bp_send_json(array $payload, int $statusCode = 200)
{
    http_response_code($statusCode);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $fallback = [
            'status' => false,
            'message' => 'Failed to encode JSON response',
            'json_error' => function_exists('json_last_error_msg')
                ? json_last_error_msg()
                : 'unknown',
        ];
        $json = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $json;
    exit;
}

function bp_require_file(array $candidates, string $label): string
{
    foreach ($candidates as $file) {
        if (is_file($file)) {
            return $file;
        }
    }

    bp_send_json([
        'status' => false,
        'message' => "Missing dependency: {$label}",
        'debug_paths' => $candidates,
    ], 500);
}

function bp_find_file(array $candidates): ?string
{
    foreach ($candidates as $file) {
        if (is_file($file)) {
            return $file;
        }
    }

    return null;
}

$legacyRoot = rtrim(BP_BLUE_PLANET_ROOT, DIRECTORY_SEPARATOR);

require_once bp_require_file([
    $legacyRoot . '/config/dbconfig.php',
    __DIR__ . '/../config/dbconfig.php',
], 'dbconfig.php');

require_once bp_require_file([
    $legacyRoot . '/config/new_db.php',
    __DIR__ . '/../config/new_db.php',
], 'new_db.php');

$commonFunctionFile = bp_find_file([
    $legacyRoot . '/include/common_function.php',
    $legacyRoot . '/include/common_functions.php',
    __DIR__ . '/../include/common_function.php',
    __DIR__ . '/../include/common_functions.php',
]);
if ($commonFunctionFile !== null) {
    require_once $commonFunctionFile;
}

if (!isset($pdo)) {
    bp_send_json([
        'status' => false,
        'message' => 'Database wrapper variable $pdo is unavailable.',
    ], 500);
}

function bp_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bp_str(array $input, string $key, string $fallback = ''): string
{
    if (!array_key_exists($key, $input)) {
        return $fallback;
    }
    return trim((string)$input[$key]);
}

function bp_now(): string
{
    return date('Y-m-d H:i:s');
}

function bp_unique_id(): string
{
    if (function_exists('unique_id')) {
        return (string) unique_id();
    }

    return date('YmdHis') . bin2hex(random_bytes(4));
}

function bp_date_ymd(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return null;
    }

    return $value;
}

function bp_status_code_from_mixed(string $value): ?int
{
    $v = strtolower(trim($value));
    if ($v === '') {
        return null;
    }

    if (is_numeric($v)) {
        $n = (int) $v;
        return in_array($n, [0, 1, 2], true) ? $n : null;
    }

    if ($v === 'pending') {
        return 0;
    }
    if ($v === 'approved' || $v === 'approve') {
        return 1;
    }
    if ($v === 'rejected' || $v === 'reject') {
        return 2;
    }

    return null;
}

function bp_status_label(int $status): string
{
    if ($status === 0) {
        return 'Pending';
    }
    if ($status === 1) {
        return 'Approved';
    }
    if ($status === 2) {
        return 'Rejected';
    }

    return 'Unknown';
}

function bp_sql_quote(string $value): string
{
    return "'" . addslashes($value) . "'";
}
