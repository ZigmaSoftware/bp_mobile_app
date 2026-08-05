<?php
declare(strict_types=1);

require_once __DIR__ . '/wfh_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$ids = $input['notification_ids'] ?? [];

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}
if (is_string($ids)) {
    $decoded = json_decode($ids, true);
    $ids = is_array($decoded) ? $decoded : explode(',', $ids);
}
if (!is_array($ids)) {
    $ids = [];
}

$staff = bp_wfh_require_staff($staffIdInput);
$employeeId = trim((string)($staff['employee_id'] ?? ''));
$updated = bp_wfh_mark_notifications_read($employeeId, array_map('strval', $ids));

bp_send_json([
    'status' => true,
    'message' => 'WFH notifications updated',
    'data' => [
        'updated' => $updated,
    ],
]);
