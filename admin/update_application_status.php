<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

/* 1. METHOD CHECK */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

/* 2. AUTH & ROLE CHECK */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isApprover() && !isAdmin() && !isSuperAdmin() && !canManageActivities()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$admin_id = (int)$_SESSION['user_id'];
$pdo = db();

/* 3. INPUT & CSRF VALIDATION */
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

// Validate CSRF token sent in the JSON payload or POST array
$sentCsrf = $data['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

$id     = isset($data['id']) ? (int)$data['id'] : 0;
$status = $data['status'] ?? null;
$action = $data['action'] ?? null;

if ($id < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid Application ID']);
    exit();
}

/* 4. HANDLE DELETE */
if ($action === 'delete') {
    try {
        $stmt = $pdo->prepare('DELETE FROM applications WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Application not found']);
            exit();
        }

        echo json_encode(['success' => true, 'message' => 'Deleted']);
    } catch (PDOException $e) {
        error_log('Application delete failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
    exit();
}

/* 5. HANDLE UPDATE */
$validStatuses = ['approved', 'rejected', 'submitted', 'under_review'];

if (!$status || !in_array($status, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status requested']);
    exit();
}

try {
    $pdo->beginTransaction();

    // NOTE: Change 'updated_at' to 'reviewed_at' if that is your actual column name.
    $stmt = $pdo->prepare(
        'UPDATE applications SET status = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$status, $id]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Application not found or status unchanged']);
        exit();
    }

    // Log to Audit Trail
    $log = $pdo->prepare(
        'INSERT INTO audit_trail (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())'
    );
    $log->execute([
        $admin_id,
        'update_application_status',
        'Application #' . $id . ' changed to ' . $status,
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Application updated successfully'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Secure error handling: Log the real error, send a generic one.
    error_log('Application update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Please try again.'
    ]);
}