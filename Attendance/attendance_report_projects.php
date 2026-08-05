<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

/**
 * Per-user scoped project list for the attendance-report Project dropdown.
 * Mirrors the web report's get_project_name() scoping so a user only sees the
 * projects (work_locations) they are allowed to filter by.
 *
 * Input  : staff_unique_id (or employee_id)
 * Output : all_allowed (whether an "All" option applies) + projects[]
 */

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
    $scope = is_array($context['scope'] ?? null) ? $context['scope'] : [];
    $scopeType = (string)($scope['scope_type'] ?? 'self');

    // Same guard as the report: only wider-than-self users get the dropdown.
    if ($scopeType === 'self' || $scopeType === '') {
        bp_send_json([
            'status' => false,
            'message' => 'You do not have access to the attendance report',
        ], 403);
    }

    $list = bp_att_scoped_project_list($context);

    bp_send_json([
        'status' => true,
        'message' => 'Projects loaded',
        'data' => [
            'all_allowed' => (bool)($list['all_allowed'] ?? false),
            'projects' => array_values($list['projects'] ?? []),
        ],
    ]);
} catch (Throwable $e) {
    error_log('bp_mobile_app attendance_report_projects fatal: ' . bp_att_error_text($e));
    bp_send_json([
        'status' => false,
        'message' => 'Failed to load projects',
        'error' => bp_att_error_text($e),
    ], 500);
}
