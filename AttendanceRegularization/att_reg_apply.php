<?php
declare(strict_types=1);

require_once __DIR__ . '/att_reg_helpers.php';

// Create or edit an attendance regularization request. The web treats this as a
// single upsert (att_regular/crud.php case "createupdate"), so this endpoint
// does too: pass unique_id to edit, omit it to create.
//
// Every rule the web form enforces in JavaScript is enforced here as well. The
// web server accepts anything - no date check, no duplicate check, no monthly
// cap, no ownership check - so all of its limits are bypassable.

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$uniqueId = trim(bp_str($input, 'unique_id', bp_str($input, 'att_reg_unique_id')));
$shiftDate = bp_date_ymd(bp_str($input, 'shift_date', bp_str($input, 'attendance_date')));
$typeRaw = trim(bp_str($input, 'type', bp_str($input, 'regular_type')));
$regIn = trim(bp_str($input, 'reg_in', bp_str($input, 'regular_in_time')));
$regOut = trim(bp_str($input, 'reg_out', bp_str($input, 'regular_out_time')));
$reasonId = trim(bp_str($input, 'reason_id'));
$description = trim(bp_str($input, 'description'));

$type = (int)$typeRaw;

if ($staffIdInput === '' || $shiftDate === null || !in_array($type, [1, 2, 3], true) || $reasonId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, shift_date, type (1/2/3), and reason_id are required',
    ], 400);
}

if ($description === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Description is required',
    ], 400);
}

$staff = bp_att_reg_require_staff($staffIdInput);
bp_att_reg_require_access($staff);

$employeeId = trim((string)($staff['employee_id'] ?? ''));
$reportingOfficer = bp_att_reg_fetch_reporting_officer($staff);

// Future dates: web blocks this in JS only.
if ($shiftDate > date('Y-m-d')) {
    bp_send_json([
        'status' => false,
        'message' => 'Attendance date cannot be in the future.',
        'error_title' => 'Invalid Date',
    ], 400);
}

$reasonMap = bp_att_reg_reason_map();
if (!isset($reasonMap[$reasonId])) {
    bp_send_json([
        'status' => false,
        'message' => 'Selected reason is not valid',
    ], 400);
}

// Type normalization, matching the web: the unused side is nulled. We also
// require the side that IS in play, which the web never checks.
if ($type === 1) {
    $regOut = '';
    if ($regIn === '') {
        bp_send_json([
            'status' => false,
            'message' => 'Regularized in-time is required for a Check-In regularization',
        ], 400);
    }
} elseif ($type === 2) {
    $regIn = '';
    if ($regOut === '') {
        bp_send_json([
            'status' => false,
            'message' => 'Regularized out-time is required for a Check-Out regularization',
        ], 400);
    }
} else {
    if ($regIn === '' || $regOut === '') {
        bp_send_json([
            'status' => false,
            'message' => 'Both regularized in-time and out-time are required',
        ], 400);
    }
}

/** Normalizes an HH:MM / HH:MM:SS time to H:i, or null when unparseable. */
function bp_att_reg_normalize_time(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $matches) !== 1) {
        return null;
    }

    $hours = (int)$matches[1];
    $minutes = (int)$matches[2];
    if ($hours > 23 || $minutes > 59) {
        return null;
    }

    return sprintf('%02d:%02d', $hours, $minutes);
}

$regInNormalized = null;
$regOutNormalized = null;

if ($regIn !== '') {
    $regInNormalized = bp_att_reg_normalize_time($regIn);
    if ($regInNormalized === null) {
        bp_send_json([
            'status' => false,
            'message' => 'Regularized in-time is not a valid time',
        ], 400);
    }
}
if ($regOut !== '') {
    $regOutNormalized = bp_att_reg_normalize_time($regOut);
    if ($regOutNormalized === null) {
        bp_send_json([
            'status' => false,
            'message' => 'Regularized out-time is not a valid time',
        ], 400);
    }
}

if ($type === 3 && $regInNormalized !== null && $regOutNormalized !== null
    && $regInNormalized >= $regOutNormalized) {
    bp_send_json([
        'status' => false,
        'message' => 'Regularized in-time must be earlier than out-time.',
        'error_title' => 'Invalid Time Range',
    ], 400);
}

$isEdit = $uniqueId !== '';
$existing = null;

if ($isEdit) {
    $existing = bp_att_reg_fetch_record($uniqueId);
    if (!$existing) {
        bp_send_json([
            'status' => false,
            'message' => 'Regularization request not found',
        ], 404);
    }

    if (trim((string)($existing['employee_id'] ?? '')) !== $employeeId) {
        bp_send_json([
            'status' => false,
            'message' => 'Unauthorized: this regularization request is not yours',
        ], 403);
    }

    // Web dies with "Approved regularization cannot be edited." A rejected
    // request stays editable and returns to pending, which we preserve.
    if ((int)($existing['status'] ?? 0) === 1) {
        bp_send_json([
            'status' => false,
            'message' => 'Approved requests cannot be edited.',
            'error_title' => 'Already Approved',
        ], 409);
    }
}

$ignoreId = $isEdit ? $uniqueId : '';

if (bp_att_reg_date_taken($employeeId, $shiftDate, $ignoreId)) {
    bp_send_json([
        'status' => false,
        'message' => 'You have already applied for a regularization on this date.',
        'error_title' => 'Duplicate Request',
    ], 409);
}

$monthUsed = bp_att_reg_month_usage($employeeId, $shiftDate, $ignoreId);
if ($monthUsed >= BP_ATT_REG_MONTHLY_LIMIT) {
    bp_send_json([
        'status' => false,
        'message' => 'You have used ' . $monthUsed . ' of ' . BP_ATT_REG_MONTHLY_LIMIT
            . ' regularizations for this month.',
        'error_title' => 'Monthly Limit Reached',
    ], 409);
}

$attachmentName = null;
$hasNewAttachment = false;
if (!empty($_FILES['attachment']['name'] ?? '')) {
    $attachmentName = bp_att_reg_store_attachment((array)$_FILES['attachment']);
    if ($attachmentName === null) {
        bp_send_json([
            'status' => false,
            'message' => 'Attachment could not be saved. Allowed types: '
                . implode(', ', BP_ATT_REG_ATTACHMENT_EXTENSIONS) . ' (max 5 MB).',
            'error_title' => 'Attachment Failed',
        ], 400);
    }
    $hasNewAttachment = true;
}

$now = bp_now();

if ($isEdit) {
    // Re-snapshot the actual punches only when the date moved; otherwise keep
    // what was captured at submit time.
    $dateChanged = trim((string)($existing['shift_date'] ?? '')) !== $shiftDate;

    $update = [
        'shift_date' => $shiftDate,
        'type' => $type,
        'reg_in' => $regInNormalized,
        'reg_out' => $regOutNormalized,
        'reason_id' => $reasonId,
        'description' => $description,
        // Editing returns the request to pending, as the web does.
        'status' => 0,
        'approved_by' => null,
        'approved_at' => null,
        'updated' => $now,
        'updated_user_id' => $employeeId,
        'updated_at' => $now,
    ];

    if ($dateChanged) {
        $punches = bp_att_reg_actual_punches($employeeId, $shiftDate);
        $update['actual_in'] = $punches['actual_in'];
        $update['actual_out'] = $punches['actual_out'];
    }

    // Only touch `attachment` when a new file arrived. The web writes this
    // column unconditionally, so editing without re-picking the file silently
    // destroys the existing attachment.
    if ($hasNewAttachment) {
        $update['attachment'] = $attachmentName;
    }

    $result = bp_update_row(
        BP_ATT_REG_TABLE,
        bp_filter_table_columns(BP_ATT_REG_TABLE, $update),
        ['unique_id' => $uniqueId, 'is_delete' => 0]
    );

    if (!$result || !($result->status ?? false)) {
        bp_send_json([
            'status' => false,
            'message' => 'Failed to update regularization request',
            'error' => (string)($result->error ?? ''),
        ], 500);
    }
} else {
    $uniqueId = bp_unique_id();
    $punches = bp_att_reg_actual_punches($employeeId, $shiftDate);

    $columns = bp_filter_table_columns(BP_ATT_REG_TABLE, [
        'unique_id' => $uniqueId,
        'employee_id' => $employeeId,
        'shift_date' => $shiftDate,
        'type' => $type,
        'actual_in' => $punches['actual_in'],
        'actual_out' => $punches['actual_out'],
        'reg_in' => $regInNormalized,
        'reg_out' => $regOutNormalized,
        'reason_id' => $reasonId,
        'description' => $description,
        'attachment' => $attachmentName,
        'status' => 0,
        'created' => $now,
        'created_user_id' => $employeeId,
        'updated' => $now,
        'updated_user_id' => $employeeId,
        'is_delete' => 0,
        'acc_year' => date('Y'),
        'session_id' => '',
        'sess_user_type' => '',
        'sess_user_id' => $employeeId,
        'sess_company_id' => '',
        'sess_branch_id' => '',
    ]);

    $result = bp_insert_row_raw(BP_ATT_REG_TABLE, $columns);
    if (!$result || !($result->status ?? false)) {
        bp_send_json([
            'status' => false,
            'message' => 'Failed to save regularization request',
            'error' => (string)($result->error ?? ''),
        ], 500);
    }
}

$saved = bp_att_reg_fetch_record($uniqueId);

$notification = ['attempted' => false, 'sent' => false, 'error' => null];
$push = ['attempted' => false, 'sent' => false, 'error' => null];

try {
    $recipients = bp_att_reg_recipient_ids($employeeId, $reportingOfficer);
    if (!empty($recipients)) {
        $employeeName = trim((string)($staff['staff_name'] ?? '')) ?: $employeeId;
        $title = 'Attendance regularization pending';
        $message = ($isEdit ? 'Updated' : 'New') . ' attendance regularization request from '
            . $employeeName . ' for ' . $shiftDate
            . ' (' . bp_att_reg_type_label($type) . ')';

        $delivery = bp_att_reg_deliver_notifications(
            $recipients,
            $employeeId,
            $uniqueId,
            $title,
            $message,
            '/attendance-regularization-approval?attRegId=' . rawurlencode($uniqueId),
            [
                'route' => '/attendance-regularization-approval',
                'attRegId' => $uniqueId,
                'type' => 'att_reg_approval',
            ]
        );

        $notification = (array)($delivery['notification'] ?? $notification);
        $push = (array)($delivery['push'] ?? $push);
    }
} catch (Throwable $e) {
    $notification = ['attempted' => true, 'sent' => false, 'error' => bp_error_text($e)];
    $push = ['attempted' => true, 'sent' => false, 'error' => bp_error_text($e)];
    error_log('bp_mobile_app att_reg_apply notification error: ' . bp_error_text($e));
}

bp_send_json([
    'status' => true,
    'message' => $isEdit
        ? 'Regularization request updated and sent for approval'
        : 'Regularization request submitted for approval',
    'data' => [
        'att_reg_unique_id' => $uniqueId,
        'regularization' => $saved,
        'monthly_used' => bp_att_reg_month_usage($employeeId, $shiftDate),
        'monthly_limit' => BP_ATT_REG_MONTHLY_LIMIT,
        'notification' => $notification,
        'push' => $push,
    ],
]);
