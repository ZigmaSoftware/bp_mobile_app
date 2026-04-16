<?php
declare(strict_types=1);

require_once __DIR__ . '/leave_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$input = bp_input();
$officerIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$leaveUniqueId = bp_str($input, 'leave_unique_id', bp_str($input, 'unique_id'));
$statusCode = bp_status_code_from_mixed(bp_str($input, 'status'));

if ($officerIdInput === '' || $leaveUniqueId === '' || $statusCode === null) {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, leave_unique_id and status are required',
    ], 400);
}

if (!in_array($statusCode, [1, 2], true)) {
    bp_send_json([
        'status' => false,
        'message' => 'Invalid status. Use 1 (Approved) or 2 (Rejected)',
    ], 400);
}

$officer = bp_fetch_staff($officerIdInput);
if (!$officer) {
    bp_send_json([
        'status' => false,
        'message' => 'Approver not found',
    ], 404);
}

$officerId = trim((string)($officer['employee_id'] ?? ''));
if ($officerId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Approver employee id mapping failed',
    ], 500);
}

$record = bp_fetch_leave_record($leaveUniqueId);
if (!$record) {
    bp_send_json([
        'status' => false,
        'message' => 'Leave entry not found',
    ], 404);
}

if (((int)($record['status'] ?? 0)) !== 0) {
    bp_send_json([
        'status' => false,
        'message' => 'Only pending leaves can be updated',
    ], 409);
}

$employeeId = trim((string)($record['employee_id'] ?? ''));
if ($employeeId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Leave entry is missing employee_id',
    ], 500);
}

$employee = bp_fetch_staff($employeeId);
if (!$employee) {
    bp_send_json([
        'status' => false,
        'message' => 'Employee not found for this leave entry',
    ], 404);
}

$expectedOfficer = trim((string)($employee['reporting_officer'] ?? ''));
$isHrUser = bp_is_hr_staff($officerId);
if (!$isHrUser && ($expectedOfficer === '' || $expectedOfficer !== $officerId)) {
    bp_send_json([
        'status' => false,
        'message' => 'Unauthorized: You are not allowed to update this leave request',
    ], 403);
}

$now = bp_now();
$update = bp_update_row(
    'leave_entry',
    [
        'status' => $statusCode,
        'approved_by' => $officerId,
        'approved_at' => $now,
        'updated_user_id' => $officerId,
        'updated' => $now,
    ],
    [
        'unique_id' => $leaveUniqueId,
        'is_delete' => 0,
    ]
);

if (!$update || !($update->status ?? false)) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to update leave status',
        'error' => (string)($update->error ?? ''),
    ], 500);
}

$typeMap = bp_fetch_leave_type_map();
$leaveTypeId = (string)($record['leave_type_id'] ?? '');
$leaveTypeLabel = $typeMap[$leaveTypeId]['leave_type'] ?? ($leaveTypeId === 'lwp' ? 'Leave Without Pay' : $leaveTypeId);
$warnings = [];

$title = $statusCode === 1 ? 'Leave Approved' : 'Leave Rejected';
$message = 'Your leave has been ' . strtolower(bp_status_label($statusCode)) . ' • '
    . $leaveTypeLabel . ' • ' . (string)($record['from_date'] ?? '') . ' → ' . (string)($record['to_date'] ?? '');

$notificationResult = [];
$pushResult = [
    'attempted' => false,
    'sent' => false,
    'token_count' => 0,
    'success_count' => 0,
    'failure_count' => 0,
    'invalidated_count' => 0,
    'error' => null,
];
try {
    $deliveryResult = bp_deliver_leave_notification_result(
        $employeeId,
        $officerId,
        $leaveUniqueId,
        $title,
        $message,
        '/leave?leaveId=' . rawurlencode($leaveUniqueId),
        [
            'route' => '/leave',
            'leaveId' => $leaveUniqueId,
            'type' => 'leave_status',
            'status' => (string)$statusCode,
        ]
    );
    $notificationResult = (array)($deliveryResult['notification'] ?? []);
    $pushResult = (array)($deliveryResult['push'] ?? $pushResult);
} catch (Throwable $e) {
    $warning = 'Notification step failed: ' . bp_error_text($e);
    $warnings[] = $warning;
    $notificationResult = [
        'status' => false,
        'error' => $warning,
    ];
    $pushResult['attempted'] = true;
    $pushResult['error'] = $warning;
    error_log('bp_mobile_app leave_update_status notification error: ' . bp_error_text($e));
}

$emailPayload = $record;
$emailPayload['status'] = $statusCode;
$emailPayload['approved_by'] = $officerId;
$emailPayload['approved_at'] = $now;
$mailResult = [
    'attempted' => false,
    'sent' => false,
    'to' => [],
    'subject' => '',
    'error' => null,
];
try {
    $mailResult = bp_send_leave_status_email(
        $emailPayload,
        $employee,
        $officer,
        $leaveTypeLabel,
        $statusCode
    );
} catch (Throwable $e) {
    $warnings[] = 'Email step failed: ' . bp_error_text($e);
    $mailResult = [
        'attempted' => true,
        'sent' => false,
        'to' => [],
        'subject' => '',
        'error' => 'Email step failed: ' . bp_error_text($e),
    ];
    error_log('bp_mobile_app leave_update_status email error: ' . bp_error_text($e));
}

$updatedMeta = null;
try {
    $updatedRecord = bp_fetch_leave_record($leaveUniqueId);
    $updatedMeta = $updatedRecord ? (bp_attach_leave_meta([$updatedRecord])[0] ?? null) : null;
} catch (Throwable $e) {
    $warnings[] = 'Saved leave fetch failed: ' . bp_error_text($e);
    error_log('bp_mobile_app leave_update_status fetch error: ' . bp_error_text($e));
}

bp_send_json([
    'status' => true,
    'message' => 'Leave status updated',
    'data' => [
        'leave_unique_id' => $leaveUniqueId,
        'new_status' => $statusCode,
        'leave_entry' => $updatedMeta,
        'notification' => [
            'attempted' => true,
            'sent' => (bool)($notificationResult['status'] ?? false),
            'to_staff_id' => $employeeId,
            'error' => !empty($notificationResult['status'])
                ? null
                : ((string)($notificationResult['error'] ?? '') ?: 'Failed to save notification'),
        ],
        'push' => $pushResult,
        'email' => $mailResult,
        'warnings' => $warnings,
    ],
]);
