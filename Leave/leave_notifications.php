<?php
declare(strict_types=1);

require_once __DIR__ . '/leave_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$unreadOnlyRaw = strtolower(bp_str($input, 'unread_only', '1'));
$unreadOnly = !in_array($unreadOnlyRaw, ['0', 'false', 'no'], true);
$limit = (int)bp_str($input, 'limit', '30');

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$staff = bp_fetch_staff($staffIdInput);
if (!$staff) {
    bp_send_json([
        'status' => false,
        'message' => 'Employee not found',
    ], 404);
}

$staffId = trim((string)($staff['employee_id'] ?? ''));
if ($staffId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Employee id mapping failed',
    ], 500);
}

$items = bp_fetch_notifications($staffId, $unreadOnly, max(1, $limit));
$unreadCount = bp_notifications_unread_count($staffId);

bp_send_json([
    'status' => true,
    'message' => 'Notifications loaded',
    'data' => [
        'staff_unique_id' => $staffId,
        'staff_row_unique_id' => (string)($staff['unique_id'] ?? ''),
        'employee_id' => $staffId,
        'unread_count' => $unreadCount,
        'items' => $items,
        'count' => count($items),
    ],
]);
