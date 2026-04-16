<?php
declare(strict_types=1);

require_once __DIR__ . '/leave_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$fromDate = bp_date_ymd(bp_str($input, 'from_date'));
$toDate = bp_date_ymd(bp_str($input, 'to_date'));
$statusRaw = bp_str($input, 'status');
$status = $statusRaw !== '' ? bp_status_code_from_mixed($statusRaw) : null;
$limit = (int)bp_str($input, 'limit', '50');

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
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

$entries = bp_fetch_leave_entries_by_employee($employeeId, $fromDate, $toDate, $status);
$entries = bp_attach_leave_meta(array_slice($entries, 0, max(1, $limit)));

bp_send_json([
    'status' => true,
    'message' => 'Leave list loaded',
    'data' => [
        'staff_unique_id' => $employeeId,
        'staff_row_unique_id' => (string)($staff['unique_id'] ?? ''),
        'employee_id' => $employeeId,
        'items' => $entries,
        'count' => count($entries),
    ],
]);
