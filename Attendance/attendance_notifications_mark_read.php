<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$rawIds = $input['notification_ids'] ?? ($input['ids'] ?? null);

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$ids = [];
if (is_array($rawIds)) {
    foreach ($rawIds as $id) {
        $id = trim((string)$id);
        if ($id !== '') {
            $ids[] = $id;
        }
    }
} elseif ($rawIds !== null) {
    $id = trim((string)$rawIds);
    if ($id !== '') {
        $ids[] = $id;
    }
}

if (empty($ids)) {
    bp_send_json([
        'status' => false,
        'message' => 'notification_ids are required',
    ], 400);
}

$context = bp_att_require_context($staffIdInput);
$employeeId = (string)$context['employee_id'];
$updated = bp_att_mark_notifications_read($employeeId, $ids);

bp_send_json([
    'status' => true,
    'message' => 'Attendance notifications updated',
    'data' => [
        'updated_count' => $updated,
        'unread_count' => bp_att_notifications_unread_count($employeeId),
    ],
]);
