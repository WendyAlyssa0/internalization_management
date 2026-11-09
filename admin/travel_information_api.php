<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* =========================
   RESPONSE HELPER
========================= */
function respond(int $status, bool $success, string $message, ?array $data = null): void {
    http_response_code($status);
    echo json_encode([
        'status'  => $status,
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* =========================
   AUTH + ROLE
========================= */
if (!isset($_SESSION['user_id'])) {
    respond(401, false, 'Unauthorized.');
}

$currentRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
$currentRole = str_replace('_', ' ', $currentRole);
$isSuperAdmin = ($currentRole === 'super admin');
$isAdmin      = ($currentRole === 'admin');
$isStaff      = ($isAdmin || $isSuperAdmin);

$currentUserId = (int)$_SESSION['user_id'];

/* =========================
   METHOD + CSRF
========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Method not allowed.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$sentCsrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'], $sentCsrf)) {
    respond(403, false, 'Invalid CSRF token.');
}

/* =========================
   ROUTER
========================= */
$action = (string)($_POST['action'] ?? 'save');

/* ---------- DELETE ---------- */
if ($action === 'delete') {
    /* Only staff can delete travel records */
    if (!$isStaff) {
        respond(403, false, 'Only staff can delete travel records.');
    }

    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        respond(422, false, 'Invalid ID.');
    }

    try {
        $pdo = db();

        /* Existence check */
        $exists = $pdo->prepare("SELECT user_id FROM travel_information WHERE id = ? LIMIT 1");
        $exists->execute([$id]);
        $existing = $exists->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            respond(404, false, 'Travel record not found.');
        }

        /* Audit log before delete */
        try {
            $pdo->prepare("
                INSERT INTO audit_trail (user_id, action, description, created_at)
                VALUES (?, 'delete', ?, NOW())
            ")->execute([$currentUserId, "Deleted travel record #{$id} for user #{$existing['user_id']}"]);
        } catch (Throwable $auditErr) {
            error_log('travel delete audit: ' . $auditErr->getMessage());
        }

        $pdo->prepare("DELETE FROM travel_information WHERE id = ?")->execute([$id]);
        respond(200, true, 'Travel record deleted.');

    } catch (PDOException $e) {
        error_log('travel_information.php delete (PDO): ' . $e->getMessage());
        respond(500, false, 'Database error. Please try again.');
    }
}

/* ---------- SAVE (add / edit) ---------- */
if ($action !== 'save') {
    respond(400, false, 'Unknown action.');
}

/* Only staff can create/edit travel records */
if (!$isStaff) {
    respond(403, false, 'Only staff can manage travel records.');
}

/* =========================
   INPUT
========================= */
$id         = filter_var($_POST['id']         ?? 0, FILTER_VALIDATE_INT) ?: 0;
$userId     = filter_var($_POST['user_id']    ?? 0, FILTER_VALIDATE_INT);
$flight     = trim((string)($_POST['flight_number'] ?? ''));
$depDate    = trim((string)($_POST['departure_date']   ?? ''));
$depTime    = trim((string)($_POST['departure_time']   ?? ''));
$depAirport = trim((string)($_POST['departure_airport'] ?? ''));
$arrDate    = trim((string)($_POST['arrival_date']      ?? ''));
$arrTime    = trim((string)($_POST['arrival_time']      ?? ''));
$arrAirport = trim((string)($_POST['arrival_airport']   ?? ''));

/* =========================
   VALIDATION
========================= */
$errors = [];

if ($userId === false || $userId < 1) {
    $errors[] = 'Valid user is required.';
}
if ($flight === '') {
    $errors[] = 'Flight number is required.';
} elseif (mb_strlen($flight, 'UTF-8') > 50) {
    $errors[] = 'Flight number is too long (max 50).';
}
if ($depDate === '') {
    $errors[] = 'Departure date is required.';
}
if ($arrDate === '') {
    $errors[] = 'Arrival date is required.';
}

/* Date validation */
function parseDateStrict(string $value, string $fieldLabel, array &$errors): ?string {
    if ($value === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        $errors[] = "Invalid {$fieldLabel}.";
        return null;
    }
    return $value;
}

/* Time validation (HH:MM, optional :SS) */
function parseTimeStrict(string $value, string $fieldLabel, array &$errors): ?string {
    if ($value === '') return null;
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
        $errors[] = "Invalid {$fieldLabel}. Use HH:MM.";
        return null;
    }
    return $value;
}

$depDateValid = parseDateStrict($depDate, 'departure date', $errors);
$arrDateValid = parseDateStrict($arrDate, 'arrival date',   $errors);
$depTimeValid = parseTimeStrict($depTime, 'departure time', $errors);
$arrTimeValid = parseTimeStrict($arrTime, 'arrival time',   $errors);

/* Airport names */
if (mb_strlen($depAirport, 'UTF-8') > 150) $errors[] = 'Departure airport is too long.';
if (mb_strlen($arrAirport, 'UTF-8') > 150) $errors[] = 'Arrival airport is too long.';

/* Departure must be before arrival */
if ($depDateValid && $arrDateValid) {
    $depTs = strtotime($depDateValid . ' ' . ($depTimeValid ?: '00:00'));
    $arrTs = strtotime($arrDateValid . ' ' . ($arrTimeValid ?: '00:00'));
    if ($arrTs < $depTs) {
        $errors[] = 'Arrival must be on or after departure.';
    }
}

if ($errors) {
    respond(422, false, 'Validation failed.', ['errors' => $errors]);
}

/* =========================
   VERIFY USER EXISTS
========================= */
try {
    $pdo = db();

    $userStmt = $pdo->prepare("
        SELECT CONCAT(first_name, ' ', last_name) AS full_name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $userStmt->execute([$userId]);
    $applicantName = $userStmt->fetchColumn();

    if ($applicantName === false) {
        respond(404, false, 'User not found.');
    }

    $applicantName = trim((string)$applicantName) ?: 'Unknown';

    /* =========================
       INSERT / UPDATE
    ========================= */
    if ($id > 0) {
        /* Verify record exists */
        $existsStmt = $pdo->prepare("SELECT 1 FROM travel_information WHERE id = ? LIMIT 1");
        $existsStmt->execute([$id]);
        if (!$existsStmt->fetchColumn()) {
            respond(404, false, 'Travel record not found.');
        }

        $stmt = $pdo->prepare("
            UPDATE travel_information SET
                user_id           = ?,
                applicant_name    = ?,
                departure_date    = ?,
                departure_time    = ?,
                departure_airport = ?,
                flight_number     = ?,
                arrival_date      = ?,
                arrival_time      = ?,
                arrival_airport   = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $userId, $applicantName, $depDateValid, $depTimeValid ?: null,
            $depAirport ?: null, $flight, $arrDateValid, $arrTimeValid ?: null,
            $arrAirport ?: null, $id,
        ]);

        respond(200, true, 'Travel record updated.', ['id' => $id]);

    } else {
        $stmt = $pdo->prepare("
            INSERT INTO travel_information
                (user_id, applicant_name, departure_date, departure_time, departure_airport,
                 flight_number, arrival_date, arrival_time, arrival_airport)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId, $applicantName, $depDateValid, $depTimeValid ?: null,
            $depAirport ?: null, $flight, $arrDateValid, $arrTimeValid ?: null,
            $arrAirport ?: null,
        ]);

        respond(200, true, 'Travel record created.', ['id' => (int)$pdo->lastInsertId()]);
    }

} catch (PDOException $e) {
    error_log('travel_information.php save (PDO): ' . $e->getMessage());
    respond(500, false, 'Database error. Please try again.');

} catch (Throwable $e) {
    error_log('travel_information.php: ' . $e->getMessage());
    respond(500, false, 'An unexpected error occurred.');
}