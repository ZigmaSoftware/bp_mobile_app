<?php
declare(strict_types=1);

require_once __DIR__ . '/att_reg_helpers.php';

// Read-only preview of the real punches for a date, so the apply form can show
// what is being corrected. Ports att_regular/crud.php case "get_actual_times".
// Always resolved for the authenticated staff - the web's apply-on-behalf staff
// picker is a hardcoded ADM001/TEST001 backdoor and is not ported.

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$shiftDate = bp_date_ymd(bp_str($input, 'shift_date', bp_str($input, 'entry_date')));

if ($staffIdInput === '' || $shiftDate === null) {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, and shift_date are required',
    ], 400);
}

$staff = bp_att_reg_require_staff($staffIdInput);
bp_att_reg_require_access($staff);

$employeeId = trim((string)($staff['employee_id'] ?? ''));
$punches = bp_att_reg_actual_punches($employeeId, $shiftDate);

bp_send_json([
    'status' => true,
    'message' => 'Actual punch times loaded',
    'data' => [
        'shift_date' => $shiftDate,
        'actual_in' => $punches['actual_in'],
        'actual_out' => $punches['actual_out'],
        'has_record' => (bool)$punches['has_record'],
    ],
]);
