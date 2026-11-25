<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/* =========================
   AUTHENTICATION
========================= */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized. Please log in.']);
    exit();
}

/* =========================
   AUTHORIZATION
========================= */
$role = strtolower(trim($_SESSION['role'] ?? ''));

$canManageAnnouncements = in_array($role, [
    'super_admin', 'super admin',
    'admin',
    'campus_coordinator', 'campus coordinator',
    'ovplia_staff', 'ovplia staff',
], true);

if (!$canManageAnnouncements) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'You do not have permission to manage announcements.']);
    exit();
}

/* =========================
   CSRF
========================= */
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

$pdo = db();

$id           = (int)($_POST['id'] ?? 0);
$title        = trim($_POST['title'] ?? '');
$announcement = trim($_POST['announcement'] ?? '');
$publish_date = trim($_POST['publish_date'] ?? '');
$status       = trim($_POST['status'] ?? 'draft');

/* =========================
   VALIDATION
========================= */
if ($title === '' || $announcement === '' || $publish_date === '') {
    echo json_encode(['ok' => false, 'message' => 'Title, content, and date are required.']);
    exit();
}

if (mb_strlen($title) > 255) {
    echo json_encode(['ok' => false, 'message' => 'Title is too long (max 255 chars).']);
    exit();
}

if (mb_strlen($announcement) > 5000) {
    echo json_encode(['ok' => false, 'message' => 'Announcement content is too long (max 5000 chars).']);
    exit();
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $publish_date)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid publish date format.']);
    exit();
}

$validStatuses = ['draft', 'published', 'archived'];
if (!in_array($status, $validStatuses, true)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid status value.']);
    exit();
}

try {
    if ($id > 0) {
        /* EDIT — verify record exists */
        $check = $pdo->prepare("SELECT id FROM announcements WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetchColumn()) {
            echo json_encode(['ok' => false, 'message' => 'Announcement not found.']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE announcements SET title = ?, announcement = ?, publish_date = ?, status = ? WHERE id = ?");
        $stmt->execute([$title, $announcement, $publish_date, $status, $id]);
        echo json_encode(['ok' => true, 'message' => 'Announcement updated.']);
    } else {
        /* CREATE */
        $stmt = $pdo->prepare("INSERT INTO announcements (title, announcement, publish_date, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$title, $announcement, $publish_date, $status]);
        echo json_encode(['ok' => true, 'message' => 'Announcement created.', 'id' => (int)$pdo->lastInsertId()]);
    }
} catch (PDOException $e) {
    error_log("Announcement Save Error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'A database error occurred. Please try again.']);
}