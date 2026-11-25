<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

// Only Approving Officials (and Super Admin) can reject
if (!isApprover() && !isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to reject applications.']);
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid application ID.']);
    exit;
}

try {
    $pdo = db();

    // Update application status
    $stmt = $pdo->prepare("UPDATE applications SET status = 'rejected', reviewed_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Application not found or already rejected.']);
        exit;
    }

    // Fetch applicant ID for notification
    $application = $pdo->prepare("SELECT applicant_id FROM applications WHERE id = ? LIMIT 1");
    $application->execute([$id]);
    $applicantId = (int)$application->fetchColumn();

    // Send Notification
    if ($applicantId > 0) {
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
            VALUES (?, 'rejected', 'Application Rejected', 'Your application was rejected.', 0, NOW())
        ")->execute([$applicantId]);
    }

    // Log Action
    $pdo->prepare("
        INSERT INTO audit_trail (user_id, action, description, created_at)
        VALUES (?, 'reject_application', ?, NOW())
    ")->execute([
        (int)$_SESSION['user_id'],
        'Application #' . $id . ' was rejected.',
    ]);

    echo json_encode(['success' => true, 'message' => 'Application rejected successfully.']);

} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to reject application.']);
}