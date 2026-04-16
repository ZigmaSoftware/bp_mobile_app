<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

try {
    $input = bp_input();
    $staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));

    if ($staffIdInput === '') {
        bp_send_json([
            'status' => false,
            'message' => 'staff_unique_id or employee_id is required',
        ], 400);
    }

    $lookup = bp_att_fetch_employee_qr_lookup($staffIdInput);
    $record = $lookup['record'] ?? null;
    if (!$record) {
        bp_send_json([
            'status' => false,
            'message' => trim((string)($lookup['message'] ?? 'Employee QR not found')),
        ], 404);
    }

    bp_send_json([
        'status' => true,
        'message' => 'Employee QR loaded',
        'source' => trim((string)($record['source_table'] ?? 'employees')),
        'data' => $record,
    ]);
} catch (Throwable $e) {
    error_log('bp_mobile_app employee_qr fatal: ' . bp_error_text($e));
    bp_send_json([
        'status' => false,
        'message' => 'Failed to load employee QR',
        'error' => bp_error_text($e),
    ], 500);
}
