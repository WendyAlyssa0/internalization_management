<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_middleware.php';
require_once __DIR__ . '/../config/roles.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* 1. AUTHORIZATION */
requireLogin();

if (!canManagePrograms()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

header('Content-Type: application/json');

/* 2. METHOD CHECK */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

/* 3. CSRF PROTECTION */
$sentCsrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

/* 4. LOAD CONFIG & INPUT */
$departmentOptions = require __DIR__ . '/../config/academic_departments.php';

$programId            = (int)($_POST['id'] ?? 0);
$programName          = trim((string)($_POST['program_name'] ?? ''));
$programType          = trim((string)($_POST['program_type'] ?? ''));
$eligibleDepartment   = trim((string)($_POST['eligible_department'] ?? ''));
$accountTypeInput     = $_POST['eligible_account_type'] ?? '';
$eligibleAccountType  = is_string($accountTypeInput) ? trim($accountTypeInput) : '';
$status               = trim((string)($_POST['status'] ?? ''));
$countryId            = (int)($_POST['country_id'] ?? 0);
$partnerId            = (int)($_POST['partner_id'] ?? 0);
$startDate            = trim((string)($_POST['start_date'] ?? ''));
$endDate              = trim((string)($_POST['end_date'] ?? ''));
$description          = trim((string)($_POST['description'] ?? ''));

/* 5. VALIDATION */
if (
    $programId <= 0 ||
    $programName === '' ||
    $programType === '' ||
    ($eligibleDepartment !== 'All Departments' && !array_key_exists($eligibleDepartment, $departmentOptions)) ||
    !in_array($eligibleAccountType, ['Student', 'Faculty'], true) ||
    $status === '' ||
    $countryId <= 0 ||
    $partnerId <= 0 ||
    $startDate === '' ||
    $endDate === '' ||
    $description === ''
) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if ($endDate < $startDate) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'End date cannot be before start date.']);
    exit;
}

/* 6. DATABASE UPDATE */
try {
    $pdo = db();

    // Verify partner belongs to the selected country
    $partnerStmt = $pdo->prepare("
        SELECT status
        FROM partners
        WHERE id = ? AND country_id = ?
    ");
    $partnerStmt->execute([$partnerId, $countryId]);
    $partner = $partnerStmt->fetch(PDO::FETCH_ASSOC);

    if ($partner === false) {
        echo json_encode([
            'success' => false,
            'message' => 'The selected partner is not available for this country.'
        ]);
        exit;
    }

    // If partner is inactive, ensure the program isn't changing to a different inactive partner
    if (strcasecmp((string)$partner['status'], 'Active') !== 0) {
        $currentStmt = $pdo->prepare("
            SELECT partner_id, country_id
            FROM programs
            WHERE id = ?
        ");
        $currentStmt->execute([$programId]);
        $currentProgram = $currentStmt->fetch(PDO::FETCH_ASSOC);

        if (
            $currentProgram === false ||
            (int)$currentProgram['partner_id'] !== $partnerId ||
            (int)$currentProgram['country_id'] !== $countryId
        ) {
            echo json_encode([
                'success' => false,
                'message' => 'The selected partner is not available for this country.'
            ]);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        UPDATE programs SET
            program_name = ?,
            program_type = ?,
            eligible_department = ?,
            eligible_account_type = ?,
            status = ?,
            country_id = ?,
            partner_id = ?,
            start_date = ?,
            end_date = ?,
            description = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $programName,
        $programType,
        $eligibleDepartment,
        $eligibleAccountType,
        $status,
        $countryId,
        $partnerId,
        $startDate,
        $endDate,
        $description,
        $programId
    ]);

    // Check if a row was actually updated
    if ($stmt->rowCount() === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Program not found or no changes were made.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Program updated successfully.'
    ]);

} catch (PDOException $e) {
    // 7. SECURE ERROR HANDLING (No leaking to the browser)
    error_log('update_program.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Please try again later.'
    ]);
}