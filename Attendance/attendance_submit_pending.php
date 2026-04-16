<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$approvalIdInput = bp_str($input, 'approval_id', bp_str($input, 'id'));
$recordsInput = bp_str($input, 'records');
$recognitionDateInput = bp_str($input, 'recognition_date');

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$context = bp_att_require_context($staffIdInput);
$employeeId = trim((string)($context['employee_id'] ?? ''));

try {
    $approvalRow = bp_att_find_pending_approval_for_employee(
        $employeeId,
        $approvalIdInput,
        $recordsInput,
        $recognitionDateInput
    );
} catch (Throwable $e) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to resolve pending attendance approval',
        'error' => $e->getMessage(),
    ], 500);
}

if (!$approvalRow) {
    bp_send_json([
        'status' => false,
        'message' => 'Pending attendance approval not found',
    ], 404);
}

try {
    $notificationResult = bp_att_notify_pending_approval($approvalRow, $employeeId);
} catch (Throwable $e) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to notify HR users',
        'error' => $e->getMessage(),
        'data' => [
            'approval' => bp_att_map_approval_row($approvalRow),
        ],
    ], 500);
}

bp_send_json([
    'status' => true,
    'message' => !empty($notificationResult['already_notified'])
        ? 'Attendance approval notification was already sent'
        : 'Attendance approval submitted to HR',
    'data' => [
        'approval' => bp_att_map_approval_row($approvalRow),
        'notification' => $notificationResult,
    ],
]);
