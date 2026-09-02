<?php
declare(strict_types=1);
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

$username = req('username');
if ($username === '') $username = req('user');
if ($username === '') $username = req('user_name');
$password = req('password');
$deviceId = req('device_id', 'bp-mobile-app');

if ($username === '' || $password === '') {
    out(400, ['status' => 'error', 'message' => 'Username and password required']);
}

$ch = curl_init(LEGACY_CRUD_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'action' => 'login',
        'user_name' => $username,
        'password' => $password,
        'device_id' => $deviceId,
    ]),
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT_SECONDS,
    CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
]);

$body = curl_exec($ch);
$err = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false) {
    out(502, ['status' => 'error', 'message' => 'Legacy login unreachable', 'debug' => $err]);
}

$legacy = json_decode($body, true);
if (!is_array($legacy)) {
    out(502, [
        'status' => 'error',
        'message' => 'Legacy login returned non-JSON',
        'preview' => substr(strip_tags($body), 0, 150),
    ]);
}

$legacyStatus = (int)($legacy['status'] ?? 0);
$legacyMsg = (string)($legacy['msg'] ?? '');

if ($httpCode === 200 && $legacyStatus === 1 && ($legacyMsg === 'success_login' || $legacyMsg === 'force_password_change')) {
    if (!empty($legacy['force_password_change'])) {
        out(403, ['status' => 'error', 'message' => 'Password change required on web before mobile login']);
    }

    $session = is_array($legacy['session'] ?? null) ? $legacy['session'] : [];
    $token = bin2hex(random_bytes(24)); // temporary app token

    $sessionUserName = trim((string)($session['user_name'] ?? '')) !== ''
        ? trim((string)$session['user_name'])
        : $username;
    $staffId = trim((string)($session['staff_id'] ?? ''));

    // Off Role accounts carry no staff linkage (user.staff_unique_id = '').
    // The legacy session still fills staff_name / designation_type, because the
    // web-side lookups treat an empty staff id as "no filter" and hand back the
    // first active staff row instead of nothing. Trusting those values shows
    // every off-role user another employee's name and designation, so resolve
    // the identity from the account itself whenever the staff id is blank.
    $isOffRole = ($staffId === '');

    $staffName = trim((string)($session['staff_name'] ?? ''));
    $designation = trim((string)($session['designation_type'] ?? ''));

    if ($isOffRole) {
        $staffName = $sessionUserName;
        $designation = 'Off Role';
    } elseif ($staffName === '') {
        $staffName = $sessionUserName;
    }

    out(200, [
        'status' => 'success',
        'message' => 'Login successful',
        'token' => $token,
        'user_name' => $sessionUserName,
        'staff_name' => $staffName,
        'empid' => $staffId,
        'department_name' => $designation,
        'is_off_role' => $isOffRole,
        'show_icon' => false,
    ]);
}

out(401, [
    'status' => 'error',
    'message' => ($legacyMsg === 'incorrect') ? 'Invalid username or password' : 'Login failed',
]);
