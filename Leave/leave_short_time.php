<?php
declare(strict_types=1);

require_once __DIR__ . '/leave_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

if (!defined('BP_ENABLE_SHORT_LEAVE') || !BP_ENABLE_SHORT_LEAVE) {
    bp_send_json([
        'status' => false,
        'message' => 'Short Leave is not available yet',
    ], 403);
}

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$dateRaw = bp_str($input, 'from_date', bp_str($input, 'date'));
$shortType = (int)bp_str($input, 'short_type', '0');

if ($staffIdInput === '' || $dateRaw === '' || !in_array($shortType, [1, 2], true)) {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, from_date, and short_type are required',
    ], 400);
}

$date = bp_date_ymd($dateRaw);
if (!$date) {
    bp_send_json([
        'status' => false,
        'message' => 'Invalid from_date. Expected YYYY-MM-DD',
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

if (bp_short_leave_month_count($employeeId, $date) >= BP_SHORT_LEAVE_PER_MONTH) {
    bp_send_json([
        'status' => false,
        'message' => 'You have already applied a Short Leave for ' . date('F Y', strtotime($date)),
        'data' => [
            'date' => $date,
            'allowed_per_month' => BP_SHORT_LEAVE_PER_MONTH,
            'monthly_count' => bp_short_leave_month_count($employeeId, $date),
        ],
    ], 400);
}

$times = bp_short_leave_shift_times($staff, $date, $shortType);
if (!empty($times['error'])) {
    bp_send_json([
        'status' => false,
        'message' => (string)$times['error'],
    ], 400);
}

bp_send_json([
    'status' => true,
    'message' => 'Short Leave time loaded',
    'data' => [
        'date' => $date,
        'short_type' => $shortType,
        'slot_label' => $shortType === 2 ? 'Afternoon' : 'Forenoon',
        'from_time' => (string)$times['from_time'],
        'to_time' => (string)$times['to_time'],
        'total_days' => 0.25,
    ],
]);
