<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

/* --- CSRF check --- */
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    respond(['success' => false, 'error' => 'Session expired. Please refresh the page.'], 403);
}

/* --- Token validation --- */
$token   = trim($_POST['token'] ?? '');
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';

if (strlen($token) !== 64) {
    respond(['success' => false, 'error' => 'Invalid reset link.'], 400);
}

$hashed = hash('sha256', $token);

$pdo = db();
$stmt = $pdo->prepare("
    SELECT id FROM users
    WHERE reset_token = ?
      AND reset_token_expiry > NOW()
      AND status = 'active'
    LIMIT 1
");
$stmt->execute([$hashed]);
$userId = $stmt->fetchColumn();

if (!$userId) {
    respond(['success' => false, 'error' => 'This reset link has expired or been used. Please request a new one.'], 400);
}

/* --- Password validation --- */
if (strlen($password) < 8) {
    respond(['success' => false, 'error' => 'Password must be at least 8 characters.'], 400);
}
if ($password !== $confirm) {
    respond(['success' => false, 'error' => 'Passwords do not match.'], 400);
}

/* --- Update password + invalidate token --- */
$update = $pdo->prepare("
    UPDATE users
    SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL
    WHERE id = ?
");
$update->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);

/* --- Regenerate CSRF for safety --- */
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

respond(['success' => true, 'message' => 'Password reset successfully. Redirecting to login...']);