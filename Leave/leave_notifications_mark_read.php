<?php
declare(strict_types=1);

require_once __DIR__ . '/leave_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));

$rawIds = $input['notification_ids'] ?? ($input['ids'] ?? null);
$ids = [];
if (is_string($rawIds)) {
    foreach (explode(',', $rawIds) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $ids[] = $part;
        }
    }
} elseif (is_array($rawIds)) {
    foreach ($rawIds as $id) {
        $id = trim((string)$id);
        if ($id !== '') {
            $ids[] = $id;
        }
    }
}

if ($staffIdInput === '' || empty($ids)) {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id and notification_ids are required',
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

$updated = bp_mark_notifications_read($staffId, $ids);
$unreadCount = bp_notifications_unread_count($staffId);

bp_send_json([
    'status' => true,
    'message' => 'Notifications updated',
    'data' => [
        'staff_unique_id' => $staffId,
        'staff_row_unique_id' => (string)($staff['unique_id'] ?? ''),
        'employee_id' => $staffId,
        'updated' => $updated,
        'unread_count' => $unreadCount,
    ],
]);
