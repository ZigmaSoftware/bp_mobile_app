<?php
declare(strict_types=1);

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

define('LEGACY_CRUD_URL', BP_LEGACY_WEB_BASE_URL . '/folders/login/crud.php');
define('CONNECT_TIMEOUT_SECONDS', 10);
define('REQUEST_TIMEOUT_SECONDS', 20);
