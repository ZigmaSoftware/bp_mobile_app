<?php
declare(strict_types=1);

require_once __DIR__ . '/att_reg_helpers.php';

/**
 * Access check endpoint: reports exactly which access check passes or fails for a
 * staff member, so a "web shows it but the app does not" report can be resolved
 * without database access.
 *
 * Read-only. Safe to leave deployed, but it does expose role/permission facts
 * about the requested staff id, so remove it once access issues are settled.
 *
 * Usage:
 *   curl -s -X POST https://zigma.in/bp_mobile_app/AttendanceRegularization/att_reg_access_check.php \
 *        -d 'staff_unique_id=ADM001'
 */

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$staff = bp_att_reg_require_staff($staffIdInput);
$employeeId = trim((string)($staff['employee_id'] ?? ''));

$userType = bp_user_type_for_staff($staff);

// Which folder names actually resolve to a user_screen row, and which of those
// the user's user_type has a permission row for. Any folder that resolves but
// is not granted is the failing link.
$folderProbe = [];
foreach ([
    BP_ATT_REG_SCREEN_FOLDER,
    BP_ATT_REG_APPROVAL_SCREEN_FOLDER,
    'attendance_regularization',
    'attendance_regularisation',
    'att_regularization',
    'att_regularisation',
    'regularization',
    'regularisation',
    'reg_approval',
    'regularization_approval',
    'att_reg',
    'time_change',
] as $folder) {
    $screenId = bp_screen_unique_id_for_folder($folder);
    $strict = $screenId !== ''
        ? bp_staff_has_screen_permission($staff, $folder)
        : false;
    $menuParity = $screenId !== ''
        ? bp_att_reg_has_screen_permission($staff, $folder)
        : false;

    $folderProbe[$folder] = [
        'screen_row_exists' => $screenId !== '',
        'screen_unique_id' => $screenId,
        // Strict = shared helper (adds is_active=1 AND is_delete=0 on the
        // permission row). Menu parity = what the web menu actually uses
        // (user_type + screen only). strict false + parity true means the
        // permission row exists but is inactive or soft-deleted: the web shows
        // the screen, and the app would have hidden it before this fix.
        'granted_strict' => $strict,
        'granted_menu_parity' => $menuParity,
        'inactive_row_divergence' => (!$strict && $menuParity),
    ];
}

$isReportingOfficer = bp_is_reporting_officer($employeeId);
$isHrStaff = bp_is_hr_staff($employeeId);

bp_send_json([
    'status' => true,
    'message' => 'Attendance regularization access diagnostic',
    'data' => [
        'employee' => [
            'employee_id' => $employeeId,
            'staff_unique_id' => (string)($staff['unique_id'] ?? ''),
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'reporting_officer' => (string)($staff['reporting_officer'] ?? ''),
            'designation_unique_id' => (string)($staff['designation_unique_id'] ?? ''),
        ],
        'user_type_resolved' => $userType,
        'user_type_found' => $userType !== '',
        'feature_flag_enabled' => bp_att_reg_enabled(),
        'folder_probe' => $folderProbe,
        'role_checks' => [
            'is_reporting_officer' => $isReportingOfficer,
            'is_hr_staff_by_designation_id' => $isHrStaff,
            'hr_designation_ids_matched' => bp_hr_designation_ids(),
            'designation_name' => bp_att_reg_designation_name($staff),
            'privileged_designation_names' => bp_att_reg_privileged_designations(),
            'is_privileged_approver' => bp_att_reg_is_privileged_approver($staff),
        ],
        // The two flags the app actually gates its tiles on.
        'effective' => [
            'can_use_regularization' => bp_att_reg_can_use($staff),
            'can_approve_regularization' => bp_att_reg_can_approve($staff),
        ],
        'reasons_available' => count(bp_att_reg_reasons()),
    ],
]);
