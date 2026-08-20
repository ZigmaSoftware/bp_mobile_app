<?php
declare(strict_types=1);

require_once __DIR__ . '/att_reg_helpers.php';

// Approve or reject a regularization request. Ports reg_appr/crud.php case
// "update_att_status", with two of its holes closed:
//
//  * Self-approval. The web guard is
//      if ($row_lm !== $lm_id && $row_emp !== $lm_id) -> Unauthorized
//    so the requester themself passes it and can approve their own request by
//    posting directly; the UI only hides the dropdown. Here the requester is
//    always rejected, regardless of role.
//  * Status transitions. The web is explicitly unguarded ("UPDATE (NO MORE
//    BLOCKING)"), so an approved row can be flipped to rejected repeatedly.
//    Only pending rows can transition here.
//
// Like the web, this writes ONLY to attendance_regularization. Approving does
// not modify any attendance table - the module is record-keeping only.

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$input = bp_input();
$approverInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$attRegUniqueId = trim(bp_str($input, 'att_reg_unique_id', bp_str($input, 'unique_id')));
$statusCode = (int)bp_str($input, 'status');

if ($approverInput === '' || $attRegUniqueId === '' || !in_array($statusCode, [1, 2], true)) {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, att_reg_unique_id, and status 1/2 are required',
    ], 400);
}

$approver = bp_att_reg_require_staff($approverInput);
bp_att_reg_require_approver($approver);
$approverId = trim((string)($approver['employee_id'] ?? ''));

$record = bp_att_reg_fetch_record($attRegUniqueId);
if (!$record) {
    bp_send_json([
        'status' => false,
        'message' => 'Regularization request not found',
    ], 404);
}

if ((int)($record['status'] ?? 0) !== 0) {
    bp_send_json([
        'status' => false,
        'message' => 'Only pending regularization requests can be updated',
        'error_title' => 'Already Processed',
    ], 409);
}

$employeeId = trim((string)($record['employee_id'] ?? ''));

if ($employeeId === $approverId) {
    bp_send_json([
        'status' => false,
        'message' => 'You cannot approve your own regularization request',
    ], 403);
}

// Privileged roles (HR / Developer) approve anyone; everyone else only their
// direct reportees (single hop, matching the web's st.reporting_officer = $empid
// check). Same test the dashboard uses to decide the queue scope.
if (!bp_att_reg_is_privileged_approver($approver)) {
    $employee = bp_att_reg_require_staff($employeeId);
    $expectedOfficer = bp_att_reg_fetch_reporting_officer($employee);
    if ($expectedOfficer === '' || $expectedOfficer !== $approverId) {
        bp_send_json([
            'status' => false,
            'message' => 'Unauthorized: this regularization request is not assigned to you',
        ], 403);
    }
}

$now = bp_now();
$update = bp_filter_table_columns(BP_ATT_REG_TABLE, [
    'status' => $statusCode,
    'approved_by' => $approverId,
    'approved_at' => $now,
    'updated_at' => $now,
    'updated' => $now,
    'updated_user_id' => $approverId,
]);

$result = bp_update_row(BP_ATT_REG_TABLE, $update, ['unique_id' => $attRegUniqueId, 'is_delete' => 0]);
if (!$result || !($result->status ?? false)) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to update regularization request',
        'error' => (string)($result->error ?? ''),
    ], 500);
}

$label = bp_att_reg_status_label($statusCode);
$approverName = trim((string)($approver['staff_name'] ?? '')) ?: $approverId;
$title = 'Attendance regularization ' . $label;
$message = 'Your attendance regularization for ' . (string)($record['shift_date'] ?? '')
    . ' has been ' . strtolower($label) . ' by ' . $approverName . '.';

$notification = ['attempted' => false, 'sent' => false, 'error' => null];
$push = ['attempted' => false, 'sent' => false, 'error' => null];

try {
    $delivery = bp_att_reg_deliver_notification(
        $employeeId,
        $approverId,
        $attRegUniqueId,
        $title,
        $message,
        '/attendance-regularization?attRegId=' . rawurlencode($attRegUniqueId),
        [
            'route' => '/attendance-regularization',
            'attRegId' => $attRegUniqueId,
            'type' => 'att_reg_status',
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
    error_log('bp_mobile_app att_reg_update_status notification error: ' . bp_error_text($e));
}

bp_send_json([
    'status' => true,
    'message' => 'Regularization request ' . strtolower($label),
    'data' => [
        'att_reg_unique_id' => $attRegUniqueId,
        'new_status' => $statusCode,
        'regularization' => bp_att_reg_fetch_record($attRegUniqueId),
        'notification' => $notification,
        'push' => $push,
    ],
]);
