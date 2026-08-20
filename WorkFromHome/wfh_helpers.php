<?php
declare(strict_types=1);

require_once __DIR__ . '/../Leave/leave_helpers.php';

const BP_WFH_TABLE = 'work_from_home';
const BP_WFH_NOTIFICATION_TABLE = 'bp_wfh_notifications';

function bp_wfh_status_label(int $status): string
{
    switch ($status) {
        case 0:
            return 'Pending';
        case 1:
            return 'Approved';
        case 2:
            return 'Rejected';
        case 3:
            return 'Cancelled';
        default:
            return 'Unknown';
    }
}

function bp_wfh_normalize_text(string $value): string
{
    $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? ''));
    return preg_replace('/\s+/', ' ', $normalized) ?? '';
}

function bp_wfh_matches_bp_india(string $value, bool $allowEntityNameFallback = true): bool
{
    $normalized = bp_wfh_normalize_text($value);
    $compact = str_replace(' ', '', $normalized);
    if ($normalized === '') {
        return false;
    }

    // Contiguous spellings ("BP India", "Blue Planet India").
    if (strpos($normalized, 'bp india') !== false
        || strpos($normalized, 'blue planet india') !== false
        || strpos($compact, 'bpindia') !== false
        || strpos($compact, 'blueplanetindia') !== false) {
        return true;
    }

    if (!$allowEntityNameFallback) {
        return false;
    }

    // The registered entity names put words between the brand and the country
    // ("Blue Planet Environmental Solutions India Private Limited"), so a
    // contiguous match alone misses every real BP India company. Require the
    // brand and "india" as separate words instead.
    //
    // Only safe to apply to the company's registered name. Sister entities
    // like Blue Planet Biofuels are legally separate from BP India but are
    // frequently sited on projects physically located in India ("Pune,
    // India"), so running this same fallback against work_location/project
    // strings previously misclassified their staff as BP India. "india" is
    // matched as a whole word so "indian"/"indiana" do not qualify, and the
    // brand requirement keeps unrelated Indian companies out.
    $hasBrand = strpos($normalized, 'blue planet') !== false
        || preg_match('/\bbp\b/', $normalized) === 1;
    $hasIndia = preg_match('/\bindia\b/', $normalized) === 1;

    return $hasBrand && $hasIndia;
}

function bp_wfh_employee_id_is_bp_india(string $employeeId): bool
{
    $normalized = strtoupper(trim($employeeId));
    return preg_match('/^BPIN[0-9A-Z_-]*$/', $normalized) === 1;
}

function bp_wfh_resolve_names(string $table, array $ids, array $candidateColumns): array
{
    $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
    if (empty($ids)) {
        return [];
    }

    $columns = bp_table_columns($table);
    if (empty($columns) || !isset($columns['unique_id'])) {
        return [];
    }

    $select = ['unique_id'];
    foreach ($candidateColumns as $column) {
        if (isset($columns[$column])) {
            $select[] = $column;
        }
    }
    if (count($select) === 1) {
        return [];
    }

    $quoted = array_map('bp_sql_quote', $ids);
    $where = 'unique_id IN (' . implode(', ', $quoted) . ')';
    if (isset($columns['is_delete'])) {
        $where .= ' AND is_delete = 0';
    }

    $rows = bp_fetch_rows($table, $select, $where);
    $out = [];
    foreach ($rows as $row) {
        foreach ($candidateColumns as $column) {
            $name = trim((string)($row[$column] ?? ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }
    }
    return $out;
}

/** Folder name the ERP registers the WFH module under (user_screen.folder_name). */
const BP_WFH_SCREEN_FOLDER = 'work_from_home';

/**
 * Whether this staff member's user_type has been granted the WFH screen on the
 * web User Permission screen (user_screen_permission).
 *
 * This is the single source of truth for WFH eligibility. Access is managed
 * entirely from the web ERP - ticking/unticking WFH for a role there takes
 * effect in the mobile app with no redeploy. Nothing about company names,
 * employee-id prefixes or work locations is inferred here; those heuristics
 * previously both denied real BP India staff and risked admitting sister
 * entities.
 *
 * See bp_staff_has_screen_permission() in Leave/leave_helpers.php for the
 * lookup and the deliberate fail-closed behaviour.
 */
function bp_wfh_can_use_work_from_home(array $staff): bool
{
    return bp_staff_has_screen_permission($staff, BP_WFH_SCREEN_FOLDER);
}

/**
 * Kept as the name the WFH endpoints already call so the gate could be swapped
 * without touching every call site. The name is now a misnomer - eligibility is
 * a web-managed permission, not an entity check - but the behaviour is the one
 * the product wants: only roles granted WFH in the ERP get it.
 */
function bp_wfh_staff_is_bp_india(array $staff): bool
{
    return bp_wfh_can_use_work_from_home($staff);
}

function bp_wfh_require_staff(string $staffIdInput): array
{
    $staff = bp_fetch_staff($staffIdInput);
    if (!$staff) {
        bp_send_json([
            'status' => false,
            'message' => 'Employee not found',
        ], 404);
    }

    $employeeId = trim((string)($staff['employee_id'] ?? ''));
    if ($employeeId === '') {
        bp_send_json([
            'status' => false,
            'message' => 'Employee id mapping failed',
        ], 500);
    }

    return $staff;
}

function bp_wfh_require_bp_india(array $staff): void
{
    if (!bp_wfh_can_use_work_from_home($staff)) {
        bp_send_json([
            'status' => false,
            'message' => 'Work From Home access has not been enabled for your role',
        ], 403);
    }
}

function bp_wfh_is_reporting_officer(string $employeeId): bool
{
    if ($employeeId === '') {
        return false;
    }

    $rows = bp_fetch_rows(
        'staff_test',
        ['employee_id'],
        [
            'reporting_officer' => $employeeId,
            'is_active' => 1,
            'is_delete' => 0,
        ]
    );

    return !empty($rows);
}

function bp_wfh_staff_name(string $employeeId): string
{
    $staff = bp_fetch_staff($employeeId);
    $name = trim((string)($staff['staff_name'] ?? ''));
    return $name !== '' ? $name : $employeeId;
}

function bp_wfh_department_name(string $departmentId): string
{
    $departmentId = trim($departmentId);
    if ($departmentId === '') {
        return '';
    }

    $columns = bp_table_columns('department_creation');
    if (empty($columns)) {
        return $departmentId;
    }

    if (isset($columns['unique_id']) && isset($columns['department'])) {
        $row = bp_fetch_one(
            'department_creation',
            ['department'],
            [
                'unique_id' => $departmentId,
                'is_delete' => 0,
            ]
        );
        $name = trim((string)($row['department'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }

    return $departmentId;
}

function bp_wfh_fetch_reporting_officer(array $staff): string
{
    return trim((string)($staff['reporting_officer'] ?? ''));
}

function bp_wfh_format_entry(array $row): array
{
    $employeeId = trim((string)($row['employee_id'] ?? ''));
    $department = trim((string)($row['department'] ?? ''));
    $headId = trim((string)($row['department_head'] ?? ''));
    $status = (int)($row['status'] ?? 0);

    return [
        'unique_id' => (string)($row['unique_id'] ?? ''),
        'employee_id' => $employeeId,
        'employee_name' => bp_wfh_staff_name($employeeId),
        'department' => $department,
        'department_name' => bp_wfh_department_name($department),
        'department_head' => $headId,
        'department_head_name' => bp_wfh_staff_name($headId),
        'date' => (string)($row['date'] ?? ''),
        'day' => (string)($row['day'] ?? ''),
        'remarks' => (string)($row['remarks'] ?? ''),
        'status' => $status,
        'status_label' => bp_wfh_status_label($status),
        'reject_reason' => (string)($row['reject_reason'] ?? ''),
        'approved_by' => (string)($row['approved_by'] ?? ''),
        'approved_at' => (string)($row['approved_at'] ?? ''),
        'rejected_by' => (string)($row['rejected_by'] ?? ''),
        'rejected_at' => (string)($row['rejected_at'] ?? ''),
        'cancel_reason' => (string)($row['cancel_reason'] ?? ''),
        'cancelled_at' => (string)($row['cancelled_at'] ?? ''),
        'linked_leave_unique_id' => (string)($row['linked_leave_unique_id'] ?? ''),
        'created' => (string)($row['created'] ?? ''),
        'updated' => (string)($row['updated'] ?? ''),
    ];
}

function bp_wfh_fetch_entries(
    string $employeeId,
    string $viewType,
    ?string $fromDate = null,
    ?string $toDate = null,
    ?int $status = null,
    int $limit = 100
): array {
    $columns = bp_table_columns(BP_WFH_TABLE);
    if (empty($columns)) {
        return [];
    }

    $select = array_values(array_filter([
        'unique_id',
        'employee_id',
        'department',
        'department_head',
        'date',
        'day',
        'remarks',
        'status',
        'reject_reason',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        isset($columns['cancel_reason']) ? 'cancel_reason' : null,
        isset($columns['cancelled_at']) ? 'cancelled_at' : null,
        isset($columns['linked_leave_unique_id']) ? 'linked_leave_unique_id' : null,
        'created',
        'updated',
    ], static fn($column) => is_string($column) && isset($columns[$column])));

    $where = ['is_delete = 0'];
    if ($viewType === 'team') {
        $quoted = bp_sql_quote($employeeId);
        $where[] = '(department_head = ' . $quoted . ' OR department_head = (SELECT unique_id FROM staff_test WHERE employee_id = ' . $quoted . ' AND is_delete = 0 AND is_active = 1 LIMIT 1))';
    } else {
        $where[] = 'employee_id = ' . bp_sql_quote($employeeId);
    }

    if ($fromDate !== null) {
        $where[] = 'date >= ' . bp_sql_quote($fromDate);
    }
    if ($toDate !== null) {
        $where[] = 'date <= ' . bp_sql_quote($toDate);
    }
    if ($status !== null) {
        $where[] = 'status = ' . (int)$status;
    }

    $limit = max(1, min($limit, 300));
    $rows = bp_fetch_rows(
        BP_WFH_TABLE,
        $select,
        implode(' AND ', $where) . ' ORDER BY date DESC, created DESC LIMIT ' . $limit
    );

    return array_map('bp_wfh_format_entry', $rows);
}

function bp_wfh_fetch_record(string $uniqueId): ?array
{
    $rows = bp_wfh_fetch_rows_by_where('unique_id = ' . bp_sql_quote($uniqueId) . ' AND is_delete = 0 LIMIT 1');
    return $rows[0] ?? null;
}

function bp_wfh_fetch_rows_by_where(string $where): array
{
    $columns = bp_table_columns(BP_WFH_TABLE);
    if (empty($columns)) {
        return [];
    }

    $select = array_values(array_filter([
        'unique_id',
        'employee_id',
        'department',
        'department_head',
        'date',
        'day',
        'remarks',
        'status',
        'reject_reason',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        isset($columns['cancel_reason']) ? 'cancel_reason' : null,
        isset($columns['cancelled_at']) ? 'cancelled_at' : null,
        isset($columns['linked_leave_unique_id']) ? 'linked_leave_unique_id' : null,
        'created',
        'updated',
        'created_user_id',
    ], static fn($column) => is_string($column) && isset($columns[$column])));

    return array_map(
        'bp_wfh_format_entry',
        bp_fetch_rows(BP_WFH_TABLE, $select, $where)
    );
}

function bp_wfh_count(string $where): int
{
    $rows = bp_fetch_rows(BP_WFH_TABLE, ['COUNT(*) AS cnt'], $where);
    return (int)($rows[0]['cnt'] ?? 0);
}

function bp_wfh_has_leave_conflict(string $employeeId, string $date): bool
{
    $where = 'employee_id = ' . bp_sql_quote($employeeId)
        . ' AND ' . bp_sql_quote($date) . ' BETWEEN from_date AND to_date'
        . ' AND status != 2 AND is_delete = 0';
    return bp_wfh_count_table('leave_entry', $where) > 0;
}

function bp_wfh_count_table(string $table, string $where): int
{
    $rows = bp_fetch_rows($table, ['COUNT(*) AS cnt'], $where);
    return (int)($rows[0]['cnt'] ?? 0);
}

function bp_wfh_cancel_for_leave(string $employeeId, string $fromDate, string $toDate, string $leaveUniqueId = ''): int
{
    $columns = bp_table_columns(BP_WFH_TABLE);
    if (empty($columns)) {
        return 0;
    }

    $update = [
        'status' => 3,
        'updated_user_id' => $employeeId,
        'updated' => bp_now(),
    ];
    if (isset($columns['cancel_reason'])) {
        $update['cancel_reason'] = 'Auto-cancelled because leave was applied for this date.';
    }
    if (isset($columns['cancelled_at'])) {
        $update['cancelled_at'] = bp_now();
    }
    if ($leaveUniqueId !== '' && isset($columns['linked_leave_unique_id'])) {
        $update['linked_leave_unique_id'] = $leaveUniqueId;
    }

    $where = 'employee_id = ' . bp_sql_quote($employeeId)
        . ' AND date >= ' . bp_sql_quote($fromDate)
        . ' AND date <= ' . bp_sql_quote($toDate)
        . ' AND status IN (0, 1)'
        . ' AND is_delete = 0';

    $rows = bp_fetch_rows(BP_WFH_TABLE, ['unique_id'], $where);
    foreach ($rows as $row) {
        $uid = trim((string)($row['unique_id'] ?? ''));
        if ($uid === '') {
            continue;
        }
        bp_update_row(BP_WFH_TABLE, $update, ['unique_id' => $uid, 'is_delete' => 0]);
    }

    return count($rows);
}

function bp_wfh_notification_table_ddl(): string
{
    return 'CREATE TABLE IF NOT EXISTS `' . BP_WFH_NOTIFICATION_TABLE . '` ('
        . '`id` bigint unsigned NOT NULL AUTO_INCREMENT,'
        . '`unique_id` varchar(64) NOT NULL,'
        . '`to_staff_id` varchar(64) NOT NULL,'
        . '`from_staff_id` varchar(64) DEFAULT NULL,'
        . '`wfh_unique_id` varchar(64) DEFAULT NULL,'
        . '`title` varchar(255) NOT NULL,'
        . '`message` text NOT NULL,'
        . '`deep_link` varchar(255) DEFAULT NULL,'
        . '`is_read` tinyint(1) NOT NULL DEFAULT 0,'
        . '`created` datetime DEFAULT CURRENT_TIMESTAMP,'
        . '`updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . '`is_active` tinyint(1) NOT NULL DEFAULT 1,'
        . '`is_delete` tinyint(1) NOT NULL DEFAULT 0,'
        . 'PRIMARY KEY (`id`),'
        . 'UNIQUE KEY `uniq_bp_wfh_notifications_uid` (`unique_id`),'
        . 'KEY `idx_bp_wfh_notifications_to_staff` (`to_staff_id`),'
        . 'KEY `idx_bp_wfh_notifications_wfh` (`wfh_unique_id`),'
        . 'KEY `idx_bp_wfh_notifications_unread` (`to_staff_id`, `is_read`, `is_delete`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

/// Reads the notification table's columns without going through the cached
/// `bp_table_columns()` helper, whose per-request static cache would otherwise
/// pin an empty result from before the table was created.
function bp_wfh_read_notification_columns(): array
{
    global $pdo;

    try {
        $res = $pdo->query('SHOW COLUMNS FROM `' . BP_WFH_NOTIFICATION_TABLE . '`');
    } catch (Throwable $e) {
        return [];
    }

    if (!$res || !($res->status ?? false) || !is_array($res->data ?? null)) {
        return [];
    }

    $set = [];
    foreach ($res->data as $row) {
        $name = trim((string)($row['Field'] ?? ''));
        if ($name !== '') {
            $set[$name] = true;
        }
    }

    return $set;
}

function bp_wfh_create_notification_table(): bool
{
    global $pdo;

    try {
        $pdo->query(bp_wfh_notification_table_ddl());
        return true;
    } catch (Throwable $e) {
        error_log('bp_mobile_app wfh notification table create failed: ' . bp_error_text($e));
        return false;
    }
}

function bp_wfh_notification_columns(): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $columns = bp_wfh_read_notification_columns();
    if (!empty($columns)) {
        return $columns;
    }

    // The WFH module ships its own notification table (bp_wfh_notifications.sql).
    // When the PHP is deployed without running that script, every insert failed
    // silently: the approver still received the FCM push, but the in-app bell and
    // unread badge stayed at zero because there was no row to read back. Create
    // the table on first use so the module is self-installing.
    bp_wfh_create_notification_table();
    $columns = bp_wfh_read_notification_columns();

    return $columns;
}

function bp_wfh_insert_notification(
    string $toStaffId,
    string $fromStaffId,
    string $wfhUniqueId,
    string $title,
    string $message,
    string $deepLink
): array {
    $tableColumns = bp_wfh_notification_columns();
    if (empty($tableColumns)) {
        $error = BP_WFH_NOTIFICATION_TABLE . ' table is missing and could not be created';
        error_log('bp_mobile_app wfh notification insert skipped: ' . $error);
        return ['status' => false, 'error' => $error];
    }

    $now = bp_now();
    $uniqueId = bp_unique_id();
    // Filtered against the column set read above rather than
    // bp_filter_table_columns(), so a just-created table is not judged by a
    // stale cache. Note the filter is key-based: `0` values must survive.
    $row = array_filter(
        [
            'unique_id' => $uniqueId,
            'to_staff_id' => $toStaffId,
            'from_staff_id' => $fromStaffId,
            'wfh_unique_id' => $wfhUniqueId,
            'title' => $title,
            'message' => $message,
            'deep_link' => $deepLink,
            'is_read' => 0,
            'created' => $now,
            'updated' => $now,
            'is_active' => 1,
            'is_delete' => 0,
        ],
        static function ($_, $key) use ($tableColumns): bool {
            return isset($tableColumns[(string)$key]);
        },
        ARRAY_FILTER_USE_BOTH
    );

    $result = bp_insert_row_raw(BP_WFH_NOTIFICATION_TABLE, $row);
    $status = (bool)($result->status ?? false);
    $error = bp_error_text($result->error ?? '');
    if (!$status) {
        error_log('bp_mobile_app wfh notification insert failed: ' . ($error !== '' ? $error : 'unknown error'));
    }

    return [
        'status' => $status,
        'error' => $error,
        'unique_id' => $uniqueId,
    ];
}

function bp_wfh_deliver_notification(
    string $toStaffId,
    string $fromStaffId,
    string $wfhUniqueId,
    string $title,
    string $message,
    string $deepLink,
    array $pushData = []
): array {
    $notification = bp_wfh_insert_notification(
        $toStaffId,
        $fromStaffId,
        $wfhUniqueId,
        $title,
        $message,
        $deepLink
    );

    $payload = $pushData;
    $payload['route'] = $payload['route'] ?? $deepLink;
    $payload['deepLink'] = $payload['deepLink'] ?? $deepLink;
    $payload['wfhId'] = $payload['wfhId'] ?? $wfhUniqueId;
    $payload['type'] = $payload['type'] ?? 'wfh';

    $push = bp_send_push_notification_to_staff($toStaffId, $title, $message, $payload);
    return [
        'notification' => $notification,
        'push' => $push,
    ];
}

function bp_wfh_fetch_notifications(string $staffId, bool $unreadOnly, int $limit): array
{
    if (empty(bp_wfh_notification_columns())) {
        return [];
    }

    $where = 'to_staff_id = ' . bp_sql_quote($staffId)
        . ' AND is_delete = 0 AND is_active = 1';
    if ($unreadOnly) {
        $where .= ' AND is_read = 0';
    }
    $where .= ' ORDER BY created DESC LIMIT ' . max(1, min($limit, 100));

    return bp_fetch_rows(
        BP_WFH_NOTIFICATION_TABLE,
        ['unique_id', 'to_staff_id', 'from_staff_id', 'wfh_unique_id', 'title', 'message', 'deep_link', 'is_read', 'created'],
        $where
    );
}

function bp_wfh_unread_count(string $staffId): int
{
    if (empty(bp_wfh_notification_columns())) {
        return 0;
    }

    return bp_wfh_count_table(
        BP_WFH_NOTIFICATION_TABLE,
        'to_staff_id = ' . bp_sql_quote($staffId)
        . ' AND is_read = 0 AND is_delete = 0 AND is_active = 1'
    );
}

function bp_wfh_mark_notifications_read(string $staffId, array $notificationIds): int
{
    $ids = array_values(array_unique(array_filter(array_map('trim', $notificationIds))));
    if (empty($ids) || empty(bp_wfh_notification_columns())) {
        return 0;
    }

    $updated = 0;
    foreach ($ids as $id) {
        $res = bp_update_row(
            BP_WFH_NOTIFICATION_TABLE,
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

function bp_wfh_month_bounds(string $date): array
{
    $stamp = strtotime($date);
    return [
        date('Y-m-01', $stamp),
        date('Y-m-t', $stamp),
    ];
}

function bp_wfh_week_bounds(string $date): array
{
    $stamp = strtotime($date);
    return [
        date('Y-m-d', strtotime('monday this week', $stamp)),
        date('Y-m-d', strtotime('sunday this week', $stamp)),
    ];
}
