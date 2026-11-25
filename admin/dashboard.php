<?php ob_start();

require_once __DIR__ . '/../config/auth_middleware.php';
require_once __DIR__ . '/role_helpers.php';

/*
 * Use requireLogin() instead of requireAdmin() so that
 * Coordinators, OVPLIA Staff, Approvers, and Admins can all
 * reach the dashboard shell. Each individual page enforces
 * its own RBAC (e.g. canManagePartners(), isApprover(), etc.).
 */
requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page = (string)($_GET['page'] ?? 'home');

/* =========================
   VALID PAGES (whitelist)
========================= */
$validPages = [
    'home',

    // Master Data
    'countries_addresses', 'partners', 'agreement_types', 'program_category', 'degree_programs',

    // Program Management
    'programs', 'requirements', 'forms', 'program_requirements',

    // Applications
    'applications', 'documents',

    // Student Mobility
    'inbound_students', 'outbound_students', 'student_management', 'approved_students', 'travel_information', 'activity_reports',

    // Communication
    'notifications', 'announcements',

    // Administration
    'users', 'audit_trail', 'settings',
];

// Enforce whitelist ONCE, before any require.
if (!in_array($page, $validPages, true)) {
    http_response_code(404);
    $page = 'home';
}

/* =========================
   POST HANDLERS (AJAX)
========================= */
$ajaxPages = [
    'countries_addresses', 'program_category', 'agreement_types', 'degree_programs',
    'partners', 'programs', 'requirements', 'forms', 'users',
    'program_requirements',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($page, $ajaxPages, true)) {
    require __DIR__ . '/' . $page . '.php';
    exit;
}

/* =========================
   USER CONTEXT
========================= */
$firstName = $_SESSION['first_name'] ?? 'Administrator';
$lastName  = $_SESSION['last_name']  ?? '';
$role      = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
$role      = str_replace('_', ' ', $role);
$fullName  = trim("$firstName $lastName");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PSU Internationalization Management System</title>
    <link rel="stylesheet" href="../asset/css/dashboard.css">
    <link rel="stylesheet" href="../asset/css/report.css">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">
</head>
<body>

<?php require_once 'sidebar.php'; ?>

<div class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Open menu">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <span class="topbar-title">PSU Internationalization</span>
    </div>
    <div class="user">
        <span class="material-symbols-outlined">account_circle</span>
        <span><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</div>

<div class="container">

<?php
/* =========================
   FLASH MESSAGES
========================= */
if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-info" style="margin:16px 0;padding:12px 16px;border-radius:8px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">
        <?= htmlspecialchars((string)$_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php unset($_SESSION['flash']);
endif;

/* =========================
   PAGE ROUTER
========================= */
switch ($page) {
    case 'home':                 require __DIR__ . '/reports.php'; break;
    case 'countries_addresses':  require __DIR__ . '/countries_addresses.php'; break;
    case 'partners':             require __DIR__ . '/partners.php'; break;
    case 'agreement_types':      require __DIR__ . '/agreement_types.php'; break;
    case 'program_category':     require __DIR__ . '/program_category.php'; break;
    case 'degree_programs':      require __DIR__ . '/degree_programs.php'; break;
    case 'programs':             require __DIR__ . '/programs.php'; break;
    case 'requirements':         require __DIR__ . '/requirements.php'; break;
    case 'forms':                require __DIR__ . '/forms.php'; break;
    case 'program_requirements': require __DIR__ . '/program_requirements.php'; break;
    case 'applications':         require __DIR__ . '/applications.php'; break;
    case 'documents':            require __DIR__ . '/documents.php'; break;
    case 'inbound_students':     require __DIR__ . '/inbound_students.php'; break;
    case 'outbound_students':    require __DIR__ . '/outbound_students.php'; break;
    case 'student_management':   require __DIR__ . '/student_management.php'; break;
    case 'travel_information':   require __DIR__ . '/travel_information.php'; break;
    case 'activity_reports':     require __DIR__ . '/activity_reports.php'; break;
    case 'approved_students':    require __DIR__ . '/approved_students.php'; break;
    case 'notifications':        require __DIR__ . '/notifications.php'; break;
    case 'announcements':        require __DIR__ . '/announcements.php'; break;
    case 'users':                require __DIR__ . '/users.php'; break;
    case 'audit_trail':          require __DIR__ . '/audit_trail.php'; break;
    case 'settings':             require __DIR__ . '/general_settings.php'; break;
    default:                     require __DIR__ . '/reports.php'; break;
}
?>
</div>

<script src="../asset/js/dashboard.js"></script>
</body>
</html>