<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure roles helper is loaded
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

$currentRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
$currentRole = str_replace('_', ' ', $currentRole);
$isSuperAdmin = ($currentRole === 'super admin');

$page = (string)($_GET['page'] ?? 'home');

/* HELPERS */
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function activePage(string $current, string $page): string {
    return $current === $page ? 'active' : '';
}
function openMenu(array $items, string $page): string {
    return array_key_exists($page, $items) ? 'open' : '';
}

/* =========================================
   DEFINE MENUS WITH PERMISSION CHECKS
========================================= */
$menus = [];

// 1. Program Management
if (canManagePrograms()) {
    $menus['program_management'] = [
        'label' => 'Program Management', 'icon' => 'fa-solid fa-graduation-cap', 'group' => 'Program & Location',
        'items' => [
            'programs'         => ['icon' => 'fa-solid fa-book', 'label' => 'Programs'],
            'partners'         => ['icon' => 'fa-solid fa-building', 'label' => 'Partner Institutions'],
            'agreement_types'  => ['icon' => 'fa-solid fa-file-contract', 'label' => 'Agreement Types'],
            'program_category' => ['icon' => 'fa-solid fa-layer-group', 'label' => 'Program Category'],
        ],
    ];
}
if (canManageSystem()) {
    $menus['degree_programs'] = [
        'label' => 'Degree Programs', 'icon' => 'fa-solid fa-list', 'group' => 'Program & Location',
        'items' => [
            'degree_programs' => ['icon' => 'fa-solid fa-list', 'label' => 'Degree Program Lists'],
        ],
    ];
}

// 2. Location Management
if (canManagePartners()) {
    $menus['location_management'] = [
        'label' => 'Location Management', 'icon' => 'fa-solid fa-location-dot', 'group' => 'Program & Location',
        'items' => [
            'countries_addresses' => ['icon' => 'fa-solid fa-earth-americas', 'label' => 'Country & Address'],
        ],
    ];
}

// 3. Application Management
if (canManageActivities() || isApprover() || isAdmin()) {
    $menus['application_management'] = [
        'label' => 'Application Management', 'icon' => 'fa-solid fa-file-pen', 'group' => 'Applications',
        'items' => [
            'applications'        => ['icon' => 'fa-solid fa-file-lines', 'label' => 'Applications'],
            'documents'           => ['icon' => 'fa-solid fa-folder-open', 'label' => 'Submitted Documents'],
            'requirements'        => ['icon' => 'fa-solid fa-list-check', 'label' => 'Master Requirements'],
            'program_requirements'=> ['icon' => 'fa-solid fa-link', 'label' => 'Program Requirements'],
        ],
    ];
}

// 4. Student Mobility
if (canManageActivities() || isAdmin()) {
    $menus['student_mobility'] = [
        'label' => 'Student Mobility', 'icon' => 'fa-solid fa-plane-departure', 'group' => 'Mobility',
        'items' => [
            'inbound_students'  => ['icon' => 'fa-solid fa-user-graduate', 'label' => 'Inbound Students'],
            'outbound_students' => ['icon' => 'fa-solid fa-user-tie', 'label' => 'Outbound Students'],
            'student_management' => ['icon' => 'fa-solid fa-users', 'label' => 'Student Directory'],
            'approved_students'  => ['icon' => 'fa-solid fa-user-check', 'label' => 'Approved Students'],
            'travel_information' => ['icon' => 'fa-solid fa-passport', 'label' => 'Travel Information'],
            'activity_reports'   => ['icon' => 'fa-solid fa-chart-line', 'label' => 'Activity Reports'],
        ],
    ];
}

// 5. Communication
$menus['communication'] = [
    'label' => 'Communication', 'icon' => 'fa-solid fa-comments', 'group' => 'Communication',
    'items' => [
        'notifications' => ['icon' => 'fa-solid fa-bell', 'label' => 'Notifications'],
        'announcements' => ['icon' => 'fa-solid fa-bullhorn', 'label' => 'Announcements'],
    ],
];

// 6. Administration (Strict Admin/Super Admin Only)
if (canManageSystem() || adminCurrentRole() === 'campus coordinator') {
    $menus['users'] = [
        'label' => 'Users', 'icon' => 'fa-solid fa-user-gear', 'group' => 'Administration',
        'items' => [
            'users' => ['icon' => 'fa-solid fa-users', 'label' => 'User Accounts'],
        ],
    ];
}
if (canManageSystem()) {
    $menus['system_settings'] = [
        'label' => 'System & Settings', 'icon' => 'fa-solid fa-gear', 'group' => 'Administration',
        'items' => [
            'settings' => ['icon' => 'fa-solid fa-sliders', 'label' => 'General Settings'],
        ],
    ];
    $menus['audit_logs'] = [
        'label' => 'Audit Logs', 'icon' => 'fa-solid fa-clock-rotate-left', 'group' => 'Administration',
        'items' => [
            'audit_trail' => ['icon' => 'fa-solid fa-clock-rotate-left', 'label' => 'Audit Trail'],
        ],
    ];
}
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="main-sidebar" id="mainSidebar">
    <div class="sidebar-header">
        <div class="brand">
            <img src="../asset/img/psu_logo.png" alt="PSU logo" class="brand-logo">
            <div class="brand-text">
                <h4>PSU Internationalization</h4>
                <small>Admin Portal</small>
            </div>
        </div>
        <button class="sidebar-close" id="sidebarClose" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <ul class="sidebar-menu">
        <li>
            <a href="dashboard.php?page=home" class="top-link <?= activePage('home', $page) ?>">
                <i class="fa-solid fa-house"></i> <span>Home</span>
            </a>
        </li>

        <?php
        $lastGroup = null;
        foreach ($menus as $menu):
            if ($menu['group'] !== $lastGroup):
                $lastGroup = $menu['group'];
        ?>
            <li class="sidebar-group-label"><?= e(strtoupper($lastGroup)) ?></li>
        <?php endif; ?>

        <li class="sidebar-dropdown <?= openMenu($menu['items'], $page) ?>">
            <div class="dropdown-toggle">
                <span class="toggle-label">
                    <i class="<?= e($menu['icon']) ?>"></i>
                    <span><?= e($menu['label']) ?></span>
                </span>
                <i class="fa-solid fa-chevron-down"></i>
            </div>
            <div class="dropdown-menu">
                <?php foreach ($menu['items'] as $key => $item): ?>
                <a href="dashboard.php?page=<?= urlencode($key) ?>" class="<?= activePage($key, $page) ?>">
                    <i class="<?= e($item['icon']) ?>"></i> <?= e($item['label']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="sidebar-footer">
        <a href="../public/logout.php" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="logout-copy">
                <span class="logout-title">Logout</span>
                <span class="logout-sub">End your session</span>
            </span>
        </a>
    </div>
</div>
<script src="../asset/js/sidebar.js"></script>