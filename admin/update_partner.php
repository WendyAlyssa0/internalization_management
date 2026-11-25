<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/* 1. AUTHENTICATION */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

/* 2. AUTHORIZATION */
if (!canManagePartners()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

/* 3. METHOD CHECK */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/* 4. CSRF PROTECTION */
$sentCsrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

/* 5. INPUT VALIDATION */
$id = filter_var($_POST['edit_id'] ?? 0, FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid Partner ID']);
    exit;
}

$institution_name = trim((string)($_POST['institution_name'] ?? ''));
$country_id       = filter_var($_POST['country_id'] ?? 0, FILTER_VALIDATE_INT);

if ($institution_name === '' || !$country_id) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Institution Name and Country are required']);
    exit;
}

$contact_first_name  = trim((string)($_POST['contact_first_name'] ?? ''));
$contact_middle_name = trim((string)($_POST['contact_mi'] ?? ''));
$contact_last_name   = trim((string)($_POST['contact_last_name'] ?? ''));
$contact_email       = trim((string)($_POST['contact_email'] ?? ''));
$agreement_name      = trim((string)($_POST['agreement_type'] ?? ''));
$expiry_date         = trim((string)($_POST['expiry_date'] ?? '')) ?: null;
$status              = trim((string)($_POST['status'] ?? 'Active'));
$notes               = trim((string)($_POST['notes'] ?? ''));

/* 6. DATABASE UPDATE */
try {
    $pdo = db();

    // Look up agreement_type_id based on the string name
    $agreementId = null;
    if ($agreement_name !== '') {
        $stmtAgr = $pdo->prepare("SELECT id FROM agreement_types WHERE agreement_name = ? LIMIT 1");
        $stmtAgr->execute([$agreement_name]);
        $agreementId = $stmtAgr->fetchColumn() ?: null;
    }

    // FIXED: Using the correct column names based on your database schema
    $sql = "
        UPDATE partners SET
            institution_name = ?,
            country_id = ?,
            contact_first_name = ?,
            contact_middle_name = ?,
            contact_last_name = ?,
            contact_email = ?,
            agreement_type_id = ?,
            expiry_date = ?,
            status = ?,
            notes = ?
        WHERE id = ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $institution_name,
        $country_id,
        $contact_first_name,
        $contact_middle_name,
        $contact_last_name,
        $contact_email,
        $agreementId,
        $expiry_date,
        $status,
        $notes,
        $id
    ]);

    // Check if the record actually exists
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Partner not found or no changes made']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Partner updated successfully']);

} catch (PDOException $e) {
    // 7. SAFE ERROR HANDLING
    error_log('update_partner.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred. Please try again.'
    ]);
}