<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (empty($token)) {
    die("Invalid or missing token.");
}

// Verify token
$pdo = db();
$hashedToken = hash('sha256', $token);
$stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expiry > NOW() LIMIT 1");
$stmt->execute([$hashedToken]);
$user = $stmt->fetch();

if (!$user) {
    die("This password reset link is invalid or has expired.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        // Update password and clear token
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
        $update->execute([$hash, $user['id']]);
        
        $success = "Password successfully reset! You can now <a href='user_login.php'>login</a>.";
    }
}
?>
<!-- Add your HTML form here for entering the new password -->