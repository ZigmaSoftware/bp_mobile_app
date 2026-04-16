<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function bp_att_recognize_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"status":false,"message":"Failed to encode JSON response"}';
    }
    echo $json;
    exit;
}

if (!function_exists('curl_init')) {
    bp_att_recognize_json([
        'status' => false,
        'message' => 'cURL extension is unavailable',
    ], 500);
}

$targetUrl = rtrim((string)BP_FACE_RECOGNITION_BASE_URL, '/') . '/recognize_bp';
$postFields = [];

foreach ($_POST as $key => $value) {
    $postFields[$key] = is_scalar($value) ? (string)$value : '';
}

foreach (['targeted_image', 'captured_image'] as $field) {
    if (!isset($_FILES[$field]['tmp_name']) || !is_uploaded_file((string)$_FILES[$field]['tmp_name'])) {
        continue;
    }

    $tmpName = (string)$_FILES[$field]['tmp_name'];
    $fileName = trim((string)($_FILES[$field]['name'] ?? basename($tmpName)));
    $mimeType = trim((string)($_FILES[$field]['type'] ?? 'application/octet-stream'));
    $postFields[$field] = new CURLFile($tmpName, $mimeType, $fileName);
}

$curl = curl_init($targetUrl);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT_SECONDS,
    CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS + 20,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);

$raw = curl_exec($curl);
$error = curl_error($curl);
$httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

if ($raw === false) {
    bp_att_recognize_json([
        'status' => false,
        'message' => 'Face recognition service is unreachable',
        'error' => $error,
    ], 502);
}

http_response_code($httpCode > 0 ? $httpCode : 200);
if (stripos($contentType, 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    echo $raw;
    exit;
}

bp_att_recognize_json([
    'status' => $httpCode >= 200 && $httpCode < 300,
    'message' => 'Face recognition response received',
    'raw' => (string)$raw,
], $httpCode > 0 ? $httpCode : 200);
