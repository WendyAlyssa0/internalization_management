<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/* 1. AUTHENTICATION */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

/* 2. AUTHORIZATION (Only Staff can change student statuses) */
if (!canManageActivities() && !isAdmin() && !isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

/* 3. METHOD CHECK */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/* 4. GET & VALIDATE JSON INPUT */
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

/* 5. CSRF PROTECTION */
$sentCsrf = $data['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$id     = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);
$status = trim((string)($data['status'] ?? ''));

if (!$id || $id < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid Student ID']);
    exit;
}

/* 6. WHITELIST ALLOWED STATUSES */
$allowedStatuses = ['active', 'inactive', 'graduated', 'suspended'];
if (!in_array(strtolower($status), $allowedStatuses, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid status provided']);
    exit;
}

/* 7. DATABASE UPDATE */
try {
    $pdo = db();

    // Check if student exists
    $checkStmt = $pdo->prepare("SELECT status FROM students WHERE id = ?");
    $checkStmt->execute([$id]);
    $currentStatus = $checkStmt->fetchColumn();

    if ($currentStatus === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    if (strtolower($currentStatus) === strtolower($status)) {
        echo json_encode(['success' => false, 'message' => 'Student is already set to this status.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE students SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    echo json_encode(['success' => true, 'message' => 'Student status updated successfully']);

} catch (PDOException $e) {
    // Secure error handling: Log the real error, send a generic message
    error_log('update_student_status.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred. Please try again.'
    ]);
}