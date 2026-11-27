<?php
/**
 * Shared logic for application_approve.php / application_reject.php / application_delete.php.
 * Every request: POST only, logged in, valid CSRF token, role allowed, campus allowed.
 * Always answers with JSON {success, message}.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

function applicationActionReply(bool $success, string $message, int $httpCode = 200): void
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

function handleApplicationAction(string $action): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        applicationActionReply(false, 'Invalid request method.', 405);
    }
    if (!isset($_SESSION['user_id'])) {
        applicationActionReply(false, 'Your session has expired. Please log in again.', 401);
    }
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        applicationActionReply(false, 'Invalid CSRF token. Please refresh the page and try again.', 403);
    }

    /* Same rules the buttons on applications.php use */
    $allowed = $action === 'delete'
        ? canManageSystem()
        : (isApprover() || isSuperAdmin());
    if (!$allowed) {
        applicationActionReply(false, 'You do not have permission to do that.', 403);
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        applicationActionReply(false, 'Invalid application.', 400);
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT a.id, a.status, u.campus_id
        FROM applications a
        LEFT JOIN users u ON u.id = a.applicant_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) {
        applicationActionReply(false, 'Application not found.', 404);
    }

    /* S1: a campus coordinator may only act on their own campus.
       ASSUMES $_SESSION['campus_id'] - same key as applications.php. */
    if (!isSuperAdmin() && !empty($_SESSION['campus_id']) && (int)$app['campus_id'] !== (int)$_SESSION['campus_id']) {
        applicationActionReply(false, 'This application belongs to another campus.', 403);
    }

    try {
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$id]);
            applicationActionReply(true, 'Application deleted.');
        }

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        /* The WHERE guard makes this atomic: two admins clicking at once cannot both succeed. */
        $upd = $pdo->prepare("
            UPDATE applications
            SET status = ?, reviewed_at = NOW()
            WHERE id = ? AND LOWER(TRIM(status)) IN ('submitted', 'under_review', 'pending')
        ");
        $upd->execute([$newStatus, $id]);

        if ($upd->rowCount() === 0) {
            $current = ucwords(str_replace('_', ' ', (string)$app['status']));
            applicationActionReply(false, "This application is already {$current} and can no longer be changed.", 409);
        }

        applicationActionReply(true, $action === 'approve' ? 'Application approved.' : 'Application rejected.');
    } catch (PDOException $e) {
        error_log("application {$action}: " . $e->getMessage());
        $msg = $action === 'delete'
            ? 'Cannot delete this application. It may have uploaded documents or other records linked to it.'
            : 'Database error. Please check the server log.';
        applicationActionReply(false, $msg, 500);
    }
}