<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
[$fromDate, $toDate] = bp_att_normalize_date_range($input);

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

if ($fromDate === null || $toDate === null) {
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-t');
}

$context = bp_att_require_context($staffIdInput);
$staff = is_array($context['staff'] ?? null) ? $context['staff'] : [];
$employeeId = trim((string)($context['employee_id'] ?? ''));
$staffUniqueId = trim((string)($staff['unique_id'] ?? ''));

$holidays = [];
$holidayColumns = bp_table_columns('holiday_creation');
if (!empty($holidayColumns) && isset($holidayColumns['holiday_date'])) {
    $where = 'holiday_date >= ' . bp_sql_quote($fromDate)
        . ' AND holiday_date <= ' . bp_sql_quote($toDate);
    if (isset($holidayColumns['is_active'])) {
        $where .= ' AND is_active = 1';
    }
    if (isset($holidayColumns['is_delete'])) {
        $where .= ' AND is_delete = 0';
    }

    $holidayRows = bp_fetch_rows('holiday_creation', ['holiday_date', 'description'], $where);
    foreach ($holidayRows as $row) {
        $date = bp_date_ymd((string)($row['holiday_date'] ?? ''));
        if ($date === null) {
            continue;
        }

        $holidays[] = [
            'date' => $date,
            'reason' => (string)($row['description'] ?? 'Holiday'),
            'status' => 'Holiday',
        ];
    }
}

$tickets = [];
$leaveEntries = bp_attach_leave_meta(bp_fetch_leave_entries_by_employee($employeeId, $fromDate, $toDate));
foreach ($leaveEntries as $entry) {
    $entryFrom = bp_date_ymd((string)($entry['from_date'] ?? ''));
    $entryTo = bp_date_ymd((string)($entry['to_date'] ?? ''));
    if ($entryFrom === null || $entryTo === null) {
        continue;
    }

    $rangeFrom = max($fromDate, $entryFrom);
    $rangeTo = min($toDate, $entryTo);
    foreach (bp_date_range_ymd($rangeFrom, $rangeTo) as $date) {
        $tickets[] = [
            'kind' => 'leave',
            'date' => $date,
            'type' => (string)($entry['leave_type'] ?? 'Leave'),
            'status' => (string)($entry['status_label'] ?? ''),
            'reason' => (string)($entry['reason'] ?? ''),
            'time' => '',
            'submittedAt' => (string)($entry['created'] ?? ''),
            'leave_id' => (string)($entry['unique_id'] ?? ''),
        ];
    }
}

$attendanceItems = bp_att_fetch_actual_attendance_records($employeeId, $fromDate, $toDate, $staffUniqueId);
foreach ($attendanceItems as $item) {
    $statusBucket = strtolower(trim((string)($item['status_bucket'] ?? '')));
    if ($statusBucket !== 'permission') {
        continue;
    }

    $date = bp_date_ymd((string)($item['date'] ?? ''));
    if ($date === null) {
        continue;
    }

    $tickets[] = [
        'kind' => 'permission',
        'date' => $date,
        'type' => 'Permission',
        'status' => (string)($item['attendance_status'] ?? 'Permission'),
        'reason' => '',
        'time' => (string)($item['total_worked_time'] ?? ''),
        'submittedAt' => (string)($item['approval_records'] ?? ''),
        'perm_id' => (string)($item['approval_id'] ?? ''),
    ];
}

bp_send_json([
    'status' => true,
    'message' => 'Attendance calendar meta loaded',
    'data' => [
        'employee' => [
            'staff_unique_id' => $staffUniqueId,
            'employee_id' => $employeeId,
            'staff_name' => (string)($staff['staff_name'] ?? ''),
        ],
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'holidays' => $holidays,
        'tickets' => $tickets,
    ],
]);
