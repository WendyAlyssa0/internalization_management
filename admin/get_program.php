<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid Program ID']);
    exit;
}

try {
    $pdo = db();
    // Notice the change from countries_addresses to countries based on your DB schema
    $stmt = $pdo->prepare("
        SELECT p.id, p.program_name, p.program_type, p.partner_id, p.country_id,
               c.country_name, p.status, p.start_date, p.end_date, p.description
        FROM programs p
        LEFT JOIN countries c ON p.country_id = c.id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$program) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Program not found']);
        exit;
    }

    echo json_encode($program);

} catch (PDOException $e) {
    error_log('get_program.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
}