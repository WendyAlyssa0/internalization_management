<?php
$currentPage = $page ?? 'home';
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
        <a href="?page=home" class="nav-link <?= $currentPage === 'home' ? 'active' : '' ?>">
            <i class="fa-solid fa-house"></i> <span>Dashboard</span>
        </a>
        <a href="?page=available_programs" class="nav-link <?= in_array($currentPage, ['available_programs','program_detail']) ? 'active' : '' ?>">
            <i class="fa-solid fa-globe"></i> <span>Available Programs</span>
        </a>
        <a href="?page=my_applications" class="nav-link <?= in_array($currentPage, ['my_applications','application_status','my_application_detail']) ? 'active' : '' ?>">
            <i class="fa-regular fa-file-lines"></i> <span>My Applications</span>
        </a>
        <a href="?page=documents" class="nav-link <?= in_array($currentPage, ['documents','forms_requirements']) ? 'active' : '' ?>">
            <i class="fa-solid fa-folder-open"></i> <span>Documents</span>
        </a>

        <a href="?page=mobility_schedule" class="nav-link <?= $currentPage === 'mobility_schedule' ? 'active' : '' ?>">
            <i class="fa-regular fa-calendar-days"></i> <span>Mobility Schedule</span>
        </a>
        <a href="?page=notifications" class="nav-link <?= $currentPage === 'notifications' ? 'active' : '' ?>">
            <i class="fa-regular fa-bell"></i> <span>Notifications</span>
            <?php if (!empty($unreadCount)): ?><em><?= (int)$unreadCount ?></em><?php endif; ?>
        </a>

        <div class="nav-section">Account</div>
        <a href="?page=profile" class="nav-link <?= $currentPage === 'profile' ? 'active' : '' ?>">
            <i class="fa-regular fa-user"></i> <span>My Profile</span>
        </a>
    </nav>
</aside>