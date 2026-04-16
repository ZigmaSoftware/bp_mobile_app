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

    $isHrUser = bp_att_role_indicates_hr([
        $designationName,
        $userTypeName,
        $departmentName,
        $session['designation_type'] ?? '',
    ]);
    if (!$isHrUser && function_exists('attendance_is_hr_admin')) {
        $isHrUser = (bool)attendance_is_hr_admin($userTypeId, $designationName, $employeeId);
    }
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
        $scope = function_exists('attendance_resolve_scope')
            ? attendance_resolve_scope($session)
            : [
                'scope_type' => 'self',
                'employee_ids' => [$employeeId],
                'project_ids' => [],
                'department_filter' => null,
                'self_employee_id' => $employeeId,
            ];
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
    $where = "a.is_delete = 0 AND a.emp_id = " . bp_sql_quote($employeeId);
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

    $items = [];
    foreach ($rowsByDate as $shiftDate => $row) {
        $raw = is_array($row['_raw'] ?? null) ? $row['_raw'] : [];
        $statusRaw = trim((string)($row['attendance_status'] ?? ''));
        $approval = is_array($approvalByDate[$shiftDate] ?? null) ? $approvalByDate[$shiftDate] : null;

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
            'in_latitude' => bp_att_attendance_view_value($raw, $columnMap['in_latitude'] ?? null),
            'in_longitude' => bp_att_attendance_view_value($raw, $columnMap['in_longitude'] ?? null),
            'in_image_path' => bp_att_attendance_view_value($raw, $columnMap['in_image_path'] ?? null),
            'out_latitude' => bp_att_attendance_view_value($raw, $columnMap['out_latitude'] ?? null),
            'out_longitude' => bp_att_attendance_view_value($raw, $columnMap['out_longitude'] ?? null),
            'out_image_path' => bp_att_attendance_view_value($raw, $columnMap['out_image_path'] ?? null),
            'in_site_name' => bp_att_attendance_view_value($raw, $columnMap['in_site_name'] ?? null),
            'out_site_name' => bp_att_attendance_view_value($raw, $columnMap['out_site_name'] ?? null),
            'approval_id' => trim((string)($approval['approval_id'] ?? '')),
            'approval_status' => trim((string)($approval['status_label'] ?? '')),
            'approval_status_code' => isset($approval['status']) ? (string)$approval['status'] : '',
            'approval_time' => trim((string)($approval['recognition_time'] ?? '')),
            'approval_records' => trim((string)($approval['records'] ?? '')),
        ];
    }

    foreach ($approvalByDate as $shiftDate => $approval) {
        if (isset($items[$shiftDate])) {
            continue;
        }

        $items[$shiftDate] = [
            'date' => $shiftDate,
            'legacy_date' => bp_att_attendance_legacy_date($shiftDate),
            'employee_id' => $employeeId,
            'staff_name' => trim((string)($approval['employee_name'] ?? '')),
            'planned_shift' => '',
            'attendance_status' => '',
            'status_bucket' => '',
            'day_status' => '',
            'entry_punch' => '',
            'exit_punch' => '',
            'in_time' => '',
            'out_time' => '',
            'total_worked_time' => '',
            'shift_hours' => '',
            'in_latitude' => '',
            'in_longitude' => '',
            'in_image_path' => '',
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

    ksort($items);
    return array_values($items);
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
