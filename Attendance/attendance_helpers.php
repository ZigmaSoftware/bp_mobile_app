<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Leave/leave_helpers.php';

function bp_att_require_legacy_attendance_helpers(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $rootName = defined('BP_BLUE_PLANET_ROOT_NAME') ? BP_BLUE_PLANET_ROOT_NAME : 'blue_planet_erp';
    require_once bp_require_file(array_values(array_filter([
        defined('BP_BLUE_PLANET_ROOT') ? rtrim(BP_BLUE_PLANET_ROOT, DIRECTORY_SEPARATOR) . '/config/comfun.php' : '',
        __DIR__ . '/../../' . $rootName . '/config/comfun.php',
        dirname(__DIR__, 3) . '/' . $rootName . '/config/comfun.php',
        dirname(__DIR__, 4) . '/public_html/' . $rootName . '/config/comfun.php',
    ])), 'comfun.php');

    $loaded = true;
}

bp_att_require_legacy_attendance_helpers();

function bp_att_query_rows($result): array
{
    if ($result instanceof PDOStatement) {
        try {
            $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    if (!is_object($result)) {
        return [];
    }

    $status = (bool)($result->status ?? false);
    $rows = $result->data ?? null;
    if (!$status || !is_array($rows)) {
        return [];
    }

    return $rows;
}

function bp_att_query_row($result): ?array
{
    $rows = bp_att_query_rows($result);
    $row = $rows[0] ?? null;
    return is_array($row) ? $row : null;
}

function bp_att_safe_text($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_string($value)) {
        return trim($value);
    }

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    if (is_object($value) && method_exists($value, '__toString')) {
        return trim((string)$value);
    }

    return '';
}

function bp_att_error_text($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_string($value)) {
        return trim($value);
    }

    if ($value instanceof Throwable) {
        $message = trim($value->getMessage());
        return $message !== '' ? $message : get_class($value);
    }

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    if (is_object($value)) {
        if (method_exists($value, '__toString')) {
            return trim((string)$value);
        }

        return trim(get_class($value));
    }

    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? 'array' : $json;
    }

    return '';
}

function bp_att_role_indicates_hr(array $tokens): bool
{
    $normalized = [];
    foreach ($tokens as $token) {
        $text = strtolower(bp_att_safe_text($token));
        if ($text !== '') {
            $normalized[] = $text;
        }
    }

    if (empty($normalized)) {
        return false;
    }

    $roleText = implode(' ', $normalized);
    return $roleText === 'admin'
        || (bool)preg_match('/\b(hr|human resource|human resources)\b/i', $roleText);
}

function bp_att_scope_where_clause(array $scope, string $employeeColumn, string $projectColumn, string $departmentColumn): string
{
    if (bp_att_scope_is_all($scope)) {
        return '';
    }

    if (function_exists('attendance_build_where_clause')) {
        return (string)attendance_build_where_clause($scope, $employeeColumn, $projectColumn, $departmentColumn);
    }

    $parts = [];

    $employeeIds = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        (array)($scope['employee_ids'] ?? [])
    ))));
    if (!empty($employeeIds) && !in_array('all', $employeeIds, true)) {
        $quoted = array_map('bp_sql_quote', $employeeIds);
        $parts[] = $employeeColumn . ' IN (' . implode(', ', $quoted) . ')';
    }

    $projectIds = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        (array)($scope['project_ids'] ?? [])
    ))));
    if (!empty($projectIds) && !in_array('all', $projectIds, true)) {
        $quoted = array_map('bp_sql_quote', $projectIds);
        $parts[] = $projectColumn . ' IN (' . implode(', ', $quoted) . ')';
    }

    $departmentFilter = trim((string)($scope['department_filter'] ?? ''));
    if ($departmentFilter !== '' && strtolower($departmentFilter) !== 'all') {
        $parts[] = $departmentColumn . ' = ' . bp_sql_quote($departmentFilter);
    }

    return empty($parts) ? '' : (' AND ' . implode(' AND ', $parts));
}

function bp_att_scope_allows_employee_local(array $scope, string $employeeId): bool
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return false;
    }

    if (bp_att_scope_is_all($scope)) {
        return true;
    }

    if (function_exists('attendance_scope_allows_employee')) {
        return (bool)attendance_scope_allows_employee($scope, $employeeId);
    }

    $employeeIds = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        (array)($scope['employee_ids'] ?? [])
    ))));

    if (empty($employeeIds) || in_array('all', $employeeIds, true)) {
        return true;
    }

    return in_array($employeeId, $employeeIds, true);
}

/**
 * ─── Visibility-scope engine ─────────────────────────────────────────────
 *
 * Ports the web ERP's build_employee_condition() (blue_planet_erp
 * folders/monthly_attendance_report_new/function.php) into the session-less
 * mobile backend. The web scope engine functions (attendance_resolve_scope /
 * attendance_is_hr_admin) were never implemented in comfun.php, so every
 * non-HR user previously collapsed to a "self" scope and was denied the
 * report. These helpers reproduce the web team's authoritative rules:
 *   full access → user_type UUID (admin/hr) + Developer / Kapil Mehra
 *   department head → dept employees + recursive reportees
 *   reporting officer → recursive reportee tree
 *   plant in-charge → employees at the viewer's work_location(s)
 */

/**
 * Trim + de-duplicate ids, preserving case so they match
 * staff_test.employee_id / vw_attendance_with_shift.employee_id exactly
 * (mirrors the web report's mar_normalize_id_list()).
 */
function bp_att_scope_id_list($values): array
{
    $values = is_array($values) ? $values : [$values];
    $normalized = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        $normalized[$value] = true;
    }

    return array_keys($normalized);
}

function bp_att_scope_in_list(array $values): string
{
    $values = bp_att_scope_id_list($values);
    if (empty($values)) {
        return "('')";
    }

    return '(' . implode(', ', array_map('bp_sql_quote', $values)) . ')';
}

/**
 * Full-access check, identical to the web report's build_employee_condition():
 * authoritative user_type UUIDs (admin/hr) plus the Developer / Kapil Mehra
 * special cases. The UUIDs come from the ERP comfun.php globals when loaded,
 * falling back to the same hard-coded values the web uses.
 */
function bp_att_is_full_access_user(
    string $userTypeId,
    string $designationName,
    string $userName,
    string $staffName,
    string $employeeId
): bool {
    global $admin_user_type, $hr_user_type;

    $adminType = (isset($admin_user_type) && is_string($admin_user_type) && $admin_user_type !== '')
        ? $admin_user_type
        : '5f97fc3257f2525529';
    $hrType = (isset($hr_user_type) && is_string($hr_user_type) && $hr_user_type !== '')
        ? $hr_user_type
        : '5ff71f5fb5ca556748';

    $userTypeId = trim($userTypeId);
    if ($userTypeId !== '' && ($userTypeId === $adminType || $userTypeId === $hrType)) {
        return true;
    }

    if (strcasecmp(trim($designationName), 'Developer') === 0) {
        return true;
    }
    if (strcasecmp(trim($userName), 'Developer') === 0) {
        return true;
    }
    if (strcasecmp(trim($staffName), 'Kapil Mehra') === 0) {
        return true;
    }

    return false;
}

/**
 * Full downward reporting tree (reportees of reportees), walking
 * staff_test.reporting_officer breadth-first. Mirrors the web report's
 * mar_get_recursive_reportee_ids(); the visited-set guarantees termination
 * even if the reporting chain contains a cycle.
 */
function bp_att_recursive_reportee_ids(array $seedIds): array
{
    $queue = bp_att_scope_id_list($seedIds);
    $visited = [];

    while (!empty($queue)) {
        $chunk = array_slice($queue, 0, 50);
        $queue = array_slice($queue, 50);

        $rows = bp_fetch_rows(
            'staff_test',
            ['employee_id'],
            'is_active = 1 AND is_delete = 0 AND reporting_officer IN ' . bp_att_scope_in_list($chunk)
        );

        foreach ($rows as $row) {
            $empId = trim((string)($row['employee_id'] ?? ''));
            if ($empId === '' || isset($visited[$empId])) {
                continue;
            }
            $visited[$empId] = true;
            $queue[] = $empId;
        }
    }

    return array_keys($visited);
}

/** department_creation unique_ids headed by $empId (mar_get_department_ids_headed_by_emp). */
function bp_att_department_ids_headed_by(string $empId): array
{
    $empId = trim($empId);
    if ($empId === '') {
        return [];
    }

    $rows = bp_fetch_rows(
        'department_creation',
        ['unique_id'],
        'is_active = 1 AND is_delete = 0 AND department_head = ' . bp_sql_quote($empId)
    );

    $ids = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['unique_id'] ?? ''));
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return bp_att_scope_id_list($ids);
}

/** Employee ids in the given departments (mar_get_employees_in_departments). */
function bp_att_employees_in_departments(array $deptIds): array
{
    $deptIds = bp_att_scope_id_list($deptIds);
    if (empty($deptIds)) {
        return [];
    }

    $rows = bp_fetch_rows(
        'staff_test',
        ['employee_id'],
        'is_active = 1 AND is_delete = 0 AND department IN ' . bp_att_scope_in_list($deptIds)
    );

    $ids = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['employee_id'] ?? ''));
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return bp_att_scope_id_list($ids);
}

/** Department-head scope: dept employees + recursive reportees (mar_get_department_head_scope_ids). */
function bp_att_department_head_scope_ids(string $empId): array
{
    $empId = trim($empId);
    if ($empId === '') {
        return [];
    }

    $deptIds = bp_att_department_ids_headed_by($empId);
    if (empty($deptIds)) {
        return [$empId];
    }

    $employeeIds = bp_att_employees_in_departments($deptIds);
    $employeeIds = array_merge([$empId], $employeeIds, bp_att_recursive_reportee_ids($employeeIds));

    return bp_att_scope_id_list($employeeIds);
}

/** Employees at the viewer's work_location(s) (mar_get_plant_scope_ids). */
function bp_att_plant_scope_ids(string $workLocationCsv): array
{
    $workLocationCsv = trim($workLocationCsv);
    if ($workLocationCsv === '' || strtolower($workLocationCsv) === 'all') {
        return [];
    }

    $locations = bp_att_scope_id_list(explode(',', $workLocationCsv));
    if (empty($locations)) {
        return [];
    }

    $rows = bp_fetch_rows(
        'staff_test',
        ['employee_id'],
        'is_active = 1 AND is_delete = 0 AND work_location IN ' . bp_att_scope_in_list($locations)
    );

    $ids = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['employee_id'] ?? ''));
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return bp_att_scope_id_list($ids);
}

/**
 * Resolve a non-HR user's visibility scope, mirroring build_employee_condition().
 * Returns the mobile scope shape ('scope_type' + 'employee_ids'); a user who can
 * only see themselves stays 'self' (the report endpoint intentionally 403s that).
 */
function bp_att_resolve_scope_native(string $employeeId, string $workLocationCsv, array $roleTokens): array
{
    $employeeId = trim($employeeId);
    $selfScope = [
        'scope_type' => 'self',
        'employee_ids' => $employeeId !== '' ? [$employeeId] : [],
        'project_ids' => [],
        'department_filter' => null,
        'self_employee_id' => $employeeId,
    ];
    if ($employeeId === '') {
        return $selfScope;
    }

    // Ops-exec special case (build_employee_condition uses XWM107 -> BPIW0015).
    if (strcasecmp($employeeId, 'XWM107') === 0) {
        $opsIds = bp_att_scope_id_list(bp_att_department_head_scope_ids('BPIW0015'));
        if (count($opsIds) > 1) {
            return [
                'scope_type' => 'department',
                'employee_ids' => $opsIds,
                'project_ids' => [],
                'department_filter' => null,
                'self_employee_id' => $employeeId,
            ];
        }
    }

    $allowed = [$employeeId];
    $scopeType = 'self';

    // Department head: whole department + recursive reportees.
    if (!empty(bp_att_department_ids_headed_by($employeeId))) {
        $allowed = array_merge($allowed, bp_att_department_head_scope_ids($employeeId));
        $scopeType = 'department';
    }

    // Reporting officer: full recursive reportee tree.
    if (bp_is_reporting_officer($employeeId)) {
        $allowed = array_merge($allowed, bp_att_recursive_reportee_ids([$employeeId]));
        if ($scopeType === 'self') {
            $scopeType = 'team';
        }
    }

    // Plant in-charge: everyone at the viewer's work_location(s).
    $roleText = strtolower(trim(implode(' ', array_map('strval', $roleTokens))));
    if (preg_match('/plant in[- ]?charge/i', $roleText) === 1) {
        $plantIds = bp_att_plant_scope_ids($workLocationCsv);
        if (!empty($plantIds)) {
            $allowed = array_merge($allowed, $plantIds);
            if ($scopeType === 'self') {
                $scopeType = 'plant';
            }
        }
    }

    $allowed = bp_att_scope_id_list($allowed);
    if (count($allowed) <= 1) {
        return $selfScope;
    }

    return [
        'scope_type' => $scopeType,
        'employee_ids' => $allowed,
        'project_ids' => [],
        'department_filter' => null,
        'self_employee_id' => $employeeId,
    ];
}

/**
 * ─── Attendance-report web parity ────────────────────────────────────────
 *
 * The monthly_attendance_report_new web screen classifies each row with four
 * EXACT status lists (crud.php) + a dynamic leave_master_creation lookup, and
 * applies a BP head-office / non-head-office work_location partition
 * (build_project_scope_sql) plus an optional project (work_location) filter on
 * every query. These helpers reproduce that exactly so the mobile report
 * returns the same data as the web for the same user + filters.
 */

function bp_att_report_present_statuses(): array
{
    // crud.php $PRESENT_STATUSES
    return ['present', 'late in', 'early exit', 'half day'];
}

function bp_att_report_absent_statuses(): array
{
    // crud.php absent_condition (note: 'Missed In' is NOT absent on the web)
    return ['absent', 'missed out', 'absent - no shift assigned'];
}

function bp_att_report_weekoff_statuses(): array
{
    // crud.php $WEEKOFF_HOLIDAY_STATUSES
    return ['week off', 'holiday'];
}

/**
 * Classify a status into exactly one report bucket, or null when it belongs to
 * none (Permission, Missed In, Short Hours, blank …). Mirrors the web's four
 * independent status queries — anything unmatched is not counted anywhere.
 */
function bp_att_report_exact_bucket(string $status): ?string
{
    $normalized = bp_normalize_attendance_status($status);
    if ($normalized === '') {
        return null;
    }

    if (in_array($normalized, bp_att_report_present_statuses(), true)) {
        return 'present';
    }
    if (in_array($normalized, bp_att_report_absent_statuses(), true)) {
        return 'absent';
    }
    if (in_array($normalized, bp_att_report_weekoff_statuses(), true)) {
        return 'weekoff_holiday';
    }

    // Leave = membership in leave_master_creation.leave_type (dynamic).
    $leaveStatuses = bp_dynamic_leave_statuses();
    if (isset($leaveStatuses[$normalized])) {
        return 'leave';
    }

    return null;
}

/** Viewer work_location list (CSV), mirroring current_user_work_locations(). */
function bp_att_viewer_work_locations(array $context): array
{
    $work = trim((string)(
        ($context['session']['work_location'] ?? '') !== ''
            ? $context['session']['work_location']
            : ($context['user']['work_location'] ?? '')
    ));
    if ($work === '' || strtolower($work) === 'all') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $work)), static fn($v) => $v !== ''));
}

/**
 * BP head-office / non-head-office partition on the work_location column,
 * mirroring build_project_scope_sql(). Kapil (BPIN0082) is not partitioned;
 * a head-office user sees only HO staff; everyone else sees only non-HO staff.
 */
function bp_att_ho_partition_clause(array $context, string $workLocationCol): string
{
    $workLocationCol = trim($workLocationCol);
    if ($workLocationCol === '') {
        return '';
    }

    $employeeId = trim((string)($context['employee_id'] ?? ''));
    if (strcasecmp($employeeId, 'BPIN0082') === 0) {
        return '';
    }

    $hoId = bp_att_head_office_project_id();
    $quotedHo = bp_sql_quote($hoId);
    $isHoUser = in_array($hoId, bp_att_viewer_work_locations($context), true);

    if ($isHoUser) {
        return ' AND (' . $workLocationCol . ' = ' . $quotedHo
            . ' OR FIND_IN_SET(' . $quotedHo . ', ' . $workLocationCol . ') > 0)';
    }

    return ' AND (' . $workLocationCol . ' != ' . $quotedHo
        . ' AND NOT FIND_IN_SET(' . $quotedHo . ', ' . $workLocationCol . ') > 0)';
}

/** Optional project (work_location) filter from the dropdown; '' / 'all' = no filter. */
function bp_att_report_project_clause(string $projectCsv, string $workLocationCol): string
{
    $workLocationCol = trim($workLocationCol);
    $projectCsv = trim($projectCsv);
    if ($workLocationCol === '' || $projectCsv === '' || strtolower($projectCsv) === 'all') {
        return '';
    }

    $ids = bp_att_scope_id_list(explode(',', $projectCsv));
    if (empty($ids)) {
        return '';
    }

    return ' AND ' . $workLocationCol . ' IN ' . bp_att_scope_in_list($ids);
}

/**
 * Per-user scoped project list for the report dropdown, mirroring
 * get_project_name(): Kapil → all; HO user → only the HO project; everyone
 * else → non-HO projects limited to their session work_location list.
 */
function bp_att_scoped_project_list(array $context): array
{
    $projectColumns = bp_att_table_columns('project_creation');
    if (empty($projectColumns) || !isset($projectColumns['unique_id'])) {
        return ['all_allowed' => false, 'projects' => []];
    }

    $select = array_values(array_filter(
        ['unique_id', 'project_name', 'project_code'],
        static fn($c) => isset($projectColumns[$c])
    ));

    try {
        $rows = bp_fetch_rows('project_creation', $select, 'is_active = 1 AND is_delete = 0');
    } catch (Throwable $e) {
        error_log('bp_mobile_app scoped project list failed: ' . bp_att_error_text($e));
        return ['all_allowed' => false, 'projects' => []];
    }

    $employeeId = trim((string)($context['employee_id'] ?? ''));
    $isKapil = strcasecmp($employeeId, 'BPIN0082') === 0;
    $hoId = bp_att_head_office_project_id();

    $workRaw = trim((string)(
        ($context['session']['work_location'] ?? '') !== ''
            ? $context['session']['work_location']
            : ($context['user']['work_location'] ?? '')
    ));
    $isAll = strtolower($workRaw) === 'all';
    $locations = bp_att_viewer_work_locations($context);
    $isHoUser = in_array($hoId, $locations, true);

    $projects = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['unique_id'] ?? ''));
        if ($id === '') {
            continue;
        }

        if ($isKapil) {
            // sees everything
        } elseif ($isHoUser) {
            if ($id !== $hoId) {
                continue;
            }
        } else {
            if ($id === $hoId) {
                continue;
            }
            if (!$isAll && !empty($locations) && !in_array($id, $locations, true)) {
                continue;
            }
        }

        $name = trim((string)($row['project_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($row['project_code'] ?? ''));
        }
        $projects[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : $id,
            'code' => trim((string)($row['project_code'] ?? '')),
        ];
    }

    usort($projects, static fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));

    // list.php offers "All Project" only when the session work_location is 'all'.
    return ['all_allowed' => $isKapil || $isAll, 'projects' => $projects];
}

function bp_att_staff_join_meta(): array
{
    $columns = bp_att_table_columns('staff_test');

    $selectParts = [];
    foreach ([
        'staff_name',
        'reporting_officer',
        'designation_unique_id',
        'department',
        'work_location',
    ] as $column) {
        if (isset($columns[$column])) {
            $selectParts[] = 's.' . $column;
        }
    }

    $projectColumn = isset($columns['work_location']) ? 's.work_location' : "''";
    $departmentColumn = isset($columns['department']) ? 's.department' : "''";

    return [
        'columns' => $columns,
        'select_sql' => empty($selectParts) ? '' : (', ' . implode(', ', $selectParts)),
        'project_column' => $projectColumn,
        'department_column' => $departmentColumn,
    ];
}

function bp_att_table_columns(string $table): array
{
    global $pdo;

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }

    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $result = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }

    $rows = bp_att_query_rows($result);
    if (empty($rows)) {
        $cache[$table] = [];
        return [];
    }

    $columns = [];
    foreach ($rows as $row) {
        $field = trim((string)($row['Field'] ?? ''));
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    $cache[$table] = $columns;
    return $columns;
}

function bp_att_filter_columns(string $table, array $columns): array
{
    $tableColumns = bp_att_table_columns($table);
    if (empty($tableColumns)) {
        return [];
    }

    $filtered = [];
    foreach ($columns as $key => $value) {
        $column = trim((string)$key);
        if ($column !== '' && isset($tableColumns[$column])) {
            $filtered[$column] = $value;
        }
    }

    return $filtered;
}

function bp_att_insert_row_raw(string $table, array $columns): object
{
    global $pdo;

    $columns = bp_att_filter_columns($table, $columns);
    if (empty($columns)) {
        return (object)[
            'status' => false,
            'error' => 'No matching columns found for insert',
            'data' => [],
        ];
    }

    $fields = [];
    $placeholders = [];
    $params = [];
    foreach ($columns as $field => $value) {
        $fields[] = '`' . $field . '`';
        $placeholders[] = ':' . $field;
        $params[$field] = $value;
    }

    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';

    try {
        $result = $pdo->query($sql, $params);
        if (is_object($result) && property_exists($result, 'status')) {
            return $result;
        }

        return (object)[
            'status' => true,
            'error' => '',
            'data' => $result,
        ];
    } catch (Throwable $e) {
        return (object)[
            'status' => false,
            'error' => $e->getMessage(),
            'data' => [],
        ];
    }
}

function bp_att_status_label(int $status): string
{
    if ($status === 1) {
        return 'Approved';
    }
    if ($status === 2) {
        return 'Rejected';
    }
    if ($status === 3) {
        return 'Deleted';
    }
    return 'Pending';
}

function bp_att_normalize_date_range(array $input): array
{
    $from = bp_date_ymd(bp_str($input, 'from_date'));
    $to = bp_date_ymd(bp_str($input, 'to_date'));

    if ($from !== null || $to !== null) {
        return [$from, $to];
    }

    $month = (int)bp_str($input, 'month');
    $year = (int)bp_str($input, 'year');
    if ($month >= 1 && $month <= 12 && $year >= 2000) {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));
        return [$from, $to];
    }

    return [null, null];
}

function bp_att_fetch_staff_full(string $staffIdentifier): ?array
{
    $staffIdentifier = trim($staffIdentifier);
    if ($staffIdentifier === '') {
        return null;
    }

    $staffColumns = bp_att_table_columns('staff_test');
    $selectColumns = array_values(array_filter([
        'unique_id',
        'employee_id',
        'staff_name',
        'office_email_id',
        'reporting_officer',
        'designation_unique_id',
        'department',
        'work_location',
    ], static fn($column) => isset($staffColumns[$column])));

    if (empty($selectColumns)) {
        return null;
    }

    $candidates = [$staffIdentifier];
    $staff = bp_fetch_staff($staffIdentifier);
    if ($staff) {
        $candidates[] = trim((string)($staff['employee_id'] ?? ''));
        $candidates[] = trim((string)($staff['unique_id'] ?? ''));
    }

    $normalizedCandidates = [];
    foreach ($candidates as $candidate) {
        $candidate = strtoupper(trim((string)$candidate));
        if ($candidate !== '') {
            $normalizedCandidates[$candidate] = true;
        }
    }

    if (empty($normalizedCandidates)) {
        return null;
    }

    $sqlParts = [];
    foreach (array_keys($normalizedCandidates) as $candidate) {
        $quoted = bp_sql_quote((string)$candidate);
        if (isset($staffColumns['employee_id'])) {
            $sqlParts[] = 'UPPER(TRIM(employee_id)) = ' . $quoted;
        }
        if (isset($staffColumns['unique_id'])) {
            $sqlParts[] = 'UPPER(TRIM(unique_id)) = ' . $quoted;
        }
    }

    if (empty($sqlParts)) {
        return null;
    }

    return bp_fetch_one(
        'staff_test',
        $selectColumns,
        'is_active = 1 AND is_delete = 0 AND (' . implode(' OR ', $sqlParts) . ')'
    );
}

function bp_att_fetch_user_row(array $candidates): ?array
{
    global $pdo;

    $userColumns = bp_att_table_columns('user');
    $selectColumns = array_values(array_filter([
        'unique_id',
        'staff_unique_id',
        'user_type',
        'work_location',
        'user_name',
    ], static fn($column) => isset($userColumns[$column])));

    if (empty($selectColumns)) {
        return null;
    }

    $values = [];
    foreach ($candidates as $candidate) {
        $candidate = strtoupper(trim((string)$candidate));
        if ($candidate !== '') {
            $values[$candidate] = true;
        }
    }

    if (empty($values)) {
        return null;
    }

    $quoted = [];
    foreach (array_keys($values) as $value) {
        $quoted[] = bp_sql_quote((string)$value);
    }

    $orderBy = '1';
    if (isset($userColumns['s_no'])) {
        $orderBy = 's_no DESC';
    } elseif (isset($userColumns['unique_id'])) {
        $orderBy = 'unique_id DESC';
    }

    $sql = "
        SELECT " . implode(', ', $selectColumns) . "
        FROM user
        WHERE is_active = 1
          AND is_delete = 0
          AND (
                " . (isset($userColumns['staff_unique_id'])
                    ? 'UPPER(TRIM(staff_unique_id)) IN (' . implode(', ', $quoted) . ')'
                    : '1 = 0') . "
             OR " . (isset($userColumns['unique_id'])
                    ? 'UPPER(TRIM(unique_id)) IN (' . implode(', ', $quoted) . ')'
                    : '1 = 0') . "
          )
        ORDER BY {$orderBy}
        LIMIT 1
    ";

    $result = $pdo->query($sql);
    return bp_att_query_row($result);
}

function bp_att_designation_name(string $designationUniqueId): string
{
    $designationUniqueId = trim($designationUniqueId);
    if ($designationUniqueId === '') {
        return '';
    }

    $rows = function_exists('designation') ? designation('', $designationUniqueId) : [];
    return bp_att_safe_text($rows[0]['designation'] ?? '');
}

function bp_att_department_name(string $departmentUniqueId): string
{
    $departmentUniqueId = trim($departmentUniqueId);
    if ($departmentUniqueId === '') {
        return '';
    }

    $rows = function_exists('department') ? department($departmentUniqueId) : [];
    $name = bp_att_safe_text($rows[0]['department'] ?? '');
    return $name !== '' ? $name : $departmentUniqueId;
}

function bp_att_user_type_name(string $userTypeUniqueId): string
{
    $userTypeUniqueId = trim($userTypeUniqueId);
    if ($userTypeUniqueId === '') {
        return '';
    }

    $row = bp_fetch_one(
        'user_type',
        ['user_type'],
        [
            'unique_id' => $userTypeUniqueId,
            'is_active' => 1,
            'is_delete' => 0,
        ]
    );

    return trim((string)($row['user_type'] ?? ''));
}

function bp_att_context(string $staffIdentifier): ?array
{
    static $cache = [];

    $cacheKey = strtoupper(trim($staffIdentifier));
    if ($cacheKey === '') {
        return null;
    }
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $staff = bp_att_fetch_staff_full($staffIdentifier);
    if (!$staff) {
        $cache[$cacheKey] = null;
        return null;
    }

    $employeeId = trim((string)($staff['employee_id'] ?? ''));
    $designationName = bp_att_designation_name((string)($staff['designation_unique_id'] ?? ''));
    $departmentName = bp_att_department_name((string)($staff['department'] ?? ''));
    $userRow = bp_att_fetch_user_row([
        $staffIdentifier,
        $employeeId,
        (string)($staff['unique_id'] ?? ''),
    ]);

    $userTypeId = trim((string)($userRow['user_type'] ?? ''));
    $userTypeName = bp_att_user_type_name($userTypeId);
    $session = [
        'user_type' => $userTypeId,
        'sess_user_type' => $userTypeId,
        'work_location' => trim((string)($userRow['work_location'] ?? '')),
        'employee_id' => $employeeId,
        'staff_id' => $employeeId,
        'designation_type' => $designationName,
    ];

    // Full access (see everyone) matches the web report's authority check:
    // authoritative user_type UUID (admin/hr) + Developer / Kapil Mehra.
    $isHrUser = bp_att_is_full_access_user(
        $userTypeId,
        $designationName,
        (string)($userRow['user_name'] ?? ''),
        (string)($staff['staff_name'] ?? ''),
        $employeeId
    );
    $isReportingOfficer = function_exists('bp_is_reporting_officer')
        ? bp_is_reporting_officer($employeeId)
        : false;

    if ($isHrUser) {
        $scope = [
            'scope_type' => 'all',
            'employee_ids' => ['all'],
            'project_ids' => ['all'],
            'department_filter' => null,
            'self_employee_id' => $employeeId,
        ];
    } else {
        // Non-HR users: resolve visibility exactly like the web report's
        // build_employee_condition() — self + recursive reportees (reporting
        // officers) + department-head scope + plant-in-charge scope.
        $scope = bp_att_resolve_scope_native(
            $employeeId,
            (string)($session['work_location'] ?? ''),
            [$designationName, $userTypeName, (string)($session['designation_type'] ?? '')]
        );
    }

    $roleLabel = '';
    foreach ([$designationName, $userTypeName, $departmentName] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            $roleLabel = $candidate;
            break;
        }
    }

    if ($roleLabel === '' && $isHrUser) {
        $roleLabel = 'HR';
    } elseif ($roleLabel === '' && $isReportingOfficer) {
        $roleLabel = 'Reporting Officer';
    } elseif ($roleLabel === '') {
        $roleLabel = 'Employee';
    }

    $cache[$cacheKey] = [
        'staff' => $staff,
        'user' => $userRow,
        'employee_id' => $employeeId,
        'designation_name' => $designationName,
        'department_name' => $departmentName,
        'user_type_id' => $userTypeId,
        'user_type_name' => $userTypeName,
        'role_label' => $roleLabel,
        'is_hr_user' => $isHrUser,
        'is_reporting_officer' => $isReportingOfficer,
        'session' => $session,
        'scope' => $scope,
    ];

    return $cache[$cacheKey];
}

function bp_att_require_context(string $staffIdentifier): array
{
    $context = bp_att_context($staffIdentifier);
    if (!$context) {
        bp_send_json([
            'status' => false,
            'message' => 'Employee not found',
        ], 404);
    }

    return $context;
}

function bp_att_require_hr_context(string $staffIdentifier): array
{
    $context = bp_att_require_context($staffIdentifier);
    if (empty($context['is_hr_user'])) {
        bp_send_json([
            'status' => false,
            'message' => 'This section is only available for HR users',
        ], 403);
    }

    return $context;
}

function bp_att_unique_values(array $values): array
{
    $unique = [];
    foreach ($values as $value) {
        $text = trim((string)$value);
        if ($text === '') {
            continue;
        }

        $unique[$text] = true;
    }

    return array_keys($unique);
}

function bp_att_project_ids_from_context(array $context): array
{
    $projectIds = [];

    foreach ((array)($context['scope']['project_ids'] ?? []) as $value) {
        $value = trim((string)$value);
        if ($value !== '' && strcasecmp($value, 'all') !== 0) {
            $projectIds[] = $value;
        }
    }

    foreach ([
        (string)($context['session']['work_location'] ?? ''),
        (string)($context['user']['work_location'] ?? ''),
        (string)($context['staff']['work_location'] ?? ''),
    ] as $csvValue) {
        foreach (bp_parse_csv_values($csvValue) as $value) {
            if (strcasecmp($value, 'all') !== 0) {
                $projectIds[] = $value;
            }
        }
    }

    if (empty($projectIds) && function_exists('attendance_get_user_projects_from_user_table')) {
        foreach ((array)attendance_get_user_projects_from_user_table(
            (string)($context['employee_id'] ?? '')
        ) as $value) {
            $value = trim((string)$value);
            if ($value !== '' && strcasecmp($value, 'all') !== 0) {
                $projectIds[] = $value;
            }
        }
    }

    return bp_att_unique_values($projectIds);
}

function bp_att_float_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (float)$value : null;
}

function bp_att_fetch_project_locations(array $projectIds): array
{
    $projectIds = bp_att_unique_values($projectIds);
    if (empty($projectIds)) {
        return [];
    }

    $projectColumns = bp_att_table_columns('project_creation');
    if (empty($projectColumns) || !isset($projectColumns['unique_id'])) {
        return [];
    }

    $selectColumns = array_values(array_filter([
        'unique_id',
        'project_name',
        'project_code',
        'latitude',
        'longitude',
    ], static fn($column) => isset($projectColumns[$column])));

    if (empty($selectColumns)) {
        return [];
    }

    $quotedIds = array_map('bp_sql_quote', $projectIds);
    $where = 'is_active = 1 AND is_delete = 0 AND unique_id IN (' . implode(', ', $quotedIds) . ')';
    try {
        $rows = bp_fetch_rows('project_creation', $selectColumns, $where);
    } catch (Throwable $e) {
        error_log('bp_mobile_app project locations lookup failed: ' . bp_att_error_text($e));
        return [];
    }

    $locations = [];
    foreach ($rows as $row) {
        $projectId = trim((string)($row['unique_id'] ?? ''));
        if ($projectId === '') {
            continue;
        }

        $latitude = bp_att_float_or_null($row['latitude'] ?? null);
        $longitude = bp_att_float_or_null($row['longitude'] ?? null);
        if ($latitude === null || $longitude === null) {
            continue;
        }

        $projectName = trim((string)($row['project_name'] ?? ''));
        $projectCode = trim((string)($row['project_code'] ?? ''));
        if ($projectName === '') {
            $projectName = $projectCode !== '' ? $projectCode : $projectId;
        }

        $locations[] = [
            'project_id' => $projectId,
            'project_name' => $projectName,
            'project_code' => $projectCode,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    usort($locations, static function (array $a, array $b): int {
        return strcasecmp((string)$a['project_name'], (string)$b['project_name']);
    });

    return $locations;
}

function bp_att_project_locations_for_context(array $context): array
{
    return bp_att_fetch_project_locations(bp_att_project_ids_from_context($context));
}

function bp_att_direct_is_valid_coordinate(?float $latitude, ?float $longitude): bool
{
    if ($latitude === null || $longitude === null) {
        return false;
    }

    if (!is_finite($latitude) || !is_finite($longitude)) {
        return false;
    }

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return false;
    }

    return abs($latitude) > 0.000001 && abs($longitude) > 0.000001;
}

function bp_att_head_office_project_id(): string
{
    return '69858f736caee70745';
}

function bp_att_project_location_is_head_office(array $project): bool
{
    $projectId = trim((string)($project['project_id'] ?? $project['unique_id'] ?? ''));
    $projectName = strtoupper(trim((string)($project['project_name'] ?? '')));

    return $projectId === bp_att_head_office_project_id()
        || $projectName === 'BP HEAD OFFICE';
}

function bp_att_context_has_head_office_access(array $context, ?array $projectLocations = null): bool
{
    foreach (bp_att_project_ids_from_context($context) as $projectId) {
        if (trim((string)$projectId) === bp_att_head_office_project_id()) {
            return true;
        }
    }

    foreach ((array)($projectLocations ?? bp_att_project_locations_for_context($context)) as $project) {
        if (is_array($project) && bp_att_project_location_is_head_office($project)) {
            return true;
        }
    }

    return false;
}

function bp_att_distance_meters(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
{
    $earthRadiusMeters = 6371000;
    $lat1 = deg2rad($fromLatitude);
    $lat2 = deg2rad($toLatitude);
    $deltaLat = deg2rad($toLatitude - $fromLatitude);
    $deltaLon = deg2rad($toLongitude - $fromLongitude);

    $a = sin($deltaLat / 2) * sin($deltaLat / 2)
        + cos($lat1) * cos($lat2) * sin($deltaLon / 2) * sin($deltaLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadiusMeters * $c;
}

function bp_att_nearest_project_location(array $projectLocations, float $latitude, float $longitude): ?array
{
    $nearest = null;
    $nearestDistance = null;

    foreach ($projectLocations as $project) {
        if (!is_array($project)) {
            continue;
        }

        $projectLatitude = bp_att_float_or_null($project['latitude'] ?? null);
        $projectLongitude = bp_att_float_or_null($project['longitude'] ?? null);
        if (!bp_att_direct_is_valid_coordinate($projectLatitude, $projectLongitude)) {
            continue;
        }

        $distance = bp_att_distance_meters($latitude, $longitude, (float)$projectLatitude, (float)$projectLongitude);
        if ($nearestDistance === null || $distance < $nearestDistance) {
            $nearest = $project;
            $nearestDistance = $distance;
        }
    }

    if ($nearest === null || $nearestDistance === null) {
        return null;
    }

    $nearest['distance_meters'] = $nearestDistance;
    return $nearest;
}

function bp_att_direct_punch_timestamp(array $input): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $recognitionDate = bp_date_ymd(bp_str($input, 'recognition_date')) ?? $now->format('Y-m-d');
    $recognitionTime = trim(bp_str($input, 'recognition_time', $now->format('H:i:s')));
    $records = trim(bp_str($input, 'records', $recognitionDate . ' ' . $recognitionTime));

    return [$recognitionDate, $recognitionTime, $records];
}

function bp_att_insert_direct_recognized(array $context, array $input, float $latitude, float $longitude): array
{
    [$recognitionDate, $recognitionTime, $records] = bp_att_direct_punch_timestamp($input);
    $employeeId = trim((string)($context['employee_id'] ?? ''));
    $employeeName = trim((string)($context['staff']['staff_name'] ?? ''));

    $insert = bp_att_insert_row_raw('zigfly_recognized', [
        'emp_id' => $employeeId,
        'name' => $employeeName,
        'records' => $records,
        'captured_image_path' => 'direct_punch',
        'similarity_score' => '0',
        'latitude' => (string)$latitude,
        'longitude' => (string)$longitude,
        'recognition_date' => $recognitionDate,
        'recognition_time' => $recognitionTime,
    ]);

    if (!$insert || !($insert->status ?? false)) {
        return [
            'status' => false,
            'message' => 'Failed to mark direct attendance',
            'error' => bp_att_error_text($insert->error ?? ''),
        ];
    }

    return [
        'status' => true,
        'recognition_date' => $recognitionDate,
        'recognition_time' => $recognitionTime,
        'records' => $records,
    ];
}

function bp_att_insert_direct_approval(array $context, array $input, float $latitude, float $longitude): array
{
    [$recognitionDate, $recognitionTime, $records] = bp_att_direct_punch_timestamp($input);
    $employeeId = trim((string)($context['employee_id'] ?? ''));
    $employeeName = trim((string)($context['staff']['staff_name'] ?? ''));
    $now = bp_now();

    $insert = bp_att_insert_row_raw('att_approval', [
        'emp_id' => $employeeId,
        'name' => $employeeName,
        'records' => $records,
        'captured_image_path' => 'direct_punch',
        'similarity_score' => '0',
        'latitude' => (string)$latitude,
        'longitude' => (string)$longitude,
        'recognition_date' => $recognitionDate,
        'recognition_time' => $recognitionTime,
        'status' => 0,
        'created' => $now,
        'updated' => $now,
        'is_active' => 1,
        'is_delete' => 0,
    ]);

    if (!$insert || !($insert->status ?? false)) {
        return [
            'status' => false,
            'message' => 'Failed to send direct attendance for approval',
            'error' => bp_att_error_text($insert->error ?? ''),
        ];
    }

    $approvalRow = bp_att_find_pending_approval_for_employee(
        $employeeId,
        null,
        $records,
        $recognitionDate
    );

    if (!$approvalRow) {
        return [
            'status' => false,
            'message' => 'Direct attendance approval was created but could not be reloaded',
            'error' => '',
        ];
    }

    return [
        'status' => true,
        'approval' => $approvalRow,
        'recognition_date' => $recognitionDate,
        'recognition_time' => $recognitionTime,
        'records' => $records,
    ];
}

function bp_att_sql_date_filter(string $column, ?string $fromDate, ?string $toDate): string
{
    $parts = [];
    if ($fromDate !== null) {
        $parts[] = $column . ' >= ' . bp_sql_quote($fromDate);
    }
    if ($toDate !== null) {
        $parts[] = $column . ' <= ' . bp_sql_quote($toDate);
    }
    return empty($parts) ? '' : (' AND ' . implode(' AND ', $parts));
}

function bp_att_scope_is_all(array $scope): bool
{
    $scopeType = strtolower(trim((string)($scope['scope_type'] ?? '')));
    if ($scopeType === 'all' || $scopeType === 'hr_admin') {
        return true;
    }

    foreach ((array)($scope['employee_ids'] ?? []) as $employeeId) {
        if (strtolower(trim((string)$employeeId)) === 'all') {
            return true;
        }
    }

    return false;
}

function bp_att_enrich_approval_row(array $row): array
{
    $employeeId = trim((string)($row['emp_id'] ?? ''));
    if ($employeeId === '') {
        return $row;
    }

    $staff = bp_att_fetch_staff_full($employeeId);
    if (!$staff) {
        return $row;
    }

    foreach (['staff_name', 'reporting_officer', 'designation_unique_id', 'department'] as $column) {
        $value = trim((string)($row[$column] ?? ''));
        if ($value !== '') {
            continue;
        }

        $row[$column] = (string)($staff[$column] ?? '');
    }

    return $row;
}

function bp_att_map_approval_row(array $row): array
{
    $status = (int)($row['status'] ?? 0);
    $staffName = trim((string)($row['staff_name'] ?? ''));
    $fallbackName = trim((string)($row['name'] ?? ''));
    $employeeName = $staffName !== '' ? $staffName : $fallbackName;

    return [
        'approval_id' => (string)($row['id'] ?? ''),
        'employee_id' => trim((string)($row['emp_id'] ?? '')),
        'employee_name' => $employeeName,
        'reporting_officer' => trim((string)($row['reporting_officer'] ?? '')),
        'designation_unique_id' => trim((string)($row['designation_unique_id'] ?? '')),
        'department' => trim((string)($row['department'] ?? '')),
        'recognition_date' => trim((string)($row['recognition_date'] ?? '')),
        'recognition_time' => trim((string)($row['recognition_time'] ?? '')),
        'records' => trim((string)($row['records'] ?? '')),
        'captured_image_path' => trim((string)($row['captured_image_path'] ?? '')),
        'similarity_score' => (float)($row['similarity_score'] ?? 0),
        'latitude' => trim((string)($row['latitude'] ?? '')),
        'longitude' => trim((string)($row['longitude'] ?? '')),
        'status' => $status,
        'status_label' => bp_att_status_label($status),
        'updated' => trim((string)($row['updated'] ?? '')),
        'updated_user_id' => trim((string)($row['updated_user_id'] ?? '')),
    ];
}

function bp_att_fetch_pending_approvals_count(array $context): int
{
    global $pdo;

    $scope = $context['scope'] ?? [];
    if (bp_att_scope_is_all($scope)) {
        $result = $pdo->query("
            SELECT COUNT(id) AS c
            FROM att_approval
            WHERE is_delete = 0
              AND status = 0
        ");
        $row = bp_att_query_row($result);
        return (int)($row['c'] ?? 0);
    }

    $staffJoin = bp_att_staff_join_meta();
    $where = "a.is_delete = 0 AND a.status = 0";
    $where .= bp_att_scope_where_clause(
        $scope,
        'a.emp_id',
        $staffJoin['project_column'],
        $staffJoin['department_column']
    );

    $sql = "
        SELECT COUNT(a.id) AS c
        FROM att_approval a
        LEFT JOIN staff_test s
          ON a.emp_id = s.employee_id
        WHERE {$where}
    ";

    $result = $pdo->query($sql);
    $row = bp_att_query_row($result);
    if (!$row) {
        return 0;
    }

    return (int)($row['c'] ?? 0);
}

function bp_att_fetch_approvals(array $context, ?int $status = 0, ?string $fromDate = null, ?string $toDate = null, int $limit = 100): array
{
    global $pdo;

    $scope = $context['scope'] ?? [];
    $limit = max(1, min($limit, 200));

    if (bp_att_scope_is_all($scope)) {
        $where = "is_delete = 0";
        if ($status !== null) {
            $where .= " AND status = " . intval($status);
        }
        $where .= bp_att_sql_date_filter('recognition_date', $fromDate, $toDate);

        $result = $pdo->query("
            SELECT *
            FROM att_approval
            WHERE {$where}
            ORDER BY records DESC, id DESC
            LIMIT {$limit}
        ");
        $rows = bp_att_query_rows($result);
        if (empty($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = bp_att_map_approval_row(bp_att_enrich_approval_row($row));
        }

        return $items;
    }

    $staffJoin = bp_att_staff_join_meta();

    $where = "a.is_delete = 0";
    if ($status !== null) {
        $where .= " AND a.status = " . intval($status);
    }
    $where .= bp_att_sql_date_filter('a.recognition_date', $fromDate, $toDate);
    $where .= bp_att_scope_where_clause(
        $scope,
        'a.emp_id',
        $staffJoin['project_column'],
        $staffJoin['department_column']
    );

    $sql = "
        SELECT
            a.*" . $staffJoin['select_sql'] . "
        FROM att_approval a
        LEFT JOIN staff_test s
          ON a.emp_id = s.employee_id
        WHERE {$where}
        ORDER BY a.records DESC, a.id DESC
        LIMIT {$limit}
    ";

    $result = $pdo->query($sql);
    $rows = bp_att_query_rows($result);
    if (empty($rows)) {
        return [];
    }

    $items = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $items[] = bp_att_map_approval_row(bp_att_enrich_approval_row($row));
        }
    }

    return $items;
}

function bp_att_fetch_employee_history(string $employeeId, ?string $fromDate = null, ?string $toDate = null, ?int $status = null, int $limit = 200): array
{
    global $pdo;

    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return [];
    }

    $limit = max(1, min($limit, 365));
    $where = "a.is_delete = 0 AND a.status <> 3 AND a.emp_id = " . bp_sql_quote($employeeId);
    if ($status !== null) {
        $where .= " AND a.status = " . intval($status);
    }
    $where .= bp_att_sql_date_filter('a.recognition_date', $fromDate, $toDate);

    $sql = "
        SELECT a.*
        FROM att_approval a
        WHERE {$where}
        ORDER BY a.records DESC, a.id DESC
        LIMIT {$limit}
    ";

    $result = $pdo->query($sql);
    $rows = bp_att_query_rows($result);
    if (empty($rows)) {
        return [];
    }

    $items = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $items[] = bp_att_map_approval_row(bp_att_enrich_approval_row($row));
        }
    }

    return $items;
}

function bp_att_first_available_column(array $tableColumns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (isset($tableColumns[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

function bp_att_attendance_view_column_map(): array
{
    $tableColumns = bp_att_table_columns('vw_attendance_with_shift');

    return [
        'employee_id' => bp_att_first_available_column($tableColumns, ['employee_id']),
        'staff_name' => bp_att_first_available_column($tableColumns, ['staff_name']),
        'shift_date' => bp_att_first_available_column($tableColumns, ['shift_date']),
        'planned_shift' => bp_att_first_available_column($tableColumns, ['planned_shift', 'shift_name']),
        'attendance_status' => bp_att_first_available_column(
            $tableColumns,
            ['attendance_status', 'day_status', 'status']
        ),
        'entry_punch' => bp_att_first_available_column(
            $tableColumns,
            ['entry_punch', 'in_time', 'in_punch']
        ),
        'exit_punch' => bp_att_first_available_column(
            $tableColumns,
            ['exit_punch', 'out_time', 'out_punch']
        ),
        'worked_hours' => bp_att_first_available_column(
            $tableColumns,
            ['worked_hours', 'total_worked_time', 'worked_time']
        ),
        'shift_hours' => bp_att_first_available_column(
            $tableColumns,
            ['shift_hours', 'status_hours']
        ),
        'in_latitude' => bp_att_first_available_column(
            $tableColumns,
            ['in_latitude', 'entry_latitude', 'check_in_latitude']
        ),
        'in_longitude' => bp_att_first_available_column(
            $tableColumns,
            ['in_longitude', 'entry_longitude', 'check_in_longitude']
        ),
        'in_image_path' => bp_att_first_available_column(
            $tableColumns,
            ['in_image_path', 'entry_image_path', 'check_in_image_path', 'check_in_image']
        ),
        'out_latitude' => bp_att_first_available_column(
            $tableColumns,
            ['out_latitude', 'exit_latitude', 'check_out_latitude']
        ),
        'out_longitude' => bp_att_first_available_column(
            $tableColumns,
            ['out_longitude', 'exit_longitude', 'check_out_longitude']
        ),
        'out_image_path' => bp_att_first_available_column(
            $tableColumns,
            ['out_image_path', 'exit_image_path', 'check_out_image_path', 'check_out_image']
        ),
        'in_site_name' => bp_att_first_available_column(
            $tableColumns,
            ['in_site_name', 'entry_site_name', 'check_in_site_name']
        ),
        'out_site_name' => bp_att_first_available_column(
            $tableColumns,
            ['out_site_name', 'exit_site_name', 'check_out_site_name']
        ),
    ];
}

function bp_att_attendance_view_value(array $row, ?string $column): string
{
    if ($column === null) {
        return '';
    }

    return trim((string)($row[$column] ?? ''));
}

function bp_att_attendance_legacy_date(string $dateYmd): string
{
    $date = bp_date_ymd($dateYmd);
    if ($date === null) {
        return $dateYmd;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $dt ? $dt->format('d/F/Y') : $date;
}

function bp_att_attendance_time_only(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $normalized = str_replace('T', ' ', $value);
    if (preg_match('/\b(\d{2}:\d{2}:\d{2})\b/', $normalized, $matches) === 1) {
        return $matches[1];
    }
    if (preg_match('/\b(\d{2}:\d{2})\b/', $normalized, $matches) === 1) {
        return $matches[1] . ':00';
    }

    return $value;
}

function bp_att_attendance_day_status(string $status): string
{
    $status = trim($status);
    $normalized = bp_normalize_attendance_status($status);
    if ($normalized === '') {
        return '';
    }

    if ($normalized === 'holiday') {
        return 'Holiday';
    }
    if (bp_attendance_is_weekoff_holiday_status($normalized)) {
        return 'Week Off';
    }
    if (bp_attendance_is_absent_status($normalized)) {
        return 'Absent';
    }
    if (bp_attendance_is_permission_status($normalized)) {
        return $status !== '' ? $status : 'Permission';
    }
    if (bp_attendance_is_leave_status($normalized)) {
        return $status;
    }
    if (strpos($normalized, 'short') !== false) {
        return 'Short Hours';
    }
    if (strpos($normalized, 'half') !== false) {
        return 'Half Day';
    }
    if (bp_attendance_is_present_status($normalized)) {
        return 'Full Day';
    }

    return $status;
}

function bp_att_media_url_candidates(string $path): array
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return [];
    }

    if (preg_match('#^https?://#i', $path) === 1) {
        return [$path];
    }

    $relativePaths = [$path];

    $normalizedPath = ltrim($path, '/');
    foreach ([
        'uploads/' . $normalizedPath,
        'bp_mobile_app/' . $normalizedPath,
        'bp_mobile_app/uploads/' . $normalizedPath,
    ] as $candidatePath) {
        if ($candidatePath !== '' && strpos($normalizedPath, $candidatePath) !== 0) {
            $relativePaths[] = $candidatePath;
        }
    }

    if (preg_match('/\.[A-Za-z0-9]{2,6}$/', $path) !== 1) {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $relativePaths[] = $path . '.' . $extension;
            foreach ([
                'uploads/' . $normalizedPath . '.' . $extension,
                'bp_mobile_app/' . $normalizedPath . '.' . $extension,
                'bp_mobile_app/uploads/' . $normalizedPath . '.' . $extension,
            ] as $candidatePath) {
                $relativePaths[] = $candidatePath;
            }
        }
    }

    $hosts = array_values(array_unique(array_filter([
        defined('BP_APP_BASE_URL') ? rtrim(BP_APP_BASE_URL, '/') . '/' : '',
        defined('BP_LEGACY_WEB_BASE_URL') ? rtrim(BP_LEGACY_WEB_BASE_URL, '/') . '/' : '',
        defined('BP_QR_API_BASE_URL') ? rtrim(BP_QR_API_BASE_URL, '/') . '/' : '',
    ])));

    $urls = [];
    foreach (array_values(array_unique($relativePaths)) as $relativePath) {
        $cleanPath = ltrim($relativePath, '/');
        foreach ($hosts as $host) {
            $urls[] = $host . $cleanPath;
        }
    }

    return array_values(array_unique($urls));
}

function bp_att_remote_get(string $url, array $headers = [], int $timeout = 20): array
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
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
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

function bp_att_centralized_qr_api_base_urls(): array
{
    $candidates = [];

    $env = trim((string)getenv('BP_QR_API_BASE_URL'));
    if ($env !== '') {
        $candidates[] = rtrim($env, '/');
    }

    if (defined('BP_QR_API_BASE_URL') && BP_QR_API_BASE_URL !== '') {
        $candidates[] = rtrim((string)BP_QR_API_BASE_URL, '/');
    }

    return array_values(array_unique(array_filter($candidates)));
}

function bp_att_centralized_qr_api_base_url(): string
{
    $urls = bp_att_centralized_qr_api_base_urls();
    return $urls[0] ?? (defined('BP_QR_API_BASE_URL') ? (string)BP_QR_API_BASE_URL : '');
}

function bp_att_centralized_media_url_candidates(string $path, string $directory): array
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return [];
    }

    if (preg_match('#^https?://#i', $path) === 1) {
        return [$path];
    }

    $urls = [];

    foreach (bp_att_centralized_qr_api_base_urls() as $baseUrl) {
        $fileName = basename($path);
        $relative = ltrim($path, '/');
        $trimmedUploads = ltrim(preg_replace('#^/?uploads/#i', '', $relative) ?? $relative, '/');

        if ($fileName !== '' && $fileName !== '.' && $fileName !== '..') {
            $urls[] = $baseUrl . '/' . trim($directory, '/') . '/' . rawurlencode($fileName);
        }
        if ($relative !== '') {
            $urls[] = $baseUrl . '/' . $relative;
        }
        if ($trimmedUploads !== '' && $trimmedUploads !== $relative) {
            $urls[] = $baseUrl . '/' . $trimmedUploads;
        }
    }

    return array_values(array_unique(array_filter($urls)));
}

function bp_att_blueplanet_pdo(): ?PDO
{
    static $connection = null;
    static $initialized = false;

    if ($initialized) {
        return $connection;
    }

    $initialized = true;

    $driver = 'mysql';
    $host = trim((string)getenv('BP_ATTENDANCE_DB_HOST'));
    $user = trim((string)getenv('BP_ATTENDANCE_DB_USER'));
    $pass = (string)getenv('BP_ATTENDANCE_DB_PASS');
    $database = trim((string)getenv('BP_ATTENDANCE_DB_NAME'));

    if ($host === '') {
        $host = '192.168.1.200';
    }
    if ($user === '') {
        $user = 'my_root';
    }
    if ($pass === '') {
        $pass = 'my@123456';
    }
    if ($database === '') {
        $database = 'blueplanet';
    }

    try {
        $connection = new PDO(
            $driver . ':host=' . $host . ';port=3306;dbname=' . $database . ';charset=utf8',
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        error_log('bp_mobile_app blueplanet connection failed: ' . bp_att_error_text($e));
        $connection = null;
    }

    return $connection;
}

function bp_att_blueplanet_table_columns(string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }

    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $pdo = bp_att_blueplanet_pdo();
    if (!$pdo instanceof PDO) {
        $cache[$table] = [];
        return $cache[$table];
    }

    try {
        $statement = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        $rows = $statement instanceof PDOStatement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $cache[$table] = [];
        return $cache[$table];
    }

    $columns = [];
    foreach ($rows as $row) {
        $field = trim((string)($row['Field'] ?? ''));
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    $cache[$table] = $columns;
    return $cache[$table];
}

function bp_att_recognized_table_name(): string
{
    // Live manual attendance and direct punch both write accepted punches here.
    return 'zigfly_recognized';
}

function bp_att_central_recognized_table_name(): string
{
    // Face-recognition API writes here before the ERP sync job may copy rows.
    return 'recognized';
}

function bp_att_employee_media_tables(): array
{
    $resolved = [];
    $tables = defined('BP_APP_ENV') && BP_APP_ENV === 'beta'
        ? ['employees_beta_1', 'employees_beta', 'employee_beta']
        : ['employees'];

    foreach ($tables as $table) {
        $columns = bp_att_blueplanet_table_columns($table);
        if (empty($columns) || !isset($columns['emp_id'])) {
            continue;
        }

        $resolved[$table] = $columns;
    }

    return $resolved;
}

function bp_att_employee_id_candidates(string $employeeId): array
{
    $employeeId = strtoupper(trim($employeeId));
    if ($employeeId === '') {
        return [];
    }

    $candidates = [$employeeId => true];
    $digits = preg_replace('/\D+/', '', $employeeId);
    if ($digits === null || $digits === '') {
        return array_keys($candidates);
    }

    $trimmedDigits = ltrim($digits, '0');
    if ($trimmedDigits === '') {
        $trimmedDigits = '0';
    }

    $paddedDigits = str_pad(
        $trimmedDigits,
        max(3, strlen($digits)),
        '0',
        STR_PAD_LEFT
    );

    foreach ([
        $digits,
        $trimmedDigits,
        $paddedDigits,
        'YEPL' . $paddedDigits,
        'ZGESPL/' . $paddedDigits,
    ] as $candidate) {
        $candidate = strtoupper(trim((string)$candidate));
        if ($candidate !== '') {
            $candidates[$candidate] = true;
        }
    }

    return array_keys($candidates);
}

function bp_att_fetch_employee_media_row(string $table, array $columns, string $employeeId): ?array
{
    $pdo = bp_att_blueplanet_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }

    $requestedColumns = [
        'id',
        'emp_id',
        'name',
        'department',
        'designation',
        'company_name',
        'blood_group',
        'dob',
        'image_path',
        'qr_code_path',
    ];
    $selectedColumns = array_values(array_filter(
        $requestedColumns,
        static function (string $column) use ($columns): bool {
            return isset($columns[$column]);
        }
    ));

    if (empty($selectedColumns)) {
        return null;
    }

    $quotedCandidates = array_map(
        static fn(string $candidate): string => bp_sql_quote($candidate),
        bp_att_employee_id_candidates($employeeId)
    );
    if (empty($quotedCandidates)) {
        return null;
    }

    $placeholders = [];
    $params = [];
    foreach (bp_att_employee_id_candidates($employeeId) as $index => $candidate) {
        $placeholder = ':emp_id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = strtoupper(trim($candidate));
    }

    if (empty($placeholders)) {
        return null;
    }

    $sql = 'SELECT ' . implode(', ', $selectedColumns)
        . ' FROM `' . $table . '`'
        . ' WHERE UPPER(TRIM(emp_id)) IN (' . implode(', ', $placeholders) . ')'
        . ' LIMIT 1';

    try {
        $statement = $pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $statement->bindValue($placeholder, $value);
        }
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log(
            'bp_mobile_app employee media query failed for '
            . $table . ': ' . bp_att_error_text($e)
        );
        return null;
    }

    return is_array($row) ? $row : null;
}

function bp_att_first_non_empty_media_value(array $rowsByTable, string $field): string
{
    foreach ($rowsByTable as $row) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function bp_att_media_source_table(array $rowsByTable): string
{
    foreach ($rowsByTable as $table => $row) {
        if (trim((string)($row['qr_code_path'] ?? '')) !== '') {
            return (string)$table;
        }
        if (!empty($row['qr_code_url_candidates']) && is_array($row['qr_code_url_candidates'])) {
            return (string)$table;
        }
    }

    foreach ($rowsByTable as $table => $row) {
        if (trim((string)($row['image_path'] ?? '')) !== '') {
            return (string)$table;
        }
        if (!empty($row['image_url_candidates']) && is_array($row['image_url_candidates'])) {
            return (string)$table;
        }
    }

    $tableNames = array_keys($rowsByTable);
    return isset($tableNames[0]) ? (string)$tableNames[0] : '';
}

function bp_att_media_url_candidates_from_rows(
    array $rowsByTable,
    string $pathField,
    string $urlCandidatesField
): array {
    $urls = [];

    foreach ($rowsByTable as $row) {
        foreach ((array)($row[$urlCandidatesField] ?? []) as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $urls[] = $candidate;
            }
        }

        $path = trim((string)($row[$pathField] ?? ''));
        if ($path !== '') {
            $urls = array_merge($urls, bp_att_media_url_candidates($path));
        }
    }

    return array_values(array_unique(array_filter($urls)));
}

function bp_att_first_url_candidate(array $urls): string
{
    foreach ($urls as $url) {
        $url = trim((string)$url);
        if ($url !== '') {
            return $url;
        }
    }

    return '';
}

function bp_att_html_fragment_text(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function bp_att_centralized_html_field(string $body, string $label): string
{
    $pattern = '#<th[^>]*>\s*' . preg_quote($label, '#') . '\s*</th>\s*<td[^>]*>.*?</td>\s*<td[^>]*>(.*?)</td>#is';
    if (preg_match($pattern, $body, $matches) !== 1) {
        return '';
    }

    return bp_att_html_fragment_text((string)($matches[1] ?? ''));
}

function bp_att_centralized_html_image_path(string $body, string $needle): string
{
    $pattern = '#<img[^>]+src=["\']([^"\']*' . preg_quote($needle, '#') . '[^"\']*)["\']#i';
    if (preg_match($pattern, $body, $matches) !== 1) {
        return '';
    }

    return trim((string)($matches[1] ?? ''));
}

function bp_att_parse_centralized_employee_html(
    string $body,
    string $candidate,
    string $endpoint
): ?array {
    $html = trim($body);
    if ($html === '') {
        return null;
    }

    $hasHtml = stripos($html, '<!doctype') !== false || stripos($html, '<html') !== false;
    if (!$hasHtml) {
        return null;
    }

    $empId = bp_att_centralized_html_field($html, 'Emp ID');
    $name = bp_att_centralized_html_field($html, 'Emp Name');
    $designation = bp_att_centralized_html_field($html, 'Designation');
    $dob = bp_att_centralized_html_field($html, 'DOB');
    $companyName = '';
    if (preg_match('#<h6[^>]*>\s*<b>(.*?)</b>\s*</h6>#is', $html, $companyMatches) === 1) {
        $companyName = bp_att_html_fragment_text((string)($companyMatches[1] ?? ''));
    }

    $qrCodePath = bp_att_centralized_html_image_path($html, 'qrcodes_bp/');
    $imagePath = bp_att_centralized_html_image_path($html, 'uploads/');

    if ($empId === '' && $name === '' && $qrCodePath === '' && $imagePath === '') {
        return null;
    }

    return [
        'emp_id' => $empId !== '' ? $empId : $candidate,
        'name' => $name,
        'department' => '',
        'designation' => $designation,
        'company_name' => $companyName,
        'blood_group' => '',
        'dob' => $dob,
        'image_path' => $imagePath,
        'image_url_candidates' => bp_att_centralized_media_url_candidates($imagePath, 'uploads'),
        'qr_code_path' => $qrCodePath,
        'qr_code_url_candidates' => bp_att_centralized_media_url_candidates($qrCodePath, 'qrcodes_bp'),
        'centralized_endpoint' => $endpoint,
    ];
}

function bp_att_fetch_centralized_employee_media_row(string $employeeId, ?string &$failureReason = null): ?array
{
    $headers = ['Accept: application/json'];
    $lastFailure = '';
    $endpointPaths = defined('BP_APP_ENV') && BP_APP_ENV === 'beta'
        ? ['employees_beta', 'employees_beta_1', 'employees']
        : ['employees'];

    foreach (bp_att_centralized_qr_api_base_urls() as $baseUrl) {
        foreach ($endpointPaths as $endpointPath) {
            foreach (bp_att_employee_id_candidates($employeeId) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate === '') {
                    continue;
                }

                $url = $baseUrl . '/' . $endpointPath . '?id=' . rawurlencode($candidate);
                $response = bp_att_remote_get($url, $headers, 6);
                $payload = $response['json'];

                if (!is_array($payload)) {
                    $htmlRow = bp_att_parse_centralized_employee_html(
                        (string)($response['body'] ?? ''),
                        $candidate,
                        $baseUrl . '/' . $endpointPath
                    );
                    if (is_array($htmlRow)) {
                        return $htmlRow;
                    }

                    $lastFailure = trim((string)($response['error'] ?? ''));
                    continue;
                }

                if (!($payload['status'] ?? false)) {
                    $message = trim((string)(
                        $payload['error']
                        ?? $payload['message']
                        ?? $payload['msg']
                        ?? ''
                    ));
                    if ($message !== '') {
                        $lastFailure = $message;
                    }
                    continue;
                }

                $data = $payload['data'] ?? null;
                if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                    $data = $data[0];
                }
                if (!is_array($data)) {
                    continue;
                }

                $qrCodePath = trim((string)($data['qr_code_path'] ?? ''));
                $imagePath = trim((string)($data['image_path'] ?? ''));

                return [
                    'emp_id' => trim((string)($data['emp_id'] ?? $candidate)),
                    'name' => trim((string)($data['name'] ?? '')),
                    'department' => trim((string)($data['department'] ?? '')),
                    'designation' => trim((string)($data['designation'] ?? '')),
                    'company_name' => trim((string)($data['company_name'] ?? '')),
                    'blood_group' => trim((string)($data['blood_group'] ?? '')),
                    'dob' => trim((string)($data['dob'] ?? '')),
                    'image_path' => $imagePath,
                    'image_url_candidates' => bp_att_centralized_media_url_candidates($imagePath, 'uploads'),
                    'qr_code_path' => $qrCodePath,
                    'qr_code_url_candidates' => bp_att_centralized_media_url_candidates($qrCodePath, 'qrcodes_bp'),
                    'centralized_endpoint' => $baseUrl . '/' . $endpointPath,
                ];
            }
        }
    }

    $failureReason = $lastFailure;
    return null;
}

function bp_att_fetch_employee_qr_lookup(string $staffIdentifier): array
{
    $context = bp_att_context($staffIdentifier);
    if (!$context) {
        return [
            'record' => null,
            'message' => 'Employee not found',
        ];
    }

    $employeeId = trim((string)($context['employee_id'] ?? ''));
    if ($employeeId === '') {
        return [
            'record' => null,
            'message' => 'Employee not found',
        ];
    }

    $rowsByTable = [];
    $centralizedFailureReason = '';

    $tableMap = bp_att_employee_media_tables();
    if (!empty($tableMap)) {
        foreach ($tableMap as $table => $columns) {
            $row = bp_att_fetch_employee_media_row($table, $columns, $employeeId);
            if (is_array($row)) {
                $rowsByTable['blueplanet.' . $table] = $row;
            }
        }
    }

    $hasLocalQr = $rowsByTable !== []
        && (
            bp_att_first_non_empty_media_value($rowsByTable, 'qr_code_path') !== ''
            || !empty(bp_att_media_url_candidates_from_rows(
                $rowsByTable,
                'qr_code_path',
                'qr_code_url_candidates'
            ))
        );

    if (!$hasLocalQr) {
        $centralizedRow = bp_att_fetch_centralized_employee_media_row($employeeId, $centralizedFailureReason);
        if (is_array($centralizedRow)) {
            $rowsByTable['centralized_api.' . trim((string)($centralizedRow['centralized_endpoint'] ?? 'employees_beta_1'))] = $centralizedRow;
        }
    }

    if (empty($rowsByTable)) {
        $message = trim($centralizedFailureReason);
        if ($message === '') {
            $message = 'Employee QR not found';
        }

        return [
            'record' => null,
            'message' => $message,
        ];
    }

    $imagePath = bp_att_first_non_empty_media_value($rowsByTable, 'image_path');
    $qrCodePath = bp_att_first_non_empty_media_value($rowsByTable, 'qr_code_path');
    $sourceTable = bp_att_media_source_table($rowsByTable);

    if ($qrCodePath === '' && empty(bp_att_media_url_candidates_from_rows(
        $rowsByTable,
        'qr_code_path',
        'qr_code_url_candidates'
    ))) {
        $message = trim($centralizedFailureReason);
        if ($message === '') {
            $message = 'Employee QR is not available yet';
        }

        return [
            'record' => null,
            'message' => $message,
        ];
    }

    return [
        'record' => [
        'staff_unique_id' => trim((string)($context['staff']['unique_id'] ?? '')),
        'employee_id' => $employeeId,
        'source_table' => $sourceTable,
        'name' => bp_att_first_non_empty_media_value($rowsByTable, 'name')
            ?: trim((string)($context['staff']['staff_name'] ?? '')),
        'department' => bp_att_first_non_empty_media_value($rowsByTable, 'department'),
        'designation' => bp_att_first_non_empty_media_value($rowsByTable, 'designation'),
        'company_name' => bp_att_first_non_empty_media_value($rowsByTable, 'company_name'),
        'blood_group' => bp_att_first_non_empty_media_value($rowsByTable, 'blood_group'),
        'dob' => bp_att_first_non_empty_media_value($rowsByTable, 'dob'),
        'image_path' => $imagePath,
        'image_url_candidates' => bp_att_media_url_candidates_from_rows(
            $rowsByTable,
            'image_path',
            'image_url_candidates'
        ),
        'qr_code_path' => $qrCodePath,
        'qr_code_url_candidates' => bp_att_media_url_candidates_from_rows(
            $rowsByTable,
            'qr_code_path',
            'qr_code_url_candidates'
        ),
        ],
        'message' => '',
    ];
}

function bp_att_fetch_employee_qr_record(string $staffIdentifier): ?array
{
    $lookup = bp_att_fetch_employee_qr_lookup($staffIdentifier);
    $record = $lookup['record'] ?? null;
    return is_array($record) ? $record : null;
}

function bp_att_index_employee_approvals_by_date(string $employeeId, ?string $fromDate = null, ?string $toDate = null, int $limit = 400): array
{
    $history = bp_att_fetch_employee_history($employeeId, $fromDate, $toDate, null, $limit);
    $indexed = [];

    foreach ($history as $item) {
        $dateKey = bp_date_ymd((string)($item['recognition_date'] ?? ''));
        if ($dateKey === null) {
            $recordValue = trim((string)($item['records'] ?? ''));
            if ($recordValue !== '') {
                $dateKey = bp_date_ymd(substr(str_replace('T', ' ', $recordValue), 0, 10));
            }
        }

        if ($dateKey === null || isset($indexed[$dateKey])) {
            continue;
        }

        $indexed[$dateKey] = $item;
    }

    return $indexed;
}

/**
 * Groups every att_approval recognition row per date into a first (check-in)
 * and last (check-out) punch, carrying the latitude/longitude/captured image.
 *
 * vw_attendance_with_shift exposes the punch *time* but not the geo/photo for
 * face & direct punches — that data lives in att_approval. This lets the
 * attendance detail screen show the map + punch photo.
 *
 * Returns: [ 'YYYY-MM-DD' => ['in' => row|null, 'out' => row|null] ]
 */
function bp_att_index_employee_punches_by_date(string $employeeId, ?string $fromDate = null, ?string $toDate = null, int $limit = 400): array
{
    $history = bp_att_fetch_employee_history($employeeId, $fromDate, $toDate, null, $limit);

    // Collect all rows per date with a sortable timestamp.
    $byDate = [];
    foreach ($history as $item) {
        $dateKey = bp_date_ymd((string)($item['recognition_date'] ?? ''));
        if ($dateKey === null) {
            $recordValue = trim((string)($item['records'] ?? ''));
            if ($recordValue !== '') {
                $dateKey = bp_date_ymd(substr(str_replace('T', ' ', $recordValue), 0, 10));
            }
        }
        if ($dateKey === null) {
            continue;
        }

        $time = trim((string)($item['recognition_time'] ?? ''));
        $sortKey = $dateKey . ' ' . ($time !== '' ? $time : '00:00:00');
        $byDate[$dateKey][] = ['sort' => $sortKey, 'row' => $item];
    }

    $indexed = [];
    foreach ($byDate as $dateKey => $entries) {
        usort($entries, static function (array $a, array $b): int {
            return strcmp((string)$a['sort'], (string)$b['sort']);
        });
        $first = $entries[0]['row'] ?? null;
        $last = $entries[count($entries) - 1]['row'] ?? null;
        // With a single punch in the day, treat it as the check-in only.
        $indexed[$dateKey] = [
            'in' => $first,
            'out' => count($entries) > 1 ? $last : null,
        ];
    }

    return $indexed;
}

/**
 * Returns a usable image path/url from an att_approval row, or '' when the
 * punch carried no photo (e.g. direct punches store 'direct_punch').
 */
function bp_att_punch_image_path(?array $row): string
{
    if (!is_array($row)) {
        return '';
    }
    $path = trim((string)($row['captured_image_path'] ?? ''));
    if ($path === '' || strtolower($path) === 'direct_punch') {
        return '';
    }
    return $path;
}

function bp_att_fetch_actual_attendance_records(
    string $employeeId,
    ?string $fromDate = null,
    ?string $toDate = null,
    string $staffUniqueId = ''
): array {
    $employeeId = trim($employeeId);
    $staffUniqueId = trim($staffUniqueId);

    if ($employeeId === '' && $staffUniqueId === '') {
        return [];
    }

    if ($fromDate === null || bp_date_ymd($fromDate) === null) {
        $fromDate = date('Y-m-01');
    }
    if ($toDate === null || bp_date_ymd($toDate) === null) {
        $toDate = date('Y-m-t');
    }
    if ($fromDate > $toDate) {
        $tmp = $fromDate;
        $fromDate = $toDate;
        $toDate = $tmp;
    }

    $tableColumns = bp_att_table_columns('vw_attendance_with_shift');
    if (empty($tableColumns)) {
        return [];
    }

    $columnMap = bp_att_attendance_view_column_map();
    if (($columnMap['shift_date'] ?? null) === null || ($columnMap['attendance_status'] ?? null) === null) {
        return [];
    }

    $attendanceIds = [];
    foreach ([$employeeId, $staffUniqueId] as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $attendanceIds[$value] = true;
        }
    }
    if (empty($attendanceIds)) {
        return [];
    }

    $selectedColumns = array_values(array_unique(array_filter($columnMap)));
    $quotedIds = array_map(static function (string $value): string {
        return bp_sql_quote($value);
    }, array_keys($attendanceIds));

    $rows = bp_fetch_rows(
        'vw_attendance_with_shift',
        $selectedColumns,
        'employee_id IN (' . implode(',', $quotedIds) . ')'
            . ' AND shift_date >= ' . bp_sql_quote($fromDate)
            . ' AND shift_date <= ' . bp_sql_quote($toDate)
    );

    $rowsByDate = [];
    foreach ($rows as $row) {
        $shiftDate = bp_date_ymd(bp_att_attendance_view_value($row, $columnMap['shift_date'] ?? null));
        if ($shiftDate === null) {
            continue;
        }

        $candidate = [
            'shift_date' => $shiftDate,
            'attendance_status' => bp_att_attendance_view_value($row, $columnMap['attendance_status'] ?? null),
            'entry_punch' => bp_att_attendance_view_value($row, $columnMap['entry_punch'] ?? null),
            'exit_punch' => bp_att_attendance_view_value($row, $columnMap['exit_punch'] ?? null),
            'worked_hours' => bp_att_attendance_view_value($row, $columnMap['worked_hours'] ?? null),
            '_raw' => $row,
        ];

        if (!isset($rowsByDate[$shiftDate]) ||
            bp_attendance_row_score($candidate) >= bp_attendance_row_score($rowsByDate[$shiftDate])) {
            $rowsByDate[$shiftDate] = $candidate;
        }
    }

    $approvalByDate = $employeeId !== ''
        ? bp_att_index_employee_approvals_by_date($employeeId, $fromDate, $toDate)
        : [];

    // Geo + photo for punches come from att_approval, not the shift view.
    $punchByDate = $employeeId !== ''
        ? bp_att_index_employee_punches_by_date($employeeId, $fromDate, $toDate)
        : [];
    $recognizedByDate = $employeeId !== ''
        ? bp_att_fetch_recognized_records_by_date($employeeId, $fromDate, $toDate)
        : [];

    $items = [];
    foreach ($rowsByDate as $shiftDate => $row) {
        $raw = is_array($row['_raw'] ?? null) ? $row['_raw'] : [];
        $statusRaw = trim((string)($row['attendance_status'] ?? ''));
        $approval = is_array($approvalByDate[$shiftDate] ?? null) ? $approvalByDate[$shiftDate] : null;
        $punch = is_array($punchByDate[$shiftDate] ?? null) ? $punchByDate[$shiftDate] : null;
        $recognized = is_array($recognizedByDate[$shiftDate] ?? null) ? $recognizedByDate[$shiftDate] : null;
        $inPunch = is_array($punch['in'] ?? null) ? $punch['in'] : null;
        $outPunch = is_array($punch['out'] ?? null) ? $punch['out'] : null;
        $recognizedIn = is_array($recognized) ? $recognized : [];

        // Prefer the shift view's value; fall back to the att_approval punch
        // (which is where face/direct-punch geo + photo actually live).
        $viewInLat = bp_att_attendance_view_value($raw, $columnMap['in_latitude'] ?? null);
        $viewInLng = bp_att_attendance_view_value($raw, $columnMap['in_longitude'] ?? null);
        $viewInImg = bp_att_attendance_view_value($raw, $columnMap['in_image_path'] ?? null);
        $viewOutLat = bp_att_attendance_view_value($raw, $columnMap['out_latitude'] ?? null);
        $viewOutLng = bp_att_attendance_view_value($raw, $columnMap['out_longitude'] ?? null);
        $viewOutImg = bp_att_attendance_view_value($raw, $columnMap['out_image_path'] ?? null);

        $inLat = trim((string)$viewInLat) !== '' ? $viewInLat : trim((string)($recognizedIn['in_latitude'] ?? ($inPunch['latitude'] ?? '')));
        $inLng = trim((string)$viewInLng) !== '' ? $viewInLng : trim((string)($recognizedIn['in_longitude'] ?? ($inPunch['longitude'] ?? '')));
        $inImg = trim((string)$viewInImg) !== '' ? $viewInImg : (trim((string)($recognizedIn['in_image_path'] ?? '')) !== '' ? (string)$recognizedIn['in_image_path'] : bp_att_punch_image_path($inPunch));
        $outLat = trim((string)$viewOutLat) !== '' ? $viewOutLat : trim((string)($recognizedIn['out_latitude'] ?? ($outPunch['latitude'] ?? '')));
        $outLng = trim((string)$viewOutLng) !== '' ? $viewOutLng : trim((string)($recognizedIn['out_longitude'] ?? ($outPunch['longitude'] ?? '')));
        $outImg = trim((string)$viewOutImg) !== '' ? $viewOutImg : (trim((string)($recognizedIn['out_image_path'] ?? '')) !== '' ? (string)$recognizedIn['out_image_path'] : bp_att_punch_image_path($outPunch));

        $items[$shiftDate] = [
            'date' => $shiftDate,
            'legacy_date' => bp_att_attendance_legacy_date($shiftDate),
            'employee_id' => bp_att_attendance_view_value($raw, $columnMap['employee_id'] ?? null) ?: $employeeId,
            'staff_name' => bp_att_attendance_view_value($raw, $columnMap['staff_name'] ?? null),
            'planned_shift' => bp_att_attendance_view_value($raw, $columnMap['planned_shift'] ?? null),
            'attendance_status' => $statusRaw,
            'status_bucket' => bp_attendance_summary_bucket($statusRaw),
            'day_status' => bp_att_attendance_day_status($statusRaw),
            'entry_punch' => trim((string)($row['entry_punch'] ?? '')),
            'exit_punch' => trim((string)($row['exit_punch'] ?? '')),
            'in_time' => bp_att_attendance_time_only((string)($row['entry_punch'] ?? '')),
            'out_time' => bp_att_attendance_time_only((string)($row['exit_punch'] ?? '')),
            'total_worked_time' => trim((string)($row['worked_hours'] ?? '')),
            'shift_hours' => bp_att_attendance_view_value($raw, $columnMap['shift_hours'] ?? null),
            'in_latitude' => $inLat,
            'in_longitude' => $inLng,
            'in_image_path' => $inImg,
            'out_latitude' => $outLat,
            'out_longitude' => $outLng,
            'out_image_path' => $outImg,
            'in_site_name' => bp_att_attendance_view_value($raw, $columnMap['in_site_name'] ?? null),
            'out_site_name' => bp_att_attendance_view_value($raw, $columnMap['out_site_name'] ?? null),
            'approval_id' => trim((string)($approval['approval_id'] ?? '')),
            'approval_status' => trim((string)($approval['status_label'] ?? '')),
            'approval_status_code' => isset($approval['status']) ? (string)$approval['status'] : '',
            'approval_time' => trim((string)($approval['recognition_time'] ?? '')),
            'approval_records' => trim((string)($approval['records'] ?? '')),
        ];

        if ($recognized) {
            $items[$shiftDate] = bp_att_merge_recognized_attendance_item(
                $items[$shiftDate],
                $recognized
            );
        }
    }

    foreach ($approvalByDate as $shiftDate => $approval) {
        if (isset($items[$shiftDate])) {
            continue;
        }

        $isApproved = ((int)($approval['status'] ?? 0)) === 1;
        $items[$shiftDate] = [
            'date' => $shiftDate,
            'legacy_date' => bp_att_attendance_legacy_date($shiftDate),
            'employee_id' => $employeeId,
            'staff_name' => trim((string)($approval['employee_name'] ?? '')),
            'planned_shift' => '',
            'attendance_status' => '',
            'status_bucket' => '',
            'day_status' => '',
            'entry_punch' => $isApproved ? trim((string)($approval['records'] ?? '')) : '',
            'exit_punch' => '',
            'in_time' => $isApproved ? bp_att_attendance_time_only(trim((string)($approval['records'] ?? ''))) : '',
            'out_time' => '',
            'total_worked_time' => '',
            'shift_hours' => '',
            'in_latitude' => $isApproved ? trim((string)($approval['latitude'] ?? '')) : '',
            'in_longitude' => $isApproved ? trim((string)($approval['longitude'] ?? '')) : '',
            'in_image_path' => $isApproved ? bp_att_punch_image_path($approval) : '',
            'out_latitude' => '',
            'out_longitude' => '',
            'out_image_path' => '',
            'in_site_name' => '',
            'out_site_name' => '',
            'approval_id' => trim((string)($approval['approval_id'] ?? '')),
            'approval_status' => trim((string)($approval['status_label'] ?? '')),
            'approval_status_code' => isset($approval['status']) ? (string)$approval['status'] : '',
            'approval_time' => trim((string)($approval['recognition_time'] ?? '')),
            'approval_records' => trim((string)($approval['records'] ?? '')),
        ];
    }

    foreach ($recognizedByDate as $shiftDate => $recognized) {
        if (isset($items[$shiftDate])) {
            continue;
        }

        $items[$shiftDate] = bp_att_recognized_row_to_attendance_item(
            $shiftDate,
            $employeeId,
            $recognized
        );
    }

    ksort($items);
    return array_values($items);
}

function bp_att_fetch_recognized_records_by_date(
    string $employeeId,
    ?string $fromDate = null,
    ?string $toDate = null
): array {
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return [];
    }

    if ($fromDate === null || bp_date_ymd($fromDate) === null) {
        $fromDate = date('Y-m-01');
    }
    if ($toDate === null || bp_date_ymd($toDate) === null) {
        $toDate = date('Y-m-t');
    }
    if ($fromDate > $toDate) {
        $tmp = $fromDate;
        $fromDate = $toDate;
        $toDate = $tmp;
    }

    $candidateIds = bp_att_employee_id_candidates($employeeId);
    if (empty($candidateIds)) {
        return [];
    }

    $rows = [];
    $localTable = bp_att_recognized_table_name();
    $localColumns = bp_att_table_columns($localTable);
    $hasLocalRequiredColumns = !empty($localColumns);
    foreach (['emp_id', 'records', 'recognition_date'] as $column) {
        $hasLocalRequiredColumns = $hasLocalRequiredColumns && isset($localColumns[$column]);
    }

    if ($hasLocalRequiredColumns) {
        $selectedColumns = bp_att_recognized_selected_columns($localColumns);
        if (!empty($selectedColumns)) {
            $quotedIds = array_map(
                static fn(string $candidate): string => bp_sql_quote(strtoupper(trim($candidate))),
                $candidateIds
            );

            $where = 'UPPER(TRIM(emp_id)) IN (' . implode(',', $quotedIds) . ')'
                . ' AND recognition_date >= ' . bp_sql_quote($fromDate)
                . ' AND recognition_date <= ' . bp_sql_quote($toDate)
                . ' ORDER BY recognition_date ASC, records ASC'
                . (isset($localColumns['id']) ? ', id ASC' : '');

            $rows = array_merge($rows, bp_fetch_rows($localTable, $selectedColumns, $where));
        }
    }

    $rows = array_merge(
        $rows,
        bp_att_fetch_central_recognized_rows($candidateIds, $fromDate, $toDate)
    );
    if (empty($rows)) {
        return [];
    }

    $dedupedRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $records = trim((string)($row['records'] ?? ''));
        $rowEmployee = strtoupper(trim((string)($row['emp_id'] ?? $employeeId)));
        $key = $rowEmployee . '|' . $records;
        if ($records === '') {
            $key .= '|' . trim((string)($row['recognition_date'] ?? ''))
                . '|' . trim((string)($row['recognition_time'] ?? ''))
                . '|' . trim((string)($row['id'] ?? ''));
        }
        if (!isset($dedupedRows[$key])) {
            $dedupedRows[$key] = $row;
        }
    }

    $rows = array_values($dedupedRows);
    usort($rows, static function (array $a, array $b): int {
        $left = trim((string)($a['recognition_date'] ?? '')) . ' ' . trim((string)($a['records'] ?? ''));
        $right = trim((string)($b['recognition_date'] ?? '')) . ' ' . trim((string)($b['records'] ?? ''));
        return strcmp($left, $right);
    });

    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $shiftDate = bp_date_ymd((string)($row['recognition_date'] ?? ''));
        if ($shiftDate === null) {
            $shiftDate = bp_date_ymd((string)($row['records'] ?? ''));
        }
        if ($shiftDate === null) {
            continue;
        }

        $records = trim((string)($row['records'] ?? ''));
        $timeOnly = bp_att_attendance_time_only($records);
        if ($timeOnly === '') {
            $timeOnly = bp_att_attendance_time_only((string)($row['recognition_time'] ?? ''));
        }

        if (!isset($items[$shiftDate])) {
            $items[$shiftDate] = [
                'count' => 0,
                'staff_name' => trim((string)($row['name'] ?? '')),
                'entry_punch' => $records,
                'exit_punch' => '',
                'in_time' => $timeOnly,
                'out_time' => '',
                'in_latitude' => trim((string)($row['latitude'] ?? '')),
                'in_longitude' => trim((string)($row['longitude'] ?? '')),
                'in_image_path' => bp_att_punch_image_path($row),
                'out_latitude' => '',
                'out_longitude' => '',
                'out_image_path' => '',
                'first_records' => $records,
                'last_records' => $records,
            ];
        }

        $items[$shiftDate]['count']++;
        if (trim((string)($items[$shiftDate]['staff_name'] ?? '')) === '') {
            $items[$shiftDate]['staff_name'] = trim((string)($row['name'] ?? ''));
        }
        $items[$shiftDate]['last_records'] = $records;

        if ((int)($items[$shiftDate]['count'] ?? 0) > 1) {
            $items[$shiftDate]['exit_punch'] = $records;
            $items[$shiftDate]['out_time'] = $timeOnly;
            $items[$shiftDate]['out_latitude'] = trim((string)($row['latitude'] ?? ''));
            $items[$shiftDate]['out_longitude'] = trim((string)($row['longitude'] ?? ''));
            $items[$shiftDate]['out_image_path'] = bp_att_punch_image_path($row);
        }
    }

    foreach ($items as $shiftDate => $item) {
        $workedTime = '';
        if ((int)($item['count'] ?? 0) > 1) {
            $workedTime = bp_att_worked_time_between(
                (string)($item['first_records'] ?? ''),
                (string)($item['last_records'] ?? '')
            );
        }

        $items[$shiftDate] = [
            'date' => $shiftDate,
            'legacy_date' => bp_att_attendance_legacy_date($shiftDate),
            'staff_name' => (string)($item['staff_name'] ?? ''),
            'attendance_status' => 'Present',
            'status_bucket' => bp_attendance_summary_bucket('Present'),
            'day_status' => bp_att_attendance_day_status('Present'),
            'entry_punch' => (string)($item['entry_punch'] ?? ''),
            'exit_punch' => (string)($item['exit_punch'] ?? ''),
            'in_time' => (string)($item['in_time'] ?? ''),
            'out_time' => (string)($item['out_time'] ?? ''),
            'total_worked_time' => $workedTime,
            'in_latitude' => (string)($item['in_latitude'] ?? ''),
            'in_longitude' => (string)($item['in_longitude'] ?? ''),
            'in_image_path' => (string)($item['in_image_path'] ?? ''),
            'out_latitude' => (string)($item['out_latitude'] ?? ''),
            'out_longitude' => (string)($item['out_longitude'] ?? ''),
            'out_image_path' => (string)($item['out_image_path'] ?? ''),
            'in_site_name' => '',
            'out_site_name' => '',
        ];
    }

    return $items;
}

function bp_att_recognized_selected_columns(array $columns): array
{
    return array_values(array_filter([
        'id',
        'emp_id',
        'name',
        'records',
        'captured_image_path',
        'similarity_score',
        'latitude',
        'longitude',
        'recognition_date',
        'recognition_time',
        'work_location',
    ], static function (string $column) use ($columns): bool {
        return isset($columns[$column]);
    }));
}

function bp_att_fetch_central_recognized_rows(
    array $candidateIds,
    string $fromDate,
    string $toDate
): array {
    $table = bp_att_central_recognized_table_name();
    $columns = bp_att_blueplanet_table_columns($table);
    foreach (['emp_id', 'records', 'recognition_date'] as $column) {
        if (!isset($columns[$column])) {
            return [];
        }
    }

    $pdo = bp_att_blueplanet_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }

    $selectedColumns = bp_att_recognized_selected_columns($columns);
    if (empty($selectedColumns)) {
        return [];
    }

    $placeholders = [];
    $params = [
        ':from_date' => $fromDate,
        ':to_date' => $toDate,
    ];
    foreach ($candidateIds as $index => $candidate) {
        $placeholder = ':emp_id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = strtoupper(trim((string)$candidate));
    }
    if (empty($placeholders)) {
        return [];
    }

    $sql = 'SELECT ' . implode(', ', $selectedColumns)
        . ' FROM `' . $table . '`'
        . ' WHERE UPPER(TRIM(emp_id)) IN (' . implode(', ', $placeholders) . ')'
        . ' AND recognition_date >= :from_date'
        . ' AND recognition_date <= :to_date'
        . ' ORDER BY recognition_date ASC, records ASC'
        . (isset($columns['id']) ? ', id ASC' : '');

    try {
        $statement = $pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $statement->bindValue($placeholder, $value);
        }
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('bp_mobile_app central recognized attendance lookup failed: ' . bp_att_error_text($e));
        return [];
    }

    return is_array($rows) ? $rows : [];
}

function bp_att_worked_time_between(string $start, string $end): string
{
    $start = trim($start);
    $end = trim($end);
    if ($start === '' || $end === '') {
        return '';
    }

    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($startTs === false || $endTs === false || $endTs <= $startTs) {
        return '';
    }

    $seconds = $endTs - $startTs;
    $hours = (int)floor($seconds / 3600);
    $minutes = (int)floor(($seconds % 3600) / 60);
    $remainingSeconds = (int)($seconds % 60);

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
}

function bp_att_merge_recognized_attendance_item(array $item, array $recognized): array
{
    $hasRecognizedPunch = trim((string)($recognized['in_time'] ?? '')) !== ''
        || trim((string)($recognized['out_time'] ?? '')) !== ''
        || trim((string)($recognized['entry_punch'] ?? '')) !== ''
        || trim((string)($recognized['exit_punch'] ?? '')) !== '';

    foreach ([
        'entry_punch',
        'exit_punch',
        'in_time',
        'out_time',
        'total_worked_time',
        'in_latitude',
        'in_longitude',
        'in_image_path',
        'out_latitude',
        'out_longitude',
        'out_image_path',
        'in_site_name',
        'out_site_name',
    ] as $field) {
        if (trim((string)($item[$field] ?? '')) === '') {
            $item[$field] = (string)($recognized[$field] ?? '');
        }
    }

    $status = trim((string)($item['attendance_status'] ?? ''));
    $useRecognizedStatus = $status === ''
        || ($hasRecognizedPunch && bp_attendance_is_absent_status($status));
    if ($useRecognizedStatus) {
        $item['attendance_status'] = (string)($recognized['attendance_status'] ?? 'Present');
    }

    $statusBucket = trim((string)($item['status_bucket'] ?? ''));
    if ($statusBucket === '' || $useRecognizedStatus) {
        $item['status_bucket'] = (string)($recognized['status_bucket'] ?? bp_attendance_summary_bucket('Present'));
    }

    $dayStatus = trim((string)($item['day_status'] ?? ''));
    if ($dayStatus === '' || $useRecognizedStatus || ($hasRecognizedPunch && bp_attendance_is_absent_status($dayStatus))) {
        $item['day_status'] = (string)($recognized['day_status'] ?? bp_att_attendance_day_status('Present'));
    }

    if (trim((string)($item['staff_name'] ?? '')) === '') {
        $item['staff_name'] = (string)($recognized['staff_name'] ?? '');
    }

    return $item;
}

function bp_att_recognized_row_to_attendance_item(
    string $shiftDate,
    string $employeeId,
    array $recognized
): array {
    return [
        'date' => $shiftDate,
        'legacy_date' => bp_att_attendance_legacy_date($shiftDate),
        'employee_id' => $employeeId,
        'staff_name' => (string)($recognized['staff_name'] ?? ''),
        'planned_shift' => '',
        'attendance_status' => (string)($recognized['attendance_status'] ?? 'Present'),
        'status_bucket' => (string)($recognized['status_bucket'] ?? ''),
        'day_status' => (string)($recognized['day_status'] ?? ''),
        'entry_punch' => (string)($recognized['entry_punch'] ?? ''),
        'exit_punch' => (string)($recognized['exit_punch'] ?? ''),
        'in_time' => (string)($recognized['in_time'] ?? ''),
        'out_time' => (string)($recognized['out_time'] ?? ''),
        'total_worked_time' => (string)($recognized['total_worked_time'] ?? ''),
        'shift_hours' => '',
        'in_latitude' => (string)($recognized['in_latitude'] ?? ''),
        'in_longitude' => (string)($recognized['in_longitude'] ?? ''),
        'in_image_path' => (string)($recognized['in_image_path'] ?? ''),
        'out_latitude' => (string)($recognized['out_latitude'] ?? ''),
        'out_longitude' => (string)($recognized['out_longitude'] ?? ''),
        'out_image_path' => (string)($recognized['out_image_path'] ?? ''),
        'in_site_name' => (string)($recognized['in_site_name'] ?? ''),
        'out_site_name' => (string)($recognized['out_site_name'] ?? ''),
        'approval_id' => '',
        'approval_status' => '',
        'approval_status_code' => '',
        'approval_time' => '',
        'approval_records' => '',
    ];
}

function bp_att_fetch_approval_row(string $approvalId): ?array
{
    global $pdo;

    $approvalId = trim($approvalId);
    if ($approvalId === '') {
        return null;
    }

    $result = $pdo->query("
        SELECT *
        FROM att_approval
        WHERE id = :id
          AND is_delete = 0
        LIMIT 1
    ", ['id' => $approvalId]);
    $row = bp_att_query_row($result);
    if (!$row) {
        return null;
    }

    return bp_att_enrich_approval_row($row);
}

function bp_att_find_pending_approval_for_employee(
    string $employeeId,
    ?string $approvalId = null,
    ?string $records = null,
    ?string $recognitionDate = null
): ?array {
    global $pdo;

    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return null;
    }

    $approvalId = trim((string)$approvalId);
    if ($approvalId !== '') {
        $row = bp_att_fetch_approval_row($approvalId);
        if (!$row) {
            return null;
        }

        if (trim((string)($row['emp_id'] ?? '')) !== $employeeId) {
            return null;
        }

        return ((int)($row['status'] ?? 0) === 0) ? $row : null;
    }

    $records = trim((string)$records);
    $recognitionDate = bp_date_ymd((string)$recognitionDate) ?? date('Y-m-d');

    $queries = [];
    if ($records !== '') {
        $queries[] = [
            'sql' => "
                SELECT *
                FROM att_approval
                WHERE emp_id = :emp_id
                  AND records = :records
                  AND status = 0
                  AND is_delete = 0
                ORDER BY id DESC
                LIMIT 1
            ",
            'params' => [
                'emp_id' => $employeeId,
                'records' => $records,
            ],
        ];
    }

    $queries[] = [
        'sql' => "
            SELECT *
            FROM att_approval
            WHERE emp_id = :emp_id
              AND recognition_date = :recognition_date
              AND status = 0
              AND is_delete = 0
            ORDER BY records DESC, id DESC
            LIMIT 1
        ",
        'params' => [
            'emp_id' => $employeeId,
            'recognition_date' => $recognitionDate,
        ],
    ];

    $queries[] = [
        'sql' => "
            SELECT *
            FROM att_approval
            WHERE emp_id = :emp_id
              AND status = 0
              AND is_delete = 0
            ORDER BY records DESC, id DESC
            LIMIT 1
        ",
        'params' => [
            'emp_id' => $employeeId,
        ],
    ];

    foreach ($queries as $query) {
        $result = $pdo->query($query['sql'], $query['params']);
        $row = bp_att_query_row($result);
        if ($row) {
            return bp_att_enrich_approval_row($row);
        }
    }

    return null;
}

function bp_att_has_pending_notification(string $approvalId): bool
{
    global $pdo;

    if (!bp_att_notifications_available()) {
        return false;
    }

    $approvalId = trim($approvalId);
    if ($approvalId === '') {
        return false;
    }

    $result = $pdo->query("
        SELECT unique_id
        FROM " . bp_att_notification_table() . "
        WHERE approval_id = :approval_id
          AND is_delete = 0
          AND is_active = 1
          AND deep_link LIKE '/attendance-approval%'
        LIMIT 1
    ", ['approval_id' => $approvalId]);

    return bp_att_query_row($result) !== null;
}

function bp_att_notification_table(): string
{
    return 'bp_attendance_notifications';
}

function bp_att_notifications_available(): bool
{
    return !empty(bp_att_table_columns(bp_att_notification_table()));
}

function bp_att_insert_notification_result(
    string $toStaffId,
    string $fromStaffId,
    string $approvalId,
    string $title,
    string $message,
    string $deepLink
): array {
    if (!bp_att_notifications_available()) {
        return [
            'status' => false,
            'error' => 'Attendance notifications table is missing',
        ];
    }

    $now = bp_now();
    $insert = bp_att_insert_row_raw(bp_att_notification_table(), [
        'unique_id' => bp_unique_id(),
        'to_staff_id' => trim($toStaffId),
        'from_staff_id' => trim($fromStaffId),
        'approval_id' => trim($approvalId),
        'title' => trim($title),
        'message' => trim($message),
        'deep_link' => trim($deepLink),
        'is_read' => 0,
        'created' => $now,
        'updated' => $now,
        'is_active' => 1,
        'is_delete' => 0,
    ]);

    return [
        'status' => (bool)($insert->status ?? false),
        'error' => bp_att_error_text($insert->error ?? ''),
    ];
}

function bp_att_normalize_staff_ids(array $staffIds): array
{
    $normalized = [];
    foreach ($staffIds as $staffId) {
        $staffId = trim((string)$staffId);
        if ($staffId === '') {
            continue;
        }

        $normalized[strtoupper($staffId)] = $staffId;
    }

    return array_values($normalized);
}

function bp_att_hr_recipient_ids(): array
{
    global $pdo;

    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $rows = bp_att_query_rows(
        $pdo->query(
            "SELECT employee_id, designation_unique_id, department
             FROM staff_test
             WHERE is_delete = 0
               AND is_active = 1"
        )
    );

    if (empty($rows)) {
        $cache = [];
        return $cache;
    }

    $designationMap = [];
    $designationRows = bp_att_query_rows(
        $pdo->query(
            "SELECT unique_id, designation
             FROM designation_creation
             WHERE is_delete = 0
               AND is_active = 1"
        )
    );
    foreach ($designationRows as $designationRow) {
        $uniqueId = bp_att_safe_text($designationRow['unique_id'] ?? '');
        if ($uniqueId === '') {
            continue;
        }

        $designationMap[$uniqueId] = bp_att_safe_text($designationRow['designation'] ?? '');
    }

    $departmentMap = [];
    $departmentRows = bp_att_query_rows(
        $pdo->query(
            "SELECT unique_id, department
             FROM department_creation
             WHERE is_delete = 0
               AND is_active = 1"
        )
    );
    foreach ($departmentRows as $departmentRow) {
        $uniqueId = bp_att_safe_text($departmentRow['unique_id'] ?? '');
        if ($uniqueId === '') {
            continue;
        }

        $departmentMap[$uniqueId] = bp_att_safe_text($departmentRow['department'] ?? '');
    }

    $userTableColumns = bp_att_table_columns('user');
    $userOrderBy = isset($userTableColumns['s_no'])
        ? 's_no DESC'
        : (isset($userTableColumns['unique_id']) ? 'unique_id DESC' : '1');
    $userTypeByEmployee = [];
    if (isset($userTableColumns['staff_unique_id'])) {
        $userTypeMap = [];
        $userTypeTableColumns = bp_att_table_columns('user_type');
        if (isset($userTableColumns['user_type']) && !empty($userTypeTableColumns)) {
            $userTypeRows = bp_att_query_rows(
                $pdo->query(
                    "SELECT unique_id, user_type
                     FROM user_type
                     WHERE is_delete = 0
                       AND is_active = 1"
                )
            );
            foreach ($userTypeRows as $userTypeRow) {
                $uniqueId = bp_att_safe_text($userTypeRow['unique_id'] ?? '');
                if ($uniqueId === '') {
                    continue;
                }

                $userTypeMap[$uniqueId] = bp_att_safe_text($userTypeRow['user_type'] ?? '');
            }
        }

        $userSelectColumns = ['staff_unique_id'];
        if (isset($userTableColumns['user_type'])) {
            $userSelectColumns[] = 'user_type';
        }

        $latestUserRows = bp_att_query_rows(
            $pdo->query(
                "SELECT " . implode(', ', $userSelectColumns) . "
                 FROM user
                 WHERE is_delete = 0
                   AND is_active = 1
                 ORDER BY {$userOrderBy}"
            )
        );
        foreach ($latestUserRows as $userRow) {
            $staffUniqueId = strtoupper(bp_att_safe_text($userRow['staff_unique_id'] ?? ''));
            if ($staffUniqueId === '' || isset($userTypeByEmployee[$staffUniqueId])) {
                continue;
            }

            $userTypeId = isset($userTableColumns['user_type'])
                ? bp_att_safe_text($userRow['user_type'] ?? '')
                : '';
            $userTypeByEmployee[$staffUniqueId] = [
                'id' => $userTypeId,
                'name' => $userTypeId !== ''
                    ? ($userTypeMap[$userTypeId] ?? $userTypeId)
                    : '',
            ];
        }
    }

    $recipientIds = [];
    foreach ($rows as $row) {
        $employeeId = bp_att_safe_text($row['employee_id'] ?? '');
        if ($employeeId === '') {
            continue;
        }

        $designationId = bp_att_safe_text($row['designation_unique_id'] ?? '');
        $departmentId = bp_att_safe_text($row['department'] ?? '');
        $userTypeMeta = $userTypeByEmployee[strtoupper($employeeId)] ?? ['id' => '', 'name' => ''];
        $designationName = $designationMap[$designationId] ?? $designationId;
        $roleTokens = [
            $designationName,
            $departmentMap[$departmentId] ?? $departmentId,
            $userTypeMeta['name'] ?? '',
        ];

        $isHr = bp_att_role_indicates_hr($roleTokens);
        if (!$isHr && function_exists('attendance_is_hr_admin')) {
            $isHr = (bool)attendance_is_hr_admin(
                (string)($userTypeMeta['id'] ?? ''),
                $designationName,
                $employeeId
            );
        }

        if ($isHr) {
            $recipientIds[] = $employeeId;
        }
    }

    $cache = bp_att_normalize_staff_ids($recipientIds);
    return $cache;
}

function bp_att_build_push_payload(
    string $deepLink,
    array $pushData = [],
    string $approvalId = '',
    string $staffId = ''
): array {
    $payload = $pushData;

    if (!isset($payload['route'])) {
        $payload['route'] = bp_notification_route_from_deep_link($deepLink);
    }
    if (!isset($payload['deepLink'])) {
        $payload['deepLink'] = $deepLink;
    }
    if ($approvalId !== '' && !isset($payload['approvalId'])) {
        $payload['approvalId'] = $approvalId;
    }
    if ($staffId !== '' && !isset($payload['employeeId'])) {
        $payload['employeeId'] = $staffId;
    }

    return $payload;
}

function bp_att_deliver_notification_result(
    string $toStaffId,
    string $fromStaffId,
    string $approvalId,
    string $title,
    string $message,
    string $deepLink,
    array $pushData = []
): array {
    $notification = bp_att_insert_notification_result(
        $toStaffId,
        $fromStaffId,
        $approvalId,
        $title,
        $message,
        $deepLink
    );

    $push = bp_send_push_notification_to_staff(
        $toStaffId,
        $title,
        $message,
        bp_att_build_push_payload($deepLink, $pushData, $approvalId, $toStaffId)
    );

    return [
        'notification' => $notification,
        'push' => $push,
    ];
}

function bp_att_deliver_notification_results(
    array $toStaffIds,
    string $fromStaffId,
    string $approvalId,
    string $title,
    string $message,
    string $deepLink,
    array $pushData = []
): array {
    $recipientIds = bp_att_normalize_staff_ids($toStaffIds);
    if (empty($recipientIds)) {
        return [
            'attempted' => false,
            'recipient_count' => 0,
            'notification_saved_count' => 0,
            'push_attempted_count' => 0,
            'push_sent_count' => 0,
            'results' => [],
            'error' => 'No HR recipients found',
        ];
    }

    $results = [];
    $notificationSavedCount = 0;
    $pushAttemptedCount = 0;
    $pushSentCount = 0;
    $errors = [];

    foreach ($recipientIds as $recipientId) {
        $result = bp_att_deliver_notification_result(
            $recipientId,
            $fromStaffId,
            $approvalId,
            $title,
            $message,
            $deepLink,
            $pushData
        );
        $results[$recipientId] = $result;

        if (!empty($result['notification']['status'])) {
            $notificationSavedCount++;
        } else {
            $notificationError = bp_att_error_text($result['notification']['error'] ?? '');
            if ($notificationError !== '') {
                $errors[] = $notificationError;
            }
        }

        if (!empty($result['push']['attempted'])) {
            $pushAttemptedCount++;
        }
        if (!empty($result['push']['sent'])) {
            $pushSentCount++;
        } else {
            $pushError = bp_att_error_text($result['push']['error'] ?? '');
            if ($pushError !== '') {
                $errors[] = $pushError;
            }
        }
    }

    return [
        'attempted' => true,
        'recipient_count' => count($recipientIds),
        'notification_saved_count' => $notificationSavedCount,
        'push_attempted_count' => $pushAttemptedCount,
        'push_sent_count' => $pushSentCount,
        'results' => $results,
        'error' => empty($errors) ? null : implode(' | ', array_values(array_unique($errors))),
    ];
}

function bp_att_push_notification_results(
    array $toStaffIds,
    string $title,
    string $message,
    array $pushData = []
): array {
    $recipientIds = bp_att_normalize_staff_ids($toStaffIds);
    if (empty($recipientIds)) {
        return [
            'attempted' => false,
            'recipient_count' => 0,
            'push_attempted_count' => 0,
            'push_sent_count' => 0,
            'results' => [],
            'error' => 'No HR recipients found',
        ];
    }

    $results = [];
    $pushAttemptedCount = 0;
    $pushSentCount = 0;
    $errors = [];

    foreach ($recipientIds as $recipientId) {
        $result = bp_send_push_notification_to_staff(
            $recipientId,
            $title,
            $message,
            $pushData
        );
        $results[$recipientId] = $result;

        if (!empty($result['attempted'])) {
            $pushAttemptedCount++;
        }
        if (!empty($result['sent'])) {
            $pushSentCount++;
        } else {
            $pushError = bp_att_error_text($result['error'] ?? '');
            if ($pushError !== '') {
                $errors[] = $pushError;
            }
        }
    }

    return [
        'attempted' => true,
        'recipient_count' => count($recipientIds),
        'push_attempted_count' => $pushAttemptedCount,
        'push_sent_count' => $pushSentCount,
        'results' => $results,
        'error' => empty($errors) ? null : implode(' | ', array_values(array_unique($errors))),
    ];
}

function bp_att_pending_approval_delivery(array $approvalRow, string $fromStaffId = ''): array
{
    $approvalId = trim((string)($approvalRow['id'] ?? ''));
    $employeeId = trim((string)($approvalRow['emp_id'] ?? ''));
    $employeeName = trim((string)($approvalRow['name'] ?? ''));
    $recognitionDate = trim((string)($approvalRow['recognition_date'] ?? ''));
    $recognitionTime = trim((string)($approvalRow['recognition_time'] ?? ''));

    if ($approvalId === '' || $employeeId === '') {
        return [
            'status' => false,
            'error' => 'Pending approval row is missing approval ID or employee ID',
        ];
    }

    $message = 'New outside geofencing attendance';
    if ($employeeName !== '') {
        $message .= ' from ' . $employeeName;
    }
    if ($recognitionDate !== '' || $recognitionTime !== '') {
        $message .= ' • ' . trim($recognitionDate . ' ' . $recognitionTime);
    }

    return [
        'status' => true,
        'error' => '',
        'approval_id' => $approvalId,
        'employee_id' => $employeeId,
        'recipient_ids' => bp_att_hr_recipient_ids(),
        'from_staff_id' => $fromStaffId !== '' ? $fromStaffId : $employeeId,
        'title' => 'Attendance Approval Pending',
        'message' => $message,
        'deep_link' => '/attendance-approval',
        'push_data' => [
            'route' => '/attendance-approval',
            'approvalId' => $approvalId,
            'employeeId' => $employeeId,
            'employeeName' => $employeeName,
            'type' => 'attendance_approval',
        ],
    ];
}

function bp_att_notify_pending_approval(array $approvalRow, string $fromStaffId = ''): array
{
    $delivery = bp_att_pending_approval_delivery($approvalRow, $fromStaffId);
    if (empty($delivery['status'])) {
        return [
            'attempted' => false,
            'already_notified' => false,
            'recipient_count' => 0,
            'notification_saved_count' => 0,
            'push_attempted_count' => 0,
            'push_sent_count' => 0,
            'results' => [],
            'error' => bp_att_error_text($delivery['error'] ?? 'Pending approval delivery setup failed'),
        ];
    }

    $approvalId = (string)$delivery['approval_id'];
    $employeeId = (string)$delivery['employee_id'];
    $recipientIds = (array)($delivery['recipient_ids'] ?? []);
    $title = (string)$delivery['title'];
    $message = (string)$delivery['message'];
    $deepLink = (string)$delivery['deep_link'];
    $pushData = (array)($delivery['push_data'] ?? []);
    $resolvedFromStaffId = (string)$delivery['from_staff_id'];

    if (bp_att_has_pending_notification($approvalId)) {
        $pushOnly = bp_att_push_notification_results(
            $recipientIds,
            $title,
            $message,
            bp_att_build_push_payload($deepLink, $pushData, $approvalId, $employeeId)
        );

        return [
            'attempted' => (bool)($pushOnly['attempted'] ?? false),
            'already_notified' => true,
            'recipient_count' => (int)($pushOnly['recipient_count'] ?? 0),
            'notification_saved_count' => 0,
            'push_attempted_count' => (int)($pushOnly['push_attempted_count'] ?? 0),
            'push_sent_count' => (int)($pushOnly['push_sent_count'] ?? 0),
            'results' => (array)($pushOnly['results'] ?? []),
            'error' => $pushOnly['error'] ?? null,
        ];
    }

    $result = bp_att_deliver_notification_results(
        $recipientIds,
        $resolvedFromStaffId,
        $approvalId,
        $title,
        $message,
        $deepLink,
        $pushData
    );

    $result['already_notified'] = false;
    return $result;
}

function bp_att_fetch_notifications(string $staffId, bool $unreadOnly = true, int $limit = 30): array
{
    if (!bp_att_notifications_available()) {
        return [];
    }

    $staffId = trim($staffId);
    if ($staffId === '') {
        return [];
    }

    $where = 'is_delete = 0 AND is_active = 1 AND to_staff_id = ' . bp_sql_quote($staffId);
    if ($unreadOnly) {
        $where .= ' AND is_read = 0';
    }

    $rows = bp_fetch_rows(
        bp_att_notification_table(),
        [
            'unique_id',
            'to_staff_id',
            'from_staff_id',
            'approval_id',
            'title',
            'message',
            'deep_link',
            'is_read',
            'created',
        ],
        $where . ' ORDER BY created DESC LIMIT ' . max(1, min($limit, 100))
    );

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'unique_id' => (string)($row['unique_id'] ?? ''),
            'to_staff_id' => (string)($row['to_staff_id'] ?? ''),
            'from_staff_id' => (string)($row['from_staff_id'] ?? ''),
            'approval_id' => (string)($row['approval_id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'deep_link' => (string)($row['deep_link'] ?? '/attendance-summary'),
            'is_read' => ((int)($row['is_read'] ?? 0)) === 1,
            'created' => (string)($row['created'] ?? ''),
        ];
    }

    return $items;
}

function bp_att_notifications_unread_count(string $staffId): int
{
    if (!bp_att_notifications_available()) {
        return 0;
    }

    $staffId = trim($staffId);
    if ($staffId === '') {
        return 0;
    }

    $rows = bp_fetch_rows(
        bp_att_notification_table(),
        ['COUNT(unique_id) AS c'],
        'is_delete = 0 AND is_active = 1 AND is_read = 0 AND to_staff_id = ' . bp_sql_quote($staffId)
    );

    return (int)($rows[0]['c'] ?? 0);
}

function bp_att_mark_notifications_read(string $staffId, array $notificationIds): int
{
    if (!bp_att_notifications_available()) {
        return 0;
    }

    $staffId = trim($staffId);
    if ($staffId === '') {
        return 0;
    }

    $updated = 0;
    foreach ($notificationIds as $id) {
        $id = trim((string)$id);
        if ($id === '') {
            continue;
        }

        $res = bp_update_row(
            bp_att_notification_table(),
            bp_att_filter_columns(bp_att_notification_table(), [
                'is_read' => 1,
                'updated' => bp_now(),
            ]),
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
