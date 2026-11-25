<?php
require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
if (!in_array($role, [
    'admin',
    'super admin',
    'campus coordinator',
    'ovplia staff',
    'approving official',
    'bor secretary',
], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$pdo    = db();
$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
    exit();
}

try {
    switch ($action) {

       case 'approve':
    $stmt = $pdo->prepare("
        UPDATE applications
        SET status = 'approved', reviewed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$id]);
       $application = $pdo->prepare('SELECT applicant_id FROM applications WHERE id = ? LIMIT 1');
       $application->execute([$id]);
       $applicantId = (int)$application->fetchColumn();
       if ($applicantId > 0) {
           $pdo->prepare("
               INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
               VALUES (?, 'approval', 'Application Approved', 'Your application has been approved.', 0, NOW())
           ")->execute([$applicantId]);
       }
       $pdo->prepare("
           INSERT INTO audit_trail (user_id, action, description, created_at)
           VALUES (?, 'approve_application', ?, NOW())
       ")->execute([(int)$_SESSION['user_id'], 'Application #' . $id . ' was approved.']);
    echo json_encode(['success' => true, 'message' => 'Application approved successfully.']);
    break;

    case 'reject':
        $stmt = $pdo->prepare("
            UPDATE applications
            SET status = 'rejected', reviewed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $application = $pdo->prepare('SELECT applicant_id FROM applications WHERE id = ? LIMIT 1');
        $application->execute([$id]);
        $applicantId = (int)$application->fetchColumn();
        if ($applicantId > 0) {
            $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                VALUES (?, 'rejected', 'Application Rejected', 'Your application was rejected.', 0, NOW())
            ")->execute([$applicantId]);
        }
        $pdo->prepare("
            INSERT INTO audit_trail (user_id, action, description, created_at)
            VALUES (?, 'reject_application', ?, NOW())
        ")->execute([(int)$_SESSION['user_id'], 'Application #' . $id . ' was rejected.']);
        echo json_encode(['success' => true, 'message' => 'Application rejected.']);
        break;

        case 'delete':
            $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Application deleted.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}