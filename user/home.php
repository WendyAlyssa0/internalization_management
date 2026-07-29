<?php
session_start();
require_once __DIR__ . '/../config/auth_middleware.php';

requireApplicant();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    header('Location: ../user/user_login.php');
    exit();
}

/* PAGE ROUTING */
$page = trim((string)($_GET['page'] ?? 'home'));
$validPages = [
    'home',

    // Programs
    'available_programs',

    // Applications
    'apply_program',
    'my_applications',

    // Documents
    'upload_documents',

    // Forms
    'online_forms',
    'download_forms',
    'submitted_forms',

    // Mobility
    'travel_info',
    'activity_reports',

    // Communication
    'notifications',

    // Profile
    'profile',
    'change_password',
    'delete_account'
];

if (!in_array($page, $validPages, true)) { $page = 'home'; }

$firstName = $_SESSION['first_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Applicant Portal — PSUxIZN</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="../asset/css/home.css">
</head>
<body>

<div class="layout">

  <!-- ===================== SIDEBAR ===================== -->
<aside class="sidebar">

  <div class="sidebar-header">
    <img src="../asset/img/psu_logo.png" width="44" alt="PSU Logo">
    <div>
      <div class="logo-title">PSUxIZN</div>
      <div class="logo-subtitle">Applicant Portal</div>
    </div>
  </div>

<nav class="sidebar-nav">

<a href="?page=home" class="<?= $page==='home'?'active':'' ?>">
    <i class="fa-solid fa-house"></i>
    Home
</a>

<div class="nav-title">Programs</div>

<a href="?page=available_programs" class="<?= $page==='available_programs'?'active':'' ?>">
    <i class="fa-solid fa-book-open"></i>
    Available Programs
</a>

<a href="?page=apply_program" class="<?= $page==='apply_program'?'active':'' ?>">
    <i class="fa-solid fa-file-circle-plus"></i>
    Apply for a Program
</a>

<div class="nav-title">Applications</div>

<a href="?page=my_applications" class="<?= $page==='my_applications'?'active':'' ?>">
    <i class="fa-solid fa-folder-open"></i>
    My Applications
</a>

<div class="nav-title">Documents</div>

<a href="?page=upload_documents" class="<?= $page==='upload_documents'?'active':'' ?>">
    <i class="fa-solid fa-upload"></i>
    Upload Documents
</a>

<div class="nav-title">Forms</div>

<a href="?page=online_forms" class="<?= $page==='online_forms'?'active':'' ?>">
    <i class="fa-solid fa-pen-to-square"></i>
    Fill-Up Forms
</a>

<a href="?page=download_forms" class="<?= $page==='download_forms'?'active':'' ?>">
    <i class="fa-solid fa-download"></i>
    Download Forms
</a>

<a href="?page=submitted_forms" class="<?= $page==='submitted_forms'?'active':'' ?>">
    <i class="fa-solid fa-check-circle"></i>
    Submitted Forms
</a>

<div class="nav-title">Mobility</div>

<a href="?page=travel_info" class="<?= $page==='travel_info'?'active':'' ?>">
    <i class="fa-solid fa-passport"></i>
    Travel Information
</a>

<a href="?page=activity_reports" class="<?= $page==='activity_reports'?'active':'' ?>">
    <i class="fa-solid fa-chart-line"></i>
    Activity Reports
</a>

<a href="?page=notifications" class="<?= $page==='notifications'?'active':'' ?>">
    <i class="fa-solid fa-bell"></i>
    Notifications
</a>

<div class="nav-title">Account</div>

<a href="?page=profile" class="<?= $page==='profile'?'active':'' ?>">
    <i class="fa-solid fa-user"></i>
    Profile
</a>

<a href="?page=change_password" class="<?= $page==='change_password'?'active':'' ?>">
    <i class="fa-solid fa-key"></i>
    Change Password
</a>

<a href="?page=delete_account" class="<?= $page==='delete_account'?'active danger':'' ?>">
    <i class="fa-solid fa-trash"></i>
    Delete Account
</a>

<a href="../public/user_logout.php" class="danger">
    <i class="fa-solid fa-right-from-bracket"></i>
    Logout
</a>

</nav>
</aside>

  <!-- ===================== MAIN ===================== -->
  <div class="main">

    <!-- TOPBAR -->
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Applicant Portal</div>
        <div class="topbar-sub">PSU Internationalization</div>
      </div>

        <div class="topbar-right">
            <div class="user-info">
                <div class="account-avatar">
                    <?= strtoupper(substr($firstName,0,1)) ?>
                </div>

                <div>
                    <div class="account-name">
                        <?= htmlspecialchars($firstName) ?>
                    </div>
                    <div class="account-role">
                        Applicant
                    </div>
                </div>
            </div>
        </div>

          <div class="dropdown">
            <div class="dropdown-header">
              <div class="dh-name"><?= htmlspecialchars($firstName) ?></div>
              <div class="dh-email">Applicant Account</div>
            </div>
            <a href="?page=change_password">
              <i class="fa-solid fa-key"></i> Change Password
            </a>
            <div class="dropdown-divider"></div>
            <a href="?page=delete_account" class="danger"
              onclick="return confirm('Permanently delete your account?')">
              <i class="fa-solid fa-trash"></i> Delete Account
            </a>
            <div class="dropdown-divider"></div>
            <a href="../public/user_logout.php" class="danger">
              <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- PAGE CONTENT -->
    <?php if ($page === 'home'): ?>

    <?php elseif ($page === 'apply_program'): ?>
      <div class="box"><?php include __DIR__ . '/apply_program.php'; ?></div>
    <?php elseif ($page === 'my_applications'): ?>
      <div class="box"><?php include __DIR__ . '/my_applications.php'; ?></div>
    <?php elseif ($page === 'upload_documents'): ?>
      <div class="box"><?php include __DIR__ . '/upload_documents.php'; ?></div>
    <?php elseif ($page === 'online_forms'): ?>
    <div class="box"><?php include __DIR__ . '/online_forms.php'; ?></div>
    <?php elseif ($page === 'download_forms'): ?>
      <div class="box"><?php include __DIR__ . '/download_forms.php'; ?></div>
    <?php elseif ($page === 'submitted_forms'): ?>
      <div class="box"><?php include __DIR__ . '/submitted_forms.php'; ?></div>
    <?php elseif ($page === 'travel_info'): ?>
      <div class="box"><?php include __DIR__ . '/travel_information.php'; ?></div>
    <?php elseif ($page === 'activity_reports'): ?>
      <div class="box"><?php include __DIR__ . '/activity_reports.php'; ?></div>
    <?php elseif ($page === 'notifications'): ?>
      <div class="box"><?php include __DIR__ . '/user_notifications.php'; ?></div>
      <?php elseif ($page === 'available_programs'): ?>
      <div class="box"><?php include __DIR__ . '/available_programs.php'; ?></div>
    <?php elseif ($page === 'profile'): ?>
      <div class="box"><?php include __DIR__ . '/profile.php'; ?></div>
    <?php elseif ($page === 'change_password'): ?>
      <div class="box"><?php include __DIR__ . '/change_password.php'; ?></div>
    <?php elseif ($page === 'delete_account'): ?>
      <div class="box"><?php include __DIR__ . '/delete_account.php'; ?></div>
    <?php endif; ?>
  </div><!-- /main -->
</div><!-- /layout -->

<script src="../asset/js/home.js"></script>
</body>
</html>