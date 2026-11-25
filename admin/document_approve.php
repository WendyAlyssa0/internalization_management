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

/* ROLE */
if (!isApprover() && !isAdmin() && !isSuperAdmin()) {
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

/* EXISTENCE */
$check = $pdo->prepare("SELECT status FROM $table WHERE id = ?");
$check->execute([$id]);
if ($check->fetchColumn() === false) {
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}

/* UPDATE */
try {
    $stmt = $pdo->prepare("UPDATE $table SET status = 'approved' WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Document approved']);
} catch (Throwable $e) {
    error_log('document_approve: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}