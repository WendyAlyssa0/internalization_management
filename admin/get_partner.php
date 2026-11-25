<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

// 1. Auth Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// 2. Validate ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(422);
    echo json_encode(["error" => "Invalid or missing ID"]);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT p.id, p.institution_name, c.country_name AS country,
               p.contact_first_name, p.contact_middle_name, p.contact_last_name,
               p.contact_email, a.agreement_name AS agreement_type,
               p.expiry_date, p.notes, p.status
        FROM partners p
        LEFT JOIN countries c ON p.country_id = c.id
        LEFT JOIN agreement_types a ON p.agreement_type_id = a.id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$partner) {
        http_response_code(404);
        echo json_encode(["error" => "Partner not found."]);
        exit;
    }

    echo json_encode($partner);

} catch (PDOException $e) {
    // 3. Safe Error Handling
    error_log('get_partner.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Database error occurred."]);
}