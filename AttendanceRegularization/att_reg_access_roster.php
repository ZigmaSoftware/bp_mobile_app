<?php
declare(strict_types=1);

require_once __DIR__ . '/att_reg_helpers.php';

/**
 * TEMPORARY read-only roster: lists which staff the app will show the
 * Attendance Regularization tiles to, by evaluating the SAME gates the
 * dashboard uses (bp_att_reg_can_use / bp_att_reg_can_approve) for every
 * active employee.
 *
 * Purpose: answer "who can see Regularization Approval in the app?" for UAT.
 * DELETE THIS FILE once testing is done - it enumerates roles for all staff.
 *
 * Usage:
 *   curl -s -X POST .../att_reg_access_roster.php -d 'limit=500'
 *   curl -s -X POST .../att_reg_access_roster.php -d 'approvers_only=1'
 */

$input = bp_input();
$limit = max(1, min((int)bp_str($input, 'limit', '500'), 2000));
$approversOnly = in_array(
    strtolower(bp_str($input, 'approvers_only', '0')),
    ['1', 'true', 'yes'],
    true
);

// Screen rows first: if either is missing, nothing can be granted.
$screens = [];
foreach ([BP_ATT_REG_SCREEN_FOLDER, BP_ATT_REG_APPROVAL_SCREEN_FOLDER] as $folder) {
    $screens[$folder] = bp_screen_unique_id_for_folder($folder);
}

$staffColumns = bp_table_columns('staff_test');
$select = array_values(array_filter([
    'unique_id',
    'employee_id',
    'staff_name',
    'reporting_officer',
    isset($staffColumns['designation_unique_id']) ? 'designation_unique_id' : null,
], static fn($c) => is_string($c) && isset($staffColumns[$c])));

$rows = bp_fetch_rows(
    'staff_test',
    $select,
    'is_active = 1 AND is_delete = 0 ORDER BY employee_id LIMIT ' . $limit
);

$canUse = [];
$canApprove = [];
$userTypeTally = [];
$scanned = 0;

foreach ($rows as $staff) {
    $employeeId = trim((string)($staff['employee_id'] ?? ''));
    if ($employeeId === '') {
        continue;
    }
    $scanned++;

    $use = bp_att_reg_can_use($staff);
    $approve = bp_att_reg_can_approve($staff);

    if (!$use && !$approve) {
        continue;
    }

    $userType = bp_user_type_for_staff($staff);
    $entry = [
        'employee_id' => $employeeId,
        'staff_name' => trim((string)($staff['staff_name'] ?? '')),
        'user_type' => $userType,
        'designation' => bp_att_reg_designation_name($staff),
        'is_reporting_officer' => bp_is_reporting_officer($employeeId),
        'is_privileged' => bp_att_reg_is_privileged_approver($staff),
    ];

    if ($approve) {
        $canApprove[] = $entry;
        $key = $userType !== '' ? $userType : '(no user_type)';
        $userTypeTally[$key] = ($userTypeTally[$key] ?? 0) + 1;
    }
    if ($use && !$approversOnly) {
        $canUse[] = $entry;
    }
}

bp_send_json([
    'status' => true,
    'message' => 'Attendance regularization access roster',
    'data' => [
        'screen_rows' => $screens,
        'staff_scanned' => $scanned,
        'approver_count' => count($canApprove),
        'employee_access_count' => count($canUse),
        'approvers' => $canApprove,
        'approver_user_type_tally' => $userTypeTally,
        'employee_access' => $approversOnly ? '(suppressed)' : $canUse,
    ],
]);
