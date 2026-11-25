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

/* 2. AUTHORIZATION (Only Staff can move students) */
if (!isApprover() && !isAdmin() && !isSuperAdmin() && !canManageActivities()) {
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

$id        = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);
$direction = trim((string)($data['direction'] ?? ''));
$reason    = trim((string)($data['reason'] ?? ''));

if (!$id || $id < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid Student ID']);
    exit;
}

if (!in_array($direction, ['forward', 'back', 'reject'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid direction']);
    exit;
}

/* 6. WORKFLOW LOGIC */
$stages = [
    'submitted',
    'coordinator_review',
    'dean_approval',
    'iao_approval',
    'final_endorsement',
    'completed'
];

try {
    $pdo = db();

    // Fetch current stage and student info
    $stmt = $pdo->prepare("SELECT approval_stage, CONCAT(first_name, ' ', last_name) AS name FROM students WHERE id = ?");
    $stmt->execute([$id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    $current = $student['approval_stage'];
    
    // Prevent moving a rejected student without a specific "reinstate" action
    if ($current === 'rejected' && $direction !== 'reject') {
        http_response_code(409); // Conflict
        echo json_encode(['success' => false, 'message' => 'Cannot move a rejected student. Please reinstate them first.']);
        exit;
    }

    $index = array_search($current, $stages, true);

    if ($direction === 'reject') {
        $new = 'rejected';
    } elseif ($direction === 'forward') {
        $new = ($index !== false && $index < count($stages) - 1) ? $stages[$index + 1] : $current;
    } elseif ($direction === 'back') {
        $new = ($index !== false && $index > 0) ? $stages[$index - 1] : $current;
    }

    // If the stage didn't actually change, inform the user
    if ($new === $current) {
        echo json_encode(['success' => false, 'message' => 'Student is already at the ' . $current . ' stage.']);
        exit;
    }

    /* 7. UPDATE & AUDIT LOG */
    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("UPDATE students SET approval_stage = ?, remarks = ? WHERE id = ?");
    $updateStmt->execute([$new, $reason ?: null, $id]);

    // Log the action
    $adminId = (int)$_SESSION['user_id'];
    $logStmt = $pdo->prepare("
        INSERT INTO audit_trail (user_id, action, description, created_at) 
        VALUES (?, 'update_approval_stage', ?, NOW())
    ");
    $logStmt->execute([
        $adminId,
        "Moved Student #{$id} ({$student['name']}) from '{$current}' to '{$new}'. Reason: " . ($reason ?: 'N/A')
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Student moved to ' . str_replace('_', ' ', $new),
        'new_stage' => $new
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update_approval_stage.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}