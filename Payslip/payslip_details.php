<?php
declare(strict_types=1);

require_once __DIR__ . '/../Leave/leave_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

function bp_payslip_safe_float($value): float
{
    if (is_numeric($value)) {
        return (float)$value;
    }
    return 0.0;
}

function bp_payslip_valid_month(string $monthYear): bool
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthYear) === 1;
}

function bp_payslip_fetch_staff_meta(string $employeeId): array
{
    $rows = bp_fetch_rows(
        'staff_test st '
        . 'LEFT JOIN staff_stat_details ss '
        . 'ON ss.unique_id = st.unique_id AND ss.is_active = 1 AND ss.is_delete = 0 '
        . 'LEFT JOIN staff_account_details_test sad '
        . 'ON sad.staff_unique_id = st.unique_id AND sad.is_active = 1 AND sad.is_delete = 0',
        [
            'ss.pf_number',
            'ss.uan_number',
            'st.esi_no',
            'st.date_of_join',
            'st.pan_no',
            'sad.bank_name',
            'sad.account_no',
        ],
        'st.employee_id = ' . bp_sql_quote($employeeId)
        . ' AND st.is_active = 1 AND st.is_delete = 0'
    );

    $meta = $rows[0] ?? [];
    return [
        'pf_number' => (string)($meta['pf_number'] ?? ''),
        'uan_number' => (string)($meta['uan_number'] ?? ''),
        'esi_number' => (string)($meta['esi_no'] ?? ''),
        'date_of_joining' => (string)($meta['date_of_join'] ?? ''),
        'pan_number' => (string)($meta['pan_no'] ?? ''),
        'bank_name' => (string)($meta['bank_name'] ?? ''),
        'account_number' => (string)($meta['account_no'] ?? ''),
    ];
}

function bp_payslip_add_deduct_totals(string $employeeId, string $monthYear): array
{
    $rows = bp_fetch_rows(
        'add_deduct_entry ade '
        . 'JOIN add_deduct_type adt ON adt.unique_id = ade.add_deduct_type_id',
        ['ade.value', 'adt.nature'],
        'ade.emp_unique_id = ' . bp_sql_quote($employeeId)
        . ' AND ade.entry_month = ' . bp_sql_quote($monthYear)
        . ' AND ade.is_active = 1 AND ade.is_delete = 0'
        . ' AND adt.is_active = 1 AND adt.is_delete = 0'
    );

    $addition = 0.0;
    $deduction = 0.0;
    foreach ($rows as $row) {
        $value = bp_payslip_safe_float($row['value'] ?? 0);
        $nature = (int)($row['nature'] ?? 0);
        if ($nature === 1) {
            $addition += $value;
        } else {
            $deduction += $value;
        }
    }

    return [
        'addition' => $addition,
        'deduction' => $deduction,
    ];
}

function bp_payslip_view_url(string $uniqueId): string
{
    if ($uniqueId === '') {
        return '';
    }

    return rtrim((string)BP_LEGACY_WEB_BASE_URL, '/')
        . '/hr/payslip_generation/view.php?unique_id=' . rawurlencode($uniqueId);
}

$input = bp_input();
$staffIdInput = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));
$monthYearInput = bp_str($input, 'month_year');

if ($staffIdInput === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

if ($monthYearInput !== '' && !bp_payslip_valid_month($monthYearInput)) {
    bp_send_json([
        'status' => false,
        'message' => 'Invalid month_year. Expected YYYY-MM',
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

$baseWhere = 'sd.employee_id = ' . bp_sql_quote($employeeId)
    . ' AND sm.status = 1 AND sm.is_delete = 0';

$monthRows = bp_fetch_rows(
    'salary_generation_details sd '
    . 'INNER JOIN salary_generation_master sm ON sm.unique_id = sd.master_unique_id',
    ['sd.month_year'],
    $baseWhere
);

$monthSet = [];
foreach ($monthRows as $row) {
    $month = trim((string)($row['month_year'] ?? ''));
    if ($month !== '' && bp_payslip_valid_month($month)) {
        $monthSet[$month] = true;
    }
}

$months = array_keys($monthSet);
rsort($months, SORT_STRING);

if (empty($months)) {
    bp_send_json([
        'status' => true,
        'message' => 'No payslip found for this employee',
        'data' => [
            'employee' => [
                'employee_id' => $employeeId,
                'staff_name' => (string)($staff['staff_name'] ?? ''),
                'staff_unique_id' => (string)($staff['unique_id'] ?? ''),
            ],
            'months' => [],
            'selected_month' => '',
            'payslip' => null,
        ],
    ]);
}

$selectedMonth = $monthYearInput;
if ($selectedMonth === '' || !isset($monthSet[$selectedMonth])) {
    $selectedMonth = $months[0];
}

$detailRows = bp_fetch_rows(
    'salary_generation_details sd '
    . 'INNER JOIN salary_generation_master sm ON sm.unique_id = sd.master_unique_id '
    . 'LEFT JOIN project_creation pc ON pc.unique_id = sm.project_id '
    . 'LEFT JOIN company_creation cc ON cc.unique_id = sm.company_id',
    [
        'sd.unique_id',
        'sd.month_year',
        'sd.employee_id',
        'sd.employee_name',
        'sd.department',
        'sd.designation',
        'sd.total_days',
        'sd.present_days',
        'sd.paid_leave',
        'sd.week_off',
        'sd.lop_days',
        'sd.payable_days',
        'sd.basic_earned',
        'sd.hra_earned',
        'sd.da_earned',
        'sd.stat_earned',
        'sd.special_earned',
        'sd.other_earned',
        'sd.gross_earned',
        'sd.pf',
        'sd.esi',
        'sd.pt',
        'sd.lwf',
        'sd.total_deduction',
        'sd.net_payable',
        'pc.project_name',
        'cc.company_name',
        'cc.address',
    ],
    $baseWhere . ' AND sd.month_year = ' . bp_sql_quote($selectedMonth)
);

$row = $detailRows[0] ?? null;

if ($row === null) {
    bp_send_json([
        'status' => true,
        'message' => 'No payslip found for selected month',
        'data' => [
            'employee' => [
                'employee_id' => $employeeId,
                'staff_name' => (string)($staff['staff_name'] ?? ''),
                'staff_unique_id' => (string)($staff['unique_id'] ?? ''),
            ],
            'months' => $months,
            'selected_month' => $selectedMonth,
            'payslip' => null,
        ],
    ]);
}

$addDeduct = bp_payslip_add_deduct_totals($employeeId, $selectedMonth);
$meta = bp_payslip_fetch_staff_meta($employeeId);

$payslip = [
    'unique_id' => (string)($row['unique_id'] ?? ''),
    'month_year' => (string)($row['month_year'] ?? ''),
    'employee_id' => (string)($row['employee_id'] ?? ''),
    'employee_name' => (string)($row['employee_name'] ?? ''),
    'department' => (string)($row['department'] ?? ''),
    'designation' => (string)($row['designation'] ?? ''),
    'company_name' => (string)($row['company_name'] ?? ''),
    'company_address' => (string)($row['address'] ?? ''),
    'project_name' => (string)($row['project_name'] ?? ''),
    'total_days' => bp_payslip_safe_float($row['total_days'] ?? 0),
    'present_days' => bp_payslip_safe_float($row['present_days'] ?? 0),
    'paid_leave' => bp_payslip_safe_float($row['paid_leave'] ?? 0),
    'week_off' => bp_payslip_safe_float($row['week_off'] ?? 0),
    'lop_days' => bp_payslip_safe_float($row['lop_days'] ?? 0),
    'payable_days' => bp_payslip_safe_float($row['payable_days'] ?? 0),
    'basic_earned' => bp_payslip_safe_float($row['basic_earned'] ?? 0),
    'hra_earned' => bp_payslip_safe_float($row['hra_earned'] ?? 0),
    'da_earned' => bp_payslip_safe_float($row['da_earned'] ?? 0),
    'stat_earned' => bp_payslip_safe_float($row['stat_earned'] ?? 0),
    'special_earned' => bp_payslip_safe_float($row['special_earned'] ?? 0),
    'other_earned' => bp_payslip_safe_float($row['other_earned'] ?? 0),
    'gross_earned' => bp_payslip_safe_float($row['gross_earned'] ?? 0),
    'pf' => bp_payslip_safe_float($row['pf'] ?? 0),
    'esi' => bp_payslip_safe_float($row['esi'] ?? 0),
    'pt' => bp_payslip_safe_float($row['pt'] ?? 0),
    'lwf' => bp_payslip_safe_float($row['lwf'] ?? 0),
    'addition' => $addDeduct['addition'],
    'deduction' => $addDeduct['deduction'],
    'total_deduction' => bp_payslip_safe_float($row['total_deduction'] ?? 0),
    'net_payable' => bp_payslip_safe_float($row['net_payable'] ?? 0),
    'pf_number' => $meta['pf_number'],
    'uan_number' => $meta['uan_number'],
    'esi_number' => $meta['esi_number'],
    'pan_number' => $meta['pan_number'],
    'date_of_joining' => $meta['date_of_joining'],
    'bank_name' => $meta['bank_name'],
    'account_number' => $meta['account_number'],
    'web_view_url' => bp_payslip_view_url((string)($row['unique_id'] ?? '')),
];

bp_send_json([
    'status' => true,
    'message' => 'Payslip loaded',
    'data' => [
        'employee' => [
            'employee_id' => $employeeId,
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'staff_unique_id' => (string)($staff['unique_id'] ?? ''),
        ],
        'months' => $months,
        'selected_month' => $selectedMonth,
        'payslip' => $payslip,
    ],
]);
