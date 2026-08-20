<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';
require_once __DIR__ . '/../WorkFromHome/wfh_helpers.php';

/**
 * Folders the ERP registers an attendance report under. This mobile report
 * mirrors monthly_attendance_report_new, but the older monthly_attendance_report
 * and the executive_* variants are the same capability, so a role granted any of
 * them should see the mobile report. Verified against the live folders/ listing.
 */
const BP_ATTENDANCE_REPORT_SCREEN_FOLDERS = [
    'monthly_attendance_report_new',
    'monthly_attendance_report',
    'monthly_attendance',
    'executive_wise_monthly_attendance',
    'executive_wise_consolidated_attendance',
    'executive_lead_wise_monthly_attendance',
    'day_attendance_report',
    'attendance_abstract_new',
    'attendance_abstract',
];

/**
 * Monthly / date-range attendance report for head & HR users.
 *
 * Mirrors the web "monthly_attendance_report_new" logic, but reuses the mobile
 * backend's existing per-user scope engine (bp_att_context -> scope). A
 * department head sees only the employees that report to them; HR/admin see
 * everyone. Employees with a "self" scope are rejected (the home screen hides
 * the entry point for them, this is the server-side guard).
 *
 * Input  : staff_unique_id (or employee_id), from_date, to_date (or month/year)
 * Output : summary counts + per-bucket employee rows
 *          (present / absent / leave / wfh / weekoff / holiday, plus
 *          weekoff_holiday as the union of the last two for older builds)
 */

/**
 * Resolve a set of project_creation.unique_id values to display names in one
 * batched query. Returns a map of [project_id => project_name]. Ids that can't
 * be resolved are simply absent from the map (the caller falls back to the id).
 */
function bp_att_report_project_names(array $projectIds): array
{
    $projectIds = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        $projectIds
    ))));
    if (empty($projectIds)) {
        return [];
    }

    $projectColumns = bp_att_table_columns('project_creation');
    if (empty($projectColumns) || !isset($projectColumns['unique_id'])) {
        return [];
    }

    $selectColumns = array_values(array_filter(
        ['unique_id', 'project_name', 'project_code'],
        static fn($column) => isset($projectColumns[$column])
    ));

    $quotedIds = array_map('bp_sql_quote', $projectIds);
    $where = 'unique_id IN (' . implode(', ', $quotedIds) . ')';

    try {
        $rows = bp_fetch_rows('project_creation', $selectColumns, $where);
    } catch (Throwable $e) {
        error_log('bp_mobile_app attendance_report project lookup failed: ' . bp_att_error_text($e));
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['unique_id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $name = trim((string)($row['project_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($row['project_code'] ?? ''));
        }
        $map[$id] = $name !== '' ? $name : $id;
    }

    return $map;
}

try {
    $input = bp_input();
    $staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
    $projectFilter = bp_str($input, 'project');

    if ($staffIdInput === '') {
        bp_send_json([
            'status' => false,
            'message' => 'staff_unique_id or employee_id is required',
        ], 400);
    }

    $context = bp_att_require_context($staffIdInput);
    $scope = is_array($context['scope'] ?? null) ? $context['scope'] : [];
    $scopeType = (string)($scope['scope_type'] ?? 'self');

    // Guard 1 - web-managed permission: the report is only visible to roles
    // granted an attendance-report screen on the web User Permission screen, so
    // revoking it there hides it in the app with no redeploy. Checked against
    // every folder the ERP registers the report under.
    if (!bp_staff_has_any_screen_permission(
        is_array($context['staff'] ?? null) ? $context['staff'] : [],
        BP_ATTENDANCE_REPORT_SCREEN_FOLDERS
    )) {
        bp_send_json([
            'status' => false,
            'message' => 'Attendance report access has not been enabled for your role',
        ], 403);
    }

    // Deliberately NO second gate on scope. The web report does not reject a
    // "self" scope user - build_employee_scope_sql() simply narrows the query
    // to their own row, so someone granted the report but with no reportees
    // opens it on the web and sees their own attendance. Rejecting them here
    // made the app stricter than the web for exactly that group (granted on
    // the web, blank in the app). The scope engine below already restricts the
    // rows each viewer gets, so permission is the only gate that belongs here.

    [$fromDate, $toDate] = bp_att_normalize_date_range($input);
    if ($fromDate === null || $toDate === null) {
        $fromDate = date('Y-m-01');
        $toDate = date('Y-m-t');
    }
    if (strcmp($fromDate, $toDate) > 0) {
        $tmp = $fromDate;
        $fromDate = $toDate;
        $toDate = $tmp;
    }

    $tableColumns = bp_att_table_columns('vw_attendance_with_shift');
    if (empty($tableColumns)) {
        bp_send_json([
            'status' => false,
            'message' => 'Attendance data source is unavailable',
        ], 500);
    }

    $columnMap = bp_att_attendance_view_column_map();
    $employeeCol = (string)($columnMap['employee_id'] ?? 'employee_id');
    $statusCol = (string)($columnMap['attendance_status'] ?? 'attendance_status');
    $shiftDateCol = (string)($columnMap['shift_date'] ?? 'shift_date');

    // Resolve the column actually present in the view for location / department.
    $workLocationCol = bp_att_first_available_column(
        $tableColumns,
        ['work_location', 'project_id', 'project', 'location']
    );
    $departmentCol = bp_att_first_available_column(
        $tableColumns,
        ['department', 'department_unique_id', 'department_id']
    );

    // Build the scope clause exactly like every other scoped mobile endpoint.
    // Pass an empty string (not the employee column) when a project/department
    // column is absent, so the scope engine simply skips that filter instead
    // of matching project/department ids against employee_id.
    $scopeClause = bp_att_scope_where_clause(
        $scope,
        $employeeCol,
        $workLocationCol ?: '',
        $departmentCol ?: ''
    );

    // Match the web report exactly: employee scope + BP head-office / non-HO
    // work_location partition + optional project (work_location) filter.
    $where = $shiftDateCol . ' >= ' . bp_sql_quote($fromDate)
        . ' AND ' . $shiftDateCol . ' <= ' . bp_sql_quote($toDate)
        . $scopeClause
        . bp_att_ho_partition_clause($context, $workLocationCol ?: '')
        . bp_att_report_project_clause($projectFilter, $workLocationCol ?: '');

    $rows = bp_fetch_rows('vw_attendance_with_shift', ['*'], $where);

    // Week off and holiday are tracked separately so the app can filter them
    // independently; 'weekoff_holiday' is still reported as their union for
    // older app builds that read only that key.
    $buckets = [
        'present' => [],
        'absent' => [],
        'leave' => [],
        'wfh' => [],
        'weekoff' => [],
        'holiday' => [],
    ];

    // Pre-resolve project (work_location) ids -> names in one batched query so
    // the per-row loop stays cheap even for large teams.
    $projectNameCache = [];
    if ($workLocationCol) {
        $projectIds = [];
        foreach ($rows as $row) {
            $pid = trim((string)($row[$workLocationCol] ?? ''));
            if ($pid !== '') {
                $projectIds[$pid] = true;
            }
        }
        $projectNameCache = bp_att_report_project_names(array_keys($projectIds));
    }

    $departmentNameCache = [];
    $employeeMeta = [];

    foreach ($rows as $row) {
        $statusRaw = trim((string)bp_att_attendance_view_value($row, $statusCol));

        // Web parity: classify with the report's exact status lists. Rows that
        // match none of the four buckets (Permission, Missed In, Short Hours,
        // blank …) are not counted anywhere — exactly like the web report.
        $bucket = bp_att_report_exact_bucket($statusRaw);
        if ($bucket === null || !isset($buckets[$bucket])) {
            continue;
        }

        $shiftDate = bp_date_ymd(bp_att_attendance_view_value($row, $shiftDateCol));
        $employeeId = trim((string)bp_att_attendance_view_value($row, $employeeCol));
        $staffName = trim((string)bp_att_attendance_view_value($row, (string)($columnMap['staff_name'] ?? 'staff_name')));
        $plannedShift = trim((string)bp_att_attendance_view_value($row, (string)($columnMap['planned_shift'] ?? 'planned_shift')));
        $entryPunch = bp_att_attendance_time_only((string)bp_att_attendance_view_value($row, (string)($columnMap['entry_punch'] ?? 'entry_punch')));
        $exitPunch = bp_att_attendance_time_only((string)bp_att_attendance_view_value($row, (string)($columnMap['exit_punch'] ?? 'exit_punch')));
        $workedHours = trim((string)bp_att_attendance_view_value($row, (string)($columnMap['worked_hours'] ?? 'worked_hours')));

        $workLocationId = $workLocationCol ? trim((string)($row[$workLocationCol] ?? '')) : '';
        $departmentId = $departmentCol ? trim((string)($row[$departmentCol] ?? '')) : '';

        if ($departmentId !== '' && !isset($departmentNameCache[$departmentId])) {
            $departmentNameCache[$departmentId] = bp_att_department_name($departmentId);
        }

        $locationName = $workLocationId !== ''
            ? (($projectNameCache[$workLocationId] ?? '') ?: $workLocationId)
            : '';
        $departmentName = $departmentId !== ''
            ? (($departmentNameCache[$departmentId] ?? '') ?: $departmentId)
            : '';

        $buckets[$bucket][] = [
            'shift_date' => $shiftDate ?? '',
            'employee_id' => $employeeId,
            'staff_name' => $staffName,
            'location' => $locationName,
            'department' => $departmentName,
            'planned_shift' => $plannedShift,
            'check_in' => $entryPunch,
            'check_out' => $exitPunch,
            'worked_hours' => $workedHours,
            'attendance_status' => $statusRaw,
        ];

        if ($employeeId !== '' && !isset($employeeMeta[$employeeId])) {
            $employeeMeta[$employeeId] = [
                'staff_name' => $staffName,
                'location' => $locationName,
                'department' => $departmentName,
                'planned_shift' => $plannedShift,
            ];
        }
    }

    $allowedEmployeeIds = array_keys($employeeMeta);
    if (!empty($allowedEmployeeIds) && !empty(bp_table_columns(BP_WFH_TABLE))) {
        $quotedEmployees = array_map('bp_sql_quote', $allowedEmployeeIds);
        $wfhWhere = 'employee_id IN (' . implode(', ', $quotedEmployees) . ')'
            . ' AND date >= ' . bp_sql_quote($fromDate)
            . ' AND date <= ' . bp_sql_quote($toDate)
            . ' AND status = 1 AND is_delete = 0';
        $wfhRows = bp_wfh_fetch_rows_by_where($wfhWhere);

        foreach ($wfhRows as $wfhRow) {
            $employeeId = trim((string)($wfhRow['employee_id'] ?? ''));
            $meta = $employeeMeta[$employeeId] ?? [];
            $buckets['wfh'][] = [
                'shift_date' => (string)($wfhRow['date'] ?? ''),
                'employee_id' => $employeeId,
                'staff_name' => (string)(($wfhRow['employee_name'] ?? '') ?: ($meta['staff_name'] ?? '')),
                'location' => (string)($meta['location'] ?? ''),
                'department' => (string)(($wfhRow['department_name'] ?? '') ?: ($meta['department'] ?? '')),
                'planned_shift' => (string)($meta['planned_shift'] ?? ''),
                'check_in' => '',
                'check_out' => '',
                'worked_hours' => '',
                'attendance_status' => 'Work From Home',
            ];
        }
    }

    $sortByDateThenName = static function (array &$list): void {
        usort($list, static function (array $a, array $b): int {
            $byDate = strcmp((string)$a['shift_date'], (string)$b['shift_date']);
            if ($byDate !== 0) {
                return $byDate;
            }
            return strcmp((string)$a['staff_name'], (string)$b['staff_name']);
        });
    };

    // Stable ordering: by date then employee, so the lists read naturally.
    foreach ($buckets as &$list) {
        $sortByDateThenName($list);
    }
    unset($list);

    // Sorted again after merging, otherwise the combined legacy list would run
    // every week off before every holiday instead of being date-ordered.
    $weekoffHoliday = array_merge($buckets['weekoff'], $buckets['holiday']);
    $sortByDateThenName($weekoffHoliday);

    $summary = [
        'present' => count($buckets['present']),
        'absent' => count($buckets['absent']),
        'leave' => count($buckets['leave']),
        'wfh' => count($buckets['wfh']),
        'weekoff' => count($buckets['weekoff']),
        'holiday' => count($buckets['holiday']),
        'weekoff_holiday' => count($weekoffHoliday),
        'total' => count($buckets['present'])
            + count($buckets['absent'])
            + count($buckets['leave'])
            + count($buckets['wfh'])
            + count($weekoffHoliday),
    ];

    bp_send_json([
        'status' => true,
        'message' => 'Attendance report loaded',
        'data' => [
            'viewer' => [
                'staff_unique_id' => (string)($context['staff']['unique_id'] ?? ''),
                'employee_id' => (string)($context['employee_id'] ?? ''),
                'staff_name' => (string)($context['staff']['staff_name'] ?? ''),
                'role_label' => (string)($context['role_label'] ?? ''),
                'scope_type' => $scopeType,
            ],
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'summary' => $summary,
            'present' => $buckets['present'],
            'absent' => $buckets['absent'],
            'leave' => $buckets['leave'],
            'wfh' => $buckets['wfh'],
            'weekoff' => $buckets['weekoff'],
            'holiday' => $buckets['holiday'],
            'weekoff_holiday' => $weekoffHoliday,
            'server_time' => bp_now(),
        ],
    ]);
} catch (Throwable $e) {
    error_log('bp_mobile_app attendance_report fatal: ' . bp_att_error_text($e));
    bp_send_json([
        'status' => false,
        'message' => 'Failed to load attendance report',
        'error' => bp_att_error_text($e),
    ], 500);
}
