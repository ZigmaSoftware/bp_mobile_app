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

/**
 * ─── Leave policy resolution (ported from the web ERP) ───────────────────
 *
 * Ports config/comfun.php's leave_master_dropdown_for_employee() chain so the
 * app offers exactly the leave types the web offers the same employee.
 *
 * Previously the mobile backend returned EVERY active leave_master_creation
 * row to everyone, which surfaced other companies' leave types (Bereavement,
 * Birthday, Maternity ...), showed near-duplicate names belonging to different
 * policies, and ignored the HR-only / gender gates.
 *
 * Web resolution order, reproduced exactly:
 *   1. Project override  - leave_policy_project_map via staff_test.work_location
 *   2. Company default   - leave_policy_company_map via staff_test.company_name,
 *                          excluding project-scoped policies
 *   3. No policy         - flexi leave types only
 * then the policy's leave_master_creation rows (policy_unique_id), filtered by
 * is_hr_only + gender_applicable, plus flexi types, deduped by unique_id.
 */

/**
 * Rows from a raw SQL statement. $pdo->select() only takes a single table, so
 * joined lookups go through query() - the same pattern the attendance helpers
 * already use. Returns [] on failure rather than throwing, so a missing
 * optional table degrades instead of 500ing the endpoint.
 */
function bp_query_rows(string $sql): array
{
    global $pdo;

    try {
        $result = $pdo->query($sql);
    } catch (Throwable $e) {
        error_log('bp_mobile_app leave policy query failed: ' . bp_error_value_to_text($e));
        return [];
    }

    if (!$result || !($result->status ?? false) || !is_array($result->data ?? null)) {
        return [];
    }

    return $result->data;
}

/** True when the optional leave_policy_project_map table exists. */
function bp_leave_policy_project_map_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $available = !empty(bp_table_columns('leave_policy_project_map'));
    return $available;
}

/** staff_test.work_location CSV -> project ids (leave_policy_employee_project_ids). */
function bp_leave_policy_employee_project_ids(string $employeeId): array
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return [];
    }

    $row = bp_fetch_one(
        'staff_test',
        ['work_location'],
        'employee_id = ' . bp_sql_quote($employeeId) . ' AND is_active = 1 AND is_delete = 0 LIMIT 1'
    );

    return bp_parse_csv_values((string)($row['work_location'] ?? ''));
}

/** staff_test.company_name CSV -> company ids (leave_policy_employee_company_ids). */
function bp_leave_policy_employee_company_ids(string $employeeId): array
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return [];
    }

    $columns = bp_table_columns('staff_test');
    $select = array_values(array_filter(
        ['company_name', 'sess_company_id'],
        static fn($c) => isset($columns[$c])
    ));
    if (empty($select)) {
        return [];
    }

    $row = bp_fetch_one(
        'staff_test',
        $select,
        'employee_id = ' . bp_sql_quote($employeeId) . ' AND is_active = 1 AND is_delete = 0 LIMIT 1'
    );
    if (!$row) {
        return [];
    }

    $ids = bp_parse_csv_values((string)($row['company_name'] ?? ''));
    if (empty($ids)) {
        // Web falls back to sess_company_id when company_name is blank.
        $fallback = trim((string)($row['sess_company_id'] ?? ''));
        if ($fallback !== '') {
            $ids[] = $fallback;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * The leave policy that applies to this employee.
 * Project override wins over the company default (resolve_employee_leave_policy).
 * Returns '' when no policy applies.
 */
function bp_resolve_employee_leave_policy(string $employeeId): string
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return '';
    }

    $projectAware = bp_leave_policy_project_map_available();
    $today = bp_sql_quote(date('Y-m-d'));

    // 1. Project override.
    if ($projectAware) {
        $projectIds = bp_leave_policy_employee_project_ids($employeeId);
        if (!empty($projectIds)) {
            $quoted = implode(', ', array_map('bp_sql_quote', $projectIds));
            // Raw query: $pdo->select() takes a single table, so joins go
            // through query() here the same way the attendance helpers do.
            $rows = bp_query_rows(
                'SELECT p.unique_id
                 FROM leave_policy_project_map pm
                 JOIN leave_policy_creation p ON p.unique_id = pm.policy_unique_id
                 WHERE pm.project_id IN (' . $quoted . ')
                   AND pm.is_delete = 0 AND pm.is_active = 1
                   AND p.is_active = 1 AND p.is_delete = 0
                   AND (p.effective_from IS NULL OR p.effective_from <= ' . $today . ')
                   AND (p.effective_to IS NULL OR p.effective_to >= ' . $today . ')
                 ORDER BY (p.effective_from IS NULL), p.effective_from DESC
                 LIMIT 1'
            );
            $policyId = trim((string)($rows[0]['unique_id'] ?? ''));
            if ($policyId !== '') {
                return $policyId;
            }
        }
    }

    // 2. Company default, excluding project-scoped policies.
    $companyIds = bp_leave_policy_employee_company_ids($employeeId);
    if (empty($companyIds)) {
        return '';
    }

    $excludeProjectScoped = $projectAware
        ? ' AND NOT EXISTS (SELECT 1 FROM leave_policy_project_map pm2
                            WHERE pm2.policy_unique_id = p.unique_id
                              AND pm2.is_active = 1 AND pm2.is_delete = 0)'
        : '';

    $quoted = implode(', ', array_map('bp_sql_quote', $companyIds));
    $rows = bp_query_rows(
        'SELECT p.unique_id
         FROM leave_policy_company_map m
         JOIN leave_policy_creation p ON p.unique_id = m.policy_unique_id
         WHERE m.company_id IN (' . $quoted . ')
           AND m.is_delete = 0 AND m.is_active = 1
           AND p.is_active = 1 AND p.is_delete = 0
           AND (p.effective_from IS NULL OR p.effective_from <= ' . $today . ')
           AND (p.effective_to IS NULL OR p.effective_to >= ' . $today . ')'
        . $excludeProjectScoped
        . ' ORDER BY (p.effective_from IS NULL), p.effective_from DESC
         LIMIT 1'
    );

    return trim((string)($rows[0]['unique_id'] ?? ''));
}

/** Flexi leave types, always offered regardless of policy (leave_policy_special_leave_rows). */
function bp_leave_policy_special_leave_rows(): array
{
    return bp_fetch_rows(
        'leave_master_creation',
        ['unique_id', 'leave_type'],
        "is_active = 1 AND is_delete = 0 AND LOWER(leave_type) LIKE '%flexi%' ORDER BY leave_type ASC"
    );
}

/** staff_test.gender -> MALE/FEMALE/'' (leave_gender_norm). */
function bp_leave_gender_norm($value): string
{
    $g = strtolower(trim((string)$value));
    if ($g === '') {
        return '';
    }
    if ($g === '1' || $g[0] === 'm') {
        return 'MALE';
    }
    if ($g === '2' || $g[0] === 'f') {
        return 'FEMALE';
    }
    return '';
}

function bp_leave_employee_gender(string $employeeId): string
{
    $employeeId = trim($employeeId);
    if ($employeeId === '' || !isset(bp_table_columns('staff_test')['gender'])) {
        return '';
    }

    $row = bp_fetch_one(
        'staff_test',
        ['gender'],
        'employee_id = ' . bp_sql_quote($employeeId) . ' AND is_active = 1 AND is_delete = 0 LIMIT 1'
    );

    return bp_leave_gender_norm($row['gender'] ?? '');
}

/**
 * HR-only + gender visibility (leave_master_passes_visibility).
 *
 * NOTE: the web's HR-only gate is operator-based (leave_user_is_hr_or_dev()).
 * The mobile app has no operator concept - an employee always applies for
 * themselves - so an is_hr_only type is hidden unless the applicant is
 * themselves an HR user, which is the safe reading of the same rule.
 */
function bp_leave_master_passes_visibility(array $masterRow, string $employeeId, bool $isHrUser): bool
{
    if ((int)($masterRow['is_hr_only'] ?? 0) === 1 && !$isHrUser) {
        return false;
    }

    $genderApplicable = strtoupper(trim((string)($masterRow['gender_applicable'] ?? 'ALL')));
    if ($genderApplicable === 'MALE' || $genderApplicable === 'FEMALE') {
        $gender = bp_leave_employee_gender($employeeId);
        // Unknown gender must not hide the type (matches the web).
        if ($gender !== '' && $gender !== $genderApplicable) {
            return false;
        }
    }

    return true;
}

function bp_fetch_leave_types(string $employeeId = '', bool $isHrUser = false): array
{
    $masterColumns = bp_table_columns('leave_master_creation');
    $policyAware = isset($masterColumns['policy_unique_id']);

    $selectColumns = array_values(array_filter([
        'unique_id',
        'leave_type',
        'half_day',
        'is_document_required',
        'document_text',
        'is_sandwich_applicable',
        'balance_handling',
        isset($masterColumns['gender_applicable']) ? 'gender_applicable' : null,
        isset($masterColumns['is_hr_only']) ? 'is_hr_only' : null,
        'is_active',
        'is_delete',
    ], static fn($c) => is_string($c) && isset($masterColumns[$c])));

    $employeeId = trim($employeeId);
    $policyId = ($policyAware && $employeeId !== '')
        ? bp_resolve_employee_leave_policy($employeeId)
        : '';

    if ($policyId !== '') {
        // Policy resolved: only that policy's types, plus flexi types.
        $rows = bp_fetch_rows(
            'leave_master_creation',
            $selectColumns,
            'policy_unique_id = ' . bp_sql_quote($policyId)
            . ' AND is_active = 1 AND is_delete = 0 ORDER BY leave_type ASC'
        );

        $flexiIds = [];
        foreach (bp_leave_policy_special_leave_rows() as $flexi) {
            $flexiIds[trim((string)($flexi['unique_id'] ?? ''))] = true;
        }
        unset($flexiIds['']);
        if (!empty($flexiIds)) {
            $quoted = implode(', ', array_map('bp_sql_quote', array_keys($flexiIds)));
            foreach (bp_fetch_rows(
                'leave_master_creation',
                $selectColumns,
                'unique_id IN (' . $quoted . ') AND is_active = 1 AND is_delete = 0'
            ) as $flexiRow) {
                $rows[] = $flexiRow;
            }
        }
    } elseif ($policyAware && $employeeId !== '') {
        // No policy for this employee: the web offers flexi types only.
        $flexiIds = [];
        foreach (bp_leave_policy_special_leave_rows() as $flexi) {
            $flexiIds[trim((string)($flexi['unique_id'] ?? ''))] = true;
        }
        unset($flexiIds['']);
        $rows = [];
        if (!empty($flexiIds)) {
            $quoted = implode(', ', array_map('bp_sql_quote', array_keys($flexiIds)));
            $rows = bp_fetch_rows(
                'leave_master_creation',
                $selectColumns,
                'unique_id IN (' . $quoted . ') AND is_active = 1 AND is_delete = 0'
            );
        }
    } else {
        // Pre-policy schema, or no employee context: previous behaviour.
        $rows = bp_fetch_rows(
            'leave_master_creation',
            $selectColumns,
            ['is_delete' => 0, 'is_active' => 1]
        );
    }

    // HR-only + gender gates, then dedupe by unique_id (leave_policy_dropdown_rows).
    if ($employeeId !== '') {
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row) => bp_leave_master_passes_visibility($row, $employeeId, $isHrUser)
        ));
    }

    $seen = [];
    $deduped = [];
    foreach ($rows as $row) {
        $uid = trim((string)($row['unique_id'] ?? ''));
        if ($uid === '' || isset($seen[$uid])) {
            continue;
        }
        $seen[$uid] = true;
        $deduped[] = $row;
    }
    $rows = $deduped;

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

    // Synthetic types the web prepends for every employee via array_unshift in
    // leave_entry/form.php - they are not leave_master_creation rows, so they
    // have to be added here too.
    $special = [];

    // Short Leave stays hidden until BP_ENABLE_SHORT_LEAVE is switched on
    // (pending business approval). Gating the dropdown entry means an app
    // build that already has the Short Leave UI simply never sees the option,
    // so the backend flag alone controls the rollout.
    if (defined('BP_ENABLE_SHORT_LEAVE') && BP_ENABLE_SHORT_LEAVE) {
        $special[] = [
            'leave_type_id' => 'short_leave',
            'leave_type' => 'Short Leave',
            'half_day' => false,
            'is_document_required' => false,
            'document_text' => '',
            'is_sandwich_applicable' => false,
            'balance_handling' => 'short_leave',
        ];
    }

    $special[] = [
        'leave_type_id' => 'lwp',
        'leave_type' => 'Leave Without Pay',
        'half_day' => true,
        'is_document_required' => false,
        'document_text' => '',
        'is_sandwich_applicable' => false,
        'balance_handling' => 'lwp',
    ];

    $out = array_merge($special, $out);

    return $out;
}

function bp_fetch_leave_type_map(): array
{
    static $map;

    if ($map !== null) {
        return $map;
    }

    // Deliberately unscoped: this is an id -> metadata lookup used to render
    // existing leave records, which may reference a type outside the viewer's
    // current policy (historical rows, or a type since moved to another
    // policy). Filtering here would leave those rows without a name.
    $map = [];
    foreach (bp_fetch_leave_types() as $type) {
        $map[$type['leave_type_id']] = $type;
    }

    return $map;
}

/**
 * Display name for a leave_type_id, given the map from bp_fetch_leave_type_map().
 * Both 'lwp' and 'short_leave' are synthetic ids with no leave_master_creation
 * row (see bp_fetch_leave_types()), so they need this fallback wherever a
 * leave_entry row's type is shown - the map lookup alone leaves them blank and
 * falls through to the raw id string ("short_leave") instead of a readable
 * label. Kept in one place after the same two-branch fallback was duplicated
 * between bp_attach_leave_meta() and leave_update_status.php.
 */
function bp_leave_type_label(string $leaveTypeId, array $typeMap): string
{
    $typeRow = $typeMap[$leaveTypeId] ?? null;
    if (isset($typeRow['leave_type']) && $typeRow['leave_type'] !== '') {
        return (string)$typeRow['leave_type'];
    }

    switch (strtolower($leaveTypeId)) {
        case 'lwp':
            return 'Leave Without Pay';
        case 'short_leave':
            return 'Short Leave';
        default:
            return $leaveTypeId;
    }
}

/**
 * "01 Sep 2026" style date-range summary for a leave_entry-shaped array, used
 * in notification text. Short Leave is always a single day, so "from → to" on
 * the same date is meaningless there - the shift-derived time window is shown
 * instead, matching what the app's approval screen displays.
 */
function bp_leave_period_summary(array $record): string
{
    $leaveTypeId = strtolower(trim((string)($record['leave_type_id'] ?? '')));
    $fromDate = (string)($record['from_date'] ?? '');
    $toDate = (string)($record['to_date'] ?? '');

    if ($leaveTypeId !== 'short_leave') {
        return $fromDate . ' → ' . $toDate;
    }

    $shortType = (int)($record['short_type'] ?? 0);
    $slotLabel = $shortType === 2 ? 'Afternoon' : ($shortType === 1 ? 'Forenoon' : '');
    $fromTime = trim((string)($record['from_time'] ?? ''));
    $toTime = trim((string)($record['to_time'] ?? ''));

    $summary = $fromDate;
    if ($slotLabel !== '') {
        $summary .= ' · ' . $slotLabel;
    }
    if ($fromTime !== '' && $toTime !== '') {
        $summary .= ' (' . substr($fromTime, 0, 5) . ' - ' . substr($toTime, 0, 5) . ')';
    }

    return $summary;
}

function bp_fetch_leave_balances(string $staffName, string $staffUniqueId = '', string $employeeId = ''): array
{
    $staffName = trim($staffName);
    $staffUniqueId = trim($staffUniqueId);
    $employeeId = trim($employeeId);
    if ($staffName === '' && $staffUniqueId === '' && $employeeId === '') {
        return [];
    }

    // Primary source of truth: proxy to the web ERP's own get_balance action.
    // After the leave-policy migration the exact balance computation lives in
    // the web's leave_employee_balance_rows(), which the mobile backend cannot
    // see. Calling it server-to-server reuses the identical policy logic and
    // permissions instead of re-implementing (and drifting from) it.
    if ($employeeId !== '') {
        $proxied = bp_fetch_leave_balances_from_web($employeeId);
        if (!empty($proxied)) {
            usort($proxied, static function (array $a, array $b): int {
                return strcasecmp($a['leave_type'], $b['leave_type']);
            });
            return $proxied;
        }
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

    // Fallback: the leave-policy migration moves balances out of
    // vw_leave_balance into the policy-driven `leave_balance` table keyed by
    // employee_id / staff_unique_id. When the legacy view returns nothing,
    // read entitlements there (balance = pl_balance - pl_taken).
    if (empty($out)) {
        $out = bp_fetch_leave_balances_from_policy_table($staffUniqueId, $employeeId);
    }

    usort($out, static function (array $a, array $b): int {
        return strcasecmp($a['leave_type'], $b['leave_type']);
    });
    return $out;
}

function bp_fetch_leave_balances_from_web(string $employeeId): array
{
    $employeeId = trim($employeeId);
    if ($employeeId === '' || !defined('BP_LEGACY_WEB_BASE_URL')) {
        return [];
    }

    $url = BP_LEGACY_WEB_BASE_URL . '/folders/leave_entry/crud.php';
    $response = bp_http_post_form($url, [
        'action' => 'get_balance',
        'employee_id' => $employeeId,
    ]);

    $json = is_array($response['json'] ?? null) ? $response['json'] : null;
    if ($json === null && !empty($response['body'])) {
        $decoded = json_decode((string)$response['body'], true);
        $json = is_array($decoded) ? $decoded : null;
    }
    if ($json === null || empty($json['status']) || !is_array($json['data'] ?? null)) {
        return [];
    }

    $out = [];
    foreach ($json['data'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        // Map defensively: the web row may label fields differently.
        $leaveType = (string)($row['leave_type'] ?? $row['leave_type_name'] ?? '');
        $leaveTypeId = (string)(
            $row['leave_master_id']
            ?? $row['leave_master_unique_id']
            ?? $row['leave_type_id']
            ?? ''
        );
        $balance = $row['balance'] ?? $row['available'] ?? $row['available_balance'] ?? null;
        $used = $row['used'] ?? $row['used_leave'] ?? $row['pl_taken'] ?? $row['taken'] ?? 0;
        $entitlement = $row['entitlement'] ?? $row['pl_balance'] ?? $row['annual_days'] ?? null;

        if ($balance === null && $entitlement !== null) {
            $balance = (float)$entitlement - (float)$used;
        }

        if ($leaveType === '' && $leaveTypeId === '') {
            continue;
        }

        $out[] = [
            'leave_type' => $leaveType,
            'leave_type_id' => $leaveTypeId,
            'used' => (float)$used,
            'balance' => (float)($balance ?? 0),
        ];
    }

    return $out;
}

function bp_fetch_leave_balances_from_policy_table(
    string $staffUniqueId,
    string $employeeId
): array {
    $staffUniqueId = trim($staffUniqueId);
    $employeeId = trim($employeeId);
    if ($staffUniqueId === '' && $employeeId === '') {
        return [];
    }

    $columns = bp_table_columns('leave_balance');
    if (empty($columns)) {
        return [];
    }

    // Prefer employee_id; fall back to staff_unique_id depending on what the
    // table actually exposes.
    $clauses = [];
    if ($employeeId !== '' && isset($columns['employee_id'])) {
        $clauses[] = 'employee_id = ' . bp_sql_quote($employeeId);
    }
    if ($staffUniqueId !== '' && isset($columns['staff_unique_id'])) {
        $clauses[] = 'staff_unique_id = ' . bp_sql_quote($staffUniqueId);
    }
    if (empty($clauses)) {
        return [];
    }

    $selectColumns = array_values(array_filter(
        [
            'leave_type',
            'leave_master_unique_id',
            'pl_balance',
            'pl_taken',
            'cl_taken',
        ],
        static fn($column) => isset($columns[$column])
    ));
    if (empty($selectColumns)) {
        return [];
    }

    $whereSuffix = isset($columns['is_delete']) ? ' AND is_delete = 0' : '';

    $rows = [];
    foreach ($clauses as $clause) {
        $rows = bp_fetch_rows('leave_balance', $selectColumns, $clause . $whereSuffix);
        if (!empty($rows)) {
            break;
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $entitlement = (float)($row['pl_balance'] ?? 0);
        $used = (float)($row['pl_taken'] ?? 0);
        $out[] = [
            'leave_type' => (string)($row['leave_type'] ?? ''),
            'leave_type_id' => (string)($row['leave_master_unique_id'] ?? ''),
            'used' => $used,
            'balance' => max(0.0, $entitlement - $used),
        ];
    }

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
    $columns = bp_table_columns('leave_entry');
    $select = array_values(array_filter(
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
            // Short Leave only: the from_time/to_time window the approver
            // needs to see, and which Forenoon/Afternoon slot was requested.
            // Selected defensively since an install that predates the Short
            // Leave columns should keep working with these simply absent.
            'from_time',
            'to_time',
            'short_type',
        ],
        static fn(string $column) => empty($columns) || isset($columns[$column])
    ));

    $rows = bp_fetch_rows('leave_entry', $select, $where);

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

        $leaveType = bp_leave_type_label($leaveTypeId, $typeMap);

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
            // Short Leave only. Blank for every other leave type.
            'short_type' => (int)($row['short_type'] ?? 0),
            'from_time' => (string)($row['from_time'] ?? ''),
            'to_time' => (string)($row['to_time'] ?? ''),
        ];
    }

    return $out;
}

function bp_fetch_leave_record(string $leaveUniqueId): ?array
{
    $columns = bp_table_columns('leave_entry');
    $select = array_values(array_filter(
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
            // Short Leave only - see bp_fetch_leave_entries() for why these
            // are selected defensively.
            'from_time',
            'to_time',
            'short_type',
        ],
        static fn(string $column) => empty($columns) || isset($columns[$column])
    ));

    return bp_fetch_one(
        'leave_entry',
        $select,
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
        // Short Leave is a 2-hour slot rather than whole days, so "Total Days:
        // 0.25" reads oddly on its own - add the actual time window alongside
        // it rather than replacing any existing row.
        . (strtolower((string)($leaveRecord['leave_type_id'] ?? '')) === 'short_leave'
            ? '<tr style="background:#f9fafb;"><td style="padding:6px 10px;font-weight:600;">Time Window</td><td style="padding:6px 10px;">'
                . htmlspecialchars(bp_leave_period_summary($leaveRecord), ENT_QUOTES, 'UTF-8') . '</td></tr>'
            : '')
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

/**
 * ─── Web-managed screen permissions ──────────────────────────────────────
 *
 * The web ERP's "User Permission" screen (folders/user_permission) grants
 * modules to a *user_type*, storing one row per
 * (user_type, main_screen, section, screen, action) in user_screen_permission.
 * A module is identified by user_screen.folder_name, matching the on-disk
 * folders/<name>/ directory.
 *
 * These helpers let the mobile backend read that same grant so module access is
 * configured in one place - the web screen - and takes effect in the app with
 * no redeploy. They are strictly read-only; nothing here writes to the ERP.
 *
 * Fail CLOSED by design: an unregistered screen, a staff member with no user
 * row, or zero permission rows all resolve to "no access". The web's own
 * user_can_action() fails OPEN (no rows configured = allow everything), but
 * copying that here would turn any lookup failure into a silent grant to every
 * user in the system.
 */

/**
 * user_screen.unique_id for a module, resolved by exact folder_name.
 *
 * No screen_name LIKE fallback on purpose: a fuzzy match on an access gate can
 * resolve to the wrong screen, and the wrong screen means granting or denying
 * the module to everyone. Returns '' when the module is not registered.
 */
function bp_screen_unique_id_for_folder(string $folderName): string
{
    static $cache = [];

    $folderName = trim($folderName);
    if ($folderName === '') {
        return '';
    }
    if (array_key_exists($folderName, $cache)) {
        return $cache[$folderName];
    }

    $columns = bp_table_columns('user_screen');
    if (empty($columns) || !isset($columns['unique_id']) || !isset($columns['folder_name'])) {
        $cache[$folderName] = '';
        return $cache[$folderName];
    }

    $where = 'folder_name = ' . bp_sql_quote($folderName);
    if (isset($columns['is_active'])) {
        $where .= ' AND is_active = 1';
    }
    if (isset($columns['is_delete'])) {
        $where .= ' AND is_delete = 0';
    }

    $row = bp_fetch_one('user_screen', ['unique_id'], $where . ' LIMIT 1');
    $cache[$folderName] = trim((string)($row['unique_id'] ?? ''));
    return $cache[$folderName];
}

/**
 * The user row's user_type id for a staff_test row.
 *
 * The ERP login and user maintenance screens store the role in
 * `user_type_unique_id`; `user_type` is accepted only as an older/alternate
 * fallback. staff_unique_id holds the employee id on this install, but
 * staff_test.unique_id is matched too because other call sites treat the two
 * interchangeably.
 */
function bp_user_type_for_staff(array $staff): string
{
    $columns = bp_table_columns('user');
    if (empty($columns) || !isset($columns['staff_unique_id'])) {
        return '';
    }

    $typeColumn = isset($columns['user_type_unique_id'])
        ? 'user_type_unique_id'
        : (isset($columns['user_type']) ? 'user_type' : '');
    if ($typeColumn === '') {
        return '';
    }

    $candidates = [];
    foreach ([(string)($staff['unique_id'] ?? ''), (string)($staff['employee_id'] ?? '')] as $value) {
        $value = trim($value);
        if ($value !== '') {
            $candidates[$value] = true;
        }
    }
    if (empty($candidates)) {
        return '';
    }

    $quoted = array_map('bp_sql_quote', array_keys($candidates));
    $where = 'staff_unique_id IN (' . implode(', ', $quoted) . ')';
    if (isset($columns['is_active'])) {
        $where .= ' AND is_active = 1';
    }
    if (isset($columns['is_delete'])) {
        $where .= ' AND is_delete = 0';
    }
    // Newest row wins when a staff member has more than one user record.
    if (isset($columns['s_no'])) {
        $where .= ' ORDER BY s_no DESC';
    }

    $row = bp_fetch_one('user', [$typeColumn], $where . ' LIMIT 1');
    return trim((string)($row[$typeColumn] ?? ''));
}

/**
 * Whether this staff member's user_type has been granted the given module on
 * the web User Permission screen. Fails closed - see the section note above.
 */
function bp_staff_has_screen_permission(array $staff, string $folderName): bool
{
    $screenId = bp_screen_unique_id_for_folder($folderName);
    if ($screenId === '') {
        return false;
    }

    $userType = bp_user_type_for_staff($staff);
    if ($userType === '') {
        return false;
    }

    $columns = bp_table_columns('user_screen_permission');
    if (empty($columns) || !isset($columns['user_type']) || !isset($columns['screen_unique_id'])) {
        return false;
    }

    $where = 'user_type = ' . bp_sql_quote($userType)
        . ' AND screen_unique_id = ' . bp_sql_quote($screenId);
    if (isset($columns['is_active'])) {
        $where .= ' AND is_active = 1';
    }
    if (isset($columns['is_delete'])) {
        $where .= ' AND is_delete = 0';
    }

    $rows = bp_fetch_rows('user_screen_permission', ['unique_id'], $where . ' LIMIT 1');
    return !empty($rows);
}

/**
 * Whether this staff member's user_type has been granted ANY of the given
 * modules. The same logical screen can be registered under more than one
 * folder in the ERP (the attendance report exists as monthly_attendance_report,
 * monthly_attendance_report_new and several executive_* variants), so callers
 * pass every folder that should unlock the mobile equivalent.
 */
function bp_staff_has_any_screen_permission(array $staff, array $folderNames): bool
{
    foreach ($folderNames as $folderName) {
        if (bp_staff_has_screen_permission($staff, (string)$folderName)) {
            return true;
        }
    }

    return false;
}

/**
 * ─── Short Leave ─────────────────────────────────────────────────────────
 *
 * Ports the short-leave branch of the web's leave_entry/crud.php so a mobile
 * submission produces an identical leave_entry row. Behind BP_ENABLE_SHORT_LEAVE
 * until the feature is approved.
 */

/** Web allows one short leave per calendar month. */
const BP_SHORT_LEAVE_PER_MONTH = 1;

/**
 * Shift-derived time window for a short leave.
 * Forenoon (1) = first 2 hours of the shift, Afternoon (2) = last 2 hours,
 * matching the web. Returns ['error' => '...'] when the employee has no shift
 * rostered for the date, which the web also treats as a hard failure.
 */
function bp_short_leave_shift_times(array $staff, string $date, int $shortType): array
{
    $staffUniqueId = trim((string)($staff['unique_id'] ?? ''));
    if ($staffUniqueId === '') {
        return ['error' => 'Staff not found'];
    }

    // shift_roster_details keys on staff_test.unique_id, not employee_id. The
    // table is spelled both "roster" and "roaster" across environments, so try
    // both the same way bp_fetch_explicit_weekoff_map does.
    $roster = [];
    foreach (['shift_roster_details', 'shift_roaster_details'] as $tableName) {
        $rosterColumns = bp_table_columns($tableName);
        if (empty($rosterColumns)) {
            continue;
        }

        // shift_unique_id was added later; older schemas only have shift_name.
        $select = ['shift_name'];
        if (!empty($rosterColumns['shift_unique_id'])) {
            $select[] = 'shift_unique_id';
        }

        $roster = bp_fetch_one(
            $tableName,
            $select,
            'employee_id = ' . bp_sql_quote($staffUniqueId)
            . ' AND shift_date = ' . bp_sql_quote($date)
            . ' AND is_delete = 0 LIMIT 1'
        ) ?: [];

        if (!empty($roster)) {
            break;
        }
    }

    $shiftName = trim((string)($roster['shift_name'] ?? ''));
    $shiftUniqueId = trim((string)($roster['shift_unique_id'] ?? ''));
    if ($shiftName === '' && $shiftUniqueId === '') {
        return ['error' => 'No shift assigned for the selected date'];
    }

    // A rostered week off has no working window, so a short leave cannot apply.
    // shift_roaster stores these as shift_unique_id 'wo' / shift_name 'Week Off'.
    if (strtolower($shiftUniqueId) === 'wo'
        || strpos(str_replace(' ', '', strtolower($shiftName)), 'weekoff') !== false) {
        return ['error' => 'Selected date is a week off'];
    }

    // Match the web's get_short_leave_time: resolve the shift by its
    // unique_id, which is what shift_roaster stores at assignment time.
    // shift_name is only a display copy and can drift from shift_creation, so
    // it is a fallback for older roster rows that predate shift_unique_id.
    $shift = [];
    if ($shiftUniqueId !== '') {
        $shift = bp_fetch_one(
            'shift_creation',
            ['start_time', 'end_time'],
            'unique_id = ' . bp_sql_quote($shiftUniqueId)
            . ' AND is_delete = 0 AND is_active = 1 LIMIT 1'
        ) ?: [];
    }

    if (empty($shift) && $shiftName !== '') {
        $shift = bp_fetch_one(
            'shift_creation',
            ['start_time', 'end_time'],
            'shift_name = ' . bp_sql_quote($shiftName)
            . ' AND is_delete = 0 AND is_active = 1 LIMIT 1'
        ) ?: [];
    }

    $start = trim((string)($shift['start_time'] ?? ''));
    $end = trim((string)($shift['end_time'] ?? ''));
    if ($start === '' || $end === '') {
        return ['error' => 'Shift timing not configured'];
    }

    if ($shortType === 1) {
        $fromTime = date('H:i:s', strtotime($start));
        $toTime = date('H:i:s', strtotime('+2 hours', strtotime($start)));
    } else {
        $toTime = date('H:i:s', strtotime($end));
        $fromTime = date('H:i:s', strtotime('-2 hours', strtotime($end)));
    }

    return ['from_time' => $fromTime, 'to_time' => $toTime, 'error' => ''];
}

/** Short leaves already applied in the same calendar month (rejected ones excluded). */
function bp_short_leave_month_count(string $employeeId, string $date): int
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return 0;
    }

    $monthStart = date('Y-m-01', strtotime($date));
    $monthEnd = date('Y-m-t', strtotime($date));

    $rows = bp_fetch_rows(
        'leave_entry',
        ['COUNT(*) AS cnt'],
        'employee_id = ' . bp_sql_quote($employeeId)
        . " AND leave_type_id = 'short_leave'"
        . ' AND is_delete = 0'
        . ' AND status != 2'
        . ' AND from_date >= ' . bp_sql_quote($monthStart)
        . ' AND from_date <= ' . bp_sql_quote($monthEnd)
    );

    return (int)($rows[0]['cnt'] ?? 0);
}

/** Insert the short-leave row with the same columns the web writes. */
function bp_short_leave_insert(
    array $staff,
    string $employeeId,
    string $date,
    int $shortType,
    string $fromTime,
    string $toTime,
    string $reason
): array {
    $now = bp_now();
    $uniqueId = bp_unique_id();

    $columns = bp_filter_table_columns('leave_entry', [
        'unique_id' => $uniqueId,
        'employee_id' => $employeeId,
        'leave_type_id' => 'short_leave',
        'from_date' => $date,
        'to_date' => $date,
        'short_type' => $shortType,
        'from_time' => $fromTime,
        'to_time' => $toTime,
        'total_days' => 0.25,
        'half_day' => 0,
        'status' => 0,
        'reason' => $reason,
        'created' => $now,
        'updated' => $now,
        'created_user_id' => $employeeId,
        'updated_user_id' => $employeeId,
        'is_active' => 1,
        'is_delete' => 0,
    ]);

    $result = bp_insert_row_raw('leave_entry', $columns);
    if (!$result || !($result->status ?? false)) {
        return [
            'status' => false,
            'message' => 'Failed to submit Short Leave',
            'error' => bp_error_value_to_text($result->error ?? ''),
        ];
    }

    return [
        'status' => true,
        'message' => 'Short Leave submitted for approval',
        'data' => [
            'unique_id' => $uniqueId,
            'leave_type_id' => 'short_leave',
            'leave_type' => 'Short Leave',
            'from_date' => $date,
            'to_date' => $date,
            'short_type' => $shortType,
            'from_time' => $fromTime,
            'to_time' => $toTime,
            'total_days' => 0.25,
            'status' => 0,
            'status_label' => 'Pending',
        ],
    ];
}
