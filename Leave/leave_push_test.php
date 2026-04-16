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
$title = bp_str($input, 'title', 'BP test notification');
$message = bp_str($input, 'message', 'Push notifications are working for this account.');
$route = bp_str($input, 'route', '/notifications');
$type = bp_str($input, 'type', 'test_notification');
$leaveUniqueId = bp_str($input, 'leave_unique_id', bp_str($input, 'leaveId'));

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

$employeeId = trim((string)($staff['employee_id'] ?? ''));
if ($employeeId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Employee id mapping failed',
    ], 500);
}

$push = bp_send_push_notification_to_staff(
    $employeeId,
    $title,
    $message,
    array_filter([
        'route' => $route,
        'type' => $type,
        'leaveId' => $leaveUniqueId !== '' ? $leaveUniqueId : null,
    ], static fn($value) => $value !== null && $value !== '')
);

bp_send_json([
    'status' => (bool)($push['sent'] ?? false),
    'message' => (bool)($push['sent'] ?? false)
        ? 'Push notification dispatched'
        : ((string)($push['error'] ?? '') ?: 'Push notification failed'),
    'data' => [
        'staff_unique_id' => $employeeId,
        'push' => $push,
    ],
], (bool)($push['sent'] ?? false) ? 200 : 500);
