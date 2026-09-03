<?php
declare(strict_types=1);

/**
 * Optional .env beside this file, so the BP_* flags below can be switched per
 * environment without editing code. One KEY=VALUE per line; # starts a
 * comment; surrounding quotes are stripped.
 *
 * A variable already set in the real environment always wins, so a
 * server-level setting is never overridden by the file. Must run before the
 * getenv() calls below.
 *
 * The .env file is denied to the web by the .htaccess in this directory.
 * Keep it that way - do not move the file somewhere servable.
 */
function bp_load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }

        // A real environment variable always wins over the file.
        if (getenv($key) !== false) {
            continue;
        }

        $value = trim($parts[1]);
        $length = strlen($value);
        if ($length >= 2
            && (($value[0] === '"' && $value[$length - 1] === '"')
                || ($value[0] === "'" && $value[$length - 1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
    }
}

bp_load_env_file(__DIR__ . '/.env');

function bp_first_existing_dir(array $candidates, string $fallback): string
{
    foreach ($candidates as $candidate) {
        $path = trim((string)$candidate);
        if ($path !== '' && is_dir($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }
    }

    return rtrim($fallback, DIRECTORY_SEPARATOR);
}

$bpAppFolder = basename(__DIR__);
$bpEnvDefault = stripos($bpAppFolder, '_beta') !== false ? 'beta' : 'live';
$bpEnv = strtolower(trim((string)(getenv('BP_APP_ENV') ?: $bpEnvDefault)));
$bpEnv = $bpEnv === 'beta' ? 'beta' : 'live';

$bpLegacyRootName = $bpEnv === 'beta' ? 'blue_planet_beta' : 'blue_planet_erp';
$bpAppBaseUrlDefault = $bpEnv === 'beta'
    ? 'https://zigma.in/bp_mobile_app_beta'
    : 'https://zigma.in/bp_mobile_app';
$bpLegacyBaseUrlDefault = 'https://zigma.in/' . $bpLegacyRootName;
$bpLocalLegacyRootDefault = dirname(__DIR__) . DIRECTORY_SEPARATOR . $bpLegacyRootName;
$bpHomeRoot = dirname(dirname(__DIR__));
$bpLegacyRootResolved = bp_first_existing_dir([
    getenv('BP_BLUE_PLANET_ROOT') ?: '',
    $bpLocalLegacyRootDefault,
    $bpHomeRoot . DIRECTORY_SEPARATOR . 'Downloads' . DIRECTORY_SEPARATOR . $bpLegacyRootName,
    $bpHomeRoot . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . $bpLegacyRootName,
], $bpLocalLegacyRootDefault);
$bpQrApiBaseUrlDefault = 'http://zigfly.in:5001';

define('BP_APP_ENV', $bpEnv);
define('BP_BLUE_PLANET_ROOT_NAME', $bpLegacyRootName);
define('BP_APP_BASE_URL', rtrim((string)(getenv('BP_APP_BASE_URL') ?: $bpAppBaseUrlDefault), '/'));
define('BP_LEGACY_WEB_BASE_URL', rtrim((string)(getenv('BP_LEGACY_WEB_BASE_URL') ?: $bpLegacyBaseUrlDefault), '/'));
define('BP_BLUE_PLANET_ROOT', $bpLegacyRootResolved);
define('BP_QR_API_BASE_URL', rtrim((string)(getenv('BP_QR_API_BASE_URL') ?: $bpQrApiBaseUrlDefault), '/'));
define(
    'BP_FACE_RECOGNITION_BASE_URL',
    rtrim((string)(getenv('BP_FACE_RECOGNITION_BASE_URL') ?: BP_QR_API_BASE_URL), '/')
);

/**
 * Short Leave has been approved and is enabled for all applicable users.
 *
 * On by default. Can still be switched off per environment without a
 * redeploy with
 *   BP_ENABLE_SHORT_LEAVE=0
 * (or "false"/"no"/"off") - e.g. to disable it on beta while it is still
 * being verified there, while leaving live on.
 *
 * The Flutter app has its own matching flag; if the two disagree, whichever
 * is OFF wins for that half of the flow (the dropdown entry / the actual
 * submission), so keep both in the same state.
 */
define(
    'BP_ENABLE_SHORT_LEAVE',
    !in_array(
        strtolower(trim((string)getenv('BP_ENABLE_SHORT_LEAVE'))),
        ['0', 'false', 'no', 'off'],
        true
    )
);

/**
 * Attendance Regularization, ported from the web ERP modules att_regular
 * (employee) and reg_appr (approval).
 *
 * On by default. Can be switched off per environment without a redeploy with
 *   BP_ENABLE_ATT_REGULARIZATION=0
 * (or "false"/"no"/"off") - e.g. to hold it on beta while it is verified.
 *
 * Turning this off makes the dashboard report can_use_regularization /
 * can_approve_regularization as false, so the app hides both tiles, and makes
 * the apply/update/delete endpoints 403. Per-role access on top of this flag
 * stays where the web manages it: user_screen_permission for the att_regular
 * and reg_appr screens.
 */
define(
    'BP_ENABLE_ATT_REGULARIZATION',
    !in_array(
        strtolower(trim((string)getenv('BP_ENABLE_ATT_REGULARIZATION'))),
        ['0', 'false', 'no', 'off'],
        true
    )
);

/**
 * Geofence bypass for the mobile punch endpoints - scoped to specific test
 * employee IDs, never a blanket switch.
 *
 * This runs against bp_mobile_app, the real production backend - there is no
 * separate sandbox database - so a global "enforcement off" flag would waive
 * the geofence for every live user, not just the tester. Instead, list the
 * exact employee IDs allowed to bypass it:
 *
 *   BP_ATT_GEOFENCE_BYPASS_EMPLOYEE_IDS=TEST001,BPIN0093
 *
 * Comma-separated, case-insensitive, whitespace ignored. Empty/unset (the
 * default) bypasses nothing - every employee is geofenced as normal. Every
 * OTHER employee ID keeps full geofence enforcement regardless of this
 * setting, so this is safe to set directly on bp_mobile_app's own .env.
 *
 * TESTING ONLY, and only for the listed IDs: while an ID is listed, a punch
 * from ANY location for that employee is accepted, and an off-site punch is
 * written straight to zigfly_recognized as a normal attendance punch instead
 * of going to att_approval for approval. Remove the ID when done testing -
 * this is not something to leave populated.
 *
 * Every bypassed punch is marked in the API response (geofence.enforced =
 * false, geofence.bypass_reason) and written to the PHP error log, so test
 * punches can be told apart from real ones afterwards.
 */
function bp_att_geofence_bypass_employee_ids(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }

    $raw = (string)getenv('BP_ATT_GEOFENCE_BYPASS_EMPLOYEE_IDS');
    $ids = array_values(array_filter(array_map(
        static fn ($id) => strtoupper(trim($id)),
        explode(',', $raw)
    ), static fn ($id) => $id !== ''));

    return $ids;
}

function bp_att_geofence_bypass_allowed(string $employeeId): bool
{
    $employeeId = strtoupper(trim($employeeId));
    if ($employeeId === '') {
        return false;
    }

    return in_array($employeeId, bp_att_geofence_bypass_employee_ids(), true);
}

/**
 * Connect timeout, in seconds, for the optional secondary attendance database
 * (the centralized `blueplanet` DB read by bp_att_blueplanet_pdo()).
 *
 * That connection had no timeout at all, and its default host is a private LAN
 * address (192.168.1.200) which a public web server cannot reach - so PDO
 * blocked on the TCP connect until the OS gave up, roughly 30 seconds, on every
 * single request. attendance_records.php took ~30.6s for both a one-day and a
 * whole-month query, because the cost was this connect, not the query.
 *
 * Keep this small. Set BP_ATTENDANCE_DB_ENABLED=0 to skip the connection
 * entirely where the host is unreachable.
 */
define(
    'BP_ATTENDANCE_DB_CONNECT_TIMEOUT',
    max(1, (int)(getenv('BP_ATTENDANCE_DB_CONNECT_TIMEOUT') ?: 2))
);

/**
 * Punch de-duplication window, in seconds.
 *
 * The punch tables (zigfly_recognized / att_approval) store no punch
 * direction - in/out is inferred from row order within the day - so one punch
 * written twice silently fills the day's out slot and the employee can no
 * longer punch out. The web path has guarded this with a 120s window since
 * day one (folders/manual_attendance/crud.php); the mobile endpoints had no
 * guard, which is what produced the duplicate rows seen in live data.
 *
 * Set BP_ATT_PUNCH_DEDUPE_SECONDS=0 to disable the guard entirely.
 */
define(
    'BP_ATT_PUNCH_DEDUPE_SECONDS',
    max(0, (int)(getenv('BP_ATT_PUNCH_DEDUPE_SECONDS') ?: 120))
);

define('LEGACY_CRUD_URL', BP_LEGACY_WEB_BASE_URL . '/folders/login/crud.php');
define('CONNECT_TIMEOUT_SECONDS', 10);
define('REQUEST_TIMEOUT_SECONDS', 20);
