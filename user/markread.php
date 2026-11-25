<?php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']); exit;
}
if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid session']); exit;
}
$id = (int)($data['id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false]); exit; }

$stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
echo json_encode(['success' => $stmt->rowCount() > 0]);