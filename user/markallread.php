<?php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false]); exit; }
if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid session']); exit;
}

$stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);