<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$unreadOnly = in_array(strtolower(bp_str($input, 'unread_only', '1')), ['1', 'true', 'yes'], true);
$limit = max(1, min((int)bp_str($input, 'limit', '30'), 100));

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$context = bp_att_require_context($staffIdInput);
$employeeId = (string)$context['employee_id'];

bp_send_json([
    'status' => true,
    'message' => 'Attendance notifications loaded',
    'data' => [
        'items' => bp_att_fetch_notifications($employeeId, $unreadOnly, $limit),
        'unread_count' => bp_att_notifications_unread_count($employeeId),
    ],
]);
