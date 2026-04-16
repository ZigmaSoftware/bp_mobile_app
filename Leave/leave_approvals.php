<?php
declare(strict_types=1);

require_once __DIR__ . '/leave_helpers.php';

$input = bp_input();
$officerIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$fromDate = bp_date_ymd(bp_str($input, 'from_date'));
$toDate = bp_date_ymd(bp_str($input, 'to_date'));
$statusRaw = bp_str($input, 'status', '0');
$status = $statusRaw !== '' ? bp_status_code_from_mixed($statusRaw) : 0;
$limit = (int)bp_str($input, 'limit', '50');

if ($officerIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$staff = bp_fetch_staff($officerIdInput);
if (!$staff) {
    bp_send_json([
        'status' => false,
        'message' => 'Employee not found',
    ], 404);
}

$officerId = trim((string)($staff['employee_id'] ?? ''));
if ($officerId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Officer employee id mapping failed',
    ], 500);
}

$isOfficer = bp_is_reporting_officer($officerId);
$isHrUser = bp_is_hr_staff($officerId);

if (!$isOfficer && !$isHrUser) {
    bp_send_json([
        'status' => true,
        'message' => 'No reporting staff found',
        'data' => [
            'staff_unique_id' => $officerId,
            'staff_row_unique_id' => (string)($staff['unique_id'] ?? ''),
            'officer_id' => $officerId,
            'scope_type' => 'none',
            'items' => [],
            'count' => 0,
        ],
    ]);
}

$scopeType = $isHrUser ? 'hr' : 'reporting_officer';
$entries = $isHrUser
    ? bp_fetch_leave_entries_for_hr($fromDate, $toDate, $status)
    : bp_fetch_leave_entries_for_officer($officerId, $fromDate, $toDate, $status);
$entries = bp_attach_leave_meta(array_slice($entries, 0, max(1, $limit)));

bp_send_json([
    'status' => true,
    'message' => 'Approval list loaded',
    'data' => [
        'staff_unique_id' => $officerId,
        'staff_row_unique_id' => (string)($staff['unique_id'] ?? ''),
        'officer_id' => $officerId,
        'scope_type' => $scopeType,
        'items' => $entries,
        'count' => count($entries),
    ],
]);
