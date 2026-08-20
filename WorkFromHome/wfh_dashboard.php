<?php
declare(strict_types=1);

require_once __DIR__ . '/wfh_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
[$fromDate, $toDate] = [bp_date_ymd(bp_str($input, 'from_date')), bp_date_ymd(bp_str($input, 'to_date'))];

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$staff = bp_wfh_require_staff($staffIdInput);
$employeeId = trim((string)($staff['employee_id'] ?? ''));
$isBpIndia = bp_wfh_staff_is_bp_india($staff);
$isReportingOfficer = bp_wfh_is_reporting_officer($employeeId);

if ($fromDate === null || $toDate === null || $fromDate > $toDate) {
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-t');
}

$myRequests = $isBpIndia
    ? bp_wfh_fetch_entries($employeeId, 'own', $fromDate, $toDate, null, 100)
    : [];
$teamRequests = ($isBpIndia && $isReportingOfficer)
    ? bp_wfh_fetch_entries($employeeId, 'team', $fromDate, $toDate, null, 100)
    : [];

// Requests awaiting this head's action are deliberately NOT scoped to the
// browsing window. Applying late in one month for a day in the next is the
// common case, and scoping it away made the request both uncountable (no
// approvals badge) and un-approvable (missing from the Team tab).
$pendingTeamRequests = ($isBpIndia && $isReportingOfficer)
    ? bp_wfh_fetch_entries($employeeId, 'team', null, null, 0, 300)
    : [];

// Fold the out-of-window pending rows into the list the approval screen
// renders, newest first. Response shape is unchanged - same keys, same types -
// so older app builds parse this exactly as before.
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
    static fn(array $a, array $b): int => [$b['date'] ?? '', $b['created'] ?? '']
        <=> [$a['date'] ?? '', $a['created'] ?? '']
);
$teamRequests = $mergedTeamRequests;

bp_send_json([
    'status' => true,
    'message' => 'WFH dashboard loaded',
    'data' => [
        'employee' => [
            'staff_unique_id' => (string)($staff['unique_id'] ?? ''),
            'employee_id' => $employeeId,
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'reporting_officer' => (string)($staff['reporting_officer'] ?? ''),
        ],
        // can_use_wfh is the accurate name (a web-managed permission, not an
        // entity check). is_bp_india is kept as the same value so already
        // shipped app builds, which only know that key, keep working.
        'can_use_wfh' => $isBpIndia,
        'is_bp_india' => $isBpIndia,
        'is_reporting_officer' => $isReportingOfficer,
        'pending_approvals_count' => count($pendingTeamRequests),
        'unread_notifications_count' => $isBpIndia ? bp_wfh_unread_count($employeeId) : 0,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'my_requests' => $myRequests,
        'team_requests' => $teamRequests,
        'server_time' => bp_now(),
    ],
]);
