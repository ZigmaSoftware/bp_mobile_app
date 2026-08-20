<?php
declare(strict_types=1);

require_once __DIR__ . '/att_reg_helpers.php';

// Soft-delete a regularization request. Ports att_regular/crud.php case
// "delete", but keeps the status guard that the live web code lost (the older
// BP_Beta fork still has it): an approved request cannot be deleted, which is
// consistent with it not being editable either.

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$attRegUniqueId = trim(bp_str($input, 'att_reg_unique_id', bp_str($input, 'unique_id')));

if ($staffIdInput === '' || $attRegUniqueId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id, and att_reg_unique_id are required',
    ], 400);
}

$staff = bp_att_reg_require_staff($staffIdInput);
bp_att_reg_require_access($staff);
$employeeId = trim((string)($staff['employee_id'] ?? ''));

$record = bp_att_reg_fetch_record($attRegUniqueId);
if (!$record) {
    bp_send_json([
        'status' => false,
        'message' => 'Regularization request not found',
    ], 404);
}

if (trim((string)($record['employee_id'] ?? '')) !== $employeeId) {
    bp_send_json([
        'status' => false,
        'message' => 'Unauthorized: this regularization request is not yours',
    ], 403);
}

if ((int)($record['status'] ?? 0) === 1) {
    bp_send_json([
        'status' => false,
        'message' => 'Cannot delete. This request has already been approved.',
        'error_title' => 'Already Approved',
    ], 409);
}

$now = bp_now();
$result = bp_update_row(
    BP_ATT_REG_TABLE,
    bp_filter_table_columns(BP_ATT_REG_TABLE, [
        'is_delete' => 1,
        'updated' => $now,
        'updated_user_id' => $employeeId,
        'updated_at' => $now,
    ]),
    ['unique_id' => $attRegUniqueId, 'is_delete' => 0]
);

if (!$result || !($result->status ?? false)) {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to delete regularization request',
        'error' => (string)($result->error ?? ''),
    ], 500);
}

bp_send_json([
    'status' => true,
    'message' => 'Regularization request deleted',
    'data' => [
        'att_reg_unique_id' => $attRegUniqueId,
        'monthly_used' => bp_att_reg_month_usage($employeeId, (string)($record['shift_date'] ?? date('Y-m-d'))),
        'monthly_limit' => BP_ATT_REG_MONTHLY_LIMIT,
    ],
]);
