<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

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
 *          (present / absent / leave / weekoff_holiday)
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

    if ($staffIdInput === '') {
        bp_send_json([
            'status' => false,
            'message' => 'staff_unique_id or employee_id is required',
        ], 400);
    }

    $context = bp_att_require_context($staffIdInput);
    $scope = is_array($context['scope'] ?? null) ? $context['scope'] : [];
    $scopeType = (string)($scope['scope_type'] ?? 'self');

    // Server-side guard: only users with a wider-than-self scope may view the
    // report. HR/admin ("all"/"hr_admin"), department heads, reporting
    // officers and plant in-charges all qualify.
    if ($scopeType === 'self' || $scopeType === '') {
        bp_send_json([
            'status' => false,
            'message' => 'You do not have access to the attendance report',
        ], 403);
    }

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

    $where = $shiftDateCol . ' >= ' . bp_sql_quote($fromDate)
        . ' AND ' . $shiftDateCol . ' <= ' . bp_sql_quote($toDate)
        . $scopeClause;

    $rows = bp_fetch_rows('vw_attendance_with_shift', ['*'], $where);

    $buckets = [
        'present' => [],
        'absent' => [],
        'leave' => [],
        'weekoff_holiday' => [],
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

    foreach ($rows as $row) {
        $statusRaw = trim((string)bp_att_attendance_view_value($row, $statusCol));
        $bucket = bp_attendance_summary_bucket($statusRaw);

        // The report groups permission days under "present" (the employee was
        // at work). Unknown/blank statuses are skipped.
        if ($bucket === 'permission') {
            $bucket = 'present';
        }
        if (!isset($buckets[$bucket])) {
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
    }

    // Stable ordering: by date then employee, so the lists read naturally.
    foreach ($buckets as &$list) {
        usort($list, static function (array $a, array $b): int {
            $byDate = strcmp((string)$a['shift_date'], (string)$b['shift_date']);
            if ($byDate !== 0) {
                return $byDate;
            }
            return strcmp((string)$a['staff_name'], (string)$b['staff_name']);
        });
    }
    unset($list);

    $summary = [
        'present' => count($buckets['present']),
        'absent' => count($buckets['absent']),
        'leave' => count($buckets['leave']),
        'weekoff_holiday' => count($buckets['weekoff_holiday']),
        'total' => count($buckets['present'])
            + count($buckets['absent'])
            + count($buckets['leave'])
            + count($buckets['weekoff_holiday']),
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
            'weekoff_holiday' => $buckets['weekoff_holiday'],
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
