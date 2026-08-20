<?php
declare(strict_types=1);

require_once __DIR__ . '/../Leave/leave_helpers.php';
require_once __DIR__ . '/../Attendance/attendance_helpers.php';

const BP_ATT_REG_TABLE = 'attendance_regularization';
const BP_ATT_REG_NOTIFICATION_TABLE = 'bp_att_reg_notifications';

/**
 * Folder names the ERP registers the regularization screens under
 * (user_screen.folder_name). 'att_regular' is the employee-side screen,
 * 'reg_appr' the approver-side one. See bp_staff_has_screen_permission() in
 * Leave/leave_helpers.php for the lookup and its deliberate fail-closed
 * behaviour.
 */
const BP_ATT_REG_SCREEN_FOLDER = 'att_regular';
const BP_ATT_REG_APPROVAL_SCREEN_FOLDER = 'reg_appr';

/**
 * Web enforces "max 3 per calendar month" in JavaScript only, and its two
 * counter endpoints disagree on the filter (check_monthly_limit counts every
 * non-deleted row, recount counts status IN (0,1)). We enforce it server-side
 * using the recount semantics, which are the ones the form's helper text
 * describes to the user.
 */
const BP_ATT_REG_MONTHLY_LIMIT = 3;

const BP_ATT_REG_ATTACHMENT_MAX_BYTES = 5242880; // 5 MB
const BP_ATT_REG_ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];

function bp_att_reg_enabled(): bool
{
    return !defined('BP_ENABLE_ATT_REGULARIZATION') || BP_ENABLE_ATT_REGULARIZATION;
}

function bp_att_reg_status_label(int $status): string
{
    switch ($status) {
        case 0:
            return 'Pending';
        case 1:
            return 'Approved';
        case 2:
            return 'Rejected';
        default:
            return 'Unknown';
    }
}

/** Types are hardcoded in the web form (att_regular/form.php), not table-driven. */
function bp_att_reg_type_label(int $type): string
{
    switch ($type) {
        case 1:
            return 'Check-In';
        case 2:
            return 'Check-Out';
        case 3:
            return 'Check-In & Check-Out';
        default:
            return 'Unknown';
    }
}

/**
 * Whether this staff member's user_type has been granted a screen, matching the
 * WEB MENU's definition of granted.
 *
 * bp_staff_has_screen_permission() adds `is_active = 1 AND is_delete = 0` to the
 * user_screen_permission lookup. The web menu does not: menu_permission()
 * (config/comfun.php:683) filters on user_type alone, and login caches that
 * straight into $_SESSION['screens'], which is what renders the left menu. So a
 * permission row with is_active = 0, is_delete = 1, or NULL in either column
 * still shows the screen on the web while the stricter lookup hides it in the
 * app - exactly the "admin sees it on web but not in app" divergence. BP_ERP's
 * own dev_tools/permission_audit.php documents this discrepancy.
 *
 * The user_screen row itself IS required to be active, because the web's
 * user_screen() helper (comfun.php:2650) filters it and the menu will not render
 * a screen it does not return. bp_screen_unique_id_for_folder() already applies
 * that filter, so this only relaxes the permission-row side.
 *
 * Deliberately local to this module rather than a change to the shared helper:
 * that helper also gates WFH and the attendance report, and loosening those is a
 * separate decision.
 */
function bp_att_reg_has_screen_permission(array $staff, string $folderName): bool
{
    // Strict path first: when the row is properly active this is the same
    // answer, and it keeps the shared helper as the primary source of truth.
    if (bp_staff_has_screen_permission($staff, $folderName)) {
        return true;
    }

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

    // user_type + screen only, mirroring menu_permission().
    $rows = bp_fetch_rows(
        'user_screen_permission',
        ['unique_id'],
        'user_type = ' . bp_sql_quote($userType)
        . ' AND screen_unique_id = ' . bp_sql_quote($screenId)
        . ' LIMIT 1'
    );

    return !empty($rows);
}

function bp_att_reg_can_use(array $staff): bool
{
    if (!bp_att_reg_enabled()) {
        return false;
    }

    return bp_att_reg_has_screen_permission($staff, BP_ATT_REG_SCREEN_FOLDER);
}

/**
 * Designation names the web treats as "sees everything" on the approval screen.
 *
 * reg_appr/list.php checks
 *   $_SESSION['designation_type'] == 'Human Resources Manager' || == 'Developer'
 * while reg_appr/crud.php checks only the first. We accept both, matching the
 * more permissive of the two, so an admin/developer role that sees the screen on
 * the web also sees it in the app.
 */
function bp_att_reg_privileged_designations(): array
{
    return ['human resources manager', 'developer'];
}

/** The staff row's designation display name, read straight from the master. */
function bp_att_reg_designation_name(array $staff): string
{
    $designationId = trim((string)($staff['designation_unique_id'] ?? ''));
    if ($designationId === '') {
        return '';
    }

    $columns = bp_table_columns('designation_creation');
    if (empty($columns) || !isset($columns['unique_id']) || !isset($columns['designation'])) {
        return '';
    }

    $where = 'unique_id = ' . bp_sql_quote($designationId);
    if (isset($columns['is_delete'])) {
        $where .= ' AND is_delete = 0';
    }

    $row = bp_fetch_one('designation_creation', ['designation'], $where . ' LIMIT 1');
    return trim((string)($row['designation'] ?? ''));
}

/**
 * Whether this staff member gets the web's unrestricted view of the approval
 * screen: HR by designation id (the id the web's own mailers hardcode) or by
 * designation name (what reg_appr actually compares against).
 */
function bp_att_reg_is_privileged_approver(array $staff): bool
{
    $employeeId = trim((string)($staff['employee_id'] ?? ''));
    if ($employeeId !== '' && bp_is_hr_staff($employeeId)) {
        return true;
    }

    $designation = strtolower(bp_att_reg_designation_name($staff));
    if ($designation === '') {
        return false;
    }

    return in_array($designation, bp_att_reg_privileged_designations(), true);
}

/**
 * Approver eligibility: the reg_appr screen permission, plus having somebody to
 * approve for - either a privileged designation (HR/Developer, who see all) or
 * being a reporting officer.
 *
 * The role clause deliberately mirrors what the web's approval screen actually
 * does rather than being stricter: a role granted reg_appr on the web but with
 * no reportees and no privileged designation would still see the screen there,
 * so gating it away here made the app hide a screen the web shows.
 */
function bp_att_reg_can_approve(array $staff): bool
{
    if (!bp_att_reg_enabled()) {
        return false;
    }

    if (!bp_att_reg_has_screen_permission($staff, BP_ATT_REG_APPROVAL_SCREEN_FOLDER)) {
        return false;
    }

    $employeeId = trim((string)($staff['employee_id'] ?? ''));
    if ($employeeId === '') {
        return false;
    }

    return bp_att_reg_is_privileged_approver($staff)
        || bp_is_reporting_officer($employeeId);
}

function bp_att_reg_require_staff(string $staffIdInput): array
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

function bp_att_reg_require_access(array $staff): void
{
    if (!bp_att_reg_enabled()) {
        bp_send_json([
            'status' => false,
            'message' => 'Attendance Regularization is not enabled',
        ], 403);
    }

    if (!bp_att_reg_can_use($staff)) {
        bp_send_json([
            'status' => false,
            'message' => 'Attendance Regularization has not been enabled for your role',
        ], 403);
    }
}

function bp_att_reg_require_approver(array $staff): void
{
    if (!bp_att_reg_enabled()) {
        bp_send_json([
            'status' => false,
            'message' => 'Attendance Regularization is not enabled',
        ], 403);
    }

    if (!bp_att_reg_can_approve($staff)) {
        bp_send_json([
            'status' => false,
            'message' => 'Attendance Regularization approval has not been enabled for your role',
        ], 403);
    }
}

function bp_att_reg_fetch_reporting_officer(array $staff): string
{
    return trim((string)($staff['reporting_officer'] ?? ''));
}

function bp_att_reg_staff_name(string $employeeId): string
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return '';
    }

    $name = bp_employee_name($employeeId);
    return $name !== '' ? $name : $employeeId;
}

/** Reason master shared with other ERP modules; label column is reason_name. */
function bp_att_reg_reasons(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $columns = bp_table_columns('reason_creation');
    if (empty($columns) || !isset($columns['unique_id']) || !isset($columns['reason_name'])) {
        $cached = [];
        return $cached;
    }

    $where = [];
    if (isset($columns['is_active'])) {
        $where[] = 'is_active = 1';
    }
    if (isset($columns['is_delete'])) {
        $where[] = 'is_delete = 0';
    }
    $whereSql = empty($where) ? '1 = 1' : implode(' AND ', $where);

    $rows = bp_fetch_rows(
        'reason_creation',
        ['unique_id', 'reason_name'],
        $whereSql . ' ORDER BY reason_name'
    );

    $out = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['unique_id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $out[] = [
            'unique_id' => $id,
            'reason_name' => trim((string)($row['reason_name'] ?? '')),
        ];
    }

    $cached = $out;
    return $cached;
}

function bp_att_reg_reason_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $map = [];
    foreach (bp_att_reg_reasons() as $reason) {
        $map[$reason['unique_id']] = $reason['reason_name'];
    }

    return $map;
}

/**
 * Snapshot of the real punches for a shift date, read from
 * vw_attendance_with_shift exactly as the web does. Column names go through
 * the existing defensive map because the view has been reshaped before.
 * Missing rows yield nulls, matching web behaviour.
 */
function bp_att_reg_actual_punches(string $employeeId, string $shiftDate): array
{
    $empty = ['actual_in' => null, 'actual_out' => null, 'has_record' => false];

    $employeeId = trim($employeeId);
    if ($employeeId === '' || $shiftDate === '') {
        return $empty;
    }

    try {
        $map = bp_att_attendance_view_column_map();
    } catch (Throwable $e) {
        error_log('bp_mobile_app att_reg punch lookup failed: ' . bp_error_text($e));
        return $empty;
    }

    $employeeColumn = (string)($map['employee_id'] ?? '');
    $dateColumn = (string)($map['shift_date'] ?? '');
    $inColumn = (string)($map['entry_punch'] ?? '');
    $outColumn = (string)($map['exit_punch'] ?? '');

    if ($employeeColumn === '' || $dateColumn === '' || ($inColumn === '' && $outColumn === '')) {
        return $empty;
    }

    $select = [];
    if ($inColumn !== '') {
        $select[] = $inColumn;
    }
    if ($outColumn !== '') {
        $select[] = $outColumn;
    }

    $where = $employeeColumn . ' = ' . bp_sql_quote($employeeId)
        . ' AND ' . $dateColumn . ' = ' . bp_sql_quote($shiftDate)
        . ' LIMIT 1';

    try {
        $rows = bp_fetch_rows('vw_attendance_with_shift', $select, $where);
    } catch (Throwable $e) {
        error_log('bp_mobile_app att_reg punch fetch failed: ' . bp_error_text($e));
        return $empty;
    }

    if (empty($rows)) {
        return $empty;
    }

    $row = $rows[0];
    $actualIn = $inColumn !== '' ? trim((string)($row[$inColumn] ?? '')) : '';
    $actualOut = $outColumn !== '' ? trim((string)($row[$outColumn] ?? '')) : '';

    return [
        'actual_in' => $actualIn !== '' ? $actualIn : null,
        'actual_out' => $actualOut !== '' ? $actualOut : null,
        'has_record' => true,
    ];
}

function bp_att_reg_attachment_url(string $attachment): string
{
    $attachment = trim($attachment);
    if ($attachment === '') {
        return '';
    }

    $base = defined('BP_LEGACY_WEB_BASE_URL') ? rtrim((string)BP_LEGACY_WEB_BASE_URL, '/') : '';
    if ($base === '') {
        return '';
    }

    return $base . '/uploads/attendance_regularization/' . rawurlencode($attachment);
}

function bp_att_reg_format_entry(array $row): array
{
    $employeeId = trim((string)($row['employee_id'] ?? ''));
    $approvedBy = trim((string)($row['approved_by'] ?? ''));
    $reasonId = trim((string)($row['reason_id'] ?? ''));
    $attachment = trim((string)($row['attachment'] ?? ''));
    $status = (int)($row['status'] ?? 0);
    $type = (int)($row['type'] ?? 0);
    $reasonMap = bp_att_reg_reason_map();

    return [
        'unique_id' => (string)($row['unique_id'] ?? ''),
        'employee_id' => $employeeId,
        'employee_name' => bp_att_reg_staff_name($employeeId),
        'shift_date' => (string)($row['shift_date'] ?? ''),
        'type' => $type,
        'type_label' => bp_att_reg_type_label($type),
        'actual_in' => (string)($row['actual_in'] ?? ''),
        'actual_out' => (string)($row['actual_out'] ?? ''),
        'reg_in' => (string)($row['reg_in'] ?? ''),
        'reg_out' => (string)($row['reg_out'] ?? ''),
        'reason_id' => $reasonId,
        'reason_name' => (string)($reasonMap[$reasonId] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'attachment' => $attachment,
        'attachment_url' => bp_att_reg_attachment_url($attachment),
        'status' => $status,
        'status_label' => bp_att_reg_status_label($status),
        'approved_by' => $approvedBy,
        'approved_by_name' => bp_att_reg_staff_name($approvedBy),
        'approved_at' => (string)($row['approved_at'] ?? ''),
        'created' => (string)($row['created'] ?? ''),
        'updated' => (string)($row['updated'] ?? ''),
    ];
}

function bp_att_reg_select_columns(): array
{
    $columns = bp_table_columns(BP_ATT_REG_TABLE);
    if (empty($columns)) {
        return [];
    }

    $wanted = [
        'unique_id',
        'employee_id',
        'shift_date',
        'type',
        'actual_in',
        'actual_out',
        'reg_in',
        'reg_out',
        'reason_id',
        'description',
        'attachment',
        'status',
        'approved_by',
        'approved_at',
        'created',
        'updated',
    ];

    return array_values(array_filter(
        $wanted,
        static fn(string $column): bool => isset($columns[$column])
    ));
}

function bp_att_reg_fetch_rows_by_where(string $where): array
{
    $select = bp_att_reg_select_columns();
    if (empty($select)) {
        return [];
    }

    return array_map(
        'bp_att_reg_format_entry',
        bp_fetch_rows(BP_ATT_REG_TABLE, $select, $where)
    );
}

function bp_att_reg_fetch_record(string $uniqueId): ?array
{
    $uniqueId = trim($uniqueId);
    if ($uniqueId === '') {
        return null;
    }

    $rows = bp_att_reg_fetch_rows_by_where(
        'unique_id = ' . bp_sql_quote($uniqueId) . ' AND is_delete = 0 LIMIT 1'
    );

    return $rows[0] ?? null;
}

function bp_att_reg_count(string $where): int
{
    $rows = bp_fetch_rows(BP_ATT_REG_TABLE, ['COUNT(*) AS cnt'], $where);
    return (int)($rows[0]['cnt'] ?? 0);
}

/**
 * Scope clause for a view type:
 *   own  - just this employee
 *   team - direct reportees only. The web joins staff_test and matches
 *          st.reporting_officer = $empid, i.e. a single hop, NOT recursive.
 *   all  - HR override, everyone
 * 'team' and 'all' both exclude the caller's own rows, because the web hides
 * the status dropdown when employee_id == the approver (nobody approves their
 * own request).
 */
function bp_att_reg_scope_where(string $employeeId, string $viewType): string
{
    $quoted = bp_sql_quote($employeeId);

    if ($viewType === 'all') {
        return 'employee_id <> ' . $quoted;
    }

    if ($viewType === 'team') {
        return 'employee_id <> ' . $quoted
            . ' AND employee_id IN (SELECT employee_id FROM staff_test'
            . ' WHERE reporting_officer = ' . $quoted
            . ' AND is_active = 1 AND is_delete = 0)';
    }

    return 'employee_id = ' . $quoted;
}

function bp_att_reg_fetch_entries(
    string $employeeId,
    string $viewType,
    ?string $fromDate = null,
    ?string $toDate = null,
    ?int $status = null,
    int $limit = 100
): array {
    if (empty(bp_att_reg_select_columns())) {
        return [];
    }

    $where = ['is_delete = 0', bp_att_reg_scope_where($employeeId, $viewType)];

    if ($fromDate !== null) {
        $where[] = 'shift_date >= ' . bp_sql_quote($fromDate);
    }
    if ($toDate !== null) {
        $where[] = 'shift_date <= ' . bp_sql_quote($toDate);
    }
    if ($status !== null) {
        $where[] = 'status = ' . (int)$status;
    }

    $limit = max(1, min($limit, 300));

    return bp_att_reg_fetch_rows_by_where(
        implode(' AND ', $where) . ' ORDER BY shift_date DESC, created DESC LIMIT ' . $limit
    );
}

function bp_att_reg_month_bounds(string $date): array
{
    $stamp = strtotime($date);
    if ($stamp === false) {
        $stamp = time();
    }

    return [date('Y-m-01', $stamp), date('Y-m-t', $stamp)];
}

/**
 * Requests already consuming the monthly allowance for the month containing
 * $shiftDate. status IN (0,1) - a rejected request does not consume quota.
 * $ignoreUniqueId lets an edit exclude itself.
 */
function bp_att_reg_month_usage(string $employeeId, string $shiftDate, string $ignoreUniqueId = ''): int
{
    [$from, $to] = bp_att_reg_month_bounds($shiftDate);

    $where = 'employee_id = ' . bp_sql_quote($employeeId)
        . ' AND shift_date >= ' . bp_sql_quote($from)
        . ' AND shift_date <= ' . bp_sql_quote($to)
        . ' AND status IN (0, 1)'
        . ' AND is_delete = 0';

    if ($ignoreUniqueId !== '') {
        $where .= ' AND unique_id <> ' . bp_sql_quote($ignoreUniqueId);
    }

    return bp_att_reg_count($where);
}

function bp_att_reg_date_taken(string $employeeId, string $shiftDate, string $ignoreUniqueId = ''): bool
{
    $where = 'employee_id = ' . bp_sql_quote($employeeId)
        . ' AND shift_date = ' . bp_sql_quote($shiftDate)
        . ' AND status IN (0, 1)'
        . ' AND is_delete = 0';

    if ($ignoreUniqueId !== '') {
        $where .= ' AND unique_id <> ' . bp_sql_quote($ignoreUniqueId);
    }

    return bp_att_reg_count($where) > 0;
}

function bp_att_reg_pending_count(string $employeeId, string $viewType): int
{
    return bp_att_reg_count(
        'is_delete = 0 AND status = 0 AND ' . bp_att_reg_scope_where($employeeId, $viewType)
    );
}

function bp_att_reg_upload_dir_candidates(): array
{
    $root = defined('BP_BLUE_PLANET_ROOT') ? rtrim((string)BP_BLUE_PLANET_ROOT, '/') : '';
    $candidates = [];

    if ($root !== '') {
        $candidates[] = $root . '/uploads/attendance_regularization';
    }

    $candidates[] = dirname(__DIR__) . '/uploads/attendance_regularization';

    return $candidates;
}

function bp_att_reg_pick_upload_dir(): string
{
    $candidates = bp_att_reg_upload_dir_candidates();

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

/**
 * Stores an uploaded attachment and returns the stored filename, or null when
 * the upload is unusable. Filename format matches the web's
 * md5(uniqid(true)).ext so both apps read the same directory identically.
 */
function bp_att_reg_store_attachment(array $file): ?string
{
    $name = (string)($file['name'] ?? '');
    $tmp = (string)($file['tmp_name'] ?? '');
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $size = (int)($file['size'] ?? 0);

    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }

    if ($size <= 0 || $size > BP_ATT_REG_ATTACHMENT_MAX_BYTES) {
        return null;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, BP_ATT_REG_ATTACHMENT_EXTENSIONS, true)) {
        return null;
    }

    $safeName = md5(uniqid('', true)) . '.' . $ext;
    $dir = bp_att_reg_pick_upload_dir();
    $target = rtrim($dir, '/') . '/' . $safeName;

    if (!@move_uploaded_file($tmp, $target)) {
        return null;
    }

    return $safeName;
}

function bp_att_reg_notification_table_ddl(): string
{
    return 'CREATE TABLE IF NOT EXISTS `' . BP_ATT_REG_NOTIFICATION_TABLE . '` ('
        . '`id` bigint unsigned NOT NULL AUTO_INCREMENT,'
        . '`unique_id` varchar(64) NOT NULL,'
        . '`to_staff_id` varchar(64) NOT NULL,'
        . '`from_staff_id` varchar(64) DEFAULT NULL,'
        . '`att_reg_unique_id` varchar(64) DEFAULT NULL,'
        . '`title` varchar(255) NOT NULL,'
        . '`message` text NOT NULL,'
        . '`deep_link` varchar(255) DEFAULT NULL,'
        . '`is_read` tinyint(1) NOT NULL DEFAULT 0,'
        . '`created` datetime DEFAULT CURRENT_TIMESTAMP,'
        . '`updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . '`is_active` tinyint(1) NOT NULL DEFAULT 1,'
        . '`is_delete` tinyint(1) NOT NULL DEFAULT 0,'
        . 'PRIMARY KEY (`id`),'
        . 'UNIQUE KEY `uniq_bp_att_reg_notifications_uid` (`unique_id`),'
        . 'KEY `idx_bp_att_reg_notifications_to_staff` (`to_staff_id`),'
        . 'KEY `idx_bp_att_reg_notifications_reg` (`att_reg_unique_id`),'
        . 'KEY `idx_bp_att_reg_notifications_unread` (`to_staff_id`, `is_read`, `is_delete`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

/// Reads the notification table's columns without going through the cached
/// bp_table_columns() helper, whose per-request static cache would otherwise
/// pin an empty result from before the table was created.
function bp_att_reg_read_notification_columns(): array
{
    global $pdo;

    try {
        $res = $pdo->query('SHOW COLUMNS FROM `' . BP_ATT_REG_NOTIFICATION_TABLE . '`');
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

function bp_att_reg_create_notification_table(): bool
{
    global $pdo;

    try {
        $pdo->query(bp_att_reg_notification_table_ddl());
        return true;
    } catch (Throwable $e) {
        error_log('bp_mobile_app att_reg notification table create failed: ' . bp_error_text($e));
        return false;
    }
}

function bp_att_reg_notification_columns(): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $columns = bp_att_reg_read_notification_columns();
    if (!empty($columns)) {
        return $columns;
    }

    // Self-installing, same as the WFH module: when the PHP is deployed without
    // running bp_att_reg_notifications.sql, every insert would fail silently -
    // the push still arrives but the in-app bell and unread badge stay at zero.
    bp_att_reg_create_notification_table();
    $columns = bp_att_reg_read_notification_columns();

    return $columns;
}

function bp_att_reg_insert_notification(
    string $toStaffId,
    string $fromStaffId,
    string $attRegUniqueId,
    string $title,
    string $message,
    string $deepLink
): array {
    $tableColumns = bp_att_reg_notification_columns();
    if (empty($tableColumns)) {
        $error = BP_ATT_REG_NOTIFICATION_TABLE . ' table is missing and could not be created';
        error_log('bp_mobile_app att_reg notification insert skipped: ' . $error);
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
            'att_reg_unique_id' => $attRegUniqueId,
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

    $result = bp_insert_row_raw(BP_ATT_REG_NOTIFICATION_TABLE, $row);
    $status = (bool)($result->status ?? false);
    $error = bp_error_text($result->error ?? '');
    if (!$status) {
        error_log('bp_mobile_app att_reg notification insert failed: ' . ($error !== '' ? $error : 'unknown error'));
    }

    return [
        'status' => $status,
        'error' => $error,
        'unique_id' => $uniqueId,
    ];
}

/**
 * Inserts the in-app row and sends the push. 'route' is always set explicitly
 * because bp_notification_route_from_deep_link() only knows the /leave routes
 * and would otherwise default this module's taps to /notifications.
 */
function bp_att_reg_deliver_notification(
    string $toStaffId,
    string $fromStaffId,
    string $attRegUniqueId,
    string $title,
    string $message,
    string $deepLink,
    array $pushData = []
): array {
    $notification = bp_att_reg_insert_notification(
        $toStaffId,
        $fromStaffId,
        $attRegUniqueId,
        $title,
        $message,
        $deepLink
    );

    $payload = $pushData;
    $payload['route'] = $payload['route'] ?? $deepLink;
    $payload['deepLink'] = $payload['deepLink'] ?? $deepLink;
    $payload['attRegId'] = $payload['attRegId'] ?? $attRegUniqueId;
    $payload['type'] = $payload['type'] ?? 'att_reg';

    $push = bp_send_push_notification_to_staff($toStaffId, $title, $message, $payload);

    return [
        'notification' => $notification,
        'push' => $push,
    ];
}

/**
 * Delivers the same notification to several recipients (submit notifies the
 * reporting officer plus HR, matching the web mail audience). Returns an
 * aggregate in the same shape a single delivery reports.
 */
function bp_att_reg_deliver_notifications(
    array $toStaffIds,
    string $fromStaffId,
    string $attRegUniqueId,
    string $title,
    string $message,
    string $deepLink,
    array $pushData = []
): array {
    $notificationSent = 0;
    $pushSent = 0;
    $attempted = 0;
    $errors = [];

    foreach ($toStaffIds as $toStaffId) {
        $toStaffId = trim((string)$toStaffId);
        if ($toStaffId === '') {
            continue;
        }

        $attempted++;
        try {
            $delivery = bp_att_reg_deliver_notification(
                $toStaffId,
                $fromStaffId,
                $attRegUniqueId,
                $title,
                $message,
                $deepLink,
                $pushData
            );

            if ((bool)($delivery['notification']['status'] ?? false)) {
                $notificationSent++;
            } else {
                $error = trim((string)($delivery['notification']['error'] ?? ''));
                if ($error !== '') {
                    $errors[] = $error;
                }
            }

            if ((int)($delivery['push']['sent'] ?? 0) > 0) {
                $pushSent++;
            }
        } catch (Throwable $e) {
            $errors[] = bp_error_text($e);
            error_log('bp_mobile_app att_reg notification error: ' . bp_error_text($e));
        }
    }

    return [
        'notification' => [
            'attempted' => $attempted > 0,
            'sent' => $notificationSent > 0,
            'recipients' => $attempted,
            'delivered' => $notificationSent,
            'error' => empty($errors) ? '' : implode('; ', array_unique($errors)),
        ],
        'push' => [
            'attempted' => $attempted > 0,
            'sent' => $pushSent > 0,
            'recipients' => $attempted,
            'delivered' => $pushSent,
        ],
    ];
}

/**
 * Notification audience on submit: the employee's reporting officer plus all
 * HR staff, minus the requester. Mirrors the web mailer's recipient list
 * (att_regular/crud.php case "sendmail") without the employee's own copy.
 */
function bp_att_reg_recipient_ids(string $employeeId, string $reportingOfficer): array
{
    return bp_unique_staff_ids(
        array_merge([$reportingOfficer], bp_fetch_hr_staff_ids()),
        [$employeeId]
    );
}

function bp_att_reg_count_table(string $table, string $where): int
{
    $rows = bp_fetch_rows($table, ['COUNT(*) AS cnt'], $where);
    return (int)($rows[0]['cnt'] ?? 0);
}

function bp_att_reg_fetch_notifications(string $staffId, bool $unreadOnly, int $limit): array
{
    if (empty(bp_att_reg_notification_columns())) {
        return [];
    }

    $where = 'to_staff_id = ' . bp_sql_quote($staffId)
        . ' AND is_delete = 0 AND is_active = 1';
    if ($unreadOnly) {
        $where .= ' AND is_read = 0';
    }
    $where .= ' ORDER BY created DESC LIMIT ' . max(1, min($limit, 100));

    return bp_fetch_rows(
        BP_ATT_REG_NOTIFICATION_TABLE,
        [
            'unique_id',
            'to_staff_id',
            'from_staff_id',
            'att_reg_unique_id',
            'title',
            'message',
            'deep_link',
            'is_read',
            'created',
        ],
        $where
    );
}

function bp_att_reg_unread_count(string $staffId): int
{
    if (empty(bp_att_reg_notification_columns())) {
        return 0;
    }

    return bp_att_reg_count_table(
        BP_ATT_REG_NOTIFICATION_TABLE,
        'to_staff_id = ' . bp_sql_quote($staffId)
        . ' AND is_read = 0 AND is_delete = 0 AND is_active = 1'
    );
}

function bp_att_reg_mark_notifications_read(string $staffId, array $notificationIds): int
{
    $ids = array_values(array_unique(array_filter(array_map('trim', $notificationIds))));
    if (empty($ids) || empty(bp_att_reg_notification_columns())) {
        return 0;
    }

    $updated = 0;
    foreach ($ids as $id) {
        $res = bp_update_row(
            BP_ATT_REG_NOTIFICATION_TABLE,
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
