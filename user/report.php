<?php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userId = $_SESSION['user_id'] ?? null;
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$applications = [];

if ($userId) {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT a.id, a.status, a.mobility_type, a.created_at, a.reviewed_at, a.approval_date,
               p.program_name, c.country_name AS country
        FROM applications a
        LEFT JOIN programs p ON a.program_id = p.id
        LEFT JOIN countries c ON c.id = p.country_id
        WHERE a.applicant_id = ?
        ORDER BY a.id DESC
    ");
    $stmt->execute([$userId]);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$statusMap = [
    'submitted'    => ['label' => 'Submitted',    'class' => 'status-pending',  'icon' => 'ti-file-upload',  'step' => 1],
    'under_review' => ['label' => 'Under Review', 'class' => 'status-review',   'icon' => 'ti-clock',        'step' => 2],
    'approved'     => ['label' => 'Approved',     'class' => 'status-approved', 'icon' => 'ti-circle-check', 'step' => 3],
    'rejected'     => ['label' => 'Rejected',     'class' => 'status-rejected', 'icon' => 'ti-circle-x',     'step' => 3],
    'completed'    => ['label' => 'Completed',    'class' => 'status-completed','icon' => 'ti-flag-check',   'step' => 4],
];
?>