<?php
declare(strict_types=1);

require_once __DIR__ . '/wfh_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$wfhDate = bp_date_ymd(bp_str($input, 'wfh_date', bp_str($input, 'date')));
$remarks = trim(bp_str($input, 'remarks'));

if ($staffIdInput === '' || $wfhDate === null || $remarks === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, wfh_date, and remarks are required',
    ], 400);
}

$staff = bp_wfh_require_staff($staffIdInput);
bp_wfh_require_bp_india($staff);

$employeeId = trim((string)($staff['employee_id'] ?? ''));
$reportingOfficer = bp_wfh_fetch_reporting_officer($staff);
$departmentId = trim((string)($staff['department'] ?? ''));

$dow = (int)date('N', strtotime($wfhDate));
if ($dow >= 6) {
    bp_send_json([
        'status' => false,
        'message' => 'WFH requests cannot be submitted for Saturday or Sunday.',
        'error_title' => 'Weekend Not Allowed',
    ], 400);
}

if (bp_wfh_has_leave_conflict($employeeId, $wfhDate)) {
    bp_send_json([
        'status' => false,
        'message' => 'You already have a leave application on this date. WFH cannot be applied on a leave date.',
        'error_title' => 'Leave Conflict',
    ], 409);
}

[$weekFrom, $weekTo] = bp_wfh_week_bounds($wfhDate);
$weekWhere = 'employee_id = ' . bp_sql_quote($employeeId)
    . ' AND date >= ' . bp_sql_quote($weekFrom)
    . ' AND date <= ' . bp_sql_quote($weekTo)
    . ' AND status NOT IN (2, 3)'
    . ' AND is_delete = 0';
if (bp_wfh_count($weekWhere) > 0) {
    bp_send_json([
        'status' => false,
        'message' => 'You have already applied a WFH request for this calendar week. Only one WFH per week is permitted.',
        'error_title' => 'Weekly Limit Reached',
    ], 409);
}

[$monthFrom, $monthTo] = bp_wfh_month_bounds($wfhDate);
$monthWhere = 'employee_id = ' . bp_sql_quote($employeeId)
    . ' AND date >= ' . bp_sql_quote($monthFrom)
    . ' AND date <= ' . bp_sql_quote($monthTo)
    . ' AND status NOT IN (2, 3)'
    . ' AND is_delete = 0';
if (bp_wfh_count($monthWhere) >= 4) {
    bp_send_json([
        'status' => false,
        'message' => 'You have already used 4 WFH requests for this month.',
        'error_title' => 'Monthly Limit Reached',
    ], 409);
}

$duplicateWhere = 'employee_id = ' . bp_sql_quote($employeeId)
    . ' AND date = ' . bp_sql_quote($wfhDate)
    . ' AND status != 2'
    . ' AND is_delete = 0';
if (bp_wfh_count($duplicateWhere) > 0) {
    bp_send_json([
        'status' => false,
        'message' => 'A WFH request already exists for this date.',
        'error_title' => 'Duplicate Request',
    ], 409);
}

$now = bp_now();
$uniqueId = bp_unique_id();
$columns = bp_filter_table_columns(BP_WFH_TABLE, [
    'unique_id' => $uniqueId,
    'employee_id' => $employeeId,
    'department' => $departmentId,
    'department_head' => $reportingOfficer,
    'date' => $wfhDate,
    'day' => date('l', strtotime($wfhDate)),
    'remarks' => $remarks,
    'status' => 0,
    'reject_reason' => null,
    'created_user_id' => $employeeId,
    'created' => $now,
    'updated_user_id' => $employeeId,
    'updated' => $now,
    'is_active' => 1,
    'is_delete' => 0,
    'acc_year' => date('Y'),
    'session_id' => '',
    'sess_user_type' => '',
    'sess_user_id' => $employeeId,
    'sess_company_id' => '',
    'sess_branch_id' => '',
]);

$result = bp_insert_row_raw(BP_WFH_TABLE, $columns);
if (!$result || !($result->status ?? false)) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to save WFH request',
        'error' => (string)($result->error ?? ''),
    ], 500);
}

$notification = ['attempted' => false, 'sent' => false, 'error' => null];
$push = ['attempted' => false, 'sent' => false, 'error' => null];
if ($reportingOfficer !== '') {
    try {
        $title = 'WFH request pending';
        $message = 'New WFH request from ' . (string)($staff['staff_name'] ?? $employeeId) . ' on ' . $wfhDate;
        $delivery = bp_wfh_deliver_notification(
            $reportingOfficer,
            $employeeId,
            $uniqueId,
            $title,
            $message,
            '/wfh-approval?wfhId=' . rawurlencode($uniqueId),
            [
                'route' => '/wfh-approval',
                'wfhId' => $uniqueId,
                'type' => 'wfh_approval',
            ]
        );
        $notification = [
            'attempted' => true,
            'sent' => (bool)(($delivery['notification']['status'] ?? false)),
            'error' => (string)(($delivery['notification']['error'] ?? '')),
        ];
        $push = (array)($delivery['push'] ?? $push);
    } catch (Throwable $e) {
        $notification = ['attempted' => true, 'sent' => false, 'error' => bp_error_text($e)];
        $push = ['attempted' => true, 'sent' => false, 'error' => bp_error_text($e)];
        error_log('bp_mobile_app wfh_apply notification error: ' . bp_error_text($e));
    }
}

$saved = bp_wfh_fetch_record($uniqueId);

bp_send_json([
    'status' => true,
    'message' => 'WFH request submitted for approval',
    'data' => [
        'wfh_unique_id' => $uniqueId,
        'wfh_request' => $saved,
        'notification' => $notification,
        'push' => $push,
    ],
]);
