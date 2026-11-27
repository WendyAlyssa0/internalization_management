<?php
// user/get_program_ajax.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/program_eligibility.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = db();
header('Content-Type: application/json');
ob_clean();

$programId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($programId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid program ID']);
    exit;
}

try {
    // Fetch program details (schema-safe)
    $stmt = $pdo->prepare("
        SELECT 
            p.id, 
            p.program_name, 
            p.description, 
            p.start_date, 
            p.end_date, 
            c.country_name, 
            partner.institution_name AS partner_name
        FROM programs p
        INNER JOIN users applicant ON applicant.id = ?
        LEFT JOIN degree_programs degree
            ON degree.campus_id = applicant.campus_id
           AND degree.program_name = applicant.program
        LEFT JOIN countries c ON c.id = p.country_id
        LEFT JOIN partners partner ON partner.id = p.partner_id
        WHERE p.id = ?
          AND p.status IN ('Active', 'Upcoming')
          AND " . programApplicationWindowSql() . "
          AND " . programEligibilitySql() . "
    ");
    $stmt->execute([(int)$_SESSION['user_id'], $programId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$program) {
        echo json_encode(['success' => false, 'message' => 'Program not found in database.']);
        exit;
    }

    // Fetch requirements
    $reqStmt = $pdo->prepare("
        SELECT r.requirement_name, r.description 
        FROM program_requirements pr
        JOIN requirements r ON r.id = pr.requirement_id
        WHERE pr.program_id = ?
    ");
    $reqStmt->execute([$programId]);
    $program['requirements'] = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $program]);
    exit;

} catch (Throwable $e) {
    // Catch ANY fatal errors or missing columns and send back as JSON
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    exit;
}