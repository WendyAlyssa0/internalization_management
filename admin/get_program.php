<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

try {

    $pdo = db();

    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid Program ID'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.program_name,
            p.program_type,
            p.partner_id,
            p.country_id,
            c.country_name,
            p.status,
            p.start_date,
            p.end_date,
            p.description
        FROM programs p
        LEFT JOIN countries_addresses c
            ON p.country_id = c.id
        WHERE p.id = ?
    ");

    $stmt->execute([$id]);

    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$program) {
        echo json_encode([
            'success' => false,
            'message' => 'Program not found'
        ]);
        exit;
    }

    echo json_encode($program);

} catch (PDOException $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

}