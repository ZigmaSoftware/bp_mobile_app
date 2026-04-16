<?php
declare(strict_types=1);

require_once __DIR__ . '/leave_helpers.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        bp_send_json([
            'status' => false,
            'message' => 'Method not allowed',
        ], 405);
    }

    $input = bp_input();
    $staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
    $fcmToken = trim(bp_str($input, 'fcm_token', bp_str($input, 'token')));

    if ($staffIdInput === '' || $fcmToken === '') {
        bp_send_json([
            'status' => false,
            'message' => 'staff_unique_id or employee_id and fcm_token are required',
        ], 400);
    }

    $staff = bp_fetch_staff($staffIdInput);
    if (!$staff) {
        bp_send_json([
            'status' => false,
            'message' => 'Employee not found',
        ], 404);
    }

    $employeeId = trim((string)($staff['employee_id'] ?? ''));
    if ($employeeId === '') {
        bp_send_json([
            'status' => false,
            'message' => 'Employee id mapping failed',
        ], 500);
    }

    $result = bp_deactivate_device_token($fcmToken, $employeeId);
    $ok = (bool)($result['status'] ?? false);

    bp_send_json([
        'status' => $ok,
        'message' => $ok ? 'Device token removed' : ((string)($result['error'] ?? '') ?: 'Failed to remove device token'),
        'data' => [
            'staff_unique_id' => $employeeId,
            'updated' => (int)($result['updated'] ?? 0),
        ],
    ], $ok ? 200 : 500);
} catch (Throwable $e) {
    bp_send_json([
        'status' => false,
        'message' => 'Token delete crashed',
        'error' => $e->getMessage(),
        'type' => get_class($e),
        'file' => basename((string) $e->getFile()),
        'line' => (int) $e->getLine(),
    ], 500);
}
