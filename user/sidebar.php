<?php
$currentPage = $page ?? 'home';

/* In-page sections shown as sub-items under the active menu entry.
   asset/js/user_scrollspy.js highlights the one in view and hides any
   item whose target id is not present on the page. */
$sectionsDocs = [['forms', 'Application Forms'], ['required-documents', 'Required Documents']];
$pageSections = [
    'documents'             => $sectionsDocs,
    'forms_requirements'    => $sectionsDocs,
    'program_detail'        => [['program-description', 'Description'], ['program-requirements', 'Requirements']],
    'my_application_detail' => [['application-details', 'Details'], ['application-requirements', 'Requirements']],
];

/* aria-current: "page" on the exact page, "true" when a related sub-page is open. */
$ariaCurrent = static function (array $group) use ($currentPage): string {
    if (!in_array($currentPage, $group, true)) {
        return '';
    }
    return $currentPage === $group[0] ? ' aria-current="page"' : ' aria-current="true"';
};

$renderSubnav = static function (array $group) use ($currentPage, $pageSections): void {
    if (!in_array($currentPage, $group, true) || empty($pageSections[$currentPage])) {
        return;
    }
    echo '<ul class="nav-subnav" aria-label="On this page">';
    foreach ($pageSections[$currentPage] as [$id, $label]) {
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        echo '<li><a class="nav-sublink" href="#' . $safeId . '" data-target="' . $safeId . '">'
           . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    echo '</ul>';
};

$gHome      = ['home'];
$gPrograms  = ['available_programs', 'program_detail'];
$gApps      = ['my_applications', 'application_status', 'my_application_detail'];
$gDocs      = ['documents', 'forms_requirements'];
?>
<aside class="sidebar" id="mainSidebar">
    <div class="brand">
        <div>
            <strong>PSU</strong>
            <b>Internationalization</b>
            <small>Applicant Portal</small>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="?page=home" class="nav-link <?= $currentPage === 'home' ? 'active' : '' ?>"<?= $ariaCurrent($gHome) ?>>
            <i class="fa-solid fa-house"></i> <span>Dashboard</span>
        </a>
        <a href="?page=available_programs" class="nav-link <?= in_array($currentPage, $gPrograms) ? 'active' : '' ?>"<?= $ariaCurrent($gPrograms) ?>>
            <i class="fa-solid fa-globe"></i> <span>Available Programs</span>
        </a>
        <?php $renderSubnav($gPrograms); ?>
        <a href="?page=my_applications" class="nav-link <?= in_array($currentPage, $gApps) ? 'active' : '' ?>"<?= $ariaCurrent($gApps) ?>>
            <i class="fa-regular fa-file-lines"></i> <span>My Applications</span>
        </a>
        <?php $renderSubnav($gApps); ?>
        <a href="?page=documents" class="nav-link <?= in_array($currentPage, $gDocs) ? 'active' : '' ?>"<?= $ariaCurrent($gDocs) ?>>
            <i class="fa-solid fa-folder-open"></i> <span>Documents</span>
        </a>
        <?php $renderSubnav($gDocs); ?>

        <a href="?page=mobility_schedule" class="nav-link <?= $currentPage === 'mobility_schedule' ? 'active' : '' ?>"<?= $ariaCurrent(['mobility_schedule']) ?>>
            <i class="fa-regular fa-calendar-days"></i> <span>Mobility Schedule</span>
        </a>
        <a href="?page=notifications" class="nav-link <?= $currentPage === 'notifications' ? 'active' : '' ?>"<?= $ariaCurrent(['notifications']) ?>>
            <i class="fa-regular fa-bell"></i> <span>Notifications</span>
            <?php if (!empty($unreadCount)): ?><em><?= (int)$unreadCount ?></em><?php endif; ?>
        </a>

        <div class="nav-section">Account</div>
        <a href="?page=profile" class="nav-link <?= $currentPage === 'profile' ? 'active' : '' ?>"<?= $ariaCurrent(['profile']) ?>>
            <i class="fa-regular fa-user"></i> <span>My Profile</span>
        </a>
    </nav>
</aside>