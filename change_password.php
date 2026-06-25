<?php
declare(strict_types=1);

/**
 * change_password.php
 *
 * Updates a user's password in the legacy blue_planet_erp `user` table.
 *
 * The mobile app sends `username` (also as `user`/`user_name`) and
 * `new_password`. The legacy DB stores passwords in plaintext and keys the
 * user row by `unique_id`, so we first look up `unique_id` from the username
 * (matching `user_name` OR `phone_no`), then update the password directly.
 *
 * DB access ($pdo, the legacy Db wrapper) is wired through config.php +
 * the legacy config/dbconfig.php, the same way the Leave/Attendance modules
 * obtain $pdo.
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function out(int $code, array $payload): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function req(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, ['status' => 'error', 'message' => 'Method not allowed']);
}

// IMPORTANT: read the request into uniquely-named variables BEFORE including
// the legacy dbconfig.php. That config runs in the global scope and declares
// its own $username/$password (the DB credentials), which would otherwise
// overwrite our request values.
$reqUsername = req('username');
if ($reqUsername === '') $reqUsername = req('user');
if ($reqUsername === '') $reqUsername = req('user_name');
$reqNewPassword = req('new_password');
$reqDebug = req('debug');

if ($reqUsername === '' || $reqNewPassword === '') {
    out(400, ['status' => 'error', 'message' => 'Username and new password required']);
}

// ── Wire up the legacy DB wrapper ($pdo) ───────────────────────────────────
$legacyRoot = rtrim(BP_BLUE_PLANET_ROOT, DIRECTORY_SEPARATOR);

$dbConfig = null;
foreach ([
    $legacyRoot . '/config/dbconfig.php',
    __DIR__ . '/config/dbconfig.php',
] as $candidate) {
    if (is_file($candidate)) {
        $dbConfig = $candidate;
        break;
    }
}

if ($dbConfig === null) {
    out(500, ['status' => 'error', 'message' => 'Database configuration is unavailable on the server.']);
}

require_once $dbConfig;

if (!isset($pdo)) {
    out(500, ['status' => 'error', 'message' => 'Database wrapper $pdo is unavailable.']);
}

// ── Look up the user's unique_id by username or phone number ───────────────
$idMatch = "(user_name = '" . addslashes($reqUsername) . "' OR phone_no = '" . addslashes($reqUsername) . "')";

// TEMP DIAGNOSTIC: pass debug=1 to see whether the username matches at all and
// whether the active/delete filter is what excludes the row. Remove after fixing.
if ($reqDebug === '1') {
    $matchAny = $pdo->select(['user', ['unique_id', 'user_name', 'phone_no', 'is_active', 'is_delete']], $idMatch);
    out(200, [
        'status' => 'debug',
        'username_received' => $reqUsername,
        'rows_matching_identity_only' => is_array($matchAny->data ?? null) ? $matchAny->data : [],
    ]);
}

$where = $idMatch . " AND is_active = 1 AND is_delete = 0";
$lookup = $pdo->select(['user', ['unique_id']], $where);

if (!$lookup || !($lookup->status ?? false) || empty($lookup->data) || !is_array($lookup->data)) {
    out(404, ['status' => 'error', 'message' => 'User not found']);
}

$userId = (string)($lookup->data[0]['unique_id'] ?? '');
if ($userId === '') {
    out(404, ['status' => 'error', 'message' => 'User not found']);
}

// ── Update the password (plaintext, matching legacy login + update_password) ─
$update = $pdo->update('user', [
    'password'         => $reqNewPassword,
    'password_updated' => date('Y-m-d H:i:s'),
    'password_status'  => 0,
], [
    'unique_id' => $userId,
]);

if (!$update || !($update->status ?? false)) {
    out(502, ['status' => 'error', 'message' => 'Password update failed. Please try again.']);
}

out(200, [
    'status'  => 'success',
    'message' => 'Password updated successfully.',
]);
