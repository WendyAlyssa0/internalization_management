<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$pdo = db();

/* ------------------------------------------------------------------
 * ROUTING
 * ------------------------------------------------------------------ */
$allowedPages = ['login', 'browse'];
$page = $_GET['page'] ?? 'login';
if (!in_array($page, $allowedPages, true)) {
    $page = 'login';
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ==================================================================
 * STATE VARIABLES
 * ================================================================== */
$login_error    = '';
$login_username = $_COOKIE['remember_user'] ?? '';
$login_remember = !empty($_COOKIE['remember_user']);

/* ==================================================================
 * POST HANDLING (LOGIN ONLY) — USER PORTAL ONLY
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'login') {

    $login_username = trim($_POST['username'] ?? '');
    $password       = $_POST['password'] ?? '';
    $login_remember = isset($_POST['remember']);

    if ($login_username === '' || $password === '') {
        $login_error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, first_name, email, user_role, password_hash
            FROM users
            WHERE email = :username
            LIMIT 1
        ");
        $stmt->execute([':username' => $login_username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $login_error = 'Invalid email or password. Please try again.';
        } else {
            $role = strtolower(trim($user['user_role'] ?? ''));

            if (in_array($role, [
                'admin', 'super admin', 'campus coordinator', 'ovplia staff', 'approving official', 'bor secretary'
            ], true)) {
                $login_error = 'Invalid email or password. Please try again.';
            } elseif (in_array($role, ['applicant', 'student', 'faculty', 'user', 'ap'], true)) {

                session_regenerate_id(true);
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['username']   = $user['email'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['user_role']  = $user['user_role'];

                $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

                if ($login_remember) {
                    setcookie('remember_user', $login_username, [
                        'expires'  => time() + 60 * 60 * 24 * 30,
                        'path'     => '/',
                        'secure'   => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                } else {
                    setcookie('remember_user', '', [
                        'expires'  => time() - 3600,
                        'path'     => '/',
                    ]);
                }

                header('Location: ../user/home.php');
                exit();
            } else {
                $login_error = 'Your account does not have access to this portal.';
            }
        }
    }
}

/* ==================================================================
 * BROWSE DATA
 * ================================================================== */
$programs = [];
$loadError = null;
$typeCounts = ['exchange' => 0, 'internship' => 0, 'research' => 0, 'training' => 0];
$countrySet = [];
$totalPrograms = 0;
$totalCountries = 0;

if ($page === 'browse') {
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.program_name, p.program_type, p.description, p.start_date, p.end_date,
                   p.status, p.partner_id, p.country_id, c.country_name, pr.institution_name
            FROM programs p
            LEFT JOIN countries c ON p.country_id = c.id
            LEFT JOIN partners pr ON p.partner_id = pr.id
            WHERE p.status = 'Active'
              AND p.eligible_account_type = 'Student'
            ORDER BY p.id DESC
        ");
        $stmt->execute();
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        error_log('index.php (browse) query failed: ' . $ex->getMessage());
        $loadError = 'We ran into a problem loading programs. Please try again shortly.';
    }

    foreach ($programs as $p) {
        $t = strtolower($p['program_type'] ?? '');
        if (isset($typeCounts[$t])) $typeCounts[$t]++;
        if (!empty($p['country_name'])) $countrySet[$p['country_name']] = true;
    }
    $totalPrograms = count($programs);
    $totalCountries = count($countrySet);
}

$pageTitles = [
    'login'    => 'Sign In — PSU Internationalization',
    'browse'   => 'PSU Internationalization — Student Programs Portal',
];
$pageTitle = $pageTitles[$page];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>

    <?php if ($page === 'login'): ?>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* ===== DESIGN TOKENS ===== */
    :root {
        --psu-navy: #0b1a30;
        --psu-blue: #1c3d6e;
        --psu-gold: #e4b134;
        --psu-gold-light: #f4c856;
        
        --bg-main: #eef2f7;
        --white: #ffffff;
        --text-main: #0f172a;
        --text-muted: #475569;
        --text-light: #94a3b8;
        
        --border-light: #e2e8f0;
        --radius-sm: 8px;
        --radius: 12px;
        --radius-lg: 20px;
        --radius-full: 9999px;
        
        --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
        --shadow-card: 0 20px 40px -10px rgba(11, 26, 48, 0.1), 0 0 0 1px rgba(11, 26, 48, 0.02);
        
        --font-heading: 'Plus Jakarta Sans', sans-serif;
        --font-body: 'Inter', sans-serif;
        --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { min-height: 100%; }
    
    body {
        font-family: var(--font-body);
        background-color: var(--bg-main);
        color: var(--text-main);
        -webkit-font-smoothing: antialiased;
        line-height: 1.5;
        position: relative;
        overflow-x: hidden;
    }

    /* Background Map Pattern */
    body::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: url('data:image/svg+xml;utf8,<svg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><path d="M10 10h80v80H10z" fill="none" stroke="%23cbd5e1" stroke-width="0.5" opacity="0.3"/></svg>');
        background-size: 800px;
        background-position: center;
        opacity: 0.8;
        z-index: -1;
    }

    /* Decorative Bottom Waves */
    body::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 25vh;
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%230b1a30" fill-opacity="1" d="M0,224L48,213.3C96,203,192,181,288,181.3C384,181,480,203,576,224C672,245,768,267,864,250.7C960,235,1056,181,1152,165.3C1248,149,1344,171,1392,181.3L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path><path fill="%23e4b134" fill-opacity="1" d="M0,288L48,272C96,256,192,224,288,213.3C384,203,480,213,576,229.3C672,245,768,267,864,261.3C960,256,1056,224,1152,208C1248,192,1344,192,1392,192L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>');
        background-size: cover;
        background-repeat: no-repeat;
        background-position: bottom center;
        z-index: -1;
    }

    .page {
        display: flex;
        width: 100%;
        min-height: 100vh;
    }

    /* ===== LEFT SIDE: HERO ===== */
    .hero {
        flex: 1;
        padding: 60px 80px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        z-index: 1;
    }

    .hero-top {
        position: absolute;
        top: 60px;
        left: 80px;
    }

    .brand {
        display: inline-flex;
        align-items: center;
        gap: 16px;
        text-decoration: none;
        font-family: var(--font-heading);
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--psu-navy);
    }

    .brand .badge {
        background: var(--psu-navy);
        color: var(--white);
        font-size: 0.95rem;
        font-weight: 800;
        padding: 8px 14px;
        border-radius: var(--radius-sm);
        letter-spacing: 0.5px;
    }

    .brand .divider-line { 
        width: 2px; 
        height: 24px; 
        background: var(--psu-gold); 
        border-radius: 2px;
    }

    .hero-body {
        max-width: 600px;
        margin-top: 40px;
    }

    .eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        background: var(--white);
        border: 1px solid rgba(228, 177, 52, 0.4);
        padding: 8px 24px;
        border-radius: var(--radius-full);
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--psu-navy);
        box-shadow: var(--shadow-sm);
        margin-bottom: 32px;
    }

    .eyebrow i {
        width: 20px;
        height: 20px;
        background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%23e4b134" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>') no-repeat center;
        display: inline-block;
    }

    .hero h2 {
        font-family: var(--font-heading);
        font-size: clamp(2.5rem, 4vw, 3.8rem);
        line-height: 1.1;
        font-weight: 800;
        color: var(--psu-navy);
        letter-spacing: -1px;
        margin-bottom: 24px;
    }

    .hero h2 em {
        color: var(--psu-gold);
        font-style: normal;
    }

    .hero .lead {
        font-size: 1.15rem;
        color: var(--text-muted);
        line-height: 1.7;
        margin-bottom: 56px;
        max-width: 500px;
    }

    .features {
        list-style: none;
        display: flex;
        gap: 32px;
        padding-top: 32px;
        border-top: 1px solid rgba(11, 26, 48, 0.1);
    }

    .features li {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 12px;
        flex: 1;
        position: relative;
    }

    .features li:not(:last-child)::after {
        content: '';
        position: absolute;
        right: -16px;
        top: 20%;
        height: 60%;
        width: 1px;
        background: rgba(11, 26, 48, 0.1);
    }

    .features .ico {
        width: 48px;
        height: 48px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--psu-navy);
    }

    .features strong {
        font-family: var(--font-heading);
        font-size: 0.95rem;
        color: var(--psu-navy);
        font-weight: 700;
        line-height: 1.3;
    }

    .hero-foot {
        position: absolute;
        bottom: 40px;
        left: 80px;
        font-size: 0.85rem;
        color: var(--text-muted);
    }

    /* ===== RIGHT SIDE: FORM CARD ===== */
    .form-side {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 80px 40px 20px;
        position: relative;
        z-index: 2;
    }

    .login-card {
        background: var(--white);
        width: 100%;
        max-width: 460px;
        border-radius: var(--radius-lg);
        padding: 48px;
        box-shadow: var(--shadow-card);
        border: 1px solid rgba(255, 255, 255, 0.5);
        position: relative;
        margin-top: 40px;
        margin-bottom: 40px;
    }

    .login-header {
        text-align: center;
        margin-bottom: 32px;
    }

    .user-plus-icon {
        width: 64px;
        height: 64px;
        background: var(--bg-main);
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
        position: relative;
    }
    
    .user-plus-icon svg { color: var(--psu-navy); }

    .user-plus-icon::after {
        content: '+';
        position: absolute;
        right: 0px;
        bottom: 0px;
        background: var(--psu-gold);
        color: var(--white);
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 700;
        border: 3px solid var(--white);
    }

    .login-header h1 {
        font-family: var(--font-heading);
        font-size: 2.2rem;
        color: var(--psu-navy);
        font-weight: 800;
        margin-bottom: 8px;
    }

    .login-header p {
        color: var(--text-muted);
        font-size: 0.95rem;
    }

    /* Alerts */
    .alert-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
        padding: 16px;
        border-radius: var(--radius-sm);
        margin-bottom: 24px;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: shake 0.4s ease-in-out;
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        75% { transform: translateX(5px); }
    }

    /* Input Fields */
    .form-group { margin-bottom: 20px; }
    .form-label { 
        display: block; 
        font-size: 0.85rem; 
        font-weight: 700; 
        color: var(--psu-navy); 
        margin-bottom: 8px; 
    }
    
    .input-wrap { position: relative; }
    .input-wrap > svg.lead-ic {
        position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
        width: 20px; height: 20px; color: var(--text-light); pointer-events: none; transition: color 0.2s;
    }
    
    .form-input {
        width: 100%; padding: 14px 16px 14px 48px;
        border: 1px solid var(--border-light); border-radius: var(--radius-sm);
        font-family: var(--font-body); font-size: 0.95rem; color: var(--text-main);
        background: var(--white); transition: var(--transition);
    }
    
    .form-input:focus {
        outline: none;
        border-color: var(--psu-navy);
        box-shadow: 0 0 0 3px rgba(11, 26, 48, 0.05);
    }
    
    .input-wrap:focus-within > svg.lead-ic { color: var(--psu-navy); }
    .form-input::placeholder { color: var(--text-light); }
    
    .toggle-pw {
        position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
        background: none; border: none; cursor: pointer; padding: 4px; color: var(--text-light);
        display: grid; place-items: center; transition: var(--transition);
    }
    
    .toggle-pw:hover { color: var(--psu-navy); }

    /* Options */
    .form-options {
        display: flex; justify-content: space-between; align-items: center;
        font-size: 0.85rem; margin-bottom: 24px; margin-top: 4px;
    }
    
    .form-options label {
        display: flex; align-items: center; gap: 8px; cursor: pointer;
        color: var(--text-main); font-weight: 500;
    }
    
    .form-options input[type="checkbox"] { 
        width: 16px; height: 16px; accent-color: var(--psu-navy); cursor: pointer; 
        border-radius: 4px;
    }
    
    .form-options a { 
        color: var(--psu-navy); text-decoration: none; font-weight: 700; transition: var(--transition); 
    }
    
    .form-options a:hover { text-decoration: underline; }

    /* Buttons */
    .btn-submit {
        width: 100%;
        background: var(--psu-navy);
        color: var(--white); 
        border: none; border-radius: var(--radius-full);
        font-family: var(--font-heading); font-size: 1rem; font-weight: 700;
        padding: 16px; cursor: pointer; transition: var(--transition);
        display: flex; align-items: center; justify-content: center; gap: 8px;
        position: relative;
    }
    
    .btn-submit:hover { 
        background: var(--psu-blue);
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(11, 26, 48, 0.2);
    }

    .btn-submit.loading {
        pointer-events: none;
        color: transparent;
    }
    .btn-submit.loading svg {
        display: none;
    }

    /* Divider */
    .divider {
        display: flex; align-items: center; text-align: center; margin: 24px 0;
        color: var(--text-light); font-size: 0.75rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: 0.5px;
    }
    
    .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid var(--border-light); }
    .divider::before { margin-right: 16px; }
    .divider::after { margin-left: 16px; }

    /* Register Button */
    .btn-register {
        width: 100%; 
        background: var(--white); 
        color: var(--psu-navy);
        border: 1px solid var(--border-light); 
        border-radius: var(--radius-full);
        font-family: var(--font-heading); font-size: 0.95rem; font-weight: 700;
        padding: 14px; cursor: pointer; transition: var(--transition);
        text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    
    .btn-register:hover { 
        background: var(--bg-main); 
        border-color: var(--psu-navy);
    }

    /* Footer */
    .login-footer { 
        text-align: center; 
        margin-top: 32px; 
        font-size: 0.8rem; 
        color: var(--text-light); 
        line-height: 1.6; 
    }
    
    .login-footer a { 
        color: var(--psu-navy); 
        text-decoration: none; 
        font-weight: 600; 
    }
    
    .login-footer a:hover { text-decoration: underline; }

    /* ===== FULL SCREEN AIRPLANE LOADING OVERLAY ===== */
    .loading-overlay {
        position: fixed;
        inset: 0;
        background: #0a192f;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.4s ease, visibility 0.4s ease;
    }
    .loading-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .flight-container {
        position: relative;
        width: 100%;
        max-width: 800px;
        height: 200px;
        margin-bottom: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .flight-path {
        position: absolute;
        top: 50%;
        left: 10%;
        width: 80%;
        height: 100px;
        border-top: 2px dashed rgba(255, 255, 255, 0.15);
        border-radius: 50% / 100% 100% 0 0;
        transform: translateY(-50%) rotate(-5deg);
    }

    .airplane {
        position: absolute;
        top: 40%;
        left: -10%;
        width: 80px;
        height: 80px;
        color: #ffffff;
        transform: translateY(-50%) rotate(15deg);
        /* SLOWED DOWN: 20 seconds */
        animation: flyAcross 20s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        filter: drop-shadow(0 0 15px rgba(59, 130, 246, 0.8)) drop-shadow(0 0 30px rgba(59, 130, 246, 0.5));
        z-index: 2;
    }

    .light-trail {
        position: absolute;
        top: 50%;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, transparent, #3b82f6, #ffffff);
        transform: translateY(-50%);
        opacity: 0;
        /* SLOWED DOWN: 20 seconds */
        animation: trailGlow 20s ease-out forwards;
        border-radius: 4px;
        filter: blur(2px);
        z-index: 1;
    }

    @keyframes flyAcross {
        0% {
            left: -10%;
            opacity: 0;
            transform: translateY(10px) rotate(15deg) scale(0.8);
        }
        10% {
            opacity: 1;
        }
        50% {
            transform: translateY(-20px) rotate(5deg) scale(1);
        }
        90% {
            opacity: 1;
            left: 110%;
            transform: translateY(-30px) rotate(0deg) scale(1);
        }
        100% {
            left: 110%;
            opacity: 0;
        }
    }

    @keyframes trailGlow {
        0% { opacity: 0; width: 0%; left: 0; }
        20% { opacity: 1; }
        80% { opacity: 1; width: 100%; }
        100% { opacity: 0; width: 100%; }
    }

    .loading-text {
        font-family: 'Inter', sans-serif;
        font-size: 2.5rem;
        color: #ffffff;
        font-weight: 700;
        letter-spacing: -0.5px;
        margin-bottom: 12px;
    }
    .loading-subtext {
        font-family: 'Inter', sans-serif;
        font-size: 1.1rem;
        color: #94a3b8;
        margin-bottom: 40px;
    }

    /* Progress Bar */
    .progress-bar-container {
        width: 100%;
        max-width: 450px;
        height: 8px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 999px;
        overflow: hidden;
        position: relative;
    }
    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #2563eb, #3b82f6, #60a5fa);
        border-radius: 999px;
        width: 0%;
        box-shadow: 0 0 15px rgba(59, 130, 246, 0.6);
        transition: width 0.1s linear;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 1024px) {
        .hero { padding: 40px; }
        .hero-top { top: 40px; left: 40px; }
        .hero h2 { font-size: 2.8rem; }
        .form-side { padding: 40px; }
    }
    
    @media (max-width: 860px) {
        .page { flex-direction: column; }
        .hero { 
            padding: 100px 24px 40px; 
            align-items: center; 
            text-align: center; 
        }
        .hero-top { top: 30px; left: 50%; transform: translateX(-50%); width: 100%; display: flex; justify-content: center; }
        .hero .lead { margin: 0 auto 40px; }
        .features { display: none; }
        .hero-foot { position: relative; bottom: 0; left: 0; margin-top: 40px; }
        .form-side { padding: 0 24px 40px; }
        .login-card { margin-top: 0; }
    }
    
    @media (max-width: 480px) {
        .login-card { padding: 32px 24px; }
        .login-header h1 { font-size: 1.8rem; }
        .flight-container { height: 120px; }
        .airplane { width: 60px; height: 60px; }
        .loading-text { font-size: 1.8rem; }
        .loading-subtext { font-size: 0.95rem; }
        .progress-bar-container { max-width: 280px; }
    }
</style>
    <?php else: ?>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../asset/css/website.css">
    <?php endif; ?>
</head>

<body>

<?php if ($page === 'login'): ?>

    <!-- Cinematic Airplane Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="flight-container">
            <div class="flight-path"></div>
            <div class="light-trail"></div>
            <svg class="airplane" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                <path d="M21.989 2.011C21.989 2.011 17.959 1.441 14.109 1.611C10.259 1.781 3.12 6.44 2.989 7.33C2.859 8.22 8.239 8.33 8.239 8.33C8.239 8.33 8.869 11.86 10.349 13.85C11.829 15.84 15.369 16.42 15.369 16.42C15.369 16.42 15.499 21.69 16.369 21.55C17.239 21.41 21.839 14.29 22.009 10.49C22.179 6.69 21.989 2.011 21.989 2.011Z" />
            </svg>
        </div>
        <div class="loading-text">Welcome back!</div>
        <div class="loading-subtext">Loading your dashboard...</div>
        
        <div class="progress-bar-container">
            <div class="progress-bar-fill" id="progressBarFill"></div>
        </div>
    </div>

    <div class="page">
        <!-- HERO PANEL -->
        <aside class="hero">
            <div class="hero-top">
                <a href="website.php" class="brand">
                    <span class="badge">PSU</span> 
                    <div class="divider-line"></div>
                    PSU Internationalization
                </a>
            </div>

            <div class="hero-body">
                <div class="eyebrow"><i></i> Student Mobility &amp; Internship Portal</div>
                <h2>Welcome back to your <em>global</em> journey.</h2>
                <p class="lead">Sign in to continue your applications, track your status, and discover new international programs from Pangasinan State University.</p>

                <ul class="features">
                    <li>
                        <div class="ico"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></div>
                        <div><strong>Explore<br>Programs</strong></div>
                    </li>
                    <li>
                        <div class="ico"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                        <div><strong>Build<br>Global Connections</strong></div>
                    </li>
                    <li>
                        <div class="ico"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg></div>
                        <div><strong>Broaden<br>Your Horizons</strong></div>
                    </li>
                </ul>
            </div>

            <div class="hero-foot">&copy; <?= date('Y') ?> Pangasinan State University &middot; Internationalization Office</div>
        </aside>

        <!-- FORM SIDE -->
        <main class="form-side">
            <div class="login-card">
                <div class="login-header">
                    <div class="user-plus-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    </div>
                    <h1>Welcome Back</h1>
                    <p>Sign in to your PSU Internationalization account</p>
                </div>

                <?php if ($login_error): ?>
                    <div class="alert-error">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <div><?= e($login_error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="?page=login" id="loginForm">
                    <div class="form-group">
                        <label class="form-label" for="username">Email Address</label>
                        <div class="input-wrap">
                            <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>
                            <input class="form-input" type="email" name="username" id="username"
                                placeholder="e.g. juan.delacruz@psu.edu.ph"
                                value="<?= e($login_username) ?>"
                                required autocomplete="username" autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-wrap">
                            <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input class="form-input" type="password" name="password" id="password"
                                placeholder="Enter your password"
                                required autocomplete="current-password" style="padding-right:48px;">
                            <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Show or hide password">
                                <svg id="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-options">
                        <label>
                            <input type="checkbox" name="remember" <?= $login_remember ? 'checked' : '' ?>>
                            Remember me
                        </label>
                        <a href="user_forgot_pass.php">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        <span>Sign In</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </form>

                <div class="divider">OR</div>

                <a href="register.php" class="btn-register">
                    Create an Account
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>

                <div class="login-footer">
                    By signing in, you agree to our <a href="#">Terms of Service</a> and<br><a href="#">Privacy Policy</a>.
                </div>
            </div>
        </main>
    </div>

<script>
    function togglePw() {
        const pw = document.getElementById('password');
        const icon = document.getElementById('eye-icon');
        if (pw.type === 'password') {
            pw.type = 'text';
            icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
        } else {
            pw.type = 'password';
            icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        }
    }

    // Handle login loading state
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        // Show full screen overlay
        document.getElementById('loadingOverlay').classList.add('active');
        
        // Add loading state to button (hides text and icon)
        const btn = document.getElementById('submitBtn');
        btn.classList.add('loading');

        // Animate the progress bar from 0 to 100 over 20 seconds
        let progress = 0;
        const progressBar = document.getElementById('progressBarFill');
        
        const interval = setInterval(() => {
            // Increment smoothly by a small amount every 100ms
            // (0.5 * 200 ticks = 100%)
            progress += 0.5; 
            
            if (progress > 100) {
                progress = 100;
            }
            
            progressBar.style.width = progress + '%';

            // Stop when full
            if (progress >= 100) {
                clearInterval(interval);
            }
        }, 100);
    });
</script>

<?php elseif ($page === 'browse'): ?>

    <!-- ============================== BROWSE PROGRAMS ============================== -->
    <nav>
      <div class="nav-logo">PSU <span>Internationalization</span></div>
      <ul class="nav-links">
        <li><a href="?page=browse">Browse Programs</a></li>
        <li><a href="partner_ins.php">Partner Institutions</a></li>
        <li><a href="how_it_works.php">How It Works</a></li>
        <li><a href="register.php" class="nav-cta">Register Now</a></li>
      </ul>
    </nav>

    <section class="hero">
      <div class="hero-inner">
        <div class="hero-eyebrow">Student Mobility &amp; Internship Portal</div>
        <h1>Find your next program, <em>abroad</em>.</h1>
        <p>Discover internships, exchange programs, and research opportunities from partner institutions around the world — open to PSU students.</p>
        <div class="search-bar">
          <input type="text" placeholder="Search by program name, country, or field…" id="searchInput"/>
          <select id="typeFilter">
            <option value="">All Types</option>
            <option value="exchange">Exchange</option>
            <option value="internship">Internship</option>
            <option value="research">Research</option>
            <option value="training">Training</option>
          </select>
          <button onclick="applySearch()">Search Programs</button>
        </div>
        <div class="stats-strip">
          <div class="stat"><strong><?= $totalPrograms ?></strong> Active Programs</div>
          <div class="stat"><strong><?= $totalCountries ?></strong> Partner Countries</div>
          <div class="stat"><strong>130+</strong> Students Placed</div>
        </div>
      </div>
    </section>

    <div class="page-body">
      <aside class="sidebar">
        <div class="filter-card">
          <h3>Program Type</h3>
          <div class="filter-group">
            <label><input type="checkbox" data-filter="type" value="exchange" onchange="filterCards()"> Student Exchange <span class="badge-count"><?= $typeCounts['exchange'] ?></span></label>
            <label><input type="checkbox" data-filter="type" value="internship" onchange="filterCards()"> Internship <span class="badge-count"><?= $typeCounts['internship'] ?></span></label>
            <label><input type="checkbox" data-filter="type" value="research" onchange="filterCards()"> Research <span class="badge-count"><?= $typeCounts['research'] ?></span></label>
            <label><input type="checkbox" data-filter="type" value="training" onchange="filterCards()"> Training <span class="badge-count"><?= $typeCounts['training'] ?></span></label>
          </div>
        </div>

        <div class="filter-card">
          <h3>Country / Region</h3>
          <select id="countryFilter" onchange="filterCards()">
            <option value="">All Countries</option>
            <?php foreach (array_keys($countrySet) as $countryName): ?>
              <option><?= e($countryName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filter-card">
          <h3>Duration</h3>
          <div class="filter-group">
            <label><input type="checkbox" onchange="filterCards()"> 1 – 3 months</label>
            <label><input type="checkbox" onchange="filterCards()"> 4 – 6 months</label>
            <label><input type="checkbox" onchange="filterCards()"> 1 semester</label>
            <label><input type="checkbox" onchange="filterCards()"> 1 academic year</label>
          </div>
        </div>

        <div class="filter-card">
          <h3>Funding</h3>
          <div class="filter-group">
            <label><input type="checkbox" data-filter="funding" value="full scholarship" onchange="filterCards()"> With Scholarship</label>
            <label><input type="checkbox" data-filter="funding" value="partial" onchange="filterCards()"> Partially Funded</label>
            <label><input type="checkbox" data-filter="funding" value="self-funded" onchange="filterCards()"> Self-funded</label>
          </div>
        </div>

        <button class="clear-btn" onclick="clearFilters()">Clear all filters</button>
      </aside>

      <main>
        <div class="listings-header">
          <div>
            <h2>Available Programs</h2>
            <span id="resultCount">Showing <?= $totalPrograms ?> of <?= $totalPrograms ?> programs</span>
          </div>
          <select class="sort-select" id="sortSelect">
            <option value="deadline">Sort: Deadline (Soonest)</option>
            <option value="slots">Sort: Most Slots</option>
            <option value="az">Sort: A → Z</option>
          </select>
        </div>

        <?php if ($loadError): ?>
          <div class="programs-empty"><p><?= e($loadError) ?></p></div>
        <?php elseif ($totalPrograms === 0): ?>
          <div class="programs-empty"><p>No programs are available right now. Please check back soon.</p></div>
        <?php else: ?>
          <div id="programList"></div>
          <div class="pagination" id="paginationBar"></div>
        <?php endif; ?>
      </main>
    </div>

    <div class="modal-overlay" id="modalOverlay" onclick="closeModal(event)">
      <div class="modal" id="modalBox">
        <div class="modal-header">
          <div class="modal-logo" id="modalLogo"></div>
          <div>
            <div class="modal-title" id="modalTitle"></div>
            <div class="modal-institution" id="modalInstitution"></div>
            <div class="card-tags" id="modalTags"></div>
          </div>
          <button class="modal-close" onclick="closeModalDirect()">✕</button>
        </div>
        <div class="modal-body">
          <div class="modal-section">
            <h4>About this Program</h4>
            <p id="modalDesc"></p>
          </div>
          <div class="modal-section">
            <h4>Program Details</h4>
            <div class="modal-meta" id="modalMeta"></div>
          </div>
          <div class="modal-section">
            <h4>Requirements</h4>
            <ul class="requirements-list" id="modalReqs"></ul>
          </div>
          <a href="#" id="applyLink" class="apply-btn">Apply for this Program</a>
        </div>
      </div>
    </div>

    <footer>
      <strong>PSU Internationalization Management System</strong><br/>
      Pangasinan State University · Office of International Affairs · <a href="#" style="color:rgba(255,255,255,.55)">Contact Us</a>
    </footer>

    <?php if (!$loadError && $totalPrograms > 0): ?>
    <script>
      const rawPrograms = <?= json_encode($programs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const TYPE_EMOJI = { exchange: '🔄', internship: '💼', research: '🔬' };

      function formatDate(dateStr) {
        if (!dateStr) return 'TBA';
        const d = new Date(dateStr);
        if (isNaN(d)) return 'TBA';
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      }

      function computeDuration(start, end) {
        if (!start || !end) return 'Flexible';
        const s = new Date(start), e = new Date(end);
        if (isNaN(s) || isNaN(e)) return 'Flexible';
        const months = Math.max(1, Math.round((e - s) / (1000 * 60 * 60 * 24 * 30)));
        if (months >= 10) return '1 academic year';
        if (months >= 5) return '1 semester';
        if (months >= 4) return '4 – 6 months';
        return '1 – 3 months';
      }

      function isUrgent(endDate) {
        if (!endDate) return false;
        const days = (new Date(endDate) - new Date()) / (1000 * 60 * 60 * 24);
        return days >= 0 && days <= 14;
      }

      const programs = rawPrograms.map(p => {
        const type = (p.program_type || '').toLowerCase();
        return {
            id: p.id, title: p.program_name, type: type,
            desc: p.description || 'No description available.',
            institution: p.institution_name || 'Partner Institution',
            country: p.country_name || 'Unknown',
            startDate: p.start_date, endDate: p.end_date,
            deadline: formatDate(p.end_date),
            duration: computeDuration(p.start_date, p.end_date),
            emoji: TYPE_EMOJI[type] || '🎓',
            featured: false, urgent: isUrgent(p.end_date)
        };
      });

      const PER_PAGE = 8;
      let currentPage = 1;
      let filteredPrograms = [...programs];

      function renderPrograms(list, page) {
        currentPage = page || 1;
        filteredPrograms = list;
        const total = list.length;
        const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
        const start = (currentPage - 1) * PER_PAGE;
        const pageItems = list.slice(start, start + PER_PAGE);
        const container = document.getElementById('programList');
        container.innerHTML = '';

        if (total === 0) {
          container.innerHTML = '<div style="text-align:center;padding:60px 0;color:var(--gray-500);">No programs match your filters. Try adjusting your search.</div>';
          renderPagination(0, 1);
          document.getElementById('resultCount').textContent = 'No programs found';
          return;
        }

        pageItems.forEach(p => {
            const desc = p.desc.length > 120 ? p.desc.substring(0, 120) + "..." : p.desc;
            const card = document.createElement('div');
            card.className = 'program-card' + (p.featured ? ' featured' : '');
            card.onclick = () => openModal(p);

            card.innerHTML = `
                <div class="card-logo">${p.emoji}</div>
                <div class="card-body">
                    <div class="card-top">
                        <div>
                            <div class="card-title">${p.title}</div>
                            <div class="card-institution">${p.institution} · ${p.country}</div>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                            ${p.featured ? '<span class="featured-badge">FEATURED</span>' : ''}
                            <span class="card-deadline${p.urgent ? ' urgent' : ''}">Deadline: ${p.deadline}</span>
                        </div>
                    </div>
                    <div class="card-desc">${desc}</div>
                    <div class="card-tags">
                        <span class="tag type-${p.type}">${capitalize(p.type)}</span>
                        <span class="tag">⏱ ${p.duration}</span>
                    </div>
                </div>
            `;
            container.appendChild(card);
        });

        document.getElementById('resultCount').textContent =
          `Showing ${start + 1}–${Math.min(start + PER_PAGE, total)} of ${total} programs`;
        renderPagination(totalPages, currentPage);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }

      function renderPagination(totalPages, active) {
        const pg = document.getElementById('paginationBar');
        pg.innerHTML = '';
        if (totalPages <= 1) return;

        const btn = (label, page, disabled, isActive) => {
          const b = document.createElement('button');
          b.className = 'page-btn' + (isActive ? ' active' : '');
          b.textContent = label;
          b.disabled = disabled;
          if (!disabled && !isActive) b.onclick = () => renderPrograms(filteredPrograms, page);
          return b;
        };

        pg.appendChild(btn('‹', active - 1, active === 1, false));
        let pages = [];
        if (totalPages <= 7) {
          pages = Array.from({length: totalPages}, (_, i) => i + 1);
        } else {
          pages = [1];
          if (active > 3) pages.push('…');
          for (let i = Math.max(2, active - 1); i <= Math.min(totalPages - 1, active + 1); i++) pages.push(i);
          if (active < totalPages - 2) pages.push('…');
          pages.push(totalPages);
        }
        pages.forEach(p => {
          if (p === '…') {
            const s = document.createElement('span');
            s.textContent = '…';
            s.style.cssText = 'padding:0 6px;color:var(--gray-500);line-height:36px;';
            pg.appendChild(s);
          } else {
            pg.appendChild(btn(p, p, false, p === active));
          }
        });
        pg.appendChild(btn('›', active + 1, active === totalPages, false));
      }

      function openModal(p) {
        document.getElementById('modalLogo').textContent = p.emoji;
        document.getElementById('modalTitle').textContent = p.title;
        document.getElementById('modalInstitution').textContent = p.institution + ' · ' + p.country;
        document.getElementById('modalTags').innerHTML = `
          <span class="tag type-${p.type}">${capitalize(p.type)}</span>
          <span class="tag">⏱ ${p.duration}</span>`;
        document.getElementById('modalDesc').textContent = p.desc;
        document.getElementById('modalMeta').innerHTML = `
          <div class="meta-item"><div class="label">Duration</div><div class="value">${p.duration}</div></div>
          <div class="meta-item"><div class="label">Application Deadline</div><div class="value">${p.deadline}</div></div>
          <div class="meta-item"><div class="label">Country</div><div class="value">${p.country}</div></div>`;
        document.getElementById('applyLink').href = '?page=apply_program&program_id=' + p.id;
        document.getElementById('modalOverlay').classList.add('open');
      }

      function closeModal(e) {
        if (e.target === document.getElementById('modalOverlay')) closeModalDirect();
      }
      function closeModalDirect() {
        document.getElementById('modalOverlay').classList.remove('open');
      }

      function applySearch() {
        const q = document.getElementById('searchInput').value.toLowerCase();
        const type = document.getElementById('typeFilter').value;
        const checkedTypes = [...document.querySelectorAll('.filter-group input[type=checkbox][data-filter=type]:checked')].map(c => c.value);
        const countryVal = document.getElementById('countryFilter').value;

        const filtered = programs.filter(p => {
          const matchQ = !q || p.title.toLowerCase().includes(q) || p.country.toLowerCase().includes(q) || p.institution.toLowerCase().includes(q);
          const matchType = (!type || p.type === type) && (checkedTypes.length === 0 || checkedTypes.includes(p.type));
          const matchCountry = !countryVal || p.country === countryVal;
          return matchQ && matchType && matchCountry;
        });
        renderPrograms(filtered, 1);
      }

      function filterCards() { applySearch(); }

      function clearFilters() {
        document.querySelectorAll('.filter-group input[type=checkbox]').forEach(cb => cb.checked = false);
        document.getElementById('searchInput').value = '';
        document.getElementById('typeFilter').value = '';
        document.getElementById('countryFilter').value = '';
        renderPrograms(programs, 1);
      }

      function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

      document.getElementById('searchInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') applySearch();
      });

      document.getElementById('sortSelect').addEventListener('change', function() {
        const val = this.value;
        const sorted = [...filteredPrograms];
        sorted.sort((a, b) => {
          const da = a.endDate ? new Date(a.endDate) : new Date(8640000000000000);
          const db = b.endDate ? new Date(b.endDate) : new Date(8640000000000000);
          return da - db;
        });
        if (val === 'az') sorted.sort((a, b) => a.title.localeCompare(b.title));
        renderPrograms(sorted, 1);
      });

      renderPrograms(programs, 1);
    </script>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>