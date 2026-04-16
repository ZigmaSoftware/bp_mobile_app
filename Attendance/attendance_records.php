<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_helpers.php';

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
[$fromDate, $toDate] = bp_att_normalize_date_range($input);

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$context = bp_att_require_context($staffIdInput);
$employeeId = trim((string)($context['employee_id'] ?? ''));

if ($fromDate === null || $toDate === null) {
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-t');
}

$items = bp_att_fetch_actual_attendance_records(
    $employeeId,
    $fromDate,
    $toDate,
    (string)(($context['staff']['unique_id'] ?? ''))
);

$items = array_map(static function (array $item): array {
    $inImageCandidates = bp_att_media_url_candidates((string)($item['in_image_path'] ?? ''));
    $outImageCandidates = bp_att_media_url_candidates((string)($item['out_image_path'] ?? ''));
    $item['check_in_image_url'] = bp_att_first_url_candidate($inImageCandidates);
    $item['check_in_image_url_candidates'] = $inImageCandidates;
    $item['check_out_image_url'] = bp_att_first_url_candidate($outImageCandidates);
    $item['check_out_image_url_candidates'] = $outImageCandidates;
    return $item;
}, $items);

$legacyRecords = array_map(static function (array $item): array {
    $inImageCandidates = bp_att_media_url_candidates((string)($item['in_image_path'] ?? ''));
    $outImageCandidates = bp_att_media_url_candidates((string)($item['out_image_path'] ?? ''));

    return [
        'date' => (string)($item['legacy_date'] ?? ''),
        'in_time' => (string)($item['in_time'] ?? ''),
        'out_time' => (string)($item['out_time'] ?? ''),
        'in_latitude' => (string)($item['in_latitude'] ?? ''),
        'in_longitude' => (string)($item['in_longitude'] ?? ''),
        'in_image_path' => (string)($item['in_image_path'] ?? ''),
        'in_image_url' => bp_att_first_url_candidate($inImageCandidates),
        'in_image_url_candidates' => $inImageCandidates,
        'out_image_path' => (string)($item['out_image_path'] ?? ''),
        'out_image_url' => bp_att_first_url_candidate($outImageCandidates),
        'out_image_url_candidates' => $outImageCandidates,
        'in_site_name' => (string)($item['in_site_name'] ?? ''),
        'out_site_name' => (string)($item['out_site_name'] ?? ''),
        'total_worked_time' => (string)($item['total_worked_time'] ?? ''),
        'shift_hours' => (string)($item['shift_hours'] ?? ''),
        'day_status' => (string)($item['day_status'] ?? ''),
        'attendance_status' => (string)($item['attendance_status'] ?? ''),
        'out_longitude' => (string)($item['out_longitude'] ?? ''),
        'out_latitude' => (string)($item['out_latitude'] ?? ''),
        'approval_id' => (string)($item['approval_id'] ?? ''),
        'approval_status' => (string)($item['approval_status'] ?? ''),
        'approval_status_code' => (string)($item['approval_status_code'] ?? ''),
        'approval_time' => (string)($item['approval_time'] ?? ''),
        'approval_records' => (string)($item['approval_records'] ?? ''),
    ];
}, $items);

bp_send_json([
    'status' => true,
    'message' => 'Attendance records loaded',
    'source' => 'bp_mobile_app.vw_attendance_with_shift',
    'records' => $legacyRecords,
    'data' => [
        'employee' => [
            'staff_unique_id' => (string)($context['staff']['unique_id'] ?? ''),
            'employee_id' => $employeeId,
            'staff_name' => (string)($context['staff']['staff_name'] ?? ''),
            'reporting_officer' => (string)($context['staff']['reporting_officer'] ?? ''),
        ],
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'items' => $items,
        'server_time' => bp_now(),
    ],
]);
