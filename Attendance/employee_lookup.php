<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$context = bp_att_require_context($staffIdInput);
$staff = is_array($context['staff'] ?? null) ? $context['staff'] : [];

bp_send_json([
    'status' => true,
    'message' => 'Employee loaded',
    'data' => [
        'employee_name' => (string)($staff['staff_name'] ?? ''),
        'designation_name' => (string)($context['designation_name'] ?? ''),
        'department_name' => (string)($context['department_name'] ?? ''),
        'employee_id' => (string)($context['employee_id'] ?? ''),
        'zigma_id' => (string)($staff['unique_id'] ?? ''),
        'role_label' => (string)($context['role_label'] ?? 'Employee'),
    ],
]);
