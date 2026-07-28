<?php
$page = $_GET['page'] ?? 'home';

/*
|--------------------------------------------------------------------------
| SIDEBAR MENU DEFINITION
|--------------------------------------------------------------------------
| Each top-level entry is a collapsible section with a label and a list
| of items (page key, icon class, display label). The Dashboard/Home
| link is handled separately since it isn't a dropdown.
*/
$menus = [
    'program_management' => [
        'label' => 'Program Management',
        'items' => [
            'programs'         => ['icon' => 'fa-solid fa-book',           'label' => 'Programs'],
            'partners'         => ['icon' => 'fa-solid fa-building',       'label' => 'Partner Institutions'],
            'agreement_types'  => ['icon' => 'fa-solid fa-file-contract',  'label' => 'Agreement Types'],
            'program_category' => ['icon' =>'fa-solid fa-layer-group',      'label' => 'Program Category'],
        ],
    ],

    'location_management' => [
        'label' => 'Location Management',
        'items' => [
            'countries_addresses' => ['icon' => 'fa-solid fa-earth-americas', 'label' => 'Country & Address'],
        ],
    ],

    'application_management' => [
        'label' => 'Application Management',
        'items' => [
            'applications'           => ['icon' => 'fa-solid fa-file-lines',   'label' => 'Applications'],
            'documents'              => ['icon' => 'fa-solid fa-folder-open',  'label' => 'Submitted Documents'],
            'requirements'           => ['icon' => 'fa-solid fa-list-check',   'label' => 'Requirements Management'],
            'program_requirements'   => ['icon' => 'fa-solid fa-list-check',   'label' => 'Program Requirements'],
        ],
    ],

    'form_management' => [
        'label' => 'Form Management',
        'items' => [
            'forms' => ['icon' => 'fa-solid fa-file-arrow-up', 'label' => 'Internalization Forms'],
        ],
    ],

    'mobility_management' => [
        'label' => 'Mobility Management',
        'items' => [
            'approved_students'   => ['icon' => 'fa-solid fa-user-graduate', 'label' => 'Approved Students'],
            'travel_information'  => ['icon' => 'fa-solid fa-passport',      'label' => 'Travel Information'],
            'activity_reports'    => ['icon' => 'fa-solid fa-chart-line',    'label' => 'Activity Reports'],
        ],
    ],

    'communication' => [
        'label' => 'Communication',
        'items' => [
            'notifications'  => ['icon' => 'fa-solid fa-bell',          'label' => 'Notifications'],
            'announcements'  => ['icon' => 'fa-solid fa-bullhorn',      'label' => 'Announcements'],
        ],
    ],

    'administration' => [
        'label' => 'Administration',
        'items' => [
            'users'        => ['icon' => 'fa-solid fa-users',               'label' => 'User Accounts'],
            'audit_trail'  => ['icon' => 'fa-solid fa-clock-rotate-left',   'label' => 'Audit Trail'],
        ],
    ],
];

function activePage(string $current, string $page): string
{
    return $current === $page ? 'active' : '';
}

function openMenu(array $items, string $page): string
{
    return array_key_exists($page, $items) ? 'open' : '';
}
?>

<!-- dark backdrop, click to close on mobile. Lives outside the
     sidebar itself (position: fixed, so DOM placement doesn't
     matter for layout) but declared here so it always ships
     alongside the sidebar markup -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="main-sidebar" id="mainSidebar">

    <!-- LOGO -->
    <div class="sidebar-header">
        <div class="brand">
            <div class="brand-text">
                <h4>PSU Internationalization</h4>
                <small>Admin Portal</small>
            </div>
        </div>
        <!-- mobile-only close (X); the hamburger that opens this
             lives in the topbar, not here, since the topbar is
             the only thing still visible when the drawer is shut -->
        <button class="sidebar-close" id="sidebarClose" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <ul class="sidebar-menu">

        <!-- DASHBOARD -->
        <li>
            <a href="dashboard.php?page=home" class="<?= activePage('home', $page) ?>">
                <i class="fa-solid fa-home"></i>
                <span>Home</span>
            </a>
        </li>

        <?php foreach ($menus as $menu): ?>

        <li class="sidebar-dropdown <?= openMenu($menu['items'], $page) ?>">
            <div class="dropdown-toggle">
                <span><?= htmlspecialchars($menu['label']) ?></span>
                <i class="fa-solid fa-chevron-down"></i>
            </div>

            <div class="dropdown-menu">
                <?php foreach ($menu['items'] as $key => $item): ?>
                <a href="dashboard.php?page=<?= urlencode($key) ?>" class="<?= activePage($key, $page) ?>">
                    <i class="<?= htmlspecialchars($item['icon']) ?>"></i>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </li>

        <?php endforeach; ?>

    </ul>

    <!-- FOOTER -->
    <div class="sidebar-footer">
        <a href="../public/logout.php" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>

</div>

<script src="../asset/js/sidebar.js"></script>