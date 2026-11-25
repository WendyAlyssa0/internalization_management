<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/role_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/* AUTH */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

/* ROLE — only admins/super admins can delete */
if (!canManageSystem() && !isAdmin() && !isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

/* CSRF */
$sent = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$sent)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

/* INPUT */
$id = (int)($_POST['id'] ?? 0);
$source = (string)($_POST['source'] ?? 'document');
$table = $source === 'applicant' ? 'user_documents' : ($source === 'document' ? 'documents' : '');
if ($id <= 0 || $table === '') {
    echo json_encode(['success' => false, 'message' => 'Missing ID']);
    exit;
}

$pdo = db();

/* FETCH FILE PATH */
$row = $pdo->prepare("SELECT file_path FROM $table WHERE id = ?");
$row->execute([$id]);
$file = $row->fetchColumn();

if ($file === false) {
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}

/* DELETE */
try {
    $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);

    /* Unlink safely — only inside /uploads */
    if (!empty($file)) {
        $abs = realpath(__DIR__ . '/../' . $file);
        $uploadsRoot = realpath(__DIR__ . '/../uploads');

        if ($abs && $uploadsRoot && str_starts_with($abs, $uploadsRoot . DIRECTORY_SEPARATOR) && is_file($abs) && !unlink($abs)) {
            error_log('Unable to remove reviewed document file: ' . $abs);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Document deleted']);
} catch (Throwable $e) {
    error_log('document_delete: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}