<?php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

$pdo = db();
$userId = $_SESSION['user_id'];
$programId = (int)($_POST['program_id'] ?? 0);
$reqName = trim($_POST['requirement_name'] ?? '');

if (!$programId || !$reqName || empty($_FILES['document_file'])) {
    die("Invalid request.");
}

$file = $_FILES['document_file'];

// Validate
$allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
if (!in_array($file['type'], $allowedTypes)) {
    die("Invalid file type.");
}
if ($file['size'] > 5 * 1024 * 1024) {
    die("File is too large. Max 5MB.");
}

// Save file
$uploadDir = __DIR__ . '/../uploads/documents/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = "doc_{$userId}_{$programId}_" . preg_replace('/[^a-zA-Z0-9]/', '_', $reqName) . "_" . time() . "." . $ext;
$filePath = "uploads/documents/" . $filename;

if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
    die("Failed to save file.");
}

// Save to database (using the `documents` table)
$stmt = $pdo->prepare("
    INSERT INTO documents (user_id, title, file_path, file_type, status) 
    VALUES (?, ?, ?, ?, 'complete')
");
$stmt->execute([$userId, $reqName, $filePath, $file['type']]);

// Redirect back
header("Location: home.php?page=program_requirements&uploaded=1");
exit();