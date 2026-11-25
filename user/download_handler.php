<?php
/*
 * download_handler.php
 * Handles ?download_type=template | user_doc | managed_form
 *
 * Location: user/ (same folder as dashboard.php and upload_documents.php)
 * It is required from the TOP of upload_documents.php, where $pdo already exists.
 * dashboard.php buffers output with ob_start(), so sending the file from here works.
 */
if (!isset($_GET['download_type'])) {
    return;
}

// No database connection yet in this scope: do nothing (a later require will handle it)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- Logged-in user ---------- */
$dlUserId = (int)($_SESSION['user_id'] ?? 0);
if ($dlUserId <= 0) {
    http_response_code(403);
    exit('Please log in.');
}

$dlType   = (string)$_GET['download_type'];
$dlRoot   = realpath(__DIR__ . '/..');          // project root (internalization_management)
$dlRel    = null;                               // path stored in the database
$dlName   = null;                               // nice download name
$dlInline = false;                              // open in browser instead of downloading

switch ($dlType) {
    case 'template':
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare("
            SELECT pr.requirement_file, r.requirement_name
            FROM program_requirements pr
            INNER JOIN requirements r ON r.id = pr.requirement_id
            INNER JOIN applications a ON a.program_id = pr.program_id
            WHERE pr.id = ? AND a.applicant_id = ?
            LIMIT 1
        ");
        $st->execute([$id, $dlUserId]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dlRel  = $row['requirement_file'];
            $dlName = $row['requirement_name'];
        }
        break;

    case 'user_doc':
        $req = (int)($_GET['req'] ?? 0);
        $st  = $pdo->prepare("SELECT file_path FROM user_documents WHERE user_id = ? AND requirement_id = ? LIMIT 1");
        $st->execute([$dlUserId, $req]);
        $dlRel    = $st->fetchColumn() ?: null;
        $dlInline = true;
        break;

    case 'managed_form':
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare("SELECT file_path, form_name FROM forms WHERE id = ? AND status = 'active' LIMIT 1");
        $st->execute([$id]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dlRel  = $row['file_path'];
            $dlName = $row['form_name'];
        }
        break;

    default:
        return;
}

if (!is_string($dlRel) || trim($dlRel) === '') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(404);
    exit('No file is attached to this item.');
}

/* Try the stored path as-is, then common folders in case only a filename was saved */
$dlRel = ltrim(str_replace('\\', '/', $dlRel), '/');
$candidates = [
    $dlRel,
    'uploads/requirements/' . basename($dlRel),
    'uploads/templates/'    . basename($dlRel),
    'uploads/forms/'        . basename($dlRel),
];

$dlFull = null;
foreach ($candidates as $c) {
    $p = realpath($dlRoot . '/' . $c);
    // must exist and stay inside <project>/uploads
    if ($p && is_file($p) && str_starts_with($p, $dlRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR)) {
        $dlFull = $p;
        break;
    }
}

if ($dlFull === null) {
    error_log('Download: file not found for stored path "' . $dlRel . '"');
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(404);
    exit('File not found on the server. Stored path: ' . htmlspecialchars($dlRel, ENT_QUOTES, 'UTF-8'));
}

$ext  = strtolower(pathinfo($dlFull, PATHINFO_EXTENSION));
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($dlFull) ?: 'application/octet-stream';
$name = $dlName ? preg_replace('/[^A-Za-z0-9 _\-.]/', '', $dlName) . '.' . $ext : basename($dlFull);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($dlFull));
header('Content-Disposition: ' . ($dlInline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
readfile($dlFull);
exit;