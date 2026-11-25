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

/* 2. AUTHORIZATION (Only Staff can update travel status) */
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

/* 4. CSRF PROTECTION */
$sentCsrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

/* 5. INPUT VALIDATION */
$id     = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
$status = trim((string)($_POST['status'] ?? ''));

if (!$id || $id < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid Travel Record ID']);
    exit;
}

/* 6. WHITELIST ALLOWED STATUSES */
$allowedStatuses = ['pending', 'traveling', 'completed', 'rejected'];
if (!in_array(strtolower($status), $allowedStatuses, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid status provided']);
    exit;
}

/* 7. DATABASE UPDATE */
try {
    $pdo = db();

    // Check if record exists (Note: using 'travel_information' as per your schema)
    $checkStmt = $pdo->prepare("SELECT travel_status FROM travel_information WHERE id = ?");
    $checkStmt->execute([$id]);
    $currentStatus = $checkStmt->fetchColumn();

    if ($currentStatus === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Travel record not found']);
        exit;
    }

    // Optional: Prevent redundant updates
    if (strtolower($currentStatus) === strtolower($status)) {
        echo json_encode(['success' => false, 'message' => 'Record is already set to this status.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE travel_information SET travel_status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    echo json_encode(['success' => true, 'message' => 'Travel status updated successfully']);

} catch (PDOException $e) {
    // Secure error handling: Log the real error, send a generic message
    error_log('update_travel_status.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred. Please try again.'
    ]);
}