<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/* =========================
   RESPONSE HELPER
========================= */
function respond(int $status, bool $success, string $message, ?array $data = null): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ]);
    exit;
}

/* =========================
   AUTHENTICATION
========================= */
if (!isset($_SESSION['user_id'])) {
    respond(401, false, 'Unauthorized. Please log in.');
}

/* =========================
   AUTHORIZATION (Based on DFD)
   Only Campus Coordinator / OVPLIA Staff manage partner records.
   Super Admin retains full access.
========================= */
$role = strtolower(trim($_SESSION['role'] ?? ''));

$canManagePartners = in_array($role, [
    'super_admin', 'super admin',
    'campus_coordinator', 'campus coordinator',
    'ovplia_staff', 'ovplia staff',
], true);

if (!$canManagePartners) {
    respond(403, false, 'You do not have permission to manage partner records.');
}

/* =========================
   OPTIONAL: CSRF CHECK
========================= */
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    respond(419, false, 'Invalid or expired security token. Please refresh the page.');
}

try {
    $pdo = db();

    /* GET FORM DATA */
    $country_id     = trim($_POST['country_id'] ?? '');
    $country_manual = trim($_POST['country_manual'] ?? '');
    $institution    = trim($_POST['institution_name'] ?? '');
    $agreementName  = trim($_POST['agreement_type'] ?? '');
    $status         = ucfirst(strtolower(trim($_POST['status'] ?? 'Active')));
    
    $firstName      = trim($_POST['contact_first_name'] ?? '');
    $middleName     = trim($_POST['contact_mi'] ?? '');
    $lastName       = trim($_POST['contact_last_name'] ?? '');
    $email          = trim($_POST['contact_email'] ?? '');
    $expiry         = trim($_POST['expiry_date'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    /* =========================
       VALIDATION
    ========================= */
    if ($institution === '') {
        respond(422, false, 'Institution name is required.');
    }

    if (mb_strlen($institution) > 255) {
        respond(422, false, 'Institution name is too long.');
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, false, 'Invalid email address.');
    }

    if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
        respond(422, false, 'Expiry date must be in YYYY-MM-DD format.');
    }

    $allowedStatuses = ['Active', 'Inactive', 'Expired', 'Pending'];
    if (!in_array($status, $allowedStatuses, true)) {
        respond(422, false, 'Invalid status value.');
    }

    /* =========================
       HANDLE COUNTRY
    ========================= */
    $countryName = '';

    if ($country_id === '__other__') {
        $countryName = trim($country_manual);
    } elseif (is_numeric($country_id)) {
        $stmt = $pdo->prepare("SELECT country_name FROM countries_addresses WHERE id = ?");
        $stmt->execute([(int)$country_id]);
        $countryName = $stmt->fetchColumn();
    }

    if (!$countryName) {
        respond(422, false, 'Invalid or missing country.');
    }

    // Find or create in the countries table
    $stmt = $pdo->prepare("SELECT id FROM countries WHERE country_name = ?");
    $stmt->execute([$countryName]);
    $realCountryId = $stmt->fetchColumn();

    if (!$realCountryId) {
        $stmt = $pdo->prepare("INSERT INTO countries (country_name) VALUES (?)");
        $stmt->execute([$countryName]);
        $realCountryId = $pdo->lastInsertId();
    }

    /* =========================
       HANDLE AGREEMENT TYPE
    ========================= */
    $agreementId = null;
    if ($agreementName !== '') {
        $stmt = $pdo->prepare("SELECT id FROM agreement_types WHERE agreement_name = ?");
        $stmt->execute([$agreementName]);
        $agreementId = $stmt->fetchColumn();
        
        if (!$agreementId) {
            $stmt = $pdo->prepare("INSERT INTO agreement_types (agreement_name) VALUES (?)");
            $stmt->execute([$agreementName]);
            $agreementId = $pdo->lastInsertId();
        }
    }

    /* =========================
       DUPLICATE CHECK
    ========================= */
    $dupCheck = $pdo->prepare("
        SELECT id FROM partners 
        WHERE institution_name = ? AND country_id = ?
    ");
    $dupCheck->execute([$institution, (int)$realCountryId]);
    if ($dupCheck->fetchColumn()) {
        respond(409, false, 'A partner with this institution and country already exists.');
    }

    /* =========================
       INSERT PARTNER
    ========================= */
    $stmt = $pdo->prepare("
        INSERT INTO partners (
            institution_name, country_name, agreement_type_id, status, 
            contact_first_name, contact_middle_name, contact_last_name, 
            contact_email, expiry_date, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $institution, 
        (int)$realCountryId, 
        $agreementId ? (int)$agreementId : null, 
        $status,
        $firstName ?: null, 
        $middleName ?: null, 
        $lastName ?: null, 
        $email ?: null, 
        $expiry ?: null, 
        $notes ?: null,
        (int)$_SESSION['user_id']
    ]);

    $newId = (int)$pdo->lastInsertId();

    respond(200, true, 'Partner added successfully.', ['id' => $newId]);

} catch (Throwable $e) {
    error_log("Add Partner Error: " . $e->getMessage());
    respond(500, false, 'A database error occurred. Please try again.');
}