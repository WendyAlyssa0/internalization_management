<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/php.php'; // your helpers above

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$pdo = $GLOBALS['pdo'] ?? $pdo ?? null;

try {
    switch ($action) {
        case 'request':
            echo json_encode(requestPasswordReset($pdo, $_POST['email'] ?? ''));
            break;

        case 'verify':
            echo json_encode(verifyResetOTP($pdo, $_POST['email'] ?? '', $_POST['otp'] ?? ''));
            break;

        case 'reset':
            echo json_encode(resetPassword($pdo, $_POST['token'] ?? '', $_POST['password'] ?? ''));
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('[reset_api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error.']);
}