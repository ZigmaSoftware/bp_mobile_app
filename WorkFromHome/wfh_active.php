<?php
declare(strict_types=1);

require_once __DIR__ . '/wfh_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$date = bp_date_ymd(bp_str($input, 'date', date('Y-m-d')));

if ($staffIdInput === '' || $date === null) {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id and date are required',
    ], 400);
}

$staff = bp_wfh_require_staff($staffIdInput);
$employeeId = trim((string)($staff['employee_id'] ?? ''));
$isBpIndia = bp_wfh_staff_is_bp_india($staff);
$items = $isBpIndia
    ? bp_wfh_fetch_rows_by_where(
        'employee_id = ' . bp_sql_quote($employeeId)
        . ' AND date = ' . bp_sql_quote($date)
        . ' AND status = 1 AND is_delete = 0 LIMIT 1'
    )
    : [];

bp_send_json([
    'status' => true,
    'message' => 'WFH status loaded',
    'data' => [
        'date' => $date,
        'can_use_wfh' => $isBpIndia,
        'is_bp_india' => $isBpIndia,
        'is_wfh' => !empty($items),
        'wfh_request' => $items[0] ?? null,
    ],
]);
