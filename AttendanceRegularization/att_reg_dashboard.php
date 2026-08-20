<?php
declare(strict_types=1);

require_once __DIR__ . '/att_reg_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
[$fromDate, $toDate] = [bp_date_ymd(bp_str($input, 'from_date')), bp_date_ymd(bp_str($input, 'to_date'))];

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$staff = bp_att_reg_require_staff($staffIdInput);
$employeeId = trim((string)($staff['employee_id'] ?? ''));

$canUse = bp_att_reg_can_use($staff);
$canApprove = bp_att_reg_can_approve($staff);
$isReportingOfficer = bp_is_reporting_officer($employeeId);
// Privileged = HR by designation id, or a designation the web's approval screen
// treats as see-everything (HR Manager / Developer). Must use the same test as
// bp_att_reg_can_approve() and att_reg_update_status.php, or a user could see
// the queue but be refused when acting on it.
$isHrUser = bp_att_reg_is_privileged_approver($staff);

if ($fromDate === null || $toDate === null || $fromDate > $toDate) {
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-t');
}

$myRequests = $canUse
    ? bp_att_reg_fetch_entries($employeeId, 'own', $fromDate, $toDate, null, 100)
    : [];

// HR sees every request; a reporting officer sees direct reportees only. The
// web makes the same split (reg_appr/crud.php case "datatable").
$approvalScope = $isHrUser ? 'all' : 'team';

$teamRequests = $canApprove
    ? bp_att_reg_fetch_entries($employeeId, $approvalScope, $fromDate, $toDate, null, 100)
    : [];

// Requests awaiting this approver's action are deliberately NOT scoped to the
// browsing window. Applying late in one month for a day in the previous one is
// the common case, and scoping it away would make the request both uncountable
// (no approvals badge) and un-approvable (missing from the pending tab).
$pendingTeamRequests = $canApprove
    ? bp_att_reg_fetch_entries($employeeId, $approvalScope, null, null, 0, 300)
    : [];

$seenTeamIds = [];
$mergedTeamRequests = [];
foreach (array_merge($teamRequests, $pendingTeamRequests) as $teamRow) {
    $rowId = (string)($teamRow['unique_id'] ?? '');
    if ($rowId !== '') {
        if (isset($seenTeamIds[$rowId])) {
            continue;
        }
        $seenTeamIds[$rowId] = true;
    }
    $mergedTeamRequests[] = $teamRow;
}
usort(
    $mergedTeamRequests,
    static fn(array $a, array $b): int => [$b['shift_date'] ?? '', $b['created'] ?? '']
        <=> [$a['shift_date'] ?? '', $a['created'] ?? '']
);
$teamRequests = $mergedTeamRequests;

bp_send_json([
    'status' => true,
    'message' => 'Attendance regularization dashboard loaded',
    'data' => [
        'employee' => [
            'staff_unique_id' => (string)($staff['unique_id'] ?? ''),
            'employee_id' => $employeeId,
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'reporting_officer' => (string)($staff['reporting_officer'] ?? ''),
        ],
        'can_use_regularization' => $canUse,
        'can_approve_regularization' => $canApprove,
        'is_reporting_officer' => $isReportingOfficer,
        'is_hr_user' => $isHrUser,
        'monthly_limit' => BP_ATT_REG_MONTHLY_LIMIT,
        'monthly_used' => $canUse ? bp_att_reg_month_usage($employeeId, date('Y-m-d')) : 0,
        'pending_approvals_count' => count($pendingTeamRequests),
        'unread_notifications_count' => bp_att_reg_unread_count($employeeId),
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'reasons' => $canUse ? bp_att_reg_reasons() : [],
        'my_requests' => $myRequests,
        'team_requests' => $teamRequests,
        'server_time' => bp_now(),
    ],
]);
