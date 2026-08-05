<?php
declare(strict_types=1);

require_once __DIR__ . '/wfh_helpers.php';

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

$staff = bp_wfh_require_staff($staffIdInput);
$employeeId = trim((string)($staff['employee_id'] ?? ''));

bp_send_json([
    'status' => true,
    'message' => 'WFH notifications loaded',
    'data' => [
        'items' => bp_wfh_fetch_notifications($employeeId, $unreadOnly, $limit),
        'unread_count' => bp_wfh_unread_count($employeeId),
    ],
]);
