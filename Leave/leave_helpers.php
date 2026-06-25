<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function bp_fetch_rows(string $table, array $columns, $where = ''): array
{
    global $pdo;

    $result = $pdo->select([$table, $columns], $where);
    if (!$result || !($result->status ?? false) || !is_array($result->data ?? null)) {
        return [];
    }

    return $result->data;
}

function bp_fetch_one(string $table, array $columns, $where = ''): ?array
{
    $rows = bp_fetch_rows($table, $columns, $where);
    return $rows[0] ?? null;
}

function bp_insert_row(string $table, array $columns): object
{
    global $pdo;
    return $pdo->insert($table, $columns);
}

function bp_update_row(string $table, array $columns, $where): object
{
    global $pdo;
    return $pdo->update($table, $columns, $where);
}

function bp_is_safe_identifier(string $value): bool
{
    return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
}

function bp_table_columns(string $table): array
{
    static $cache = [];

    if (!bp_is_safe_identifier($table)) {
        return [];
    }

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    global $pdo;

    try {
        $res = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
    } catch (Throwable $e) {
        $cache[$table] = [];
        return $cache[$table];
    }

    if (!$res || !($res->status ?? false) || !is_array($res->data ?? null)) {
        $cache[$table] = [];
        return $cache[$table];
    }

    $set = [];
    foreach ($res->data as $row) {
        $name = trim((string)($row['Field'] ?? ''));
        if ($name !== '' && bp_is_safe_identifier($name)) {
            $set[$name] = true;
        }
    }

    $cache[$table] = $set;
    return $cache[$table];
}

function bp_insert_row_raw(string $table, array $columns): object
{
    if (!bp_is_safe_identifier($table)) {
        return (object)[
            'status' => 0,
            'error' => 'Invalid table name',
        ];
    }

    $names = [];
    $params = [];
    foreach ($columns as $name => $value) {
        $name = trim((string)$name);
        if ($name === '' || !bp_is_safe_identifier($name)) {
            continue;
        }
        $names[] = $name;
        $params[$name] = $value;
    }

    if (empty($names)) {
        return (object)[
            'status' => 0,
            'error' => 'No valid columns to insert',
        ];
    }

    $quotedNames = array_map(static function (string $name): string {
        return '`' . $name . '`';
    }, $names);
    $placeholders = array_map(static function (string $name): string {
        return ':' . $name;
    }, $names);

    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedNames) . ')'
        . ' VALUES (' . implode(', ', $placeholders) . ')';

    global $pdo;

    try {
        $res = $pdo->query($sql, $params);
        if (is_object($res) && property_exists($res, 'status')) {
            return $res;
        }

        return (object)[
            'status' => 1,
            'error' => '',
            'data' => $res,
        ];
    } catch (Throwable $e) {
        return (object)[
            'status' => 0,
            'error' => $e->getMessage(),
        ];
    }
}

function bp_employee_name(string $employeeId): string
{
    static $cache = [];

    if (isset($cache[$employeeId])) {
        return $cache[$employeeId];
    }

    $staff = bp_fetch_one(
        'staff_test',
        ['employee_id', 'staff_name'],
        [
            'employee_id' => $employeeId,
            'is_active' => 1,
            'is_delete' => 0,
        ]
    );

    $cache[$employeeId] = trim((string)($staff['staff_name'] ?? ''));
    return $cache[$employeeId];
}

function bp_fetch_staff(string $staffIdentifier): ?array
{
    $staffIdentifier = trim($staffIdentifier);
    if ($staffIdentifier === '') {
        return null;
    }

    $quoted = bp_sql_quote($staffIdentifier);

    $staffColumns = [
        'unique_id',
        'employee_id',
        'staff_name',
        'office_email_id',
        'reporting_officer',
        'designation_unique_id',
        'company_name',
        'work_location',
    ];

    $staff = bp_fetch_one(
        'staff_test',
        $staffColumns,
        "is_active = 1 AND is_delete = 0 AND (employee_id = {$quoted} OR unique_id = {$quoted})"
    );
    if ($staff) {
        return $staff;
    }

    // Fallback: login payloads may carry `user.unique_id`; resolve through `user.staff_unique_id`.
    $userRow = bp_fetch_one(
        'user',
        ['staff_unique_id'],
        "is_active = 1 AND is_delete = 0 AND (staff_unique_id = {$quoted} OR unique_id = {$quoted})"
    );
    if (!$userRow) {
        return null;
    }

    $mappedStaffId = trim((string)($userRow['staff_unique_id'] ?? ''));
    if ($mappedStaffId === '') {
        return null;
    }

    $mappedQuoted = bp_sql_quote($mappedStaffId);
    return bp_fetch_one(
        'staff_test',
        $staffColumns,
        "is_active = 1 AND is_delete = 0 AND (employee_id = {$mappedQuoted} OR unique_id = {$mappedQuoted})"
    );
}

function bp_resolve_employee_id(string $staffIdentifier): ?string
{
    $staff = bp_fetch_staff($staffIdentifier);
    if (!$staff) {
        return null;
    }

    $employeeId = trim((string)($staff['employee_id'] ?? ''));
    return $employeeId !== '' ? $employeeId : null;
}

function bp_parse_csv_values(string $csv): array
{
    $parts = array_map('trim', explode(',', $csv));
    $parts = array_values(array_filter($parts, static function (string $value): bool {
        return $value !== '';
    }));

    return array_values(array_unique($parts));
}

function bp_date_range_ymd(string $fromDate, string $toDate): array
{
    $from = bp_date_ymd($fromDate);
    $to = bp_date_ymd($toDate);
    if ($from === null || $to === null || $from > $to) {
        return [];
    }

    $out = [];
    $cursor = new DateTime($from);
    $end = new DateTime($to);
    while ($cursor <= $end) {
        $out[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }

    return $out;
}

function bp_parse_weekoff_day_token(string $token): ?string
{
    $token = strtolower(trim($token));
    $token = str_replace(['-', '_', ' '], '', $token);

    if ($token === '') {
        return null;
    }

    $map = [
        '1' => 'monday',
        '2' => 'tuesday',
        '3' => 'wednesday',
        '4' => 'thursday',
        '5' => 'friday',
        '6' => 'saturday',
        '7' => 'sunday',
        '0' => 'sunday',
        'mon' => 'monday',
        'monday' => 'monday',
        'tue' => 'tuesday',
        'tues' => 'tuesday',
        'tuesday' => 'tuesday',
        'wed' => 'wednesday',
        'wednesday' => 'wednesday',
        'thu' => 'thursday',
        'thur' => 'thursday',
        'thurs' => 'thursday',
        'thursday' => 'thursday',
        'fri' => 'friday',
        'friday' => 'friday',
        'sat' => 'saturday',
        'saturday' => 'saturday',
        'sun' => 'sunday',
        'sunday' => 'sunday',
    ];

    return $map[$token] ?? null;
}

function bp_parse_weekoff_days_csv(string $csv): array
{
    $daySet = [];
    foreach (bp_parse_csv_values($csv) as $token) {
        $dayName = bp_parse_weekoff_day_token($token);
        if ($dayName !== null) {
            $daySet[$dayName] = true;
        }
    }

    return $daySet;
}

function bp_fetch_weekoff_days_from_rules(array $companyIds, array $projectIds): array
{
    if (empty($companyIds) || empty($projectIds)) {
        return [];
    }

    $daySet = [];

    foreach ($companyIds as $companyId) {
        $companyId = trim((string)$companyId);
        if ($companyId === '') {
            continue;
        }

        foreach ($projectIds as $projectId) {
            $projectId = trim((string)$projectId);
            if ($projectId === '') {
                continue;
            }

            $where = 'is_delete = 0 AND is_active = 1'
                . ' AND FIND_IN_SET(' . bp_sql_quote($companyId) . ', company_id)'
                . ' AND FIND_IN_SET(' . bp_sql_quote($projectId) . ', project_id)';

            $rows = bp_fetch_rows('weekoff_creation', ['weekoff_days'], $where);
            foreach ($rows as $row) {
                $days = bp_parse_weekoff_days_csv((string)($row['weekoff_days'] ?? ''));
                foreach ($days as $dayName => $_) {
                    $daySet[$dayName] = true;
                }
            }
        }
    }

    return $daySet;
}

function bp_fetch_shift_weekoff_map(array $employeeIdentifiers, string $fromDate, string $toDate): array
{
    $employeeIdentifiers = array_values(array_unique(array_filter(array_map(
        static function ($value): string {
            return trim((string)$value);
        },
        $employeeIdentifiers
    ), static function (string $value): bool {
        return $value !== '';
    })));

    if (empty($employeeIdentifiers)) {
        return [];
    }

    $quotedIds = array_map(static function (string $value): string {
        return bp_sql_quote($value);
    }, $employeeIdentifiers);

    $where = 'is_delete = 0'
        . ' AND shift_date >= ' . bp_sql_quote($fromDate)
        . ' AND shift_date <= ' . bp_sql_quote($toDate)
        . ' AND employee_id IN (' . implode(',', $quotedIds) . ')';

    $explicit = [];
    foreach (['shift_roster_details', 'shift_roaster_details'] as $tableName) {
        if (empty(bp_table_columns($tableName))) {
            continue;
        }

        $rows = bp_fetch_rows($tableName, ['shift_date', 'is_weekoff', 'shift_name'], $where);
        foreach ($rows as $row) {
            $shiftDate = bp_date_ymd((string)($row['shift_date'] ?? ''));
            if ($shiftDate === null) {
                continue;
            }

            $isWeekoff = ((int)($row['is_weekoff'] ?? 0)) === 1;
            $shiftName = strtolower(trim((string)($row['shift_name'] ?? '')));
            if (!$isWeekoff && $shiftName !== '') {
                $compact = str_replace(' ', '', $shiftName);
                if (strpos($compact, 'weekoff') !== false) {
                    $isWeekoff = true;
                }
            }

            if (!array_key_exists($shiftDate, $explicit)) {
                $explicit[$shiftDate] = $isWeekoff;
            } elseif ($isWeekoff) {
                $explicit[$shiftDate] = true;
            }
        }
    }

    ksort($explicit);
    return $explicit;
}

function bp_fetch_weekoff_dates_for_staff(array $staff, string $fromDate, string $toDate): array
{
    $fromDate = bp_date_ymd($fromDate);
    $toDate = bp_date_ymd($toDate);
    if ($fromDate === null || $toDate === null || $fromDate > $toDate) {
        return [];
    }

    $employeeId = trim((string)($staff['employee_id'] ?? ''));
    $staffUniqueId = trim((string)($staff['unique_id'] ?? ''));

    $companyIds = bp_parse_csv_values((string)($staff['company_name'] ?? ''));
    $projectIds = bp_parse_csv_values((string)($staff['work_location'] ?? ''));
    $fallbackWeekoffDays = bp_fetch_weekoff_days_from_rules($companyIds, $projectIds);

    $explicitMap = bp_fetch_shift_weekoff_map([$employeeId, $staffUniqueId], $fromDate, $toDate);

    $weekoffDates = [];
    foreach (bp_date_range_ymd($fromDate, $toDate) as $date) {
        $isWeekoff = false;
        if (array_key_exists($date, $explicitMap)) {
            $isWeekoff = (bool)$explicitMap[$date];
        } elseif (!empty($fallbackWeekoffDays)) {
            $dayName = strtolower((string)date('l', strtotime($date)));
            $isWeekoff = isset($fallbackWeekoffDays[$dayName]);
        }

        if ($isWeekoff) {
            $weekoffDates[] = $date;
        }
    }

    return array_values(array_unique($weekoffDates));
}

function bp_fetch_leave_type(string $leaveTypeId): ?array
{
    return bp_fetch_one(
        'leave_master_creation',
        [
            'unique_id',
            'leave_type',
            'half_day',
            'is_document_required',
            'document_text',
            'is_sandwich_applicable',
            'balance_handling',
            'is_active',
            'is_delete',
        ],
        [
            'unique_id' => $leaveTypeId,
            'is_delete' => 0,
        ]
    );
}

function bp_fetch_leave_types(): array
{
    $rows = bp_fetch_rows(
        'leave_master_creation',
        [
            'unique_id',
            'leave_type',
            'half_day',
            'is_document_required',
            'document_text',
            'is_sandwich_applicable',
            'balance_handling',
            'is_active',
            'is_delete',
        ],
        [
            'is_delete' => 0,
            'is_active' => 1,
        ]
    );

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'leave_type_id' => (string)($row['unique_id'] ?? ''),
            'leave_type' => (string)($row['leave_type'] ?? ''),
            'half_day' => strtolower((string)($row['half_day'] ?? '')) === 'yes',
            'is_document_required' => ((int)($row['is_document_required'] ?? 0)) === 1,
            'document_text' => (string)($row['document_text'] ?? ''),
            'is_sandwich_applicable' => ((int)($row['is_sandwich_applicable'] ?? 0)) === 1,
            'balance_handling' => (string)($row['balance_handling'] ?? ''),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcasecmp($a['leave_type'], $b['leave_type']);
    });
    $out[] = [
        'leave_type_id' => 'lwp',
        'leave_type' => 'Leave Without Pay',
        'half_day' => true,
        'is_document_required' => false,
        'document_text' => '',
        'is_sandwich_applicable' => false,
        'balance_handling' => 'lwp',
    ];

    return $out;
}

function bp_fetch_leave_type_map(): array
{
    static $map;

    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (bp_fetch_leave_types() as $type) {
        $map[$type['leave_type_id']] = $type;
    }

    return $map;
}

function bp_fetch_leave_balances(string $staffName, string $staffUniqueId = '', string $employeeId = ''): array
{
    $staffName = trim($staffName);
    $staffUniqueId = trim($staffUniqueId);
    $employeeId = trim($employeeId);
    if ($staffName === '' && $staffUniqueId === '' && $employeeId === '') {
        return [];
    }

    $viewColumns = bp_table_columns('vw_leave_balance');
    $queries = [];
    if ($staffUniqueId !== '' && isset($viewColumns['staff_unique_id'])) {
        $queries[] = 'staff_unique_id = ' . bp_sql_quote($staffUniqueId);
    }
    if ($employeeId !== '' && isset($viewColumns['employee_id'])) {
        $queries[] = 'employee_id = ' . bp_sql_quote($employeeId);
    }
    if ($staffName !== '' && (empty($viewColumns) || isset($viewColumns['staff_name']))) {
        // Match the staff name case-insensitively and trimmed. An exact `=`
        // match silently returns nothing when casing or whitespace differs.
        $queries[] = 'UPPER(TRIM(staff_name)) = UPPER(TRIM(' . bp_sql_quote($staffName) . '))';
    }

    $rows = [];
    foreach ($queries as $where) {
        $rows = bp_fetch_rows(
            'vw_leave_balance',
            ['leave_type', 'leave_master_id', 'used_leave', 'balance'],
            $where
        );
        if (!empty($rows)) {
            break;
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'leave_type' => (string)($row['leave_type'] ?? ''),
            'leave_type_id' => (string)($row['leave_master_id'] ?? ''),
            'used' => (float)($row['used_leave'] ?? 0),
            'balance' => (float)($row['balance'] ?? 0),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcasecmp($a['leave_type'], $b['leave_type']);
    });
    return $out;
}

function bp_leave_balance_for_keyword(array $balances, string $keyword): ?float
{
    $needle = strtolower(trim($keyword));
    if ($needle === '') {
        return null;
    }

    foreach ($balances as $balance) {
        $leaveType = strtolower(trim((string)($balance['leave_type'] ?? '')));
        if ($leaveType !== '' && strpos($leaveType, $needle) !== false) {
            return (float)($balance['balance'] ?? 0);
        }
    }

    return null;
}

function bp_balance_for_leave_type(array $balances, string $leaveTypeId): float
{
    foreach ($balances as $balance) {
        if ((string)$balance['leave_type_id'] === $leaveTypeId) {
            return (float)$balance['balance'];
        }
    }
    return 0.0;
}

function bp_attendance_present_statuses(): array
{
    return [
        'present',
        'late in',
        'early exit',
        'half day',
        'half-day',
        'halfday',
    ];
}

function bp_attendance_absent_statuses(): array
{
    return [
        'absent',
        'absent - no shift assigned',
        'missed in',
        'missed out',
    ];
}

function bp_attendance_weekoff_holiday_statuses(): array
{
    return [
        'week off',
        'weekoff',
        'week-off',
        'weekoff/holiday',
        'week off/holiday',
        'holiday',
    ];
}

function bp_dynamic_leave_statuses(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    if (empty(bp_table_columns('leave_master_creation'))) {
        return $cache;
    }

    $rows = bp_fetch_rows(
        'leave_master_creation',
        ['leave_type'],
        'is_delete = 0 AND is_active = 1'
    );

    foreach ($rows as $row) {
        $normalized = bp_normalize_attendance_status((string)($row['leave_type'] ?? ''));
        if ($normalized !== '') {
            $cache[$normalized] = true;
        }
    }

    return $cache;
}

function bp_normalize_attendance_status(string $status): string
{
    $status = trim($status);
    if ($status === '') {
        return '';
    }

    $status = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $status); // en/em dash
    $status = strtolower($status);
    $status = preg_replace('/\s+/', ' ', $status) ?? $status;
    return trim($status);
}

function bp_attendance_is_present_status(string $status): bool
{
    $normalized = bp_normalize_attendance_status($status);
    return in_array($normalized, bp_attendance_present_statuses(), true);
}

function bp_attendance_is_absent_status(string $status): bool
{
    $normalized = bp_normalize_attendance_status($status);
    return in_array($normalized, bp_attendance_absent_statuses(), true);
}

function bp_attendance_is_weekoff_holiday_status(string $status): bool
{
    $normalized = bp_normalize_attendance_status($status);
    return in_array($normalized, bp_attendance_weekoff_holiday_statuses(), true);
}

function bp_attendance_is_permission_status(string $status): bool
{
    $normalized = bp_normalize_attendance_status($status);
    return $normalized !== '' && strpos($normalized, 'permission') !== false;
}

function bp_attendance_is_leave_status(string $status): bool
{
    $normalized = bp_normalize_attendance_status($status);
    if ($normalized === '') {
        return false;
    }

    $leaveStatuses = bp_dynamic_leave_statuses();
    if (isset($leaveStatuses[$normalized])) {
        return true;
    }

    if (in_array($normalized, ['lop', 'lwp'], true)) {
        return true;
    }

    if (strpos($normalized, 'leave') !== false) {
        return true;
    }

    if (preg_match('/^half[\s-]?day\s+(.+)$/', $normalized, $matches) === 1) {
        $baseStatus = trim((string)($matches[1] ?? ''));
        if ($baseStatus !== '') {
            if (isset($leaveStatuses[$baseStatus])) {
                return true;
            }

            if (in_array($baseStatus, ['lop', 'lwp'], true)) {
                return true;
            }

            if (strpos($baseStatus, 'leave') !== false) {
                return true;
            }
        }
    }

    return false;
}

function bp_attendance_summary_bucket(string $status): string
{
    if (bp_attendance_is_present_status($status)) {
        return 'present';
    }

    if (bp_attendance_is_weekoff_holiday_status($status)) {
        return 'weekoff_holiday';
    }

    if (bp_attendance_is_permission_status($status)) {
        return 'permission';
    }

    if (bp_attendance_is_absent_status($status)) {
        return 'absent';
    }

    if (bp_attendance_is_leave_status($status)) {
        return 'leave';
    }

    return 'unknown';
}

function bp_attendance_row_score(array $row): int
{
    $status = trim((string)($row['attendance_status'] ?? ''));
    $bucket = bp_attendance_summary_bucket($status);

    $score = 100;
    if ($bucket === 'present') {
        $score = 500;
    } elseif ($bucket === 'permission') {
        $score = 450;
    } elseif ($bucket === 'leave') {
        $score = 400;
    } elseif ($bucket === 'weekoff_holiday') {
        $score = 300;
    } elseif ($bucket === 'absent') {
        $score = 200;
    }

    foreach (['entry_punch', 'exit_punch', 'worked_hours'] as $column) {
        if (trim((string)($row[$column] ?? '')) !== '') {
            $score += 10;
        }
    }

    if (bp_normalize_attendance_status($status) !== '') {
        $score += 5;
    }

    return $score;
}

function bp_today_status_bucket(string $status): string
{
    $normalized = bp_normalize_attendance_status($status);
    if ($normalized === '') {
        return 'Not Marked';
    }

    if (bp_attendance_is_present_status($normalized)) {
        return 'Present';
    }
    if (bp_attendance_is_weekoff_holiday_status($normalized)) {
        return $normalized === 'holiday' ? 'Holiday' : 'Week Off';
    }
    if (bp_attendance_is_permission_status($normalized)) {
        return 'Permission';
    }
    if (bp_attendance_is_leave_status($normalized)) {
        return 'On Leave';
    }
    if (bp_attendance_is_absent_status($normalized)) {
        return 'Absent';
    }

    return 'Not Marked';
}

function bp_fetch_attendance_summary(
    string $employeeId,
    ?string $monthFrom = null,
    ?string $monthTo = null,
    string $staffUniqueId = ''
): array
{
    $employeeId = trim($employeeId);
    $staffUniqueId = trim($staffUniqueId);
    $today = date('Y-m-d');

    if ($monthFrom === null || bp_date_ymd($monthFrom) === null) {
        $monthFrom = date('Y-m-01');
    }
    if ($monthTo === null || bp_date_ymd($monthTo) === null) {
        $monthTo = date('Y-m-t');
    }
    if ($monthFrom > $monthTo) {
        $tmp = $monthFrom;
        $monthFrom = $monthTo;
        $monthTo = $tmp;
    }

    $empty = [
        'month_from' => $monthFrom,
        'month_to' => $monthTo,
        'monthly_present' => 0,
        'monthly_absent' => 0,
        'monthly_weekoff_holiday' => 0,
        'monthly_permission_used' => 0,
        'today_date' => $today,
        'today_attendance_status' => '',
        'today_status' => 'Not Marked',
        'today_entry_punch' => '',
        'today_exit_punch' => '',
        'today_worked_hours' => '',
    ];

    $attendanceIds = array_values(array_unique(array_filter([
        $employeeId,
        $staffUniqueId,
    ], static function (string $value): bool {
        return $value !== '';
    })));

    if (empty($attendanceIds)) {
        return $empty;
    }

    if (empty(bp_table_columns('vw_attendance_with_shift'))) {
        return $empty;
    }

    $quotedIds = array_map(static function (string $value): string {
        return bp_sql_quote($value);
    }, $attendanceIds);

    $rows = bp_fetch_rows(
        'vw_attendance_with_shift',
        ['shift_date', 'attendance_status', 'entry_punch', 'exit_punch', 'worked_hours'],
        'employee_id IN (' . implode(',', $quotedIds) . ')'
            . ' AND shift_date >= ' . bp_sql_quote($monthFrom)
            . ' AND shift_date <= ' . bp_sql_quote($monthTo)
    );

    $monthlyPresent = 0;
    $monthlyAbsent = 0;
    $monthlyWeekoffHoliday = 0;
    $monthlyPermissionUsed = 0;

    $todayStatusRaw = '';
    $todayEntryPunch = '';
    $todayExitPunch = '';
    $todayWorkedHours = '';

    $rowsByDate = [];
    foreach ($rows as $row) {
        $shiftDate = bp_date_ymd((string)($row['shift_date'] ?? ''));
        if ($shiftDate === null) {
            continue;
        }

        $candidate = [
            'shift_date' => $shiftDate,
            'attendance_status' => trim((string)($row['attendance_status'] ?? '')),
            'entry_punch' => trim((string)($row['entry_punch'] ?? '')),
            'exit_punch' => trim((string)($row['exit_punch'] ?? '')),
            'worked_hours' => trim((string)($row['worked_hours'] ?? '')),
        ];

        if (!isset($rowsByDate[$shiftDate])) {
            $rowsByDate[$shiftDate] = $candidate;
            continue;
        }

        if (bp_attendance_row_score($candidate) >= bp_attendance_row_score($rowsByDate[$shiftDate])) {
            $rowsByDate[$shiftDate] = $candidate;
        }
    }

    foreach ($rowsByDate as $shiftDate => $row) {
        $statusRaw = trim((string)($row['attendance_status'] ?? ''));
        $bucket = bp_attendance_summary_bucket($statusRaw);

        if ($bucket === 'present') {
            $monthlyPresent++;
        } elseif ($bucket === 'weekoff_holiday') {
            $monthlyWeekoffHoliday++;
        } elseif ($bucket === 'absent' || $bucket === 'leave') {
            $monthlyAbsent++;
        }

        if ($bucket === 'permission') {
            $monthlyPermissionUsed++;
        }

        if ($shiftDate === $today) {
            $candidateEntry = trim((string)($row['entry_punch'] ?? ''));
            $candidateExit = trim((string)($row['exit_punch'] ?? ''));
            $candidateWorked = trim((string)($row['worked_hours'] ?? ''));
            $hasBetterData = ($todayStatusRaw === '')
                || ($candidateEntry !== '' || $candidateExit !== '' || $candidateWorked !== '');

            if ($hasBetterData) {
                $todayStatusRaw = $statusRaw;
                $todayEntryPunch = $candidateEntry;
                $todayExitPunch = $candidateExit;
                $todayWorkedHours = $candidateWorked;
            }
        }
    }

    return [
        'month_from' => $monthFrom,
        'month_to' => $monthTo,
        'monthly_present' => $monthlyPresent,
        'monthly_absent' => $monthlyAbsent,
        'monthly_weekoff_holiday' => $monthlyWeekoffHoliday,
        'monthly_permission_used' => $monthlyPermissionUsed,
        'today_date' => $today,
        'today_attendance_status' => $todayStatusRaw,
        'today_status' => bp_today_status_bucket($todayStatusRaw),
        'today_entry_punch' => $todayEntryPunch,
        'today_exit_punch' => $todayExitPunch,
        'today_worked_hours' => $todayWorkedHours,
    ];
}

function bp_fetch_flexi_holidays(int $limit = 100): array
{
    $rows = bp_fetch_rows(
        'holiday_creation',
        ['unique_id', 'holiday_date', 'description'],
        'is_delete = 0 AND is_active = 1 AND is_flexi_leave = 1'
    );

    usort($rows, static function (array $a, array $b): int {
        $aTime = strtotime((string)($a['holiday_date'] ?? '')) ?: 0;
        $bTime = strtotime((string)($b['holiday_date'] ?? '')) ?: 0;
        return $aTime <=> $bTime;
    });

    $rows = array_slice($rows, 0, max(1, $limit));

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'holiday_unique_id' => (string)($row['unique_id'] ?? ''),
            'holiday_date' => (string)($row['holiday_date'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
        ];
    }

    return $out;
}

function bp_fetch_flexi_holiday(string $holidayUniqueId): ?array
{
    $holidayUniqueId = trim($holidayUniqueId);
    if ($holidayUniqueId === '') {
        return null;
    }

    return bp_fetch_one(
        'holiday_creation',
        ['unique_id', 'holiday_date', 'description', 'is_flexi_leave', 'is_active', 'is_delete'],
        [
            'unique_id' => $holidayUniqueId,
            'is_active' => 1,
            'is_delete' => 0,
        ]
    );
}

function bp_validate_flexi_holiday(?string $holidayUniqueId, string $fromDate, string $toDate): ?string
{
    $holidayUniqueId = trim((string)$holidayUniqueId);
    if ($holidayUniqueId === '') {
        return null;
    }

    $holiday = bp_fetch_flexi_holiday($holidayUniqueId);
    if (!$holiday) {
        return 'Invalid Flexi Holiday selection';
    }

    if (((int)($holiday['is_flexi_leave'] ?? 0)) !== 1) {
        return 'Selected holiday is not enabled for Flexi Leave';
    }

    $holidayDate = (string)($holiday['holiday_date'] ?? '');
    if ($holidayDate === '') {
        return 'Flexi Holiday date is missing';
    }

    if ($holidayDate < $fromDate || $holidayDate > $toDate) {
        return 'Flexi Holiday date must be within From/To date range';
    }

    return null;
}

function bp_is_reporting_officer(string $employeeId): bool
{
    if ($employeeId === '') {
        return false;
    }

    $rows = bp_fetch_rows(
        'staff_test',
        ['COUNT(unique_id) AS c'],
        "is_active = 1 AND is_delete = 0 AND reporting_officer = " . bp_sql_quote($employeeId)
    );

    return ((int)($rows[0]['c'] ?? 0)) > 0;
}

function bp_hr_designation_ids(): array
{
    return ['6895c065c993645658'];
}

function bp_fetch_hr_staff_ids(): array
{
    $designationIds = bp_hr_designation_ids();
    if (empty($designationIds)) {
        return [];
    }

    $quotedIds = array_map(static function (string $value): string {
        return bp_sql_quote($value);
    }, $designationIds);

    $rows = bp_fetch_rows(
        'staff_test',
        ['employee_id'],
        'is_active = 1 AND is_delete = 0'
            . ' AND designation_unique_id IN (' . implode(',', $quotedIds) . ')'
    );

    $ids = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['employee_id'] ?? ''));
        if ($id !== '') {
            $ids[$id] = true;
        }
    }

    return array_keys($ids);
}

function bp_is_hr_staff(string $employeeId): bool
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return false;
    }

    return in_array($employeeId, bp_fetch_hr_staff_ids(), true);
}

function bp_unique_staff_ids(array $values, array $exclude = []): array
{
    $excludeSet = [];
    foreach ($exclude as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $excludeSet[$value] = true;
        }
    }

    $out = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '' || isset($excludeSet[$value])) {
            continue;
        }
        $out[$value] = true;
    }

    return array_keys($out);
}

function bp_collect_leave_approval_recipient_ids(string $employeeId, string $officerEmployeeId): array
{
    return bp_unique_staff_ids(
        array_merge([$officerEmployeeId], bp_fetch_hr_staff_ids()),
        [$employeeId]
    );
}

function bp_get_reporting_staff_ids(string $officerEmployeeId): array
{
    if ($officerEmployeeId === '') {
        return [];
    }

    $rows = bp_fetch_rows(
        'staff_test',
        ['employee_id'],
        [
            'reporting_officer' => $officerEmployeeId,
            'is_active' => 1,
            'is_delete' => 0,
        ]
    );

    $ids = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['employee_id'] ?? ''));
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function bp_period_to_half_day(int $period): int
{
    return in_array($period, [1, 2], true) ? 1 : 0;
}

function bp_calculate_leave_days(string $fromDate, string $toDate, bool $isSandwich, int $period): float
{
    if (in_array($period, [1, 2], true)) {
        return 0.5;
    }

    $start = new DateTime($fromDate);
    $end = new DateTime($toDate);
    if ($start > $end) {
        return 0.0;
    }

    $dates = [];
    $cursor = clone $start;
    while ($cursor <= $end) {
        $dates[] = clone $cursor;
        $cursor->modify('+1 day');
    }

    $totalDays = count($dates);
    $weekendDays = 0;
    $satSunPairs = 0;
    $lastWasSat = false;

    foreach ($dates as $dt) {
        $dow = (int)$dt->format('N'); // 1..7, weekend=6,7
        if ($dow >= 6) {
            $weekendDays++;
        }

        if ($dow === 6) {
            $lastWasSat = true;
        } elseif ($dow === 7) {
            if ($lastWasSat) {
                $satSunPairs++;
            }
            $lastWasSat = false;
        } else {
            $lastWasSat = false;
        }
    }

    if (!$isSandwich) {
        return (float) max(0, $totalDays - $weekendDays);
    }

    if ($satSunPairs >= 2) {
        return (float) $totalDays;
    }

    return (float) max(0, $totalDays - $weekendDays);
}

function bp_has_overlap(string $employeeId, string $fromDate, string $toDate, string $ignoreUniqueId = ''): bool
{
    $rows = bp_fetch_rows(
        'leave_entry',
        ['unique_id', 'from_date', 'to_date', 'status'],
        [
            'employee_id' => $employeeId,
            'is_delete' => 0,
        ]
    );

    $newFrom = strtotime($fromDate);
    $newTo = strtotime($toDate);
    if ($newFrom === false || $newTo === false) {
        return true;
    }

    foreach ($rows as $row) {
        $existingId = (string)($row['unique_id'] ?? '');
        if ($ignoreUniqueId !== '' && $existingId === $ignoreUniqueId) {
            continue;
        }

        $status = (int)($row['status'] ?? 0);
        if (!in_array($status, [0, 1], true)) {
            continue;
        }

        $oldFrom = strtotime((string)($row['from_date'] ?? ''));
        $oldTo = strtotime((string)($row['to_date'] ?? ''));
        if ($oldFrom === false || $oldTo === false) {
            continue;
        }

        if ($newFrom <= $oldTo && $newTo >= $oldFrom) {
            return true;
        }
    }

    return false;
}

function bp_fetch_leave_entries(string $where): array
{
    $rows = bp_fetch_rows(
        'leave_entry',
        [
            'unique_id',
            'employee_id',
            'leave_type_id',
            'from_date',
            'to_date',
            'period',
            'total_days',
            'reason',
            'half_day',
            'status',
            'created',
            'updated',
            'updated_user_id',
        ],
        $where
    );

    usort($rows, static function (array $a, array $b): int {
        $aTime = strtotime((string)($a['created'] ?? $a['from_date'] ?? '')) ?: 0;
        $bTime = strtotime((string)($b['created'] ?? $b['from_date'] ?? '')) ?: 0;
        return $bTime <=> $aTime;
    });

    return $rows;
}

function bp_where_for_date_overlap(?string $fromDate, ?string $toDate): string
{
    if (!$fromDate && !$toDate) {
        return '';
    }

    if ($fromDate && $toDate) {
        return ' AND from_date <= ' . bp_sql_quote($toDate) . ' AND to_date >= ' . bp_sql_quote($fromDate);
    }

    if ($fromDate) {
        return ' AND to_date >= ' . bp_sql_quote($fromDate);
    }

    return ' AND from_date <= ' . bp_sql_quote((string)$toDate);
}

function bp_fetch_leave_entries_by_employee(
    string $employeeId,
    ?string $fromDate = null,
    ?string $toDate = null,
    ?int $status = null
): array {
    $where = 'is_delete = 0 AND employee_id = ' . bp_sql_quote($employeeId);
    $where .= bp_where_for_date_overlap($fromDate, $toDate);

    if ($status !== null) {
        $where .= ' AND status = ' . (int)$status;
    }

    return bp_fetch_leave_entries($where);
}

function bp_fetch_leave_entries_for_officer(
    string $officerEmployeeId,
    ?string $fromDate = null,
    ?string $toDate = null,
    ?int $status = null
): array {
    $ids = bp_get_reporting_staff_ids($officerEmployeeId);
    if (empty($ids)) {
        return [];
    }

    $quotedIds = array_map(static function (string $id): string {
        return bp_sql_quote($id);
    }, $ids);

    $where = 'is_delete = 0 AND employee_id IN (' . implode(',', $quotedIds) . ')';
    $where .= bp_where_for_date_overlap($fromDate, $toDate);

    if ($status !== null) {
        $where .= ' AND status = ' . (int)$status;
    }

    return bp_fetch_leave_entries($where);
}

function bp_fetch_leave_entries_for_hr(
    ?string $fromDate = null,
    ?string $toDate = null,
    ?int $status = null
): array {
    $where = 'is_delete = 0';
    $where .= bp_where_for_date_overlap($fromDate, $toDate);

    if ($status !== null) {
        $where .= ' AND status = ' . (int)$status;
    }

    return bp_fetch_leave_entries($where);
}

function bp_attach_leave_meta(array $entries): array
{
    $typeMap = bp_fetch_leave_type_map();

    $out = [];
    foreach ($entries as $row) {
        $employeeId = (string)($row['employee_id'] ?? '');
        $leaveTypeId = (string)($row['leave_type_id'] ?? '');
        $status = (int)($row['status'] ?? 0);

        $typeRow = $typeMap[$leaveTypeId] ?? null;
        $leaveType = $typeRow['leave_type'] ?? ($leaveTypeId === 'lwp' ? 'Leave Without Pay' : $leaveTypeId);

        $out[] = [
            'unique_id' => (string)($row['unique_id'] ?? ''),
            'employee_id' => $employeeId,
            'employee_name' => bp_employee_name($employeeId),
            'leave_type_id' => $leaveTypeId,
            'leave_type' => $leaveType,
            'from_date' => (string)($row['from_date'] ?? ''),
            'to_date' => (string)($row['to_date'] ?? ''),
            'period' => (int)($row['period'] ?? 3),
            'total_days' => (float)($row['total_days'] ?? 0),
            'half_day' => (int)($row['half_day'] ?? 0) === 1,
            'status' => $status,
            'status_label' => bp_status_label($status),
            'reason' => (string)($row['reason'] ?? ''),
            'created' => (string)($row['created'] ?? ''),
            'updated' => (string)($row['updated'] ?? ''),
        ];
    }

    return $out;
}

function bp_fetch_leave_record(string $leaveUniqueId): ?array
{
    return bp_fetch_one(
        'leave_entry',
        [
            'unique_id',
            'employee_id',
            'leave_type_id',
            'from_date',
            'to_date',
            'period',
            'total_days',
            'reason',
            'half_day',
            'status',
            'created',
            'updated',
            'updated_user_id',
        ],
        [
            'unique_id' => $leaveUniqueId,
            'is_delete' => 0,
        ]
    );
}

function bp_insert_notification_result(
    string $toStaffId,
    string $fromStaffId,
    string $leaveUniqueId,
    string $title,
    string $message,
    string $deepLink = '/leave-approval'
): array {
    if ($toStaffId === '') {
        return [
            'status' => false,
            'error' => 'Missing to_staff_id',
        ];
    }

    $columns = [
        'unique_id' => bp_unique_id(),
        'to_staff_id' => $toStaffId,
        'from_staff_id' => $fromStaffId,
        'leave_unique_id' => $leaveUniqueId,
        'title' => $title,
        'message' => $message,
        'deep_link' => $deepLink,
        'is_read' => 0,
        'created' => bp_now(),
        'is_active' => 1,
        'is_delete' => 0,
    ];

    $tableColumns = bp_table_columns('bp_leave_notifications');
    if (!empty($tableColumns)) {
        $columns = array_filter(
            $columns,
            static function ($_, $key) use ($tableColumns): bool {
                return isset($tableColumns[(string)$key]);
            },
            ARRAY_FILTER_USE_BOTH
        );
    }

    $inserted = bp_insert_row_raw('bp_leave_notifications', $columns);
    return [
        'status' => (bool)($inserted->status ?? false),
        'error' => bp_error_value_to_text($inserted->error ?? null),
    ];
}

function bp_insert_notification(
    string $toStaffId,
    string $fromStaffId,
    string $leaveUniqueId,
    string $title,
    string $message,
    string $deepLink = '/leave-approval'
): bool {
    $result = bp_insert_notification_result(
        $toStaffId,
        $fromStaffId,
        $leaveUniqueId,
        $title,
        $message,
        $deepLink
    );

    return (bool)($result['status'] ?? false);
}

function bp_notification_route_from_deep_link(string $deepLink): string
{
    $deepLink = trim($deepLink);
    if ($deepLink === '') {
        return '/notifications';
    }

    $path = parse_url($deepLink, PHP_URL_PATH);
    $path = is_string($path) ? trim($path) : '';
    if ($path === '') {
        $path = $deepLink;
    }

    if (strpos($path, '/leave-approval') === 0) {
        return '/leave-approval';
    }
    if (strpos($path, '/leave') === 0) {
        return '/leave';
    }

    return '/notifications';
}

function bp_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 20): array
{
    if (!function_exists('curl_init')) {
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => 'cURL extension is unavailable',
        ];
    }

    $body = json_encode($payload);
    if ($body === false) {
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => 'Failed to encode JSON payload',
        ];
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => $body,
    ]);

    $raw = curl_exec($curl);
    if ($raw === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => $error !== '' ? $error : 'HTTP request failed',
        ];
    }

    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $json = json_decode((string)$raw, true);
    return [
        'status' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'body' => (string)$raw,
        'json' => is_array($json) ? $json : null,
        'error' => $httpCode >= 200 && $httpCode < 300 ? '' : ('HTTP ' . $httpCode),
    ];
}

function bp_http_post_form(string $url, array $payload, array $headers = [], int $timeout = 20): array
{
    if (!function_exists('curl_init')) {
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => 'cURL extension is unavailable',
        ];
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/x-www-form-urlencoded',
        ], $headers),
        CURLOPT_POSTFIELDS => http_build_query($payload),
    ]);

    $raw = curl_exec($curl);
    if ($raw === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return [
            'status' => false,
            'http_code' => 0,
            'body' => '',
            'json' => null,
            'error' => $error !== '' ? $error : 'HTTP request failed',
        ];
    }

    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $json = json_decode((string)$raw, true);
    return [
        'status' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'body' => (string)$raw,
        'json' => is_array($json) ? $json : null,
        'error' => $httpCode >= 200 && $httpCode < 300 ? '' : ('HTTP ' . $httpCode),
    ];
}

function bp_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function bp_firebase_service_account(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $rawJson = trim((string)getenv('BP_FIREBASE_SERVICE_ACCOUNT_JSON'));
    $config = null;
    $source = '';

    if ($rawJson !== '') {
        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) {
            $config = $decoded;
            $source = 'env:BP_FIREBASE_SERVICE_ACCOUNT_JSON';
        }
    }

    if (!is_array($config)) {
        $envFile = trim((string)getenv('BP_FIREBASE_SERVICE_ACCOUNT_FILE'));
        $googleApplicationCredentials = trim((string)getenv('GOOGLE_APPLICATION_CREDENTIALS'));
        $firebaseConfigFile = 'bp-mobile-app-3d098-firebase-adminsdk-fbsvc-386a5acda8.json';
        $projectRoot = dirname(__DIR__, 3);
        $homeDir = trim((string)(getenv('HOME') ?: ''));
        $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $documentRootParent = $documentRoot !== '' ? dirname($documentRoot) : '';
        $candidatePatterns = array_filter(array_unique([
            $envFile,
            $googleApplicationCredentials,
            '/bp_mobile_app_configs/' . $firebaseConfigFile,
            $projectRoot !== '' ? $projectRoot . '/bp_mobile_app_configs/' . $firebaseConfigFile : '',
            $homeDir !== '' ? $homeDir . '/bp_mobile_app_configs/' . $firebaseConfigFile : '',
            $documentRootParent !== '' ? $documentRootParent . '/bp_mobile_app_configs/' . $firebaseConfigFile : '',
            $projectRoot !== '' ? $projectRoot . '/bp_mobile_app_configs/firebase-adminsdk-*.json' : '',
            $homeDir !== '' ? $homeDir . '/bp_mobile_app_configs/firebase-adminsdk-*.json' : '',
            $documentRootParent !== '' ? $documentRootParent . '/bp_mobile_app_configs/firebase-adminsdk-*.json' : '',
            __DIR__ . '/firebase-service-account.json',
            __DIR__ . '/firebase-adminsdk.json',
            __DIR__ . '/firebase-adminsdk-*.json',
            dirname(__DIR__) . '/config/firebase-service-account.json',
            dirname(__DIR__) . '/config/firebase-adminsdk.json',
            dirname(__DIR__) . '/config/firebase-adminsdk-*.json',
            dirname(__DIR__, 2) . '/config/firebase-service-account.json',
            dirname(__DIR__, 2) . '/config/firebase-adminsdk.json',
            dirname(__DIR__, 2) . '/config/firebase-adminsdk-*.json',
            dirname(__DIR__, 3) . '/firebase-service-account.json',
            dirname(__DIR__, 3) . '/firebase-adminsdk.json',
            dirname(__DIR__, 3) . '/firebase-adminsdk-*.json',
        ]));

        $candidates = [];
        foreach ($candidatePatterns as $pattern) {
            if (strpos($pattern, '*') !== false) {
                foreach (glob($pattern) ?: [] as $matchedFile) {
                    $candidates[] = $matchedFile;
                }
                continue;
            }

            $candidates[] = $pattern;
        }
        $candidates = array_values(array_filter(array_unique($candidates)));

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $decoded = json_decode((string)file_get_contents($candidate), true);
            if (is_array($decoded)) {
                $config = $decoded;
                $source = $candidate;
                break;
            }
        }
    }

    if (!is_array($config)) {
        $cache = [
            'status' => false,
            'error' => 'Firebase service account JSON not found. Set BP_FIREBASE_SERVICE_ACCOUNT_FILE or BP_FIREBASE_SERVICE_ACCOUNT_JSON.',
        ];
        return $cache;
    }

    $projectId = trim((string)($config['project_id'] ?? getenv('BP_FIREBASE_PROJECT_ID') ?? ''));
    $clientEmail = trim((string)($config['client_email'] ?? ''));
    $privateKey = (string)($config['private_key'] ?? '');

    if ($projectId === '' || $clientEmail === '' || $privateKey === '') {
        $cache = [
            'status' => false,
            'error' => 'Firebase service account JSON is missing project_id, client_email, or private_key.',
            'source' => $source,
        ];
        return $cache;
    }

    $cache = [
        'status' => true,
        'error' => '',
        'source' => $source,
        'data' => [
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
        ],
    ];
    return $cache;
}

function bp_error_text(Throwable $e): string
{
    $message = trim($e->getMessage());
    if ($message !== '') {
        return $message;
    }

    return get_class($e);
}

function bp_error_value_to_text($value): string
{
    if ($value === null) {
        return '';
    }

    if ($value instanceof Throwable) {
        return bp_error_text($value);
    }

    if (is_string($value)) {
        return trim($value);
    }

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    if (is_array($value)) {
        $encoded = json_encode($value);
        return $encoded !== false ? $encoded : 'array';
    }

    if (is_object($value)) {
        if (method_exists($value, 'errorInfo')) {
            try {
                $info = $value->errorInfo();
                if (is_array($info)) {
                    $parts = array_values(array_filter(array_map(
                        static function ($item): string {
                            return trim(is_scalar($item) ? (string)$item : '');
                        },
                        $info
                    )));
                    if (!empty($parts)) {
                        return implode(' | ', $parts);
                    }
                }
            } catch (Throwable $e) {
                return bp_error_text($e);
            }
        }

        if (method_exists($value, '__toString')) {
            try {
                return trim((string)$value);
            } catch (Throwable $e) {
                return bp_error_text($e);
            }
        }

        return get_class($value);
    }

    return '';
}

function bp_firebase_access_token(): array
{
    static $cache = [
        'access_token' => '',
        'expires_at' => 0,
    ];

    if ($cache['access_token'] !== '' && (int)$cache['expires_at'] > time() + 60) {
        return [
            'status' => true,
            'error' => '',
            'access_token' => (string)$cache['access_token'],
            'expires_at' => (int)$cache['expires_at'],
        ];
    }

    $serviceAccount = bp_firebase_service_account();
    if (empty($serviceAccount['status'])) {
        return [
            'status' => false,
            'error' => (string)($serviceAccount['error'] ?? 'Missing Firebase service account'),
        ];
    }

    $data = (array)($serviceAccount['data'] ?? []);
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $issuedAt = time();
    $expiresAt = $issuedAt + 3600;
    $claims = [
        'iss' => (string)$data['client_email'],
        'sub' => (string)$data['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $issuedAt,
        'exp' => $expiresAt,
    ];

    $jwtPayload = bp_base64url_encode(json_encode($header) ?: '{}')
        . '.'
        . bp_base64url_encode(json_encode($claims) ?: '{}');
    $signature = '';
    $signed = openssl_sign(
        $jwtPayload,
        $signature,
        (string)$data['private_key'],
        OPENSSL_ALGO_SHA256
    );

    if (!$signed) {
        return [
            'status' => false,
            'error' => 'Failed to sign Firebase access token request',
        ];
    }

    $assertion = $jwtPayload . '.' . bp_base64url_encode($signature);
    $response = bp_http_post_form(
        'https://oauth2.googleapis.com/token',
        [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]
    );

    if (empty($response['status'])) {
        return [
            'status' => false,
            'error' => (string)($response['error'] ?? 'Failed to request Firebase access token'),
            'details' => $response['json'] ?? $response['body'] ?? null,
        ];
    }

    $json = is_array($response['json'] ?? null) ? $response['json'] : [];
    $accessToken = trim((string)($json['access_token'] ?? ''));
    $tokenTtl = (int)($json['expires_in'] ?? 3600);
    if ($accessToken === '') {
        return [
            'status' => false,
            'error' => 'Firebase OAuth response did not include access_token',
            'details' => $json,
        ];
    }

    $cache = [
        'access_token' => $accessToken,
        'expires_at' => $issuedAt + max(60, $tokenTtl),
    ];

    return [
        'status' => true,
        'error' => '',
        'access_token' => $accessToken,
        'expires_at' => (int)$cache['expires_at'],
    ];
}

function bp_filter_table_columns(string $table, array $columns): array
{
    $tableColumns = bp_table_columns($table);
    if (empty($tableColumns)) {
        return [];
    }

    return array_filter(
        $columns,
        static function ($_, $key) use ($tableColumns): bool {
            return isset($tableColumns[(string)$key]);
        },
        ARRAY_FILTER_USE_BOTH
    );
}

function bp_upsert_device_token(string $staffId, string $fcmToken, string $platform): array
{
    $staffId = trim($staffId);
    $fcmToken = trim($fcmToken);
    $platform = strtolower(trim($platform));

    if ($staffId === '' || $fcmToken === '') {
        return [
            'status' => false,
            'error' => 'staff_id and fcm_token are required',
        ];
    }

    $tableColumns = bp_table_columns('bp_device_tokens');
    if (empty($tableColumns)) {
        return [
            'status' => false,
            'error' => 'bp_device_tokens table is missing',
        ];
    }

    if (!in_array($platform, ['android', 'ios'], true)) {
        $platform = 'android';
    }

    $now = bp_now();
    $existing = bp_fetch_one(
        'bp_device_tokens',
        ['unique_id'],
        ['fcm_token' => $fcmToken]
    );

    if ($existing) {
        $update = bp_filter_table_columns('bp_device_tokens', [
            'staff_id' => $staffId,
            'platform' => $platform,
            'last_seen_at' => $now,
            'updated' => $now,
            'is_active' => 1,
            'is_delete' => 0,
        ]);

        $res = bp_update_row(
            'bp_device_tokens',
            $update,
            ['fcm_token' => $fcmToken]
        );

        return [
            'status' => (bool)($res->status ?? false),
            'error' => bp_error_value_to_text($res->error ?? null),
            'action' => 'updated',
        ];
    }

    $insert = bp_filter_table_columns('bp_device_tokens', [
        'unique_id' => bp_unique_id(),
        'staff_id' => $staffId,
        'platform' => $platform,
        'fcm_token' => $fcmToken,
        'last_seen_at' => $now,
        'created' => $now,
        'updated' => $now,
        'is_active' => 1,
        'is_delete' => 0,
    ]);

    $res = bp_insert_row_raw('bp_device_tokens', $insert);
    return [
        'status' => (bool)($res->status ?? false),
        'error' => bp_error_value_to_text($res->error ?? null),
        'action' => 'inserted',
    ];
}

function bp_deactivate_device_token(string $fcmToken, string $staffId = ''): array
{
    $fcmToken = trim($fcmToken);
    $staffId = trim($staffId);

    if ($fcmToken === '') {
        return [
            'status' => false,
            'error' => 'fcm_token is required',
            'updated' => 0,
        ];
    }

    $tableColumns = bp_table_columns('bp_device_tokens');
    if (empty($tableColumns)) {
        return [
            'status' => false,
            'error' => 'bp_device_tokens table is missing',
            'updated' => 0,
        ];
    }

    $where = ['fcm_token' => $fcmToken];
    if ($staffId !== '') {
        $where['staff_id'] = $staffId;
    }

    $update = bp_filter_table_columns('bp_device_tokens', [
        'is_active' => 0,
        'is_delete' => 0,
        'updated' => bp_now(),
    ]);
    $res = bp_update_row('bp_device_tokens', $update, $where);

    return [
        'status' => (bool)($res->status ?? false),
        'error' => bp_error_value_to_text($res->error ?? null),
        'updated' => (bool)($res->status ?? false) ? 1 : 0,
    ];
}

function bp_fetch_device_tokens(string $staffId): array
{
    $staffId = trim($staffId);
    if ($staffId === '') {
        return [];
    }

    $rows = bp_fetch_rows(
        'bp_device_tokens',
        ['fcm_token'],
        [
            'staff_id' => $staffId,
            'is_active' => 1,
            'is_delete' => 0,
        ]
    );

    $tokens = [];
    foreach ($rows as $row) {
        $token = trim((string)($row['fcm_token'] ?? ''));
        if ($token !== '') {
            $tokens[] = $token;
        }
    }

    return array_values(array_unique($tokens));
}

function bp_send_push_to_token(string $fcmToken, string $title, string $message, array $data = []): array
{
    $tokenResult = bp_firebase_access_token();
    if (empty($tokenResult['status'])) {
        return [
            'status' => false,
            'error' => (string)($tokenResult['error'] ?? 'Missing Firebase access token'),
        ];
    }

    $serviceAccount = bp_firebase_service_account();
    $serviceData = (array)($serviceAccount['data'] ?? []);
    $projectId = trim((string)($serviceData['project_id'] ?? ''));
    if ($projectId === '') {
        return [
            'status' => false,
            'error' => 'Firebase project_id is missing',
        ];
    }

    $normalizedData = [];
    foreach ($data as $key => $value) {
        $key = trim((string)$key);
        if ($key === '' || $value === null) {
            continue;
        }
        $normalizedData[$key] = (string)$value;
    }

    $payload = [
        'message' => [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body' => $message,
            ],
            'data' => $normalizedData,
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'channel_id' => 'bp_high_importance_notifications',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ],
            'apns' => [
                'headers' => [
                    'apns-push-type' => 'alert',
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                    ],
                ],
            ],
        ],
    ];

    $response = bp_http_post_json(
        'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send',
        $payload,
        ['Authorization: Bearer ' . (string)$tokenResult['access_token']]
    );

    $json = is_array($response['json'] ?? null) ? $response['json'] : [];
    $errorText = '';
    if (!$response['status']) {
        $firebaseError = is_array($json['error'] ?? null) ? $json['error'] : [];
        $errorText = trim((string)($firebaseError['status'] ?? ''));
        $messageText = trim((string)($firebaseError['message'] ?? ''));
        if ($messageText !== '') {
            $errorText = $errorText !== '' ? ($errorText . ': ' . $messageText) : $messageText;
        }
        if ($errorText === '') {
            $errorText = (string)($response['error'] ?? 'FCM send failed');
        }
    }

    return [
        'status' => (bool)($response['status'] ?? false),
        'error' => $errorText,
        'http_code' => (int)($response['http_code'] ?? 0),
        'response' => $json,
    ];
}

function bp_send_push_notification_to_staff(string $staffId, string $title, string $message, array $data = []): array
{
    $staffId = trim($staffId);
    if ($staffId === '') {
        return [
            'attempted' => false,
            'sent' => false,
            'token_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'invalidated_count' => 0,
            'error' => 'Missing staff_id',
        ];
    }

    $tokens = bp_fetch_device_tokens($staffId);
    if (empty($tokens)) {
        return [
            'attempted' => false,
            'sent' => false,
            'token_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'invalidated_count' => 0,
            'error' => 'No active device tokens registered',
        ];
    }

    $successCount = 0;
    $failureCount = 0;
    $invalidatedCount = 0;
    $errors = [];

    foreach ($tokens as $token) {
        $result = bp_send_push_to_token($token, $title, $message, $data);
        if (!empty($result['status'])) {
            $successCount++;
            continue;
        }

        $failureCount++;
        $errorText = trim((string)($result['error'] ?? 'FCM send failed'));
        if ($errorText !== '') {
            $errors[] = $errorText;
        }

        $normalizedError = strtoupper($errorText);
        if (strpos($normalizedError, 'UNREGISTERED') !== false ||
            strpos($normalizedError, 'INVALID_ARGUMENT') !== false) {
            $deactivate = bp_deactivate_device_token($token, $staffId);
            if (!empty($deactivate['status'])) {
                $invalidatedCount++;
            }
        }
    }

    return [
        'attempted' => true,
        'sent' => $successCount > 0,
        'token_count' => count($tokens),
        'success_count' => $successCount,
        'failure_count' => $failureCount,
        'invalidated_count' => $invalidatedCount,
        'error' => empty($errors) ? null : implode(' | ', array_unique($errors)),
    ];
}

function bp_deliver_leave_notification_result(
    string $toStaffId,
    string $fromStaffId,
    string $leaveUniqueId,
    string $title,
    string $message,
    string $deepLink = '/leave-approval',
    array $pushData = []
): array {
    $notification = bp_insert_notification_result(
        $toStaffId,
        $fromStaffId,
        $leaveUniqueId,
        $title,
        $message,
        $deepLink
    );

    $route = bp_notification_route_from_deep_link($deepLink);
    $payload = $pushData;
    if (!isset($payload['route'])) {
        $payload['route'] = $route;
    }
    if (!isset($payload['deepLink'])) {
        $payload['deepLink'] = $deepLink;
    }
    if ($leaveUniqueId !== '' && !isset($payload['leaveId'])) {
        $payload['leaveId'] = $leaveUniqueId;
    }

    $push = bp_send_push_notification_to_staff(
        $toStaffId,
        $title,
        $message,
        $payload
    );

    return [
        'notification' => $notification,
        'push' => $push,
    ];
}

function bp_deliver_leave_notifications_result(
    array $toStaffIds,
    string $fromStaffId,
    string $leaveUniqueId,
    string $title,
    string $message,
    string $deepLink = '/leave-approval',
    array $pushData = []
): array {
    $targets = bp_unique_staff_ids($toStaffIds);
    if (empty($targets)) {
        return [
            'attempted' => false,
            'sent' => false,
            'sent_count' => 0,
            'failure_count' => 0,
            'to_staff_ids' => [],
            'error' => 'No notification recipients resolved',
            'results' => [],
            'push' => [
                'attempted' => false,
                'sent' => false,
                'token_count' => 0,
                'success_count' => 0,
                'failure_count' => 0,
                'invalidated_count' => 0,
                'error' => null,
            ],
        ];
    }

    $results = [];
    $notificationSentCount = 0;
    $notificationFailureCount = 0;
    $notificationErrors = [];
    $aggregatePush = [
        'attempted' => false,
        'sent' => false,
        'token_count' => 0,
        'success_count' => 0,
        'failure_count' => 0,
        'invalidated_count' => 0,
        'error' => null,
    ];
    $pushErrors = [];

    foreach ($targets as $toStaffId) {
        $delivery = bp_deliver_leave_notification_result(
            $toStaffId,
            $fromStaffId,
            $leaveUniqueId,
            $title,
            $message,
            $deepLink,
            $pushData
        );

        $notification = (array)($delivery['notification'] ?? []);
        $push = (array)($delivery['push'] ?? []);

        $notificationOk = (bool)($notification['status'] ?? false);
        if ($notificationOk) {
            $notificationSentCount++;
        } else {
            $notificationFailureCount++;
            $error = trim((string)($notification['error'] ?? ''));
            if ($error !== '') {
                $notificationErrors[] = $error;
            }
        }

        if (!empty($push['attempted'])) {
            $aggregatePush['attempted'] = true;
        }
        $aggregatePush['sent'] = $aggregatePush['sent'] || !empty($push['sent']);
        $aggregatePush['token_count'] += (int)($push['token_count'] ?? 0);
        $aggregatePush['success_count'] += (int)($push['success_count'] ?? 0);
        $aggregatePush['failure_count'] += (int)($push['failure_count'] ?? 0);
        $aggregatePush['invalidated_count'] += (int)($push['invalidated_count'] ?? 0);

        $pushError = trim((string)($push['error'] ?? ''));
        if ($pushError !== '') {
            $pushErrors[] = $pushError;
        }

        $results[] = [
            'to_staff_id' => $toStaffId,
            'notification' => $notification,
            'push' => $push,
        ];
    }

    if (!empty($pushErrors)) {
        $aggregatePush['error'] = implode(' | ', array_values(array_unique($pushErrors)));
    }

    return [
        'attempted' => true,
        'sent' => $notificationSentCount > 0,
        'sent_count' => $notificationSentCount,
        'failure_count' => $notificationFailureCount,
        'to_staff_ids' => $targets,
        'error' => empty($notificationErrors)
            ? null
            : implode(' | ', array_values(array_unique($notificationErrors))),
        'results' => $results,
        'push' => $aggregatePush,
    ];
}

function bp_fetch_notifications(string $staffId, bool $unreadOnly = true, int $limit = 30): array
{
    if ($staffId === '') {
        return [];
    }

    $where = 'is_delete = 0 AND is_active = 1 AND to_staff_id = ' . bp_sql_quote($staffId);
    if ($unreadOnly) {
        $where .= ' AND is_read = 0';
    }

    $rows = bp_fetch_rows(
        'bp_leave_notifications',
        [
            'unique_id',
            'to_staff_id',
            'from_staff_id',
            'leave_unique_id',
            'title',
            'message',
            'deep_link',
            'is_read',
            'created',
        ],
        $where
    );

    usort($rows, static function (array $a, array $b): int {
        $aTime = strtotime((string)($a['created'] ?? '')) ?: 0;
        $bTime = strtotime((string)($b['created'] ?? '')) ?: 0;
        return $bTime <=> $aTime;
    });

    $rows = array_slice($rows, 0, max(1, $limit));

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'unique_id' => (string)($row['unique_id'] ?? ''),
            'to_staff_id' => (string)($row['to_staff_id'] ?? ''),
            'from_staff_id' => (string)($row['from_staff_id'] ?? ''),
            'leave_unique_id' => (string)($row['leave_unique_id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'deep_link' => (string)($row['deep_link'] ?? '/leave-approval'),
            'is_read' => ((int)($row['is_read'] ?? 0)) === 1,
            'created' => (string)($row['created'] ?? ''),
        ];
    }

    return $out;
}

function bp_notifications_unread_count(string $staffId): int
{
    $staffId = trim($staffId);
    if ($staffId === '') {
        return 0;
    }

    $rows = bp_fetch_rows(
        'bp_leave_notifications',
        ['COUNT(unique_id) AS c'],
        'is_delete = 0 AND is_active = 1 AND to_staff_id = ' . bp_sql_quote($staffId) . ' AND is_read = 0'
    );

    return (int)($rows[0]['c'] ?? 0);
}

function bp_mark_notifications_read(string $staffId, array $notificationIds): int
{
    $staffId = trim($staffId);
    if ($staffId === '' || empty($notificationIds)) {
        return 0;
    }

    $updated = 0;
    foreach ($notificationIds as $id) {
        $id = trim((string)$id);
        if ($id === '') {
            continue;
        }

        $res = bp_update_row(
            'bp_leave_notifications',
            [
                'is_read' => 1,
                'updated' => bp_now(),
            ],
            [
                'unique_id' => $id,
                'to_staff_id' => $staffId,
                'is_delete' => 0,
            ]
        );

        if ($res && ($res->status ?? false)) {
            $updated++;
        }
    }

    return $updated;
}

function bp_fetch_leave_documents(string $leaveUniqueId, int $limit = 10): array
{
    $leaveUniqueId = trim($leaveUniqueId);
    if ($leaveUniqueId === '') {
        return [];
    }

    $rows = bp_fetch_rows(
        'leave_entry_documents',
        ['unique_id', 'leave_unique_id', 'type', 'file_attach', 'created'],
        'is_delete = 0 AND is_active = 1 AND leave_unique_id = ' . bp_sql_quote($leaveUniqueId)
    );

    usort($rows, static function (array $a, array $b): int {
        $aTime = strtotime((string)($a['created'] ?? '')) ?: 0;
        $bTime = strtotime((string)($b['created'] ?? '')) ?: 0;
        return $bTime <=> $aTime;
    });

    $rows = array_slice($rows, 0, max(1, $limit));

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'unique_id' => (string)($row['unique_id'] ?? ''),
            'leave_unique_id' => (string)($row['leave_unique_id'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'file_attach' => (string)($row['file_attach'] ?? ''),
            'created' => (string)($row['created'] ?? ''),
        ];
    }

    return $out;
}

function bp_leave_upload_dir_candidates(): array
{
    $root = dirname(__DIR__, 3); // /public_html

    return [
        $root . '/blue_planet_erp/uploads/leave_docs',
        $root . '/blue_planet_beta/uploads/leave_docs',
        $root . '/uploads/leave_docs',
        $root . '/bp_mobile_app/uploads/leave_docs',
    ];
}

function bp_ensure_dir(string $path): bool
{
    if (is_dir($path)) {
        return true;
    }

    return @mkdir($path, 0777, true) || is_dir($path);
}

function bp_pick_upload_dir(): string
{
    $candidates = bp_leave_upload_dir_candidates();

    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            return $dir;
        }
    }

    foreach ($candidates as $dir) {
        if (bp_ensure_dir($dir)) {
            return $dir;
        }
    }

    return sys_get_temp_dir();
}

function bp_store_leave_document_file(array $file): ?string
{
    $name = (string)($file['name'] ?? '');
    $tmp = (string)($file['tmp_name'] ?? '');
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return null;
    }

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ext = $ext !== '' ? $ext : 'bin';

    $safeName = 'leave_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = bp_pick_upload_dir();
    $target = rtrim($dir, '/') . '/' . $safeName;

    if (!@move_uploaded_file($tmp, $target)) {
        return null;
    }

    return $safeName;
}

function bp_insert_leave_document(
    string $leaveUniqueId,
    string $employeeId,
    string $fileName,
    string $type = 'MAIN'
): bool {
    if ($leaveUniqueId === '' || $fileName === '') {
        return false;
    }

    $cols = [
        'unique_id' => bp_unique_id(),
        'leave_unique_id' => $leaveUniqueId,
        'type' => $type,
        'file_attach' => $fileName,
        'created_user_id' => $employeeId !== '' ? $employeeId : null,
        'created' => bp_now(),
        'is_active' => 1,
        'is_delete' => 0,
    ];

    $res = bp_insert_row('leave_entry_documents', $cols);
    return (bool)($res->status ?? false);
}

function bp_email_is_valid(string $email): bool
{
    $email = trim($email);
    if ($email === '') {
        return false;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function bp_normalize_email_list(array $emails): array
{
    $out = [];
    foreach ($emails as $email) {
        $email = strtolower(trim((string)$email));
        if ($email === '' || !bp_email_is_valid($email)) {
            continue;
        }
        $out[$email] = true;
    }

    return array_keys($out);
}

function bp_fetch_hr_office_emails(): array
{
    $rows = bp_fetch_rows(
        'staff_test',
        ['office_email_id'],
        [
            'designation_unique_id' => bp_hr_designation_ids()[0] ?? '',
            'is_active' => 1,
            'is_delete' => 0,
        ]
    );

    $emails = [];
    foreach ($rows as $row) {
        $emails[] = (string)($row['office_email_id'] ?? '');
    }

    return bp_normalize_email_list($emails);
}

function bp_format_display_date(string $raw, bool $withTime = false): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '-';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }

    return $withTime ? date('d-m-Y h:i A', $ts) : date('d-m-Y', $ts);
}

function bp_leave_mail_from_address(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if (strpos($host, '.') !== false) {
            return 'noreply@' . $host;
        }
    }

    return 'test@zigma.in';
}

function bp_send_leave_status_email(
    array $leaveRecord,
    array $employee,
    ?array $officer,
    string $leaveTypeLabel,
    int $statusCode
): array {
    if (!function_exists('mail')) {
        return [
            'attempted' => false,
            'sent' => false,
            'to' => [],
            'subject' => '',
            'error' => 'mail() function is unavailable',
        ];
    }

    $emails = [];
    $emails[] = (string)($employee['office_email_id'] ?? '');
    if (is_array($officer)) {
        $emails[] = (string)($officer['office_email_id'] ?? '');
    }
    $emails = array_merge($emails, bp_fetch_hr_office_emails());
    $emails = bp_normalize_email_list($emails);

    if (empty($emails)) {
        return [
            'attempted' => false,
            'sent' => false,
            'to' => [],
            'subject' => '',
            'error' => 'No valid recipient email configured',
        ];
    }

    $employeeId = trim((string)($employee['employee_id'] ?? ($leaveRecord['employee_id'] ?? '')));
    $employeeName = trim((string)($employee['staff_name'] ?? ''));
    if ($employeeName === '') {
        $employeeName = $employeeId !== '' ? $employeeId : 'Employee';
    }

    $statusLabel = bp_status_label($statusCode);
    $statusNote = '';
    if ($statusCode === 1) {
        $statusNote = '<div style="margin-top:14px;padding:12px;background:#eafaf1;border-left:4px solid #2ecc71;">'
            . '<strong>Approved</strong>: Your leave request has been approved.</div>';
    } elseif ($statusCode === 2) {
        $statusNote = '<div style="margin-top:14px;padding:12px;background:#fdecea;border-left:4px solid #e74c3c;">'
            . '<strong>Rejected</strong>: Your leave request has been rejected.</div>';
    } else {
        $statusNote = '<div style="margin-top:14px;padding:12px;background:#eef2ff;border-left:4px solid #4b7bec;">'
            . '<strong>Pending</strong>: Your leave request is pending approval.</div>';
    }

    $reason = trim((string)($leaveRecord['reason'] ?? ''));
    if ($reason === '') {
        $reason = '-';
    }

    $decisionBy = '';
    if (is_array($officer)) {
        $decisionBy = trim((string)($officer['staff_name'] ?? $officer['employee_id'] ?? ''));
    }
    if ($decisionBy === '') {
        $decisionBy = trim((string)($leaveRecord['approved_by'] ?? '-'));
    }

    $body = '<html><body style="font-family:Segoe UI,Arial,sans-serif;background:#f6f8fa;padding:20px;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" '
        . 'style="max-width:620px;margin:auto;background:#fff;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.08);">'
        . '<tr><td style="padding:20px;border-bottom:1px solid #eaeaea;">'
        . '<h2 style="margin:0;color:#2c3e50;">Leave Status Update</h2>'
        . '<p style="margin:6px 0 0;color:#7f8c8d;font-size:14px;">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#2f3640;">'
        . '<tr><td style="padding:6px 10px;font-weight:600;">Employee</td><td style="padding:6px 10px;">'
        . htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr style="background:#f9fafb;"><td style="padding:6px 10px;font-weight:600;">Leave Type</td><td style="padding:6px 10px;">'
        . htmlspecialchars($leaveTypeLabel, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 10px;font-weight:600;">From Date</td><td style="padding:6px 10px;">'
        . htmlspecialchars(bp_format_display_date((string)($leaveRecord['from_date'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr style="background:#f9fafb;"><td style="padding:6px 10px;font-weight:600;">To Date</td><td style="padding:6px 10px;">'
        . htmlspecialchars(bp_format_display_date((string)($leaveRecord['to_date'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 10px;font-weight:600;">Total Days</td><td style="padding:6px 10px;">'
        . htmlspecialchars((string)($leaveRecord['total_days'] ?? '0'), ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr style="background:#f9fafb;"><td style="padding:6px 10px;font-weight:600;">Decision By</td><td style="padding:6px 10px;">'
        . htmlspecialchars($decisionBy, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 10px;font-weight:600;">Decision At</td><td style="padding:6px 10px;">'
        . htmlspecialchars(bp_format_display_date((string)($leaveRecord['approved_at'] ?? ''), true), ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . $statusNote
        . '<div style="margin-top:14px;padding:12px;background:#f1f3f5;border-left:4px solid #4b7bec;">'
        . '<strong>Reason</strong><p style="margin:6px 0 0;color:#444;">'
        . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</p></div>'
        . '</td></tr>'
        . '<tr><td style="padding:15px 20px;border-top:1px solid #eaeaea;font-size:12px;color:#7f8c8d;">'
        . 'This is a system-generated email from HRMS.</td></tr>'
        . '</table></body></html>';

    $subject = 'HRMS - Leave ' . $statusLabel;
    $fromEmail = bp_leave_mail_from_address();
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: HRMS <' . $fromEmail . '>',
    ];

    $sent = @mail(implode(',', $emails), $subject, $body, implode("\r\n", $headers));
    $error = null;
    if (!$sent) {
        $last = error_get_last();
        $error = is_array($last) && !empty($last['message'])
            ? (string)$last['message']
            : 'mail() call failed';
    }

    error_log('bp_mobile_app leave_status_mail sent=' . ($sent ? '1' : '0') . ' to=' . implode(',', $emails));

    return [
        'attempted' => true,
        'sent' => (bool)$sent,
        'to' => $emails,
        'subject' => $subject,
        'error' => $error,
    ];
}
