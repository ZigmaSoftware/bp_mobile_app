<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

function bp_att_profile_reporting_name(string $identifier): string
{
    $staff = bp_att_fetch_staff_full($identifier);
    return trim((string)($staff['staff_name'] ?? ''));
}

function bp_att_profile_date_of_joining(string $staffUniqueId): string
{
    $staffUniqueId = trim($staffUniqueId);
    if ($staffUniqueId === '') {
        return '';
    }

    $columns = bp_att_table_columns('staff_stat_details');
    if (empty($columns) || !isset($columns['unique_id']) || !isset($columns['date_of_join'])) {
        return '';
    }

    $where = 'unique_id = ' . bp_sql_quote($staffUniqueId);
    if (isset($columns['is_active'])) {
        $where .= ' AND is_active = 1';
    }
    if (isset($columns['is_delete'])) {
        $where .= ' AND is_delete = 0';
    }

    $row = bp_fetch_one('staff_stat_details', ['date_of_join'], $where);
    return trim((string)($row['date_of_join'] ?? ''));
}

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
$lookup = bp_att_fetch_employee_qr_lookup($staffIdInput);
$record = is_array($lookup['record'] ?? null) ? $lookup['record'] : [];

$staffUniqueId = trim((string)($staff['unique_id'] ?? ''));
$employeeId = trim((string)($context['employee_id'] ?? ''));
$reportingOfficer = trim((string)($staff['reporting_officer'] ?? ''));
$l1HeadName = bp_att_profile_reporting_name($reportingOfficer);
$l2HeadId = '';
if ($reportingOfficer !== '') {
    $l1Staff = bp_att_fetch_staff_full($reportingOfficer);
    $l2HeadId = trim((string)($l1Staff['reporting_officer'] ?? ''));
}

bp_send_json([
    'status' => true,
    'message' => 'Employee profile loaded',
    'data' => [
        'employee_details' => [
            'employee_name' => (string)($staff['staff_name'] ?? ''),
            'designation_name' => (string)($context['designation_name'] ?? ''),
            'department_name' => (string)($context['department_name'] ?? ''),
            'zigma_id' => $staffUniqueId,
            'employee_id' => $employeeId,
            'dob' => (string)($record['dob'] ?? ''),
            'blood_group' => (string)($record['blood_group'] ?? ''),
            'company_name' => (string)($record['company_name'] ?? ''),
        ],
        'head_details' => [
            'date_of_joining' => bp_att_profile_date_of_joining($staffUniqueId),
            'l1_head_name' => $l1HeadName,
            'l2_head_name' => bp_att_profile_reporting_name($l2HeadId),
        ],
        'image_url' => bp_att_first_url_candidate((array)($record['image_url_candidates'] ?? [])),
        'image_urls' => (array)($record['image_url_candidates'] ?? []),
        'qr_code_url' => bp_att_first_url_candidate((array)($record['qr_code_url_candidates'] ?? [])),
    ],
]);
