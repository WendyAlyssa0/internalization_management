<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/role_helpers.php';

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
if (!canManageAnnouncements()) {
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
$expiry_date  = trim($_POST['expiry_date'] ?? '');
$status       = trim($_POST['status'] ?? 'ongoing');

$existingAttachment = null;
if ($id > 0) {
    $existingStmt = $pdo->prepare('SELECT attachment_path FROM announcements WHERE id = ?');
    $existingStmt->execute([$id]);
    $existingAttachment = $existingStmt->fetchColumn();
    if ($existingAttachment === false) {
        echo json_encode(['ok' => false, 'message' => 'Announcement not found.']);
        exit();
    }
}

$attachmentPath = $existingAttachment ?: null;
if ($title === '' || $announcement === '' || $publish_date === '' || $expiry_date === '') {
    echo json_encode(['ok' => false, 'message' => 'Title, details, posting date, and expiry date are required.']);
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
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date) || $expiry_date < $publish_date) {
    echo json_encode(['ok' => false, 'message' => 'Expiry date must be on or after the posting date.']);
    exit();
}

$validStatuses = ['ongoing', 'completed'];
if (!in_array($status, $validStatuses, true)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid status value.']);
    exit();
}

$uploadedPath = null;
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['attachment'];
    $allowedMimes = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    $mime = $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])
        ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])
        : '';
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024 || !isset($allowedMimes[$mime])) {
        echo json_encode(['ok' => false, 'message' => 'Attachment must be a PDF, DOC, DOCX, JPG, or PNG file no larger than 10 MB.']);
        exit();
    }
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'announcements';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        echo json_encode(['ok' => false, 'message' => 'Unable to prepare announcement attachment storage.']);
        exit();
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        echo json_encode(['ok' => false, 'message' => 'Unable to save the announcement attachment.']);
        exit();
    }
    $uploadedPath = 'uploads/announcements/' . $filename;
    $attachmentPath = $uploadedPath;
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

        $stmt = $pdo->prepare("UPDATE announcements SET title = ?, announcement = ?, publish_date = ?, expiry_date = ?, attachment_path = ?, status = ? WHERE id = ?");
        $stmt->execute([$title, $announcement, $publish_date, $expiry_date, $attachmentPath, $status, $id]);
        echo json_encode(['ok' => true, 'message' => 'Announcement updated.']);
    } else {
        /* CREATE */
        $stmt = $pdo->prepare("INSERT INTO announcements (title, announcement, publish_date, expiry_date, attachment_path, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $announcement, $publish_date, $expiry_date, $attachmentPath, $status]);
        echo json_encode(['ok' => true, 'message' => 'Announcement created.', 'id' => (int)$pdo->lastInsertId()]);
    }
} catch (PDOException $e) {
    if ($uploadedPath !== null) {
        $uploadedFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadedPath);
        if (is_file($uploadedFile) && !unlink($uploadedFile)) {
            error_log('Unable to remove orphaned announcement upload: ' . $uploadedFile);
        }
    }
    error_log("Announcement Save Error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'A database error occurred. Please try again.']);
}