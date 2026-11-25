<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($page)) {
    $page = 'home';
}

if (!defined('SIDEBAR_PARTIAL')) {
    return;
}
?>

<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <a href="dashboard.php" class="brand-link">
    <img src="https://cdn.jsdelivr.net/gh/AdminLTE/AdminLTE@master/dist/img/AdminLTELogo.png" alt="AdminLTE Logo" class="brand-image img-circle elevation-3" style="opacity: .8">
    <span class="brand-text font-weight-light">PSU Internationalization</span>
  </a>

  <div class="sidebar">
    <div class="user-panel mt-3 pb-3 mb-3 d-flex">
      <div class="image">
        <img src="https://cdn.jsdelivr.net/gh/AdminLTE/AdminLTE@master/dist/img/user2-160x160.jpg" class="img-circle elevation-2" alt="User Image">
      </div>
      <div class="info">
        <a href="#" class="d-block"><?= htmlspecialchars($_SESSION['username']) ?></a>
      </div>
    </div>

    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
        <li class="nav-item">
          <a href="dashboard.php?page=home" class="nav-link <?= $page === 'home' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>Dashboard</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="dashboard.php?page=applications" class="nav-link <?= $page === 'applications' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-file-alt"></i>
            <p>Applications</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="dashboard.php?page=documents" class="nav-link <?= $page === 'documents' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-folder"></i>
            <p>Documents</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="dashboard.php?page=users" class="nav-link <?= $page === 'users' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-users"></i>
            <p>User Management</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="dashboard.php?page=notifications" class="nav-link <?= $page === 'notifications' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-bell"></i>
            <p>Notifications</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="dashboard.php?page=reports" class="nav-link <?= $page === 'reports' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-chart-bar"></i>
            <p>Reports & Analytics</p>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</aside>
