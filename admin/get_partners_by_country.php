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
function respond(int $status, bool $success, string $message, $data = null): void
{
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
   AUTH
========================= */
if (!isset($_SESSION['user_id'])) {
    respond(401, false, 'Unauthorized. Please log in.');
}

/* =========================
   METHOD GUARD
========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, false, 'Method not allowed.');
}

/* =========================
   INPUT
========================= */
$countryId = filter_input(INPUT_GET, 'country_id', FILTER_VALIDATE_INT);
if ($countryId === false || $countryId === null || $countryId < 1) {
    respond(422, false, 'Invalid country_id.');
}

/* =========================
   HANDLE
========================= */
try {
    $pdo = db();

    /* Country existence check — distinguishes "no partners" from "bad country" */
    $chk = $pdo->prepare("SELECT 1 FROM countries WHERE id = ? LIMIT 1");
    $chk->execute([$countryId]);
    if (!$chk->fetchColumn()) {
        respond(404, false, 'Country not found.');
    }

    /* Case-insensitive status match (handles 'Active' or 'active') */
    $stmt = $pdo->prepare("
        SELECT id, institution_name
        FROM partners
        WHERE country_id = ?
          AND LOWER(status) = 'active'
        ORDER BY institution_name ASC
    ");
    $stmt->execute([$countryId]);
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, true, 'OK', $partners);

} catch (PDOException $e) {
    error_log('partners_by_country.php (PDO): ' . $e->getMessage());
    respond(500, false, 'A database error occurred. Please try again.');

} catch (Throwable $e) {
    error_log('partners_by_country.php: ' . $e->getMessage());
    respond(500, false, 'An unexpected error occurred.');
}