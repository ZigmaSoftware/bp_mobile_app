<?php
declare(strict_types=1);

require_once __DIR__ . '/../Leave/leave_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_send_json([
        'status' => false,
        'message' => 'Method not allowed',
    ], 405);
}

function bp_payslip_valid_month(string $monthYear): bool
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthYear) === 1;
}

function bp_payslip_view_url(string $uniqueId): string
{
    if ($uniqueId === '') {
        return '';
    }

    return rtrim((string)BP_LEGACY_WEB_BASE_URL, '/')
        . '/hr/payslip_generation/view.php?unique_id=' . rawurlencode($uniqueId);
}

function bp_payslip_disabled_functions(): array
{
    $disabled = trim((string)ini_get('disable_functions'));
    if ($disabled === '') {
        return [];
    }

    $parts = array_map('trim', explode(',', $disabled));
    return array_values(array_filter($parts, static function (string $name): bool {
        return $name !== '';
    }));
}

function bp_payslip_shell_enabled(): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = bp_payslip_disabled_functions();
    return !in_array('shell_exec', $disabled, true);
}

function bp_payslip_http_get(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'bp_mobile_app/1.0');

            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = (string)curl_error($ch);
            curl_close($ch);

            if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
                return [
                    'ok' => true,
                    'body' => $body,
                    'status' => $status,
                    'content_type' => $contentType,
                    'error' => '',
                ];
            }

            return [
                'ok' => false,
                'body' => '',
                'status' => $status,
                'content_type' => $contentType,
                'error' => $error !== '' ? $error : ('HTTP ' . $status),
            ];
        }
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [
            'ok' => false,
            'body' => '',
            'status' => 0,
            'content_type' => '',
            'error' => 'Invalid URL',
        ];
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 25,
            'ignore_errors' => true,
            'header' => "User-Agent: bp_mobile_app/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false || $body === '') {
        return [
            'ok' => false,
            'body' => '',
            'status' => 0,
            'content_type' => '',
            'error' => 'Unable to fetch HTML source',
        ];
    }

    return [
        'ok' => true,
        'body' => $body,
        'status' => 200,
        'content_type' => '',
        'error' => '',
    ];
}

function bp_payslip_try_require_autoload(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $root = dirname(__DIR__, 3);
    $candidates = [
        __DIR__ . '/dompdf/autoload.inc.php',
        __DIR__ . '/vendor/autoload.php',
        rtrim((string)BP_BLUE_PLANET_ROOT, DIRECTORY_SEPARATOR) . '/vendor/autoload.php',
        $root . '/vendor/autoload.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            break;
        }
    }

    $loaded = true;
}

function bp_payslip_absolute_url(string $url, string $baseUrl): string
{
    if ($url === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $url) === 1) {
        return $url;
    }

    $parts = parse_url($baseUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    $scheme = (string)$parts['scheme'];
    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? ':' . (string)$parts['port'] : '';

    if (strpos($url, '//') === 0) {
        return $scheme . ':' . $url;
    }

    if (strpos($url, '/') === 0) {
        return $scheme . '://' . $host . $port . $url;
    }

    $basePath = isset($parts['path']) ? dirname((string)$parts['path']) : '';
    if ($basePath === DIRECTORY_SEPARATOR || $basePath === '.') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $port . rtrim($basePath, '/') . '/' . ltrim($url, '/');
}

function bp_payslip_mime_type(string $url, string $contentType = ''): string
{
    $type = trim(strtolower($contentType));
    if ($type !== '') {
        $semi = strpos($type, ';');
        if ($semi !== false) {
            $type = trim(substr($type, 0, $semi));
        }
    }

    if ($type !== '') {
        return $type;
    }

    $path = parse_url($url, PHP_URL_PATH);
    $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'png':
            return 'image/png';
        case 'jpg':
        case 'jpeg':
            return 'image/jpeg';
        case 'gif':
            return 'image/gif';
        case 'svg':
            return 'image/svg+xml';
        case 'webp':
            return 'image/webp';
        case 'css':
            return 'text/css';
        default:
            return 'application/octet-stream';
    }
}

function bp_payslip_local_asset(string $url): array
{
    $normalized = strtolower($url);
    $map = [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' => [
            'path' => __DIR__ . '/assets/bootstrap-5.3.3.min.css',
            'mime' => 'text/css',
        ],
        strtolower(rtrim((string)BP_LEGACY_WEB_BASE_URL, '/') . '/assets/images/logo.png') => [
            'path' => __DIR__ . '/assets/logo.png',
            'mime' => 'image/png',
        ],
    ];

    if (!isset($map[$normalized])) {
        return [
            'ok' => false,
            'body' => '',
            'content_type' => '',
        ];
    }

    $path = (string)$map[$normalized]['path'];
    if (!is_file($path)) {
        return [
            'ok' => false,
            'body' => '',
            'content_type' => '',
        ];
    }

    $body = @file_get_contents($path);
    if (!is_string($body) || $body === '') {
        return [
            'ok' => false,
            'body' => '',
            'content_type' => '',
        ];
    }

    return [
        'ok' => true,
        'body' => $body,
        'content_type' => (string)$map[$normalized]['mime'],
    ];
}

function bp_payslip_inline_assets(string $html, string $viewUrl): array
{
    $warnings = [];

    $html = preg_replace_callback(
        '/<link\b[^>]*href=(["\'])([^"\']+)\1[^>]*>/i',
        static function (array $matches) use ($viewUrl, &$warnings): string {
            $href = bp_payslip_absolute_url((string)$matches[2], $viewUrl);
            if (stripos($href, '.css') === false) {
                return $matches[0];
            }

            $res = bp_payslip_local_asset($href);
            if (empty($res['ok'])) {
                $res = bp_payslip_http_get($href);
            }
            if (empty($res['ok'])) {
                $warnings[] = 'css inline failed: ' . $href . ' (' . (string)($res['error'] ?? 'unknown') . ')';
                return $matches[0];
            }

            return "<style>\n" . (string)($res['body'] ?? '') . "\n</style>";
        },
        $html
    ) ?? $html;

    $html = preg_replace_callback(
        '/<img\b([^>]*?)src=(["\'])([^"\']+)\2([^>]*)>/i',
        static function (array $matches) use ($viewUrl, &$warnings): string {
            $src = bp_payslip_absolute_url((string)$matches[3], $viewUrl);
            $res = bp_payslip_local_asset($src);
            if (empty($res['ok'])) {
                $res = bp_payslip_http_get($src);
            }
            if (empty($res['ok'])) {
                $warnings[] = 'image inline failed: ' . $src . ' (' . (string)($res['error'] ?? 'unknown') . ')';
                return $matches[0];
            }

            $mime = bp_payslip_mime_type($src, (string)($res['content_type'] ?? ''));
            $body = (string)($res['body'] ?? '');
            if ($body === '') {
                $warnings[] = 'image inline failed: empty body for ' . $src;
                return $matches[0];
            }

            $dataUri = 'data:' . $mime . ';base64,' . base64_encode($body);
            return '<img' . $matches[1] . 'src="' . $dataUri . '"' . $matches[4] . '>';
        },
        $html
    ) ?? $html;

    return [
        'html' => $html,
        'warnings' => $warnings,
    ];
}

function bp_payslip_try_wkhtmltopdf(string $viewUrl, string $html = ''): array
{
    if (!bp_payslip_shell_enabled()) {
        return [
            'ok' => false,
            'engine' => 'wkhtmltopdf',
            'bytes' => '',
            'error' => 'shell_exec is disabled on server',
        ];
    }

    $bins = [];
    $envBin = trim((string)getenv('WKHTMLTOPDF_BIN'));
    if ($envBin !== '') {
        $bins[] = $envBin;
    }

    foreach (['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf', '/bin/wkhtmltopdf'] as $candidate) {
        $bins[] = $candidate;
    }

    $whichRaw = trim((string)@shell_exec('command -v wkhtmltopdf 2>/dev/null'));
    if ($whichRaw !== '') {
        $bins[] = $whichRaw;
    }

    $bins = array_values(array_unique(array_filter(array_map(
        static function ($value): string {
            return trim((string)$value);
        },
        $bins
    ), static function (string $value): bool {
        return $value !== '';
    })));

    if (empty($bins)) {
        return [
            'ok' => false,
            'engine' => 'wkhtmltopdf',
            'bytes' => '',
            'error' => 'wkhtmltopdf binary not found',
        ];
    }

    $runErrors = [];

    foreach ($bins as $bin) {
        if (strpos($bin, '/') !== false && !is_file($bin)) {
            continue;
        }

        $tmpPdfBase = tempnam(sys_get_temp_dir(), 'bp_pay_pdf_');
        if ($tmpPdfBase === false || $tmpPdfBase === '') {
            $runErrors[] = 'tmp file create failed';
            continue;
        }

        $tmpPdf = $tmpPdfBase . '.pdf';
        @unlink($tmpPdf);
        $tmpHtml = '';
        if ($html !== '') {
            $tmpHtmlBase = tempnam(sys_get_temp_dir(), 'bp_pay_html_');
            if ($tmpHtmlBase !== false && $tmpHtmlBase !== '') {
                $tmpHtml = $tmpHtmlBase . '.html';
                @file_put_contents($tmpHtml, $html);
            }
        }

        $inputs = [$viewUrl];
        if ($tmpHtml !== '' && is_file($tmpHtml) && filesize($tmpHtml) > 0) {
            // Prefer local HTML render first. This avoids SSL/network issues while preserving template.
            $inputs = [$tmpHtml, $viewUrl];
        }

        $commonFlags = ' --quiet --print-media-type --encoding utf-8'
            . ' --load-error-handling ignore --load-media-error-handling ignore'
            . ' --page-size A4 --margin-top 8mm --margin-bottom 8mm --margin-left 8mm --margin-right 8mm';

        foreach ($inputs as $input) {
            $cmd = escapeshellarg($bin) . $commonFlags;
            if (is_file((string)$input)) {
                $cmd .= ' --enable-local-file-access';
            }
            $cmd .= ' ' . escapeshellarg((string)$input)
                . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';

            $output = trim((string)@shell_exec($cmd));

            if (is_file($tmpPdf) && filesize($tmpPdf) > 500) {
                $bytes = @file_get_contents($tmpPdf);
                @unlink($tmpPdf);
                @unlink($tmpPdfBase);
                if ($tmpHtml !== '') {
                    @unlink($tmpHtml);
                }

                if (is_string($bytes) && $bytes !== '') {
                    return [
                        'ok' => true,
                        'engine' => 'wkhtmltopdf',
                        'bytes' => $bytes,
                        'error' => '',
                    ];
                }
            }

            if ($output !== '') {
                $runErrors[] = basename($bin) . ': ' . $output;
            } else {
                $runErrors[] = basename($bin) . ': command returned empty output';
            }
        }

        @unlink($tmpPdf);
        @unlink($tmpPdfBase);
        if ($tmpHtml !== '') {
            @unlink($tmpHtml);
        }
    }

    return [
        'ok' => false,
        'engine' => 'wkhtmltopdf',
        'bytes' => '',
        'error' => empty($runErrors)
            ? 'wkhtmltopdf failed to generate PDF'
            : implode(' | ', array_slice($runErrors, 0, 3)),
    ];
}

function bp_payslip_try_dompdf(string $html): array
{
    bp_payslip_try_require_autoload();
    if (!class_exists('\Dompdf\Dompdf')) {
        return [
            'ok' => false,
            'engine' => 'dompdf',
            'bytes' => '',
            'error' => 'dompdf not installed',
        ];
    }

    try {
        $options = class_exists('\Dompdf\Options') ? new \Dompdf\Options() : null;
        if ($options) {
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('dpi', 96);
            $dompdf = new \Dompdf\Dompdf($options);
        } else {
            $dompdf = new \Dompdf\Dompdf();
        }

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $bytes = $dompdf->output();

        if (!is_string($bytes) || $bytes === '') {
            return [
                'ok' => false,
                'engine' => 'dompdf',
                'bytes' => '',
                'error' => 'dompdf returned empty output',
            ];
        }

        return [
            'ok' => true,
            'engine' => 'dompdf',
            'bytes' => $bytes,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'engine' => 'dompdf',
            'bytes' => '',
            'error' => $e->getMessage(),
        ];
    }
}

function bp_payslip_try_mpdf(string $html): array
{
    bp_payslip_try_require_autoload();
    if (!class_exists('\Mpdf\Mpdf')) {
        return [
            'ok' => false,
            'engine' => 'mpdf',
            'bytes' => '',
            'error' => 'mpdf not installed',
        ];
    }

    try {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => sys_get_temp_dir(),
        ]);
        $mpdf->WriteHTML($html);
        $bytes = $mpdf->Output('', 'S');

        if (!is_string($bytes) || $bytes === '') {
            return [
                'ok' => false,
                'engine' => 'mpdf',
                'bytes' => '',
                'error' => 'mpdf returned empty output',
            ];
        }

        return [
            'ok' => true,
            'engine' => 'mpdf',
            'bytes' => $bytes,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'engine' => 'mpdf',
            'bytes' => '',
            'error' => $e->getMessage(),
        ];
    }
}

function bp_payslip_send_pdf(string $pdfBytes, string $fileName, string $engine): void
{
    if (function_exists('header_remove')) {
        @header_remove('Content-Type');
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-BP-PDF-Engine: ' . $engine);

    echo $pdfBytes;
    exit;
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
        'status' => false,
        'message' => 'No payslip found for this employee',
    ], 404);
}

$selectedMonth = $monthYearInput;
if ($selectedMonth === '' || !isset($monthSet[$selectedMonth])) {
    $selectedMonth = $months[0];
}

$detailRows = bp_fetch_rows(
    'salary_generation_details sd '
    . 'INNER JOIN salary_generation_master sm ON sm.unique_id = sd.master_unique_id',
    ['sd.unique_id', 'sd.month_year'],
    $baseWhere . ' AND sd.month_year = ' . bp_sql_quote($selectedMonth)
);

$row = $detailRows[0] ?? null;
if ($row === null) {
    bp_send_json([
        'status' => false,
        'message' => 'No payslip found for selected month',
    ], 404);
}

$uniqueId = trim((string)($row['unique_id'] ?? ''));
if ($uniqueId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Payslip unique id is missing',
    ], 500);
}

$viewUrl = bp_payslip_view_url($uniqueId);
if ($viewUrl === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Unable to resolve payslip view URL',
    ], 500);
}

$errors = [];
$htmlRes = bp_payslip_http_get($viewUrl);
$html = !empty($htmlRes['ok']) ? (string)($htmlRes['body'] ?? '') : '';

if ($html !== '') {
    $inline = bp_payslip_inline_assets($html, $viewUrl);
    $html = (string)($inline['html'] ?? $html);
    foreach ((array)($inline['warnings'] ?? []) as $warning) {
        $errors[] = (string)$warning;
    }
}

$wk = bp_payslip_try_wkhtmltopdf($viewUrl, $html);
if (!empty($wk['ok'])) {
    $fileName = 'payslip_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $employeeId)
        . '_' . $selectedMonth . '.pdf';
    bp_payslip_send_pdf((string)$wk['bytes'], $fileName, (string)$wk['engine']);
}
$errors[] = 'wkhtmltopdf: ' . (string)($wk['error'] ?? 'failed');

if ($html === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Failed to load payslip HTML for conversion',
        'debug' => [
            'view_url' => $viewUrl,
            'engines' => $errors,
            'html_error' => (string)($htmlRes['error'] ?? ''),
        ],
    ], 500);
}

$dom = bp_payslip_try_dompdf($html);
if (!empty($dom['ok'])) {
    $fileName = 'payslip_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $employeeId)
        . '_' . $selectedMonth . '.pdf';
    bp_payslip_send_pdf((string)$dom['bytes'], $fileName, (string)$dom['engine']);
}
$errors[] = 'dompdf: ' . (string)($dom['error'] ?? 'failed');

$mpdf = bp_payslip_try_mpdf($html);
if (!empty($mpdf['ok'])) {
    $fileName = 'payslip_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $employeeId)
        . '_' . $selectedMonth . '.pdf';
    bp_payslip_send_pdf((string)$mpdf['bytes'], $fileName, (string)$mpdf['engine']);
}
$errors[] = 'mpdf: ' . (string)($mpdf['error'] ?? 'failed');

bp_send_json([
    'status' => false,
    'message' => 'No server-side PDF engine is available',
    'debug' => [
        'view_url' => $viewUrl,
        'engines' => $errors,
        'hint' => 'Upload bp_mobile_app/Payslip/dompdf and bp_mobile_app/Payslip/assets, or install wkhtmltopdf on server for exact view.php format output.',
    ],
], 500);
