<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_middleware.php';
$departmentOptions = require __DIR__ . '/../config/academic_departments.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   AUTHORIZATION (Based on DFD)
   Program postings = Campus Coordinator / OVPLIA Staff
   Super Admin also allowed for oversight.
========================= */
$role = strtolower(trim($_SESSION['role'] ?? ''));
$canCreateProgram = in_array($role, [
    'super_admin', 'super admin',
    'campus_coordinator', 'campus coordinator',
    'ovplia_staff', 'ovplia staff',
    'admin', // optional: allow admin as backup
], true);

if (!$canCreateProgram) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Coordinators, OVPLIA Staff, or Admins can create programs.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

/* CSRF CHECK */
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh.']);
    exit;
}

header('Content-Type: application/json');

$pdo = db();

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $program_name = trim($_POST['program_name'] ?? '');
    $program_type = trim($_POST['program_type'] ?? '');
    $eligible_department = trim($_POST['eligible_department'] ?? '');
    $accountTypeInput = $_POST['eligible_account_type'] ?? '';
    $eligible_account_type = is_string($accountTypeInput) ? trim($accountTypeInput) : '';
    $description  = trim($_POST['description'] ?? '');
    $partner_id   = (int)($_POST['partner_id'] ?? 0);   
    $country_id   = (int)($_POST['country_id'] ?? 0);   
    $start_date   = trim($_POST['start_date'] ?? '');
    $end_date     = trim($_POST['end_date'] ?? '');    
    $status       = trim($_POST['status'] ?? '');

    /* =========================
       VALIDATION
    ========================= */
    $allowedTypes    = ['Exchange Program', 'Internship', 'Scholarship', 'Research', 'Seminar', 'Conference'];
    $allowedStatuses = ['Open', 'Closed', 'Upcoming', 'Ongoing'];

    if (
        $program_name === '' ||
        $program_type === '' ||
        !in_array($program_type, $allowedTypes, true) ||
        ($eligible_department !== 'All Departments' && !array_key_exists($eligible_department, $departmentOptions)) ||
        !in_array($eligible_account_type, ['Student', 'Faculty'], true) ||
        $description === '' ||
        $partner_id <= 0 ||
        $country_id <= 0 ||
        $start_date === '' ||
        $end_date === '' ||
        !in_array($status, $allowedStatuses, true)
    ) {
        echo json_encode(['success' => false, 'message' => 'All fields are required and must be valid.']);
        exit;
    }

    if (strlen($program_name) > 255) {
        echo json_encode(['success' => false, 'message' => 'Program name is too long.']);
        exit;
    }

    if (strlen($description) > 5000) {
        echo json_encode(['success' => false, 'message' => 'Description is too long.']);
        exit;
    }

    /* SECURE DATE VALIDATION */
    $startTs = strtotime($start_date);
    $endTs   = strtotime($end_date);

    if ($startTs === false || $endTs === false) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit;
    }

    if ($endTs < $startTs) {
        echo json_encode(['success' => false, 'message' => 'End date cannot be before start date.']);
        exit;
    }

    /* DUPLICATE CHECK */
    $dup = $pdo->prepare("
        SELECT id FROM programs 
        WHERE program_name = ? AND partner_id = ? AND start_date = ?
    ");
    $dup->execute([$program_name, $partner_id, $start_date]);
    if ($dup->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'A program with this name, partner, and start date already exists.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO programs
            (
                program_name,
                program_type,
                eligible_department,
                eligible_account_type,
                country_id,
                partner_id,
                status,
                start_date,
                end_date,
                description,
                created_by,
                created_at
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $program_name,
            $program_type,
            $eligible_department,
            $eligible_account_type,
            $country_id,
            $partner_id,
            $status,
            $start_date,
            $end_date,
            $description,
            (int)$_SESSION['user_id']
        ]);

        echo json_encode(['success' => true, 'message' => 'Program added successfully.', 'id' => (int)$pdo->lastInsertId()]);

    } catch (PDOException $e) {
        error_log("Add Program Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}