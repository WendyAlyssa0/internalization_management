<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit;
}

require __DIR__ . '/download_handler.php';


$pdo    = db();
$userId = (int)$_SESSION['user_id'];

/* Load full user row */
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../public/login.php');
    exit;
}

/* Whitelist of pages */
$routes = [
    'home'                  => 'home.php',
    'available_programs'    => 'available_programs.php',
    'program_detail'        => 'program_detail.php',
    'apply'                 => 'apply.php',
    'my_applications'       => 'my_applications.php',
    'application_status'    => 'my_applications.php',
    'my_application_detail' => 'my_application_detail.php',
    'documents'             => 'documents.php',
    'forms_requirements'    => 'documents.php',
    'partner_directory'     => 'partner_directory.php',
    'mobility_schedule'     => 'mobility_schedule.php',
    'travel_information'    => 'travel_information.php',
    'notifications'         => 'notifications.php',
    'profile'               => 'profile.php',
    'settings'              => 'profile.php',
];

$page  = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
if (!isset($routes[$page])) {
    $page = 'home';
}
$route = $routes[$page];

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Shared helpers */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function checkCsrf(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

/* Stream authorized document downloads */
$downloadType = is_string($_GET['download_type'] ?? null) ? $_GET['download_type'] : '';
if (in_array($page, ['documents', 'forms_requirements'], true) && $downloadType !== '') {
    $itemId = (int)($_GET['id'] ?? $_GET['req'] ?? 0);
    $storedPath = false;
    $disposition = 'inline';

    if ($downloadType === 'user_doc') {
        $stmt = $pdo->prepare('SELECT file_path FROM user_documents WHERE user_id = ? AND requirement_id = ? LIMIT 1');
        $stmt->execute([$userId, $itemId]);
        $storedPath = $stmt->fetchColumn();
    } elseif ($downloadType === 'template') {
        $stmt = $pdo->prepare("
            SELECT pr.requirement_file
            FROM program_requirements pr
            INNER JOIN applications a ON a.program_id = pr.program_id
            WHERE pr.id = ? AND a.applicant_id = ? AND a.status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([$itemId, $userId]);
        $storedPath = $stmt->fetchColumn();
        $disposition = 'attachment';
    } elseif ($downloadType === 'managed_form') {
        $stmt = $pdo->prepare("
            SELECT file_path
            FROM forms
            WHERE id = ? AND status = 'active' AND file_path LIKE 'uploads/forms/%'
            LIMIT 1
        ");
        $stmt->execute([$itemId]);
        $storedPath = $stmt->fetchColumn();
        $disposition = 'attachment';
    } else {
        http_response_code(400);
        exit('Invalid download request.');
    }

    $relativePath = is_string($storedPath) ? str_replace('\\', '/', $storedPath) : '';
    if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('~(^|/)\.\.(/|$)~', $relativePath)) {
        http_response_code(404);
        exit('File not found.');
    }

    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    $fullPath = realpath(__DIR__ . '/../' . $relativePath);
    if ($uploadsRoot === false || $fullPath === false || !str_starts_with($fullPath, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($fullPath)) {
        http_response_code(404);
        exit('File not found.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($fullPath) ?: 'application/octet-stream';
    ob_clean();
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . basename($fullPath) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($fullPath);
    exit;
}

$userName = trim((string)($user['first_name'] ?? 'Applicant'));

/* Fetch recent notifications + unread count */
$stmt = $pdo->prepare("SELECT id, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$userId]);
$recentNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$pageStyles = [
    'available_programs' => ['available_programs.css'],
    'program_detail' => ['apply.css'],
    'apply' => ['apply.css'],
    'my_applications' => ['my_applications.css'],
    'application_status' => ['my_applications.css'],
    'my_application_detail' => ['my_applications.css'],
    'profile' => ['profile.css'],
    'settings' => ['apply.css'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PSU Internationalization — Applicant Portal</title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- External CSS -->
    <link rel="stylesheet" href="../asset/css/user_accessibility.css">
    <link rel="stylesheet" href="../asset/css/upload_docs.css">
    <link rel="stylesheet" href="../asset/css/notifications.css">
    <?php foreach ($pageStyles[$page] ?? [] as $stylesheet): ?>
        <link rel="stylesheet" href="../asset/css/<?= h($stylesheet) ?>">
    <?php endforeach; ?>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* =====================================================
           1. CORE VARIABLES & RESET (Matched to Auth Pages)
           ===================================================== */
        :root {
            --psu-navy: #0b1a30;
            --psu-blue: #1c3d6e;
            --psu-gold: #e4b134;
            
            --bg-main: #eef2f7;
            --white: #ffffff;
            --text-main: #0f172a;
            --text-muted: #475569;
            --text-light: #94a3b8;
            
            --border-light: #e2e8f0;
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 20px;
            
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-card: 0 20px 40px -10px rgba(11, 26, 48, 0.1), 0 0 0 1px rgba(11, 26, 48, 0.02);
            
            --font-heading: 'Plus Jakarta Sans', sans-serif;
            --font-body: 'Inter', sans-serif;
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            
            --sidebar-w: 272px;
            --topbar-h: 72px;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; }
        
        body.applicant-portal {
            font-family: var(--font-body);
            background-color: var(--bg-main);
            /* Subtle map pattern to match auth pages */
            background-image: url('data:image/svg+xml;utf8,<svg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><path d="M10 10h80v80H10z" fill="none" stroke="%23cbd5e1" stroke-width="0.5" opacity="0.3"/></svg>');
            background-size: 800px;
            background-position: center;
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
            display: flex;
            min-height: 100vh;
        }

        a { color: inherit; text-decoration: none; }
        button { font: inherit; cursor: pointer; border: 0; background: none; color: inherit; }
        img { max-width: 100%; display: block; }
        
        h1, h2, h3, h4 { 
            font-family: var(--font-heading); 
            color: var(--psu-navy); 
            margin: 0; 
            line-height: 1.2; 
            letter-spacing: -0.02em; 
        }
        p { margin: 0; }

        /* Skip link */
        .skip-link {
            position: absolute; left: -9999px; top: auto;
            background: var(--psu-gold); color: var(--psu-navy);
            padding: 10px 16px; border-radius: 0 0 8px 0; z-index: 999; font-weight: 700;
        }
        .skip-link:focus { left: 0; top: 0; }

        /* =====================================================
           2. SIDEBAR
           ===================================================== */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: var(--sidebar-w);
            height: 100vh;
            overflow-y: auto;
            background: var(--psu-navy);
            /* Subtle grid pattern */
            background-image: 
                linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            color: var(--text-light);
            display: flex;
            flex-direction: column;
            padding: 24px 16px 20px;
            z-index: 40;
            border-right: 1px solid rgba(255,255,255,0.05);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 999px; }

        .brand { display: flex; align-items: center; gap: 12px; padding: 4px 8px 24px; border-bottom: 1px solid rgba(255,255,255,.08); margin-bottom: 20px; }
        .brand strong { display: block; font-family: var(--font-heading); font-size: 1.1rem; letter-spacing: .05em; color: var(--psu-gold); }
        .brand b { display: block; font-size: .85rem; font-weight: 700; letter-spacing: .02em; color: var(--white); }
        .brand small { display: block; font-size: .65rem; letter-spacing: .1em; color: rgba(255,255,255,.5); margin-top: 4px; text-transform: uppercase; }

        .sidebar-nav { display: flex; flex-direction: column; gap: 4px; flex: 1; }
        .nav-section { font-size: .65rem; font-weight: 700; letter-spacing: .12em; color: rgba(255,255,255,.4); margin: 20px 12px 8px; text-transform: uppercase; }
        .nav-section:first-child { margin-top: 0; }
        .nav-link { position: relative; display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: var(--radius-sm); font-size: .88rem; font-weight: 500; color: #cbd5e1; transition: var(--transition); border: 1px solid transparent; }
        .nav-link i { width: 18px; text-align: center; font-size: .95rem; opacity: .8; transition: var(--transition); color: var(--text-light); }
        .nav-link span { flex: 1; }
        .nav-link em { display: inline-grid; place-items: center; min-width: 22px; height: 22px; padding: 0 6px; border-radius: 999px; background: var(--psu-gold); color: var(--psu-navy); font-size: .7rem; font-weight: 800; font-style: normal; }
        .nav-link:hover { background: rgba(255,255,255,.06); color: var(--white); }
        .nav-link:hover i { opacity: 1; color: var(--psu-gold); }
        .nav-link.active { background: rgba(255, 255, 255, 0.1); color: var(--white); font-weight: 600; border-color: rgba(255, 255, 255, 0.1); }
        .nav-link.active i { color: var(--psu-gold); opacity: 1; }

        /* In-page section sub-items (scroll-spy) */
        html { scroll-behavior: smooth; }
        main.container [id] { scroll-margin-top: calc(var(--topbar-h) + 16px); }
        .nav-subnav { list-style: none; margin: 2px 0 8px 26px; padding: 0 0 0 12px; border-left: 1px solid rgba(255,255,255,.12); display: flex; flex-direction: column; gap: 2px; }
        .nav-subnav[hidden], .nav-subnav li[hidden] { display: none; }
        .nav-sublink { position: relative; display: block; padding: 6px 12px; border-radius: 6px; font-size: .8rem; font-weight: 500; color: #94a3b8; transition: var(--transition); }
        .nav-sublink:hover { color: var(--white); background: rgba(255,255,255,.05); }
        .nav-sublink.is-current { color: var(--psu-gold); background: rgba(228,177,52,.12); font-weight: 600; }
        .nav-sublink.is-current::before { content: ""; position: absolute; left: -13px; top: 50%; width: 2px; height: 60%; transform: translateY(-50%); background: var(--psu-gold); border-radius: 2px; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }

        /* =====================================================
           3. MAIN CONTENT AREA
           ===================================================== */
        main.container {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            width: calc(100% - var(--sidebar-w));
            margin-left: var(--sidebar-w);
            transition: margin-left 0.3s ease, width 0.3s ease;
        }

        .topbar {
            position: sticky; top: 0; z-index: 30;
            height: var(--topbar-h);
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px;
            padding: 0 32px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-light);
        }
        
        .topbar-left { display: flex; align-items: center; gap: 16px; }
        
        .sidebar-toggle {
            display: none;
            width: 40px; height: 40px;
            border-radius: var(--radius-sm);
            background: var(--bg-main);
            color: var(--psu-navy);
            align-items: center; justify-content: center;
            transition: background 0.2s;
        }
        .sidebar-toggle:hover { background: var(--border-light); }

        .topbar-title {
            font-family: var(--font-heading);
            font-weight: 800;
            font-size: 1.15rem;
            color: var(--psu-navy);
            letter-spacing: -0.01em;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Notification Bell */
        .notif-wrapper { position: relative; }
        .notification-bell {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--white);
            border: 1px solid var(--border-light);
            color: var(--text-muted);
            font-size: 1.1rem;
            transition: var(--transition);
        }
        .notification-bell:hover {
            background: var(--bg-main);
            color: var(--psu-navy);
            border-color: var(--psu-navy);
        }
        .notification-bell .badge {
            position: absolute;
            top: -4px; right: -4px;
            min-width: 20px; height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--white);
        }

        /* Notifications Dropdown */
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 340px;
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border-light);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px);
            transition: var(--transition);
            z-index: 100;
            overflow: hidden;
        }
        .notif-wrapper.open .notif-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .notif-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-main);
        }
        .notif-header h4 { font-size: 0.95rem; font-weight: 700; color: var(--psu-navy); }
        .notif-header a { font-size: 0.75rem; font-weight: 600; color: var(--psu-gold); }
        .notif-header a:hover { text-decoration: underline; }
        
        .notif-list { max-height: 320px; overflow-y: auto; padding: 8px; }
        .notif-item {
            display: flex; gap: 12px; padding: 12px; border-radius: var(--radius-sm);
            transition: background 0.2s;
        }
        .notif-item:hover { background: var(--bg-main); }
        .notif-item.unread { background: rgba(228, 177, 52, 0.05); }
        .notif-icon {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 0.85rem;
        }
        .notif-icon.blue { background: #eff6ff; color: #2563eb; }
        .notif-icon.green { background: #f0fdf4; color: #16a34a; }
        .notif-icon.gold { background: #fefce8; color: var(--psu-gold); }
        .notif-icon.red { background: #fef2f2; color: #dc2626; }
        
        .notif-body { flex: 1; min-width: 0; }
        .notif-body strong { display: block; font-size: 0.85rem; font-weight: 600; color: var(--psu-navy); margin-bottom: 2px; }
        .notif-body p { font-size: 0.75rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-body small { display: block; font-size: 0.7rem; color: var(--text-light); margin-top: 4px; }
        .notif-empty { padding: 32px 16px; text-align: center; color: var(--text-light); }
        .notif-empty i { font-size: 1.5rem; margin-bottom: 8px; opacity: 0.5; }
        .notif-empty p { font-size: 0.85rem; }

        /* =====================================================
           4. USER DROPDOWN MENU
           ===================================================== */
        .user-dropdown-wrapper {
            position: relative;
        }

        .topbar .user {
            display: flex; 
            align-items: center; 
            gap: 12px;
            padding: 6px 16px 6px 6px;
            border-radius: 999px;
            background: var(--white);
            border: 1px solid var(--border-light);
            transition: var(--transition);
            cursor: pointer;
            font-family: var(--font-body);
            text-align: left;
        }
        .topbar .user:hover { 
            border-color: var(--psu-navy); 
            box-shadow: var(--shadow-sm); 
        }

        .user-avatar {
            width: 36px; 
            height: 36px; 
            border-radius: 50%;
            display: grid; 
            place-items: center;
            background: var(--psu-gold);
            color: var(--psu-navy);
            font-family: var(--font-heading);
            font-weight: 800;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        
        .user-info { 
            display: flex; 
            flex-direction: column; 
            line-height: 1.2; 
        }
        .user-name { 
            font-family: var(--font-heading); 
            font-weight: 700; 
            font-size: 0.85rem; 
            color: var(--psu-navy); 
        }
        .user-role { 
            font-size: 0.7rem; 
            font-weight: 500; 
            color: var(--text-muted); 
        }

        .dropdown-chevron {
            font-size: 0.75rem;
            color: var(--text-light);
            margin-left: 4px;
            transition: transform 0.2s;
        }
        
        .user-dropdown-wrapper.open .dropdown-chevron {
            transform: rotate(180deg);
        }

        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 240px;
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border-light);
            padding: 8px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px);
            transition: var(--transition);
            z-index: 100;
        }
        
        .user-dropdown-wrapper.open .user-dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-header {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border-light);
            margin-bottom: 6px;
            display: flex;
            flex-direction: column;
        }
        .dropdown-header strong {
            font-size: 0.9rem;
            font-family: var(--font-heading);
            color: var(--psu-navy);
        }
        .dropdown-header small {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
            transition: var(--transition);
        }
        .dropdown-item i {
            width: 16px;
            text-align: center;
            color: var(--text-light);
            font-size: 0.95rem;
        }
        
        .dropdown-item:hover {
            background: var(--bg-main);
            color: var(--psu-navy);
        }
        .dropdown-item:hover i {
            color: var(--psu-navy);
        }

        .dropdown-item.text-danger { color: #dc2626; }
        .dropdown-item.text-danger i { color: #dc2626; }
        .dropdown-item.text-danger:hover { background: #fef2f2; }
        
        .dropdown-divider {
            height: 1px;
            background: var(--border-light);
            margin: 6px 4px;
        }

        .page-body {
            flex: 1;
            padding: 32px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* =====================================================
           5. GLOBAL PAGE STYLES (Cards, Alerts, etc.)
           ===================================================== */
        .content-card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            padding: 32px;
            width: 100%;
        }
        
        .page-header { margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; }
        .page-header h2 { font-size: 1.5rem; font-weight: 800; color: var(--psu-navy); margin-bottom: 4px; }
        .page-header p { color: var(--text-muted); font-size: 0.95rem; }

        /* Dashboard specific */
        .dashboard-wrapper { display: flex; flex-direction: column; gap: 24px; width: 100%; }
        .welcome-header { margin-bottom: 8px; }
        .welcome-header h2 { font-size: 1.75rem; font-weight: 800; color: var(--psu-navy); margin-bottom: 4px; }
        .welcome-header p { color: var(--text-muted); font-size: 0.95rem; }
        
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
        
        .stat-card { 
            background: var(--white); 
            border: 1px solid var(--border-light); 
            border-radius: var(--radius); 
            padding: 20px; 
            display: flex; 
            align-items: center; 
            gap: 16px; 
            transition: var(--transition); 
            box-shadow: var(--shadow-sm); 
        }
        .stat-card:hover { 
            border-color: var(--psu-gold); 
            box-shadow: var(--shadow-card); 
            transform: translateY(-2px); 
        }
        
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
        .stat-icon.blue { background: #eff6ff; color: #2563eb; }
        .stat-icon.gold { background: #fefce8; color: var(--psu-gold); }
        .stat-icon.green { background: #f0fdf4; color: #16a34a; }
        .stat-icon.red { background: #fef2f2; color: #dc2626; }
        
        .stat-content { display: flex; flex-direction: column; }
        .stat-value { font-family: var(--font-heading); font-size: 1.5rem; font-weight: 800; color: var(--psu-navy); line-height: 1.1; }
        .stat-label { font-size: 0.8rem; font-weight: 500; color: var(--text-muted); margin-top: 4px; }
        
        .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .chart-card { 
            background: var(--white); 
            border: 1px solid var(--border-light); 
            border-radius: var(--radius); 
            padding: 24px; 
            box-shadow: var(--shadow-sm); 
            display: flex; 
            flex-direction: column; 
        }
        .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .chart-header h3 { font-size: 1rem; font-weight: 700; color: var(--psu-navy); }
        .chart-container { position: relative; flex: 1; min-height: 220px; width: 100%; }

        .alert { padding: 16px; border-radius: var(--radius-sm); font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 10px; margin-bottom: 24px; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

        /* =====================================================
           6. RESPONSIVE
           ===================================================== */
        @media (max-width: 1024px) {
            :root { --sidebar-w: 240px; }
            .topbar { padding: 0 20px; }
            .page-body { padding: 24px 20px; }
            .charts-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 860px) {
            main.container { width: 100%; margin-left: 0; }
            .sidebar-toggle { display: inline-flex; }
            .topbar { padding: 0 16px; }
            .page-body { padding: 20px 16px; }
            .topbar-title { font-size: 1rem; }
            .content-card { padding: 24px; }
            
            .sidebar { transform: translateX(-100%); box-shadow: var(--shadow-lg); }
            .sidebar.is-open { transform: translateX(0); }
            body.sidebar-open::after { content: ""; position: fixed; inset: 0; background: rgba(10,31,68,.5); backdrop-filter: blur(2px); z-index: 35; }
            
            .notif-dropdown { position: fixed; top: var(--topbar-h); left: 16px; right: 16px; width: auto; }
            .user-dropdown-menu { position: fixed; top: var(--topbar-h); left: 16px; right: 16px; width: auto; }
        }

        @media (max-width: 520px) {
            .user-info { display: none; }
            .topbar .user { padding: 6px; }
            .dropdown-chevron { display: none; }
            .stat-grid { grid-template-columns: 1fr; }
            .notification-bell { width: 38px; height: 38px; }
        }
    </style>
</head>
<body class="applicant-portal">

<a class="skip-link" href="#mainContent">Skip to main content</a>
<?php include __DIR__ . '/sidebar.php'; ?>

<main class="container" id="mainContent">
    <header class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Open menu" aria-controls="mainSidebar" aria-expanded="false">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="topbar-title"><?= h(ucwords(str_replace('_', ' ', $page))) ?></div>
        </div>
        
        <div class="topbar-actions">
            <!-- Notification Bell -->
            <div class="notif-wrapper" id="notifWrapper">
                <button class="notification-bell" id="notifToggle" type="button" aria-label="View notifications" aria-expanded="false">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
                    <?php endif; ?>
                </button>
                
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <h4>Notifications</h4>
                        <a href="?page=notifications">View all</a>
                    </div>
                    <div class="notif-list">
                        <?php if (!empty($recentNotifs)): ?>
                            <?php foreach ($recentNotifs as $n): ?>
                                <a href="?page=notifications" class="notif-item <?= empty($n['is_read']) ? 'unread' : '' ?>">
                                    <div class="notif-icon <?= !empty($n['is_read']) ? 'blue' : 'gold' ?>">
                                        <i class="fa-regular fa-bell"></i>
                                    </div>
                                    <div class="notif-body">
                                        <strong><?= h($n['title']) ?></strong>
                                        <p><?= h($n['message'] ?? '') ?></p>
                                        <small><?= date('M d, g:i A', strtotime($n['created_at'])) ?></small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notif-empty">
                                <i class="fa-regular fa-bell-slash"></i>
                                <p>No notifications yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- User Profile Dropdown -->
            <div class="user-dropdown-wrapper" id="userDropdownWrapper">
                <button class="user" id="userDropdownToggle" type="button" aria-expanded="false">
                    <span class="user-avatar" aria-hidden="true"><?= h(strtoupper(substr($userName, 0, 1))) ?></span>
                    <span class="user-info">
                        <span class="user-name"><?= h($userName) ?></span>
                        <span class="user-role">Applicant</span>
                    </span>
                    <i class="fa-solid fa-chevron-down dropdown-chevron"></i>
                </button>

                <div class="user-dropdown-menu" id="userDropdownMenu">
                    <div class="dropdown-header">
                        <strong><?= h($userName) ?></strong>
                        <small><?= h($user['email'] ?? 'Applicant') ?></small>
                    </div>
                    
                    <a href="?page=profile&tab=profile" class="dropdown-item">
                        <i class="fa-regular fa-user"></i> My Profile
                    </a>
                    <a href="?page=profile&tab=security" class="dropdown-item">
                        <i class="fa-solid fa-gear"></i> Account Settings
                    </a>
                    
                    <div class="dropdown-divider"></div>
                    
                    <a href="?page=profile&tab=danger" class="dropdown-item text-danger">
                        <i class="fa-solid fa-triangle-exclamation"></i> Danger Zone
                    </a>
                    
                    <div class="dropdown-divider"></div>
                    
                    <a href="../public/user_logout.php" class="dropdown-item text-danger">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="page-body">
        <?php include __DIR__ . '/' . $route; ?>
    </div>
</main>

<script src="../asset/js/sidebar.js"></script>
<script src="../asset/js/user_scrollspy.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const notifWrapper = document.getElementById('notifWrapper');
        const notifToggle = document.getElementById('notifToggle');
        
        const userWrapper = document.getElementById('userDropdownWrapper');
        const userToggle = document.getElementById('userDropdownToggle');

        // Notification Toggle
        if (notifToggle) {
            notifToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = notifWrapper.classList.toggle('open');
                notifToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                
                if (userWrapper) {
                    userWrapper.classList.remove('open');
                    if (userToggle) userToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // User Dropdown Toggle
        if (userToggle) {
            userToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = userWrapper.classList.toggle('open');
                userToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                
                if (notifWrapper) {
                    notifWrapper.classList.remove('open');
                    if (notifToggle) notifToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // Close both when clicking outside
        document.addEventListener('click', function(e) {
            if (notifWrapper && notifWrapper.classList.contains('open') && !notifWrapper.contains(e.target)) {
                notifWrapper.classList.remove('open');
                if (notifToggle) notifToggle.setAttribute('aria-expanded', 'false');
            }
            if (userWrapper && userWrapper.classList.contains('open') && !userWrapper.contains(e.target)) {
                userWrapper.classList.remove('open');
                if (userToggle) userToggle.setAttribute('aria-expanded', 'false');
            }
        });
    });
</script>
</body>
</html>