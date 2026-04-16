<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
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
$employeeName = trim((string)($staff['staff_name'] ?? ''));
if ($employeeId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Employee id mapping failed',
    ], 500);
}

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
$recognitionDate = bp_date_ymd(bp_str($input, 'recognition_date')) ?? $now->format('Y-m-d');
$recognitionTime = trim(bp_str($input, 'recognition_time', $now->format('H:i:s')));
$records = trim(bp_str($input, 'records', $recognitionDate . ' ' . $recognitionTime));
$latitude = trim(bp_str($input, 'latitude', '11.3255508'));
$longitude = trim(bp_str($input, 'longitude', '77.695605'));
$similarityScore = trim(bp_str($input, 'similarity_score', '1'));
$capturedImagePath = trim(bp_str(
    $input,
    'captured_image_path',
    'test/attendance_approval_' . $employeeId . '_' . $now->format('Ymd_His') . '.jpg'
));

$insertData = [
    'emp_id' => $employeeId,
    'name' => $employeeName,
    'records' => $records,
    'captured_image_path' => $capturedImagePath,
    'similarity_score' => $similarityScore,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'recognition_date' => $recognitionDate,
    'recognition_time' => $recognitionTime,
    'status' => 0,
    'created' => bp_now(),
    'updated' => bp_now(),
    'is_active' => 1,
    'is_delete' => 0,
];

$insert = bp_att_insert_row_raw('att_approval', $insertData);
if (!$insert || !($insert->status ?? false)) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to create test attendance approval entry',
        'error' => bp_att_error_text($insert->error ?? ''),
        'data' => [
            'employee_id' => $employeeId,
            'records' => $records,
        ],
    ], 500);
}

$approvalRow = bp_att_find_pending_approval_for_employee(
    $employeeId,
    null,
    $records,
    $recognitionDate
);

if (!$approvalRow) {
    bp_send_json([
        'status' => false,
        'message' => 'Test attendance approval entry was created but could not be reloaded',
        'data' => [
            'employee_id' => $employeeId,
            'records' => $records,
        ],
    ], 500);
}

try {
    $notificationResult = bp_att_notify_pending_approval($approvalRow, $employeeId);
} catch (Throwable $e) {
    bp_send_json([
        'status' => false,
        'message' => 'Test entry created but attendance notification failed',
        'error' => $e->getMessage(),
        'data' => [
            'approval' => bp_att_map_approval_row($approvalRow),
        ],
    ], 500);
}

$approvalPayload = bp_att_map_approval_row($approvalRow);

bp_send_json([
    'status' => true,
    'message' => !empty($notificationResult['already_notified'])
        ? 'Test attendance approval entry created. Notification already existed, push retried.'
        : 'Test attendance approval entry created and notification triggered.',
    'data' => [
        'approval' => $approvalPayload,
        'notification' => $notificationResult,
    ],
]);
