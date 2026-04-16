<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';
// HR approval queue fix marker: 2026-03-19.

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$approvalId = bp_str($input, 'approval_id', bp_str($input, 'id'));
$status = bp_status_code_from_mixed(bp_str($input, 'status'));

if ($staffIdInput === '' || $approvalId === '' || $status === null) {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, approval_id and status are required',
    ], 400);
}

if (!in_array($status, [1, 2], true)) {
    bp_send_json([
        'status' => false,
        'message' => 'Only Approved (1) or Rejected (2) are supported',
    ], 400);
}

$context = bp_att_require_hr_context($staffIdInput);
$approvalRow = bp_att_fetch_approval_row($approvalId);
if (!$approvalRow) {
    bp_send_json([
        'status' => false,
        'message' => 'Approval entry not found',
    ], 404);
}

$employeeId = trim((string)($approvalRow['emp_id'] ?? ''));
if ($employeeId === '' || !bp_att_scope_allows_employee_local($context['scope'] ?? [], $employeeId)) {
    bp_send_json([
        'status' => false,
        'message' => 'Unauthorized to update this approval',
    ], 403);
}

if ((int)($approvalRow['status'] ?? 0) !== 0) {
    bp_send_json([
        'status' => false,
        'message' => 'Only pending approvals can be updated',
    ], 409);
}

$approverId = (string)($context['employee_id'] ?? '');
$now = bp_now();
$update = bp_update_row(
    'att_approval',
    [
        'status' => $status,
        'updated' => $now,
        'updated_user_id' => $approverId,
    ],
    [
        'id' => $approvalId,
        'is_delete' => 0,
    ]
);

if (!$update || !($update->status ?? false)) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to update attendance approval',
        'error' => bp_att_error_text($update->error ?? ''),
    ], 500);
}

$insertedAttendance = false;
$deliveryResult = [
    'notification' => [
        'status' => false,
        'error' => '',
    ],
    'push' => [
        'attempted' => false,
        'sent' => false,
        'token_count' => 0,
        'success_count' => 0,
        'failure_count' => 0,
        'invalidated_count' => 0,
        'error' => '',
    ],
];
$updatedApprovalPayload = null;
$warnings = [];

try {
    if ($status === 1) {
        $duplicate = bp_fetch_one('zigfly_recognized', ['emp_id'], [
            'emp_id' => $employeeId,
            'records' => (string)($approvalRow['records'] ?? ''),
        ]);

        if (!$duplicate) {
            global $pdo;
            $insert = $pdo->query(
                "
                INSERT INTO zigfly_recognized
                (
                    emp_id,
                    name,
                    records,
                    captured_image_path,
                    similarity_score,
                    latitude,
                    longitude,
                    recognition_date,
                    recognition_time
                )
                VALUES
                (
                    :emp_id,
                    :name,
                    :records,
                    :captured_image_path,
                    :similarity_score,
                    :latitude,
                    :longitude,
                    :recognition_date,
                    :recognition_time
                )
                ",
                [
                    'emp_id' => $employeeId,
                    'name' => (string)($approvalRow['name'] ?? ''),
                    'records' => (string)($approvalRow['records'] ?? ''),
                    'captured_image_path' => (string)($approvalRow['captured_image_path'] ?? ''),
                    'similarity_score' => (string)($approvalRow['similarity_score'] ?? ''),
                    'latitude' => (string)($approvalRow['latitude'] ?? ''),
                    'longitude' => (string)($approvalRow['longitude'] ?? ''),
                    'recognition_date' => (string)($approvalRow['recognition_date'] ?? ''),
                    'recognition_time' => (string)($approvalRow['recognition_time'] ?? ''),
                ]
            );

            $insertedAttendance = (bool)($insert->status ?? false);
            if (!$insertedAttendance && !empty($insert->error)) {
                $warnings[] = 'Attendance sync failed: ' . bp_att_error_text($insert->error);
            }
        }
    }
} catch (Throwable $e) {
    $warnings[] = 'Attendance sync failed: ' . $e->getMessage();
}

$title = $status === 1 ? 'Attendance Approved' : 'Attendance Rejected';
$dateText = trim((string)($approvalRow['recognition_date'] ?? ''));
$timeText = trim((string)($approvalRow['recognition_time'] ?? ''));
$message = $status === 1
    ? 'Your outside geofencing attendance'
    : 'Your outside geofencing attendance request';
if ($dateText !== '' || $timeText !== '') {
    $message .= ' for ' . trim($dateText . ' ' . $timeText);
}
$message .= $status === 1 ? ' has been approved.' : ' has been rejected.';

try {
    $deliveryResult = bp_att_deliver_notification_result(
        $employeeId,
        $approverId,
        $approvalId,
        $title,
        $message,
        '/attendance-summary?date=' . rawurlencode($dateText),
        [
            'route' => '/attendance-summary',
            'approvalId' => $approvalId,
            'employeeId' => $employeeId,
            'type' => 'attendance_approval_result',
            'status' => (string)$status,
        ]
    );

    $notificationResult = (array)($deliveryResult['notification'] ?? []);
    if (empty($notificationResult['status']) && !empty($notificationResult['error'])) {
        $warnings[] = 'Notification save failed: ' . bp_att_error_text($notificationResult['error']);
    }

    $pushResult = (array)($deliveryResult['push'] ?? []);
    if (empty($pushResult['sent']) && !empty($pushResult['error'])) {
        $warnings[] = 'Push delivery failed: ' . bp_att_error_text($pushResult['error']);
    }
} catch (Throwable $e) {
    $deliveryResult = [
        'notification' => [
            'status' => false,
            'error' => $e->getMessage(),
        ],
        'push' => [
            'attempted' => false,
            'sent' => false,
            'token_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'invalidated_count' => 0,
            'error' => $e->getMessage(),
        ],
    ];
    $warnings[] = 'Notification save failed: ' . $e->getMessage();
}

try {
    $updatedRow = bp_att_fetch_approval_row($approvalId);
    if ($updatedRow) {
        $updatedApprovalPayload = bp_att_map_approval_row($updatedRow);
    }
} catch (Throwable $e) {
    $warnings[] = 'Approval reload failed: ' . $e->getMessage();
}

bp_send_json([
    'status' => true,
    'message' => 'Attendance approval updated',
    'data' => [
        'approval' => $updatedApprovalPayload,
        'inserted_attendance' => $insertedAttendance,
        'notification' => [
            'attempted' => true,
            'sent' => (bool)($deliveryResult['notification']['status'] ?? false),
            'to_staff_id' => $employeeId,
            'error' => !empty($deliveryResult['notification']['status'])
                ? null
                : (bp_att_error_text($deliveryResult['notification']['error'] ?? '') ?: 'Failed to save attendance notification'),
        ],
        'push' => $deliveryResult['push'],
        'warnings' => $warnings,
    ],
]);
