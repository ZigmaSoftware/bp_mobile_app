<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

function bp_att_direct_endpoint_valid_coordinate(?float $latitude, ?float $longitude): bool
{
    if (function_exists('bp_att_is_valid_coordinate')) {
        return bp_att_is_valid_coordinate($latitude, $longitude);
    }

    if (function_exists('bp_att_direct_is_valid_coordinate')) {
        return bp_att_direct_is_valid_coordinate($latitude, $longitude);
    }

    if ($latitude === null || $longitude === null) {
        return false;
    }

    if (!is_finite($latitude) || !is_finite($longitude)) {
        return false;
    }

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return false;
    }

    return abs($latitude) > 0.000001 && abs($longitude) > 0.000001;
}

function bp_att_direct_geofence_payload(?array $nearest, float $radiusMeters, bool $withinGeofence): array
{
    $project = null;
    $distanceMeters = null;

    if ($nearest !== null) {
        $distanceMeters = isset($nearest['distance_meters'])
            ? round((float)$nearest['distance_meters'], 2)
            : null;
        $project = [
            'project_id' => (string)($nearest['project_id'] ?? ''),
            'project_name' => (string)($nearest['project_name'] ?? ''),
            'project_code' => (string)($nearest['project_code'] ?? ''),
            'latitude' => $nearest['latitude'] ?? null,
            'longitude' => $nearest['longitude'] ?? null,
        ];
    }

    return [
        'within_geofence' => $withinGeofence,
        'radius_meters' => $radiusMeters,
        'distance_meters' => $distanceMeters,
        'nearest_project' => $project,
    ];
}

try {
    $input = bp_input();
    $staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));

    if ($staffIdInput === '') {
        bp_send_json([
            'status' => false,
            'message' => 'staff_unique_id or employee_id is required',
        ], 400);
    }

    $latitude = bp_att_float_or_null(bp_str($input, 'latitude'));
    $longitude = bp_att_float_or_null(bp_str($input, 'longitude'));
    if (!bp_att_direct_endpoint_valid_coordinate($latitude, $longitude)) {
        bp_send_json([
            'status' => false,
            'message' => 'GPS coordinates are invalid. Please enable GPS and retry.',
        ], 400);
    }

    $context = bp_att_require_context($staffIdInput);
    $employeeId = trim((string)($context['employee_id'] ?? ''));
    $projectLocations = bp_att_project_locations_for_context($context);

    if (!bp_att_context_has_head_office_access($context, $projectLocations)) {
        bp_send_json([
            'status' => false,
            'message' => 'Direct punch is available only for Head Office users',
            'data' => [
                'head_office_project_id' => bp_att_head_office_project_id(),
            ],
        ], 403);
    }

    $radiusMeters = 200.0;
    $nearest = bp_att_nearest_project_location($projectLocations, (float)$latitude, (float)$longitude);
    $withinGeofence = $nearest !== null
        && isset($nearest['distance_meters'])
        && (float)$nearest['distance_meters'] <= $radiusMeters;
    $geofence = bp_att_direct_geofence_payload($nearest, $radiusMeters, $withinGeofence);

    if ($withinGeofence) {
        $insertResult = bp_att_insert_direct_recognized($context, $input, (float)$latitude, (float)$longitude);
        if (empty($insertResult['status'])) {
            bp_send_json([
                'status' => false,
                'message' => (string)($insertResult['message'] ?? 'Failed to mark direct attendance'),
                'error' => (string)($insertResult['error'] ?? ''),
            ], 500);
        }

        bp_send_json([
            'status' => true,
            'message' => 'Direct attendance marked successfully',
            'data' => [
                'direct_punch' => true,
                'approval_pending' => false,
                'employee_id' => $employeeId,
                'records' => (string)($insertResult['records'] ?? ''),
                'recognition_date' => (string)($insertResult['recognition_date'] ?? ''),
                'recognition_time' => (string)($insertResult['recognition_time'] ?? ''),
                'latitude' => (string)$latitude,
                'longitude' => (string)$longitude,
                'geofence' => $geofence,
            ],
        ]);
    }

    $approvalResult = bp_att_insert_direct_approval($context, $input, (float)$latitude, (float)$longitude);
    if (empty($approvalResult['status']) || empty($approvalResult['approval']) || !is_array($approvalResult['approval'])) {
        bp_send_json([
            'status' => false,
            'message' => (string)($approvalResult['message'] ?? 'Failed to send direct attendance for approval'),
            'error' => (string)($approvalResult['error'] ?? ''),
        ], 500);
    }

    $approvalRow = $approvalResult['approval'];
    try {
        $notificationResult = bp_att_notify_pending_approval($approvalRow, $employeeId);
    } catch (Throwable $e) {
        $notificationResult = [
            'status' => false,
            'error' => $e->getMessage(),
        ];
    }

    bp_send_json([
        'status' => true,
        'message' => 'Direct attendance sent for approval for punching outside geofencing',
        'approval_pending' => true,
        'data' => [
            'direct_punch' => true,
            'approval_pending' => true,
            'employee_id' => $employeeId,
            'records' => (string)($approvalResult['records'] ?? ''),
            'recognition_date' => (string)($approvalResult['recognition_date'] ?? ''),
            'recognition_time' => (string)($approvalResult['recognition_time'] ?? ''),
            'latitude' => (string)$latitude,
            'longitude' => (string)$longitude,
            'geofence' => $geofence,
            'approval' => bp_att_map_approval_row($approvalRow),
            'notification' => $notificationResult,
        ],
    ]);
} catch (Throwable $e) {
    error_log('bp_mobile_app attendance_direct_punch fatal: ' . bp_att_error_text($e));
    bp_send_json([
        'status' => false,
        'message' => 'Failed to process direct attendance punch',
        'error' => bp_att_error_text($e),
    ], 500);
}
