<?php

/*
 * S35: Accreditation report downloads.
 * Requires: composer require dompdf/dompdf
 * Place this file next to dashboard.php (it must NOT be routed through it,
 * because dashboard.php outputs HTML and would corrupt PDF/ZIP output).
 *
 * ASSUMED SCHEMA (adjust to yours):
 *   activity_reports(title, narrative, created_at)
 *   documents(file_path, file_name, file_type)  -> files stored under ../uploads
 */

require_once __DIR__ . '/../config/auth_middleware.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;

requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!(canManageActivities() || isAdmin())) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo  = db();
$type = (string)($_GET['type'] ?? '');

function hh($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function pdfShell(string $title, string $body): string
{
    return '<html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 18px 0 6px; }
        small, .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        th { background: #f1f5f9; }
        hr { border: 0; border-top: 1px solid #e2e8f0; margin: 14px 0; }
    </style></head><body>
    <h1>Pangasinan State University - Internationalization Office</h1>
    <h2 style="margin-top:0">' . hh($title) . '</h2>
    <p class="muted">Generated ' . date('F j, Y g:i A') . '</p><hr>' . $body . '</body></html>';
}

function streamPdf(string $html, string $filename): void
{
    $pdf = new Dompdf();
    $pdf->loadHtml($html);
    $pdf->setPaper('A4');
    $pdf->render();
    $pdf->stream($filename, ['Attachment' => true]);
    exit;
}

/* ---------- Evaluation Report ---------- */
if ($type === 'evaluation') {
    $totals = [
        'Partners' => (int)$pdo->query("SELECT COUNT(*) FROM partners")->fetchColumn(),
        'Programs' => (int)$pdo->query("SELECT COUNT(*) FROM programs")->fetchColumn(),
        'Applications' => (int)$pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn(),
    ];

    $mobility = $pdo->query("
        SELECT u.account_type, a.mobility_type, COUNT(*) AS cnt
        FROM applications a
        LEFT JOIN users u ON a.applicant_id = u.id
        WHERE LOWER(a.status) = 'approved'
        GROUP BY u.account_type, a.mobility_type
        ORDER BY u.account_type, a.mobility_type
    ")->fetchAll(PDO::FETCH_ASSOC);

    $statuses = $pdo->query("
        SELECT mobility_type, status, COUNT(*) AS cnt
        FROM applications
        GROUP BY mobility_type, status
        ORDER BY mobility_type, status
    ")->fetchAll(PDO::FETCH_ASSOC);

    $body = '<h2>Overview</h2><table><tr><th>Metric</th><th>Total</th></tr>';
    foreach ($totals as $label => $n) {
        $body .= '<tr><td>' . hh($label) . '</td><td>' . $n . '</td></tr>';
    }
    $body .= '</table>';

    $body .= '<h2>Approved Mobility</h2><table><tr><th>Applicant Type</th><th>Direction</th><th>Count</th></tr>';
    foreach ($mobility as $r) {
        $body .= '<tr><td>' . hh($r['account_type'] ?? 'Unknown') . '</td><td>' . hh($r['mobility_type'])
               . '</td><td>' . (int)$r['cnt'] . '</td></tr>';
    }
    $body .= '</table>';

    $body .= '<h2>Applications by Mobility Type and Status</h2><table><tr><th>Direction</th><th>Status</th><th>Count</th></tr>';
    foreach ($statuses as $r) {
        $body .= '<tr><td>' . hh($r['mobility_type']) . '</td><td>' . hh(str_replace('_', ' ', (string)$r['status']))
               . '</td><td>' . (int)$r['cnt'] . '</td></tr>';
    }
    $body .= '</table>';

    streamPdf(pdfShell('Evaluation Report', $body), 'evaluation_report_' . date('Ymd') . '.pdf');
}

/* ---------- Narrative Report ---------- */
if ($type === 'narrative') {
    $rows = $pdo->query("
        SELECT title, narrative, created_at
        FROM activity_reports
        ORDER BY created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $body = '';
    if (!$rows) {
        $body = '<p class="muted">No narrative entries available.</p>';
    }
    foreach ($rows as $r) {
        $body .= '<h2>' . hh($r['title']) . '</h2><small>' . hh($r['created_at']) . '</small>'
               . '<p>' . nl2br(hh($r['narrative'])) . '</p><hr>';
    }

    streamPdf(pdfShell('Narrative Report', $body), 'narrative_report_' . date('Ymd') . '.pdf');
}

/* ---------- Pictures / Documentation ZIP ---------- */
if ($type === 'pictures') {
    $baseDir = realpath(__DIR__ . '/../uploads');
    if ($baseDir === false) {
        http_response_code(500);
        exit('Uploads directory not found.');
    }

    $rows = $pdo->query("
        SELECT file_path, file_name
        FROM documents
        WHERE file_type LIKE 'image/%'
           OR LOWER(file_path) REGEXP '\\\\.(jpe?g|png|gif|webp)$'
    ")->fetchAll(PDO::FETCH_ASSOC);

    $tmp = tempnam(sys_get_temp_dir(), 'doc');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('Could not create ZIP.');
    }

    $i = 0;
    foreach ($rows as $r) {
        $path = realpath($baseDir . '/' . ltrim((string)$r['file_path'], '/'));
        // block path traversal: file must live inside the uploads dir
        if ($path && str_starts_with($path, $baseDir . DIRECTORY_SEPARATOR) && is_file($path)) {
            $zip->addFile($path, (++$i) . '_' . basename((string)$r['file_name']));
        }
    }

    if ($i === 0) {
        $zip->addFromString('README.txt', 'No pictures or documentation found.');
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="documentation_' . date('Ymd') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

http_response_code(400);
echo 'Unknown report type.';