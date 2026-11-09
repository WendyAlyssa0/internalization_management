<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
$pdo = db();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* =========================
   RESPONSE HELPER
========================= */
function respond(int $status, string $message, ?array $data = null): void
{
    http_response_code($status);
    echo json_encode([
        'status'  => $status,
        'success' => $status >= 200 && $status < 300,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* =========================
   ROLE HELPERS
========================= */
function isSuperAdmin(string $role): bool
{
    return $role === 'super admin';
}

function isAdmin(string $role): bool
{
    return $role === 'admin';
}

/* =========================
   AUTHENTICATION
========================= */
if (!isset($_SESSION['user_id'])) {
    respond(401, 'Unauthorized. Please log in.');
}

$currentUserId = (int)$_SESSION['user_id'];
$currentRole   = strtolower(trim($_SESSION['user_role'] ?? ''));

/* Only admins + super admins can access this endpoint */
if (!in_array($currentRole, ['admin', 'super admin'], true)) {
    respond(403, 'You do not have permission to perform this action.');
}

/* =========================
   VALIDATION
========================= */
function validate_fields(array $f): array
{
    $errors = [];

    $studentId = (int)($f['student_id'] ?? 0);
    if ($studentId <= 0) {
        $errors[] = 'Please select a student.';
    }

    $week = trim((string)($f['week_number'] ?? ''));
    if ($week === '') {
        $errors[] = 'Week number is required.';
    } elseif (!ctype_digit($week) || (int)$week < 1 || (int)$week > 52) {
        $errors[] = 'Week number must be between 1 and 52.';
    }

    $report = trim((string)($f['report'] ?? ''));
    if ($report === '') {
        $errors[] = 'Report is required.';
    } elseif (strlen($report) > 2000) {
        $errors[] = 'Report must not exceed 2000 characters.';
    }

    $progress = trim((string)($f['progress'] ?? ''));
    if ($progress === '') {
        $errors[] = 'Progress is required.';
    } elseif (!ctype_digit($progress) || (int)$progress < 0 || (int)$progress > 100) {
        $errors[] = 'Progress must be a number between 0 and 100.';
    }

    $status = trim((string)($f['status'] ?? ''));
    $valid  = ['ongoing', 'completed', 'delayed'];
    if ($status === '' || !in_array($status, $valid, true)) {
        $errors[] = 'Status must be one of: ongoing, completed, delayed.';
    }

    /* Optional field validation */
    $feedback = trim((string)($f['supervisor_feedback'] ?? ''));
    if (strlen($feedback) > 2000) {
        $errors[] = 'Supervisor feedback must not exceed 2000 characters.';
    }

    return $errors;
}

/* =========================
   ROUTER
========================= */
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

try {
    switch ($action) {

        /* ─────────────── CREATE (Admin + Super Admin) ─────────────── */
        case 'create':
            $errors = validate_fields($_POST);
            if ($errors) {
                respond(422, 'Validation failed.', ['errors' => $errors]);
            }

            // Prevent duplicate week for the same student
            $dupCheck = $pdo->prepare("
                SELECT COUNT(*) FROM activity_monitoring
                WHERE student_id = ? AND week_number = ?
            ");
            $dupCheck->execute([
                (int)$_POST['student_id'],
                (int)$_POST['week_number'],
            ]);
            if ((int)$dupCheck->fetchColumn() > 0) {
                respond(409, 'A report for this week already exists for this student.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO activity_monitoring
                    (student_id, week_number, report, supervisor_feedback, progress, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                (int)$_POST['student_id'],
                (int)$_POST['week_number'],
                trim($_POST['report']),
                trim($_POST['supervisor_feedback'] ?? ''),
                (int)$_POST['progress'],
                $_POST['status'],
            ]);

            respond(200, 'Activity record created successfully.', ['id' => (int)$pdo->lastInsertId()]);
            break;

        /* ─────────────── UPDATE (Admin + Super Admin) ─────────────── */
        case 'update':
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                respond(400, 'Invalid record ID.');
            }

            // Ensure record exists + grab current student & status
            $exists = $pdo->prepare("
                SELECT id, student_id, status
                FROM activity_monitoring
                WHERE id = ?
            ");
            $exists->execute([$id]);
            $existing = $exists->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                respond(404, 'Record not found.');
            }

            $errors = validate_fields($_POST);
            if ($errors) {
                respond(422, 'Validation failed.', ['errors' => $errors]);
            }

            $newStudentId = (int)$_POST['student_id'];
            $newStatus    = $_POST['status'];

            /* ---- SUPER ADMIN EXCLUSIVE RULES ---- */
            if (!isSuperAdmin($currentRole)) {

                // Admin cannot reassign a record to a different student
                if ($newStudentId !== (int)$existing['student_id']) {
                    respond(403, 'Admins cannot reassign a record to a different student.');
                }

                // Admin cannot reopen a completed report
                if ($existing['status'] === 'completed' && $newStatus !== 'completed') {
                    respond(403, 'Only a Super Admin can reopen a completed report.');
                }
            }

            $stmt = $pdo->prepare("
                UPDATE activity_monitoring
                SET student_id = ?, week_number = ?, report = ?,
                    supervisor_feedback = ?, progress = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $newStudentId,
                (int)$_POST['week_number'],
                trim($_POST['report']),
                trim($_POST['supervisor_feedback'] ?? ''),
                (int)$_POST['progress'],
                $newStatus,
                $id,
            ]);

            respond(200, 'Activity record updated successfully.', ['id' => $id]);
            break;

        /* ─────────────── DELETE (Super Admin ONLY) ─────────────── */
        case 'delete':
            if (!isSuperAdmin($currentRole)) {
                respond(403, 'Only a Super Admin can delete activity records.');
            }

            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                respond(400, 'Invalid record ID.');
            }

            $stmt = $pdo->prepare("DELETE FROM activity_monitoring WHERE id = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                respond(404, 'Record not found or already deleted.');
            }

            respond(200, 'Activity record deleted successfully.', ['id' => $id]);
            break;

        /* ─────────────── FETCH ONE (Admin + Super Admin) ─────────────── */
        case 'fetch':
            $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                respond(400, 'Invalid record ID.');
            }

            $stmt = $pdo->prepare("
                SELECT am.*, u.first_name, u.last_name
                FROM activity_monitoring am
                LEFT JOIN students s ON am.student_id = s.id
                LEFT JOIN users u ON s.user_id = u.id
                WHERE am.id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                respond(404, 'Record not found.');
            }

            respond(200, 'OK', $row);
            break;

        /* ─────────────── DEFAULT ─────────────── */
        default:
            respond(400, 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8'));
    }

} catch (PDOException $e) {
    error_log('activity_reports_api.php: ' . $e->getMessage());
    respond(500, 'A database error occurred. Please try again.');

} catch (Throwable $e) {
    error_log('activity_reports_api.php: ' . $e->getMessage());
    respond(500, 'An unexpected error occurred.');
}