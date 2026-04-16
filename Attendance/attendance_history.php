<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$statusFilter = bp_str($input, 'status', 'all');
$limit = max(1, min((int)bp_str($input, 'limit', '200'), 365));
[$fromDate, $toDate] = bp_att_normalize_date_range($input);

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$context = bp_att_require_context($staffIdInput);
$status = strtolower($statusFilter) === 'all'
    ? null
    : bp_status_code_from_mixed($statusFilter);

if ($statusFilter !== 'all' && $status === null) {
    bp_send_json([
        'status' => false,
        'message' => 'Invalid status filter',
    ], 400);
}

$items = bp_att_fetch_employee_history((string)$context['employee_id'], $fromDate, $toDate, $status, $limit);

bp_send_json([
    'status' => true,
    'message' => 'Attendance approval history loaded',
    'data' => [
        'items' => $items,
        'role_label' => (string)($context['role_label'] ?? 'Employee'),
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ],
]);
