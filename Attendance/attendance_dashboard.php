<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

try {
    $input = bp_input();
    $staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));

    if ($staffIdInput === '') {
        bp_send_json([
            'status' => false,
            'message' => 'staff_unique_id or employee_id is required',
        ], 400);
    }

    $context = bp_att_require_context($staffIdInput);
    $employeeId = (string)($context['employee_id'] ?? '');
    $projectLocations = bp_att_project_locations_for_context($context);
    $headOfficeAccess = bp_att_context_has_head_office_access($context, $projectLocations);

    bp_send_json([
        'status' => true,
        'message' => 'Attendance access loaded',
        'data' => [
            'employee' => [
                'staff_unique_id' => (string)($context['staff']['unique_id'] ?? ''),
                'employee_id' => $employeeId,
                'staff_name' => (string)($context['staff']['staff_name'] ?? ''),
                'reporting_officer' => (string)($context['staff']['reporting_officer'] ?? ''),
                'designation_name' => (string)($context['designation_name'] ?? ''),
                'department_name' => (string)($context['department_name'] ?? ''),
                'user_type_name' => (string)($context['user_type_name'] ?? ''),
            ],
            'role_label' => (string)($context['role_label'] ?? 'Employee'),
            'is_hr_user' => (bool)($context['is_hr_user'] ?? false),
            'is_reporting_officer' => (bool)($context['is_reporting_officer'] ?? false),
            'scope_type' => (string)(($context['scope']['scope_type'] ?? 'self')),
            'approval_access' => (bool)($context['is_hr_user'] ?? false),
            'pending_approvals_count' => !empty($context['is_hr_user'])
                ? bp_att_fetch_pending_approvals_count($context)
                : 0,
            'unread_notifications_count' => bp_att_notifications_unread_count($employeeId),
            'geofence_radius_meters' => 200,
            'project_locations' => $projectLocations,
            'can_use_direct_punch' => $headOfficeAccess,
            'can_configure_attendance_punch' => $headOfficeAccess,
            'attendance_features' => [
                'direct_punch' => $headOfficeAccess,
                'attendance_settings' => $headOfficeAccess,
                'head_office_project_id' => bp_att_head_office_project_id(),
            ],
            'server_time' => bp_now(),
        ],
    ]);
} catch (Throwable $e) {
    error_log('bp_mobile_app attendance_dashboard fatal: ' . bp_att_error_text($e));
    bp_send_json([
        'status' => false,
        'message' => 'Failed to load attendance access',
        'error' => bp_att_error_text($e),
    ], 500);
}
