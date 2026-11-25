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

/* 2. AUTHORIZATION (Role Check) */
// Adjust this if you are using a different table (e.g., travel_information)
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
$id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
$status = trim((string)($_POST['status'] ?? ''));

if (!$id || $id < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

// Whitelist the allowed statuses so users can't inject arbitrary strings
$allowedStatuses = ['submitted', 'under_review', 'approved', 'rejected', 'traveling', 'completed'];
if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

/* 6. DATABASE UPDATE */
try {
    $pdo = db();
    
    // UPDATE THIS TABLE NAME if needed (e.g., 'travel_information')
    $stmt = $pdo->prepare("UPDATE applications SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    // Optional: Check if a row was actually updated
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Record not found or status unchanged']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);

} catch (PDOException $e) {
    error_log('update_mobility_status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}