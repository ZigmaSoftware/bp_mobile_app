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
$approverInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$wfhUniqueId = bp_str($input, 'wfh_unique_id', bp_str($input, 'unique_id'));
$statusCode = (int)bp_str($input, 'status');
$rejectReason = trim(bp_str($input, 'reject_reason', bp_str($input, 'reason')));

if ($approverInput === '' || $wfhUniqueId === '' || !in_array($statusCode, [1, 2], true)) {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, wfh_unique_id, and status 1/2 are required',
    ], 400);
}
if ($statusCode === 2 && $rejectReason === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Rejection reason is required',
    ], 400);
}

$approver = bp_wfh_require_staff($approverInput);
bp_wfh_require_bp_india($approver);
$approverId = trim((string)($approver['employee_id'] ?? ''));

$record = bp_wfh_fetch_record($wfhUniqueId);
if (!$record) {
    bp_send_json([
        'status' => false,
        'message' => 'WFH request not found',
    ], 404);
}
if ((int)($record['status'] ?? 0) !== 0) {
    bp_send_json([
        'status' => false,
        'message' => 'Only pending WFH requests can be updated',
    ], 409);
}

$employeeId = trim((string)($record['employee_id'] ?? ''));
$employee = bp_wfh_require_staff($employeeId);
$expectedOfficer = bp_wfh_fetch_reporting_officer($employee);
if ($expectedOfficer === '' || $expectedOfficer !== $approverId) {
    bp_send_json([
        'status' => false,
        'message' => 'Unauthorized: this WFH request is not assigned to you',
    ], 403);
}

$now = bp_now();
$update = [
    'status' => $statusCode,
    'updated_user_id' => $approverId,
    'updated' => $now,
];
if ($statusCode === 1) {
    $update['approved_by'] = $approverId;
    $update['approved_at'] = $now;
} else {
    $update['reject_reason'] = $rejectReason;
    $update['rejected_by'] = $approverId;
    $update['rejected_at'] = $now;
}

$result = bp_update_row(BP_WFH_TABLE, $update, ['unique_id' => $wfhUniqueId, 'is_delete' => 0]);
if (!$result || !($result->status ?? false)) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to update WFH request',
        'error' => (string)($result->error ?? ''),
    ], 500);
}

$label = bp_wfh_status_label($statusCode);
$title = 'WFH ' . $label;
$message = 'Your WFH request for ' . (string)($record['date'] ?? '') . ' has been ' . strtolower($label) . '.';
if ($statusCode === 2 && $rejectReason !== '') {
    $message .= ' Reason: ' . $rejectReason;
}

$notification = ['attempted' => false, 'sent' => false, 'error' => null];
$push = ['attempted' => false, 'sent' => false, 'error' => null];
try {
    $delivery = bp_wfh_deliver_notification(
        $employeeId,
        $approverId,
        $wfhUniqueId,
        $title,
        $message,
        '/work-from-home?wfhId=' . rawurlencode($wfhUniqueId),
        [
            'route' => '/work-from-home',
            'wfhId' => $wfhUniqueId,
            'type' => 'wfh_status',
            'status' => (string)$statusCode,
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
    error_log('bp_mobile_app wfh_update_status notification error: ' . bp_error_text($e));
}

bp_send_json([
    'status' => true,
    'message' => 'WFH request updated',
    'data' => [
        'wfh_unique_id' => $wfhUniqueId,
        'new_status' => $statusCode,
        'wfh_request' => bp_wfh_fetch_record($wfhUniqueId),
        'notification' => $notification,
        'push' => $push,
    ],
]);
