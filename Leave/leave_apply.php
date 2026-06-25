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
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$leaveTypeId = bp_str($input, 'leave_type_id');
$fromDateRaw = bp_str($input, 'from_date');
$toDateRaw = bp_str($input, 'to_date');
$periodRaw = bp_str($input, 'period', '3');
$holidayUniqueId = bp_str($input, 'holiday_unique_id');
$reason = bp_str($input, 'reason');

if ($staffIdInput === '' || $leaveTypeId === '' || $fromDateRaw === '' || $toDateRaw === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, leave_type_id, from_date, and to_date are required',
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

$fromDate = bp_date_ymd($fromDateRaw);
$toDate = bp_date_ymd($toDateRaw);

if (!$fromDate || !$toDate) {
    bp_send_json([
        'status' => false,
        'message' => 'Invalid from_date/to_date. Expected YYYY-MM-DD',
    ], 400);
}

if ($fromDate > $toDate) {
    bp_send_json([
        'status' => false,
        'message' => 'from_date cannot be after to_date',
    ], 400);
}

$period = (int)($periodRaw !== '' ? $periodRaw : '3');
if (!in_array($period, [1, 2, 3], true)) {
    $period = 3;
}

if (in_array($period, [1, 2], true) && $fromDate !== $toDate) {
    bp_send_json([
        'status' => false,
        'message' => 'Half-day leave requires from_date and to_date to be the same date',
    ], 400);
}

$typeMeta = null;
$halfDayAllowed = true;
$requiresDocument = false;
$documentText = '';

if (strtolower($leaveTypeId) !== 'lwp') {
    $typeMeta = bp_fetch_leave_type($leaveTypeId);
    if (!$typeMeta) {
        bp_send_json([
            'status' => false,
            'message' => 'Leave type not found',
        ], 404);
    }

    if (((int)($typeMeta['is_active'] ?? 0)) !== 1 || ((int)($typeMeta['is_delete'] ?? 0)) !== 0) {
        bp_send_json([
            'status' => false,
            'message' => 'Leave type is inactive',
        ], 400);
    }

    $halfDayAllowed = strtolower((string)($typeMeta['half_day'] ?? '')) === 'yes';
    $requiresDocument = ((int)($typeMeta['is_document_required'] ?? 0)) === 1;
    $documentText = (string)($typeMeta['document_text'] ?? '');

    if (in_array($period, [1, 2], true) && !$halfDayAllowed) {
        bp_send_json([
            'status' => false,
            'message' => 'Selected leave type does not allow half-day leave',
        ], 400);
    }
} else {
    $leaveTypeId = 'lwp';
}

$holidayUniqueId = $holidayUniqueId !== '' ? $holidayUniqueId : null;
if ($holidayUniqueId !== null) {
    $holidayError = bp_validate_flexi_holiday($holidayUniqueId, $fromDate, $toDate);
    if ($holidayError) {
        bp_send_json([
            'status' => false,
            'message' => $holidayError,
        ], 400);
    }
}

$weekoffDates = bp_fetch_weekoff_dates_for_staff($staff, $fromDate, $toDate);
if (!empty($weekoffDates)) {
    $sampleDates = array_slice($weekoffDates, 0, 5);
    $dateText = implode(', ', $sampleDates);
    if (count($weekoffDates) > count($sampleDates)) {
        $dateText .= ', ...';
    }

    bp_send_json([
        'status' => false,
        'message' => 'Leave cannot be applied on week-off dates',
        'data' => [
            'weekoff_dates' => $weekoffDates,
            'weekoff_dates_text' => $dateText,
        ],
    ], 400);
}

if (bp_has_overlap($employeeId, $fromDate, $toDate)) {
    bp_send_json([
        'status' => false,
        'message' => 'Leave already exists for the selected date range',
    ], 409);
}

if (in_array($period, [1, 2], true)) {
    $totalDays = 0.5;
} else {
    $start = new DateTime($fromDate);
    $end = new DateTime($toDate);
    $totalDays = (float)($start->diff($end)->days + 1);
}

if ($totalDays <= 0) {
    bp_send_json([
        'status' => false,
        'message' => 'Selected date range results in 0 leave days',
    ], 400);
}

$balances = bp_fetch_leave_balances(
    (string)($staff['staff_name'] ?? ''),
    (string)($staff['unique_id'] ?? ''),
    $employeeId
);
$available = $leaveTypeId === 'lwp' ? null : bp_balance_for_leave_type($balances, $leaveTypeId);

if ($leaveTypeId !== 'lwp' && $totalDays > (float)$available) {
    bp_send_json([
        'status' => false,
        'message' => 'Insufficient leave balance',
        'data' => [
            'requested_days' => $totalDays,
            'available_balance' => (float)$available,
        ],
    ], 400);
}

$file = null;
if (!empty($_FILES['document_file'] ?? null)) {
    $file = $_FILES['document_file'];
} elseif (!empty($_FILES['file_attach'] ?? null)) {
    $file = $_FILES['file_attach'];
}

if ($requiresDocument && !$file) {
    bp_send_json([
        'status' => false,
        'message' => $documentText !== '' ? $documentText : 'Document is required for this leave type',
    ], 400);
}

$leaveUniqueId = bp_unique_id();
$now = bp_now();
$halfDay = in_array($period, [1, 2], true) ? 1 : 0;

$insertCols = [
    'unique_id' => $leaveUniqueId,
    'employee_id' => $employeeId,
    'leave_type_id' => $leaveTypeId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'period' => (string)$period,
    'holiday_unique_id' => $holidayUniqueId,
    'total_days' => $totalDays,
    'reason' => $reason !== '' ? $reason : null,
    'half_day' => $halfDay,
    'status' => 0,
    'created_user_id' => $employeeId,
    'created' => $now,
    'updated_user_id' => $employeeId,
    'updated' => $now,
    'is_active' => 1,
    'is_delete' => 0,
];

$res = bp_insert_row('leave_entry', $insertCols);
if (!$res || !($res->status ?? false)) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to save leave entry',
        'error' => (string)($res->error ?? ''),
    ], 500);
}

$documentSaved = null;
$documentInserted = false;
$warnings = [];
$notification = [
    'attempted' => false,
    'sent' => false,
    'sent_count' => 0,
    'failure_count' => 0,
    'to_staff_ids' => [],
    'error' => null,
    'results' => [],
];
$push = [
    'attempted' => false,
    'sent' => false,
    'token_count' => 0,
    'success_count' => 0,
    'failure_count' => 0,
    'invalidated_count' => 0,
    'error' => null,
];
$email = [
    'attempted' => false,
    'sent' => false,
    'to' => [],
    'subject' => '',
    'error' => null,
];

if ($file) {
    $saved = bp_store_leave_document_file($file);
    if ($saved) {
        $documentSaved = $saved;
        $documentInserted = bp_insert_leave_document($leaveUniqueId, $employeeId, $saved, 'MAIN');
    }
}

$officerId = trim((string)($staff['reporting_officer'] ?? ''));
try {
    $approvalRecipients = bp_collect_leave_approval_recipient_ids($employeeId, $officerId);
    if (!empty($approvalRecipients)) {
        $leaveTypeLabel = $leaveTypeId === 'lwp'
            ? 'Leave Without Pay'
            : (string)($typeMeta['leave_type'] ?? $leaveTypeId);
        $title = 'Leave request pending';
        $msg = 'New leave request from ' . (string)($staff['staff_name'] ?? $employeeId)
            . ' • ' . $leaveTypeLabel
            . ' • ' . $fromDate . ' → ' . $toDate
            . ' (' . $totalDays . ' day' . ($totalDays === 1.0 ? '' : 's') . ')';

        $deliverySummary = bp_deliver_leave_notifications_result(
            $approvalRecipients,
            $employeeId,
            $leaveUniqueId,
            $title,
            $msg,
            '/leave-approval?leaveId=' . rawurlencode($leaveUniqueId),
            [
                'route' => '/leave-approval',
                'leaveId' => $leaveUniqueId,
                'type' => 'leave_approval',
            ]
        );

        $notification['attempted'] = (bool)($deliverySummary['attempted'] ?? false);
        $notification['sent'] = (bool)($deliverySummary['sent'] ?? false);
        $notification['sent_count'] = (int)($deliverySummary['sent_count'] ?? 0);
        $notification['failure_count'] = (int)($deliverySummary['failure_count'] ?? 0);
        $notification['to_staff_ids'] = array_values($deliverySummary['to_staff_ids'] ?? []);
        $notification['error'] = $deliverySummary['error'] ?? null;
        $notification['results'] = array_values($deliverySummary['results'] ?? []);
        $push = (array)($deliverySummary['push'] ?? $push);
    }
} catch (Throwable $e) {
    $warning = 'Notification step failed: ' . bp_error_text($e);
    $warnings[] = $warning;
    $notification['attempted'] = true;
    $notification['error'] = $warning;
    $push['attempted'] = true;
    $push['error'] = $warning;
    error_log('bp_mobile_app leave_apply notification error: ' . bp_error_text($e));
}

$mailPayload = [
    'employee_id' => $employeeId,
    'leave_type_id' => $leaveTypeId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'total_days' => $totalDays,
    'reason' => $reason,
    'status' => 0,
    'approved_by' => null,
    'approved_at' => null,
];
$officer = $officerId !== '' ? bp_fetch_staff($officerId) : null;
try {
    $email = bp_send_leave_status_email(
        $mailPayload,
        $staff,
        $officer ?: null,
        $leaveTypeId === 'lwp'
            ? 'Leave Without Pay'
            : (string)($typeMeta['leave_type'] ?? $leaveTypeId),
        0
    );
} catch (Throwable $e) {
    $warnings[] = 'Email step failed: ' . bp_error_text($e);
    $email = [
        'attempted' => true,
        'sent' => false,
        'to' => [],
        'subject' => '',
        'error' => 'Email step failed: ' . bp_error_text($e),
    ];
    error_log('bp_mobile_app leave_apply email error: ' . bp_error_text($e));
}

$savedMeta = null;
try {
    $savedRecord = bp_fetch_leave_record($leaveUniqueId);
    $savedMeta = $savedRecord ? (bp_attach_leave_meta([$savedRecord])[0] ?? null) : null;
} catch (Throwable $e) {
    $warnings[] = 'Saved leave fetch failed: ' . bp_error_text($e);
    error_log('bp_mobile_app leave_apply fetch error: ' . bp_error_text($e));
}

bp_send_json([
    'status' => true,
    'message' => 'Leave applied successfully',
    'data' => [
        'leave_unique_id' => $leaveUniqueId,
        'requested_days' => $totalDays,
        'available_balance' => $available,
        'leave_entry' => $savedMeta,
        'document' => [
            'saved_as' => $documentSaved,
            'inserted' => $documentInserted,
        ],
        'notification' => $notification,
        'push' => $push,
        'email' => $email,
        'warnings' => $warnings,
    ],
]);
