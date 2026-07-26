<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/db.php';

/* ------------------------------------------------------------------
 * ONE-TIME DB MIGRATION NEEDED (run manually before using this file):
 *
 *   ALTER TABLE users
 *     ADD COLUMN middle_name       VARCHAR(100) NULL AFTER first_name,
 *     ADD COLUMN suffix            VARCHAR(20)  NULL AFTER last_name,
 *     ADD COLUMN sex               ENUM('male','female','prefer_not_to_say') NULL AFTER suffix,
 *     ADD COLUMN date_of_birth     DATE NULL AFTER sex,
 *     ADD COLUMN student_id_number VARCHAR(30)  NULL AFTER date_of_birth;
 *
 *   (reset_token / reset_token_expiry columns are assumed to already
 *   exist, since the original forgot-password page already used them.)
 * ------------------------------------------------------------------ */

/* ------------------------------------------------------------------
 * MAIL CONFIG — fill these in with your real SMTP details, or better,
 * move them into config/mail.php and require that instead. Left as
 * constants here just so the merge is a single drop-in file.
 * ------------------------------------------------------------------ */
if (!defined('MAIL_HOST'))        define('MAIL_HOST', 'smtp.example.com');
if (!defined('MAIL_USERNAME'))    define('MAIL_USERNAME', 'no-reply@example.com');
if (!defined('MAIL_PASSWORD'))    define('MAIL_PASSWORD', '');
if (!defined('MAIL_PORT'))        define('MAIL_PORT', 587);
if (!defined('MAIL_FROM_ADDR'))   define('MAIL_FROM_ADDR', 'no-reply@psuinternalization.edu');
if (!defined('MAIL_FROM_NAME'))   define('MAIL_FROM_NAME', 'PSU Internalization');
if (!defined('APP_BASE_URL'))     define('APP_BASE_URL', (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']);

/**
 * Sends the password reset email. Returns true on success, false on failure.
 * Failures are logged, never shown to the user (so we don't leak whether an
 * account exists).
 */
function sendPasswordResetEmail(string $toEmail, string $resetToken): bool {
    $resetLink = rtrim(APP_BASE_URL, '/') . '/index.php?page=reset&token=' . urlencode($resetToken);

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_ADDR, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Reset your PSU Internalization password';
        $mail->Body    = '
            <p>We received a request to reset your password.</p>
            <p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '">Click here to reset your password</a></p>
            <p>This link expires in 1 hour. If you didn\'t request this, you can ignore this email.</p>
        ';
        $mail->AltBody = "Reset your password: {$resetLink} (expires in 1 hour)";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Password reset email failed: ' . $mail->ErrorInfo);
        return false;
    }
}

$pdo = db();

/* ------------------------------------------------------------------
 * ROUTING
 * ------------------------------------------------------------------
 * Everything now lives in this one file. Which "screen" is shown is
 * controlled by ?page=login | register | forgot | browse
 * ------------------------------------------------------------------ */
$allowedPages = ['login', 'register', 'forgot', 'browse'];
$page = $_GET['page'] ?? 'login';
if (!in_array($page, $allowedPages, true)) {
    $page = 'login';
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ==================================================================
 * REGISTER — state
 * ================================================================== */
$reg_errors      = [];
$reg_success     = false;
$reg_first_name  = '';
$reg_middle_name = '';
$reg_last_name   = '';
$reg_suffix      = '';
$reg_email       = '';
$reg_sex         = '';
$reg_dob         = '';
$reg_student_id  = '';

/* ==================================================================
 * LOGIN — state
 * ================================================================== */
$login_error    = '';
$login_username = $_COOKIE['remember_user'] ?? '';
$login_remember = false;

/* ==================================================================
 * FORGOT PASSWORD — state
 * ================================================================== */
$forgot_error   = '';
$forgot_message = '';
$forgot_email   = '';

/* ==================================================================
 * POST HANDLING
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'register') {

    $reg_first_name  = trim($_POST['first_name'] ?? '');
    $reg_middle_name = trim($_POST['middle_name'] ?? '');
    $reg_last_name   = trim($_POST['last_name'] ?? '');
    $reg_suffix      = trim($_POST['suffix'] ?? '');
    $reg_email       = trim($_POST['email'] ?? '');
    $reg_sex         = trim($_POST['sex'] ?? '');
    $reg_dob         = trim($_POST['date_of_birth'] ?? '');
    $reg_student_id  = trim($_POST['student_id_number'] ?? '');
    $agree_terms     = isset($_POST['agree_terms']);
    $agree_privacy   = isset($_POST['agree_privacy']);
    $password        = $_POST['password'] ?? '';
    $confirm         = $_POST['confirm_password'] ?? '';

    // Public self-registration is applicant-only. Admin / Super Admin
    // accounts are never created from this form — they must be
    // provisioned separately by someone who already has admin access.
    $role = 'applicant';

    $allowedSex = ['male', 'female', 'prefer_not_to_say'];

    // VALIDATION
    if ($reg_first_name === '' || $reg_last_name === '' || $reg_email === '' || $password === '') {
        $reg_errors[] = 'Please fill in all required fields.';
    }

    if ($reg_email !== '' && !filter_var($reg_email, FILTER_VALIDATE_EMAIL)) {
        $reg_errors[] = 'Invalid email address.';
    }

    if ($password !== '' && $password !== $confirm) {
        $reg_errors[] = 'Passwords do not match.';
    }

    if ($reg_sex === '' || !in_array($reg_sex, $allowedSex, true)) {
        $reg_errors[] = 'Please select your sex.';
    }

    if ($reg_student_id === '') {
        $reg_errors[] = 'Please enter your Student ID Number.';
    }

    if (!$agree_terms || !$agree_privacy) {
        $reg_errors[] = 'You must agree to the Terms and Conditions and the Privacy Policy.';
    }

    // Date of birth is optional, but if supplied it must be a real, non-future date.
    $dobForDb = null;
    if ($reg_dob !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $reg_dob);
        $valid = $d && $d->format('Y-m-d') === $reg_dob;
        if (!$valid) {
            $reg_errors[] = 'Please enter a valid date of birth.';
        } elseif ($d > new DateTime()) {
            $reg_errors[] = 'Date of birth cannot be in the future.';
        } else {
            $dobForDb = $reg_dob;
        }
    }

    if (!$reg_errors) {

        // CHECK EMAIL EXISTENCE
        $check = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $check->execute([':email' => $reg_email]);

        if ($check->fetch()) {
            $reg_errors[] = 'Email already exists.';
        } else {

            try {

                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        first_name,
                        middle_name,
                        last_name,
                        suffix,
                        email,
                        password_hash,
                        sex,
                        date_of_birth,
                        student_id_number,
                        user_role,
                        status
                    )
                    VALUES (
                        :first_name,
                        :middle_name,
                        :last_name,
                        :suffix,
                        :email,
                        :password_hash,
                        :sex,
                        :date_of_birth,
                        :student_id_number,
                        :user_role,
                        :status
                    )
                ");

                $result = $stmt->execute([
                    ':first_name'         => $reg_first_name,
                    ':middle_name'        => $reg_middle_name !== '' ? $reg_middle_name : null,
                    ':last_name'          => $reg_last_name,
                    ':suffix'             => $reg_suffix !== '' ? $reg_suffix : null,
                    ':email'              => $reg_email,
                    ':password_hash'      => password_hash($password, PASSWORD_DEFAULT),
                    ':sex'                => $reg_sex,
                    ':date_of_birth'      => $dobForDb,
                    ':student_id_number'  => $reg_student_id,
                    ':user_role'          => $role,
                    ':status'             => 'Active',
                ]);

                if ($result) {
                    $reg_success = true;
                    header('refresh:2;url=?page=login');
                } else {
                    $reg_errors[] = 'Registration failed.';
                }

            } catch (PDOException $ex) {
                $reg_errors[] = 'Database error.';
            }
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'login') {

    $login_username = trim($_POST['username'] ?? '');
    $password       = $_POST['password'] ?? '';
    $login_remember = isset($_POST['remember']);

    if ($login_username === '' || $password === '') {
        $login_error = 'All fields are required.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, first_name, email, user_role, password_hash
            FROM users
            WHERE email = :username
            OR first_name = :username
            LIMIT 1
        ");
        $stmt->execute([':username' => $login_username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['username']   = $user['email'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['user_role']  = $user['user_role'];

            if ($login_remember) {
                setcookie('remember_user', $login_username, time() + 60 * 60 * 24 * 30, '/', '', true, true);
            } else {
                setcookie('remember_user', '', time() - 3600, '/');
            }

            // Get the role, default to applicant if empty or NULL
            $role = strtolower(trim($user['user_role'] ?? ''));

            if ($role === '') {
                $role = 'applicant';

                // Optional: save the default role in the database
                $update = $pdo->prepare("
                    UPDATE users
                    SET user_role = 'applicant'
                    WHERE id = ?
                ");
                $update->execute([$user['id']]);

                $_SESSION['user_role'] = 'applicant';
            }

            // Allow applicant/student accounts
            if (in_array($role, ['applicant', 'student', 'user', 'ap'], true)) {

                header('Location: ../user/home.php');
                exit();

            } else {

                $login_error = 'Your account does not have access to this portal.';

            }
        } else {
            $login_error = 'Invalid username or password.';
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'forgot') {

    $forgot_email = trim($_POST['email'] ?? '');
    $email = filter_var($forgot_email, FILTER_VALIDATE_EMAIL);

    if (!$email) {
        $forgot_error = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND user_role IN ('applicant', 'ap') LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $reset_token  = bin2hex(random_bytes(32));
                $token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $update = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
                $update->execute([hash('sha256', $reset_token), $token_expiry, $user['id']]);

                sendPasswordResetEmail($email, $reset_token);
            }

            // Same message whether or not the account exists, so we don't
            // leak which emails are registered.
            $forgot_message = 'If an account exists for that email, a reset link has been sent.';

        } catch (PDOException $ex) {
            $forgot_error = 'System error. Please try again later.';
        }
    }
}

/* ==================================================================
 * BROWSE — data load (only needed for the browse screen)
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
            SELECT
                p.id,
                p.program_name,
                p.program_type,
                p.description,
                p.start_date,
                p.end_date,
                p.status,
                p.partner_id,
                p.country_id,
                c.country_name,
                pr.institution_name
            FROM programs p
            LEFT JOIN countries c
                ON p.country_id = c.id
            LEFT JOIN partners pr
                ON p.partner_id = pr.id
            WHERE p.status = 'Active'
            ORDER BY p.id DESC
        ");

        $stmt->execute();

        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $ex) {
        // Don't let a query failure blank out the whole page — show a
        // friendly message instead and log the real error for debugging.
        error_log('index.php (browse) query failed: ' . $ex->getMessage());
        $loadError = 'We ran into a problem loading programs. Please try again shortly.';
    }

    // Counts for the sidebar / hero stats (computed from real data instead of hardcoded numbers)
    foreach ($programs as $p) {
        $t = strtolower($p['program_type'] ?? '');
        if (isset($typeCounts[$t])) {
            $typeCounts[$t]++;
        }
        if (!empty($p['country_name'])) {
            $countrySet[$p['country_name']] = true;
        }
    }
    $totalPrograms = count($programs);
    $totalCountries = count($countrySet);
}

/* ==================================================================
 * PAGE META
 * ================================================================== */
$pageTitles = [
    'login'    => 'PSU Internalization Login',
    'register' => 'Create Account — PSU Internalization',
    'forgot'   => 'Reset Password | PSUxIZN',
    'browse'   => 'PSU Internalization — Student Programs Portal',
];
$pageTitle = $pageTitles[$page];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>

    <?php if (in_array($page, ['login', 'register', 'forgot'], true)): ?>
        <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root{
                --auth-navy:#0b1f4a;
                --auth-navy-deep:#030b1f;
                --auth-gold:#c8a15a;
                --auth-gold-deep:#9a753c;
                --auth-gold-soft:rgba(200,161,90,0.14);
                --auth-blue:#2f8bff;
                --auth-white:#ffffff;
                --auth-gray50:#f7f8fa;
                --auth-gray100:#eef1f6;
                --auth-gray200:#e2e6ed;
                --auth-gray500:#6b7a99;
                --auth-gray800:#232c47;
                --auth-radius:16px;
                --auth-shadow:0 20px 60px rgba(11,31,74,0.18);
            }
            *{box-sizing:border-box;}
            html,body{height:100%;}
            body{
                margin:0;
                background:var(--auth-gray50);
                font-family:"Oswald",sans-serif;
                color:var(--auth-gray800);
                min-height:100vh;
                display:flex;
                align-items:center;
                justify-content:center;
                padding:28px 16px;
            }
            a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible{
                outline:2px solid var(--auth-blue);
                outline-offset:2px;
            }

            /* ---------- wrapper / split panel ---------- */
            .auth-wrapper{
                display:flex;
                width:min(980px, 100%);
                min-height:640px;
                background:var(--auth-white);
                border-radius:var(--auth-radius);
                box-shadow:var(--auth-shadow);
                overflow:hidden;
                transition:opacity .35s ease, transform .35s ease;
            }
            .auth-wrapper.is-leaving{
                opacity:0;
                transform:scale(.98);
            }

            .auth-form-panel{
                flex:1 1 50%;
                padding:44px 44px 32px;
                display:flex;
                flex-direction:column;
                justify-content:center;
                order:1;
                animation:authFadeIn .45s ease;
                overflow-y:auto;
                max-height:640px;
            }
            .auth-welcome-panel{
                flex:1 1 50%;
                order:2;
                position:relative;
                background:linear-gradient(160deg,var(--auth-navy) 0%,var(--auth-navy-deep) 100%);
                color:var(--auth-white);
                padding:44px 40px;
                display:flex;
                flex-direction:column;
                justify-content:center;
                overflow:hidden;
            }
            /* register / forgot: form slides to the right, welcome moves to the left */
            .auth-wrapper.is-flipped .auth-form-panel{order:2;}
            .auth-wrapper.is-flipped .auth-welcome-panel{order:1;}

            @keyframes authFadeIn{
                from{opacity:0; transform:translateY(6px);}
                to{opacity:1; transform:translateY(0);}
            }

            .auth-welcome-panel::before,
            .auth-welcome-panel::after{
                content:"";
                position:absolute;
                border-radius:50%;
                background:rgba(200,161,90,0.12);
            }
            .auth-welcome-panel::before{width:260px;height:260px;top:-90px;right:-80px;}
            .auth-welcome-panel::after{width:180px;height:180px;bottom:-70px;left:-60px;background:rgba(47,139,255,0.10);}
            .auth-welcome-label{
                position:relative;
                text-transform:uppercase;
                font-size:.72rem;
                letter-spacing:1.5px;
                color:var(--auth-gold);
                font-weight:600;
                margin-bottom:10px;
            }
            .auth-welcome-title{
                position:relative;
                font-size:1.9rem;
                font-weight:600;
                line-height:1.25;
                margin-bottom:14px;
            }
            .auth-welcome-title span{color:var(--auth-gold);}
            .auth-welcome-text{
                position:relative;
                font-size:.85rem;
                color:rgba(255,255,255,.75);
                line-height:1.6;
                max-width:320px;
            }

            /* ---------- shared form bits ---------- */
            .auth-wordmark{
                font-size:1rem;
                font-weight:600;
                letter-spacing:-0.3px;
                margin-bottom:22px;
                color:var(--auth-navy);
            }
            .auth-wordmark span{color:var(--auth-gold-deep);}
            .auth-form-title{
                font-size:1.3rem;
                font-weight:700;
                color:var(--auth-navy);
                margin:0 0 4px;
            }
            .auth-form-subtitle{
                font-size:.82rem;
                color:var(--auth-gray500);
                margin:0 0 20px;
            }
            .auth-error, .auth-success{
                border-radius:8px;
                padding:10px 12px;
                font-size:.78rem;
                margin-bottom:14px;
                line-height:1.5;
            }
            .auth-error{background:#fbeaea;color:#a33;border:1px solid #f0c9c9;}
            .auth-success{background:var(--auth-gold-soft);color:var(--auth-gold-deep);border:1px solid rgba(200,161,90,0.35);}

            .auth-row{display:flex;gap:10px;}
            .auth-field-group{flex:1;margin-bottom:14px;}
            .auth-field-group.full{flex:1 1 100%;}
            .auth-label{
                display:block;
                font-size:.68rem;
                font-weight:600;
                letter-spacing:.4px;
                text-transform:uppercase;
                color:var(--auth-gray500);
                margin-bottom:6px;
            }
            .auth-optional{
                text-transform:none;
                font-weight:400;
                color:#a4adc2;
                letter-spacing:0;
            }
            .auth-field{
                width:100%;
                border:1px solid var(--auth-gray200);
                background:var(--auth-gray50);
                border-radius:8px;
                padding:10px 12px;
                font-family:"Oswald",sans-serif;
                font-size:.85rem;
                color:var(--auth-gray800);
            }
            .auth-field:focus{
                outline:none;
                border-color:var(--auth-blue);
                background:var(--auth-white);
                box-shadow:0 0 0 3px rgba(47,139,255,0.15);
            }

            .auth-sex-row{display:flex;gap:8px;}
            .auth-sex-option{
                flex:1;
                display:flex;
                align-items:center;
                justify-content:center;
                gap:6px;
                border:1px solid var(--auth-gray200);
                border-radius:8px;
                padding:9px 6px;
                font-size:.74rem;
                background:var(--auth-gray50);
                cursor:pointer;
                transition:border-color .2s, background .2s, color .2s;
                text-align:center;
            }
            .auth-sex-option:has(input:checked){
                border-color:var(--auth-gold);
                background:var(--auth-gold-soft);
                color:var(--auth-gold-deep);
                font-weight:600;
            }
            .auth-sex-option input{accent-color:var(--auth-blue);margin:0;}

            .auth-options{
                display:flex;
                justify-content:space-between;
                align-items:center;
                font-size:.76rem;
                color:var(--auth-gray500);
                margin:0 0 18px;
            }
            .auth-options label{display:flex;align-items:center;gap:6px;cursor:pointer;}
            .auth-options a{color:var(--auth-gold-deep);text-decoration:none;font-weight:500;}
            .auth-options a:hover{text-decoration:underline;}

            .auth-terms{
                font-size:.68rem;
                color:var(--auth-gray500);
                line-height:1.5;
                margin:2px 0 18px;
            }
            .auth-terms a{color:var(--auth-gold-deep);text-decoration:none;font-weight:500;}
            .auth-terms a:hover{text-decoration:underline;}

            .auth-section-title{
                font-size:.72rem;
                font-weight:700;
                letter-spacing:.6px;
                text-transform:uppercase;
                color:var(--auth-navy);
                margin:22px 0 14px;
                padding-bottom:8px;
                border-bottom:1px solid var(--auth-gray200);
            }
            .auth-field-group + .auth-section-title,
            .auth-row + .auth-section-title{
                margin-top:4px;
            }

            .auth-checkbox-row{
                display:flex;
                align-items:flex-start;
                gap:8px;
                font-size:.78rem;
                color:var(--auth-gray800);
                margin-bottom:10px;
                cursor:pointer;
                line-height:1.4;
            }
            .auth-checkbox-row input{
                margin-top:2px;
                accent-color:var(--auth-blue);
                flex-shrink:0;
            }
            .auth-checkbox-row a{color:var(--auth-gold-deep);text-decoration:none;font-weight:500;}
            .auth-checkbox-row a:hover{text-decoration:underline;}

            .auth-submit{
                background:linear-gradient(135deg,var(--auth-navy) 0%,var(--auth-navy-deep) 100%);
                color:var(--auth-white);
                border:none;
                border-radius:9px;
                font-family:"Oswald",sans-serif;
                font-size:.92rem;
                font-weight:600;
                padding:12px 20px;
                cursor:pointer;
                width:100%;
                display:flex;
                align-items:center;
                justify-content:center;
                gap:8px;
                transition:background .2s ease, color .2s ease, transform .1s ease;
            }
            .auth-submit:hover{
                background:linear-gradient(135deg,var(--auth-gold) 0%,#e8c98c 100%);
                color:var(--auth-navy-deep);
            }
            .auth-submit:active{transform:translateY(1px);}
            .auth-submit svg{width:18px;height:18px;}

            .auth-switch{
                text-align:center;
                font-size:.8rem;
                color:var(--auth-gray500);
                margin-top:16px;
            }
            .auth-switch a{color:var(--auth-gold-deep);text-decoration:none;font-weight:600;}
            .auth-switch a:hover{text-decoration:underline;}

            .auth-footer{
                text-align:center;
                font-size:.68rem;
                color:#a4adc2;
                margin-top:22px;
            }

            .auth-icon-row{position:relative;display:flex;gap:10px;margin-top:26px;flex-wrap:wrap;}
            .auth-icon-chip{
                display:flex;align-items:center;gap:6px;
                background:rgba(255,255,255,.08);
                border:1px solid rgba(255,255,255,.14);
                border-radius:999px;
                padding:6px 12px;
                font-size:.72rem;
                color:rgba(255,255,255,.85);
            }
            .auth-icon-chip svg{width:14px;height:14px;}

            @media (max-width:820px){
                .auth-wrapper{flex-direction:column;min-height:0;}
                .auth-wrapper.is-flipped .auth-form-panel,
                .auth-wrapper.is-flipped .auth-welcome-panel,
                .auth-form-panel,
                .auth-welcome-panel{order:initial;max-height:none;}
                .auth-welcome-panel{padding:32px 28px;}
                .auth-form-panel{padding:32px 28px;}
                .auth-row{flex-direction:column;}
                .auth-sex-row{flex-direction:column;}
            }
            @media (prefers-reduced-motion: reduce){
                *, *::before, *::after{
                    animation-duration:0.01ms !important;
                    transition-duration:0.01ms !important;
                }
            }
        </style>
    <?php else: ?>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../asset/css/website.css">
    <?php endif; ?>
</head>

<body>

<?php if (in_array($page, ['login', 'register', 'forgot'], true)): ?>

    <!-- ============================== LOGIN / REGISTER / FORGOT PASSWORD ============================== -->
    <div class="auth-wrapper <?= in_array($page, ['register', 'forgot'], true) ? 'is-flipped' : '' ?>" id="authWrapper">

        <!-- FORM PANEL (left for login, right for register) -->
        <div class="auth-form-panel">
            <div class="auth-wordmark">PSU <span>Internalization</span></div>

            <?php if ($page === 'login'): ?>

                <div class="auth-form-title">Sign In</div>
                <div class="auth-form-subtitle">Access your PSU Internalization applicant account</div>

                <?php if ($login_error): ?>
                    <div class="auth-error"><?= e($login_error) ?></div>
                <?php endif; ?>

                <form method="POST" action="?page=login">
                    <div class="auth-field-group full">
                        <label class="auth-label">Username or Email</label>
                        <input class="auth-field" type="text" name="username"
                            placeholder="Enter username or email"
                            value="<?= e($login_username) ?>"
                            required autocomplete="username" autofocus>
                    </div>

                    <div class="auth-field-group full">
                        <label class="auth-label">Password</label>
                        <input class="auth-field" type="password" name="password"
                            placeholder="Enter your password"
                            required autocomplete="current-password">
                    </div>

                    <div class="auth-options">
                        <label>
                            <input type="checkbox" name="remember" <?= $login_remember ? 'checked' : '' ?>>
                            Remember me
                        </label>
                        <a href="?page=forgot" onclick="swapAway(event, '?page=forgot')">Forgot password?</a>
                    </div>

                    <button type="submit" class="auth-submit">
                        Log In
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/></svg>
                    </button>
                </form>

                <div class="auth-switch">
                    Don't have an account? <a href="?page=register" onclick="swapAway(event, '?page=register')">Register</a>
                </div>

                <div class="auth-footer">&copy; <?= date('Y') ?> Pangasinan State University &nbsp;·&nbsp; PSUxIZN</div>

            <?php elseif ($page === 'register'): ?>

                <div class="auth-form-title">Create an Account</div>
                <div class="auth-form-subtitle">Register as an applicant to browse and apply for programs</div>

                <?php if (!empty($reg_errors)): ?>
                    <div class="auth-error">
                        <?php foreach ($reg_errors as $error): ?>
                            <div><?= e($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($reg_success): ?>
                    <div class="auth-success">
                        <strong>Registration successful!</strong> Redirecting to login...
                    </div>
                <?php endif; ?>

                <form method="POST" action="?page=register">

                    <div class="auth-section-title">1. Personal Information</div>

                    <div class="auth-row">
                        <div class="auth-field-group">
                            <label class="auth-label">First Name</label>
                            <input class="auth-field" type="text" name="first_name" placeholder="First name" required autocomplete="off" value="<?= e($reg_first_name) ?>">
                        </div>
                        <div class="auth-field-group">
                            <label class="auth-label">Middle Name <span class="auth-optional">(Optional)</span></label>
                            <input class="auth-field" type="text" name="middle_name" placeholder="Middle name" autocomplete="off" value="<?= e($reg_middle_name) ?>">
                        </div>
                    </div>

                    <div class="auth-row">
                        <div class="auth-field-group">
                            <label class="auth-label">Last Name</label>
                            <input class="auth-field" type="text" name="last_name" placeholder="Last name" required autocomplete="off" value="<?= e($reg_last_name) ?>">
                        </div>
                        <div class="auth-field-group">
                            <label class="auth-label">Suffix <span class="auth-optional">(Optional)</span></label>
                            <input class="auth-field" type="text" name="suffix" placeholder="Jr., Sr., III…" autocomplete="off" value="<?= e($reg_suffix) ?>">
                        </div>
                    </div>

                    <div class="auth-field-group full">
                        <label class="auth-label">Sex</label>
                        <div class="auth-sex-row">
                            <label class="auth-sex-option">
                                <input type="radio" name="sex" value="male" <?= $reg_sex === 'male' ? 'checked' : '' ?> required> Male
                            </label>
                            <label class="auth-sex-option">
                                <input type="radio" name="sex" value="female" <?= $reg_sex === 'female' ? 'checked' : '' ?>> Female
                            </label>
                            <label class="auth-sex-option">
                                <input type="radio" name="sex" value="prefer_not_to_say" <?= $reg_sex === 'prefer_not_to_say' ? 'checked' : '' ?>> Prefer not to say
                            </label>
                        </div>
                    </div>

                    <div class="auth-row">
                        <div class="auth-field-group">
                            <label class="auth-label">Student ID Number</label>
                            <input class="auth-field" type="text" name="student_id_number" placeholder="e.g. 21-00123" required autocomplete="off" value="<?= e($reg_student_id) ?>">
                        </div>
                        <div class="auth-field-group">
                            <label class="auth-label">Date of Birth <span class="auth-optional">(Optional)</span></label>
                            <input class="auth-field" type="date" name="date_of_birth" value="<?= e($reg_dob) ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="auth-section-title">2. Account Information</div>

                    <div class="auth-field-group full">
                        <label class="auth-label">PSU Email Address</label>
                        <input class="auth-field" type="email" name="email" placeholder="Email address" required autocomplete="off" value="<?= e($reg_email) ?>">
                    </div>

                    <div class="auth-row">
                        <div class="auth-field-group">
                            <label class="auth-label">Password</label>
                            <input class="auth-field" type="password" name="password" placeholder="New password" required>
                        </div>
                        <div class="auth-field-group">
                            <label class="auth-label">Confirm Password</label>
                            <input class="auth-field" type="password" name="confirm_password" placeholder="Confirm password" required>
                        </div>
                    </div>

                    <div class="auth-section-title">3. Terms &amp; Verification</div>

                    <label class="auth-checkbox-row">
                        <input type="checkbox" name="agree_terms" required>
                        <span>I agree to the <a href="#">Terms and Conditions</a></span>
                    </label>
                    <label class="auth-checkbox-row">
                        <input type="checkbox" name="agree_privacy" required>
                        <span>I agree to the <a href="#">Privacy Policy</a></span>
                    </label>

                    <button type="submit" class="auth-submit" style="margin-top:16px;">Register</button>
                </form>

                <div class="auth-switch">
                    Already have an account? <a href="?page=login" onclick="swapAway(event, '?page=login')">Sign In</a>
                </div>

                <div class="auth-footer">&copy; <?= date('Y') ?> Pangasinan State University &nbsp;·&nbsp; PSUxIZN</div>

            <?php else: ?>

                <div class="auth-form-title">Reset Password</div>
                <div class="auth-form-subtitle">Enter your email to recover your account</div>

                <?php if ($forgot_error): ?>
                    <div class="auth-error"><?= e($forgot_error) ?></div>
                <?php endif; ?>

                <?php if ($forgot_message): ?>
                    <div class="auth-success"><?= e($forgot_message) ?></div>
                <?php endif; ?>

                <form method="POST" action="?page=forgot">
                    <div class="auth-field-group full">
                        <label class="auth-label">Email Address</label>
                        <input class="auth-field" type="email" name="email"
                            placeholder="Enter your email"
                            value="<?= e($forgot_email) ?>"
                            required autofocus>
                    </div>

                    <button type="submit" class="auth-submit">
                        Send Reset Link
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/></svg>
                    </button>
                </form>

                <div class="auth-switch">
                    <a href="?page=login" onclick="swapAway(event, '?page=login')">&larr; Back to Login</a>
                </div>

                <div class="auth-footer">&copy; <?= date('Y') ?> Pangasinan State University &nbsp;·&nbsp; PSUxIZN</div>

            <?php endif; ?>
        </div>

        <!-- WELCOME PANEL -->
        <div class="auth-welcome-panel">
            <div class="auth-welcome-label">
                <?php if ($page === 'login'): ?>Student Portal
                <?php elseif ($page === 'register'): ?>Join the Program
                <?php else: ?>Account Recovery
                <?php endif; ?>
            </div>
            <?php if ($page === 'login'): ?>
                <div class="auth-welcome-title">Welcome<br>to <span>PSU Internalization</span><br>Portal</div>
                <div class="auth-welcome-text">Explore new cultures. Expand your horizons. Connecting PSU students to global opportunities.</div>
            <?php elseif ($page === 'register'): ?>
                <div class="auth-welcome-title">Start your<br><span>global journey</span><br>with PSU</div>
                <div class="auth-welcome-text">Create your applicant account to browse exchange, internship, research, and training programs from partner institutions worldwide.</div>
            <?php else: ?>
                <div class="auth-welcome-title">Password<br><span>Recovery</span></div>
                <div class="auth-welcome-text">Need help getting back in? We'll help you reset your credentials to access your global opportunities.</div>
            <?php endif; ?>
            <div class="auth-icon-row">
                <div class="auth-icon-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 3 3 10l6.5 2.5L12 19l2-5.5L21 3Z"/></svg> Exchange</div>
                <div class="auth-icon-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 4 5.7 4 9s-1.5 6.5-4 9c-2.5-2.5-4-5.7-4-9s1.5-6.5 4-9Z"/></svg> Global</div>
                <div class="auth-icon-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9l10-5 10 5-10 5L2 9Z"/><path d="M6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/><path d="M22 9v6"/></svg> Scholars</div>
            </div>
        </div>

    </div>

    <script>
        function swapAway(event, url) {
            event.preventDefault();
            const wrapper = document.getElementById('authWrapper');
            wrapper.classList.add('is-leaving');
            setTimeout(() => { window.location.href = url; }, 260);
        }
    </script>

<?php elseif ($page === 'browse'): ?>

    <!-- ============================== BROWSE PROGRAMS ============================== -->
    <nav>
      <div class="nav-logo">PSU <span>Internalization</span></div>
      <ul class="nav-links">
        <li><a href="?page=browse">Browse Programs</a></li>
        <li><a href="partner_ins.php">Partner Institutions</a></li>
        <li><a href="how_it_works.php">How It Works</a></li>
        <li><a href="?page=register" class="nav-cta">Register Now</a></li>
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

          <div class="programs-empty">
            <p><?= e($loadError) ?></p>
          </div>

        <?php elseif ($totalPrograms === 0): ?>

          <div class="programs-empty">
            <p>No programs are available right now. Please check back soon.</p>
          </div>

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
      <strong>PSU Internalization Management System</strong><br/>
      Pangasinan State University · Office of International Affairs · <a href="#" style="color:rgba(255,255,255,.55)">Contact Us</a>
    </footer>

    <?php if (!$loadError && $totalPrograms > 0): ?>
    <script>

      const rawPrograms = <?= json_encode($programs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

      const TYPE_EMOJI = {
        exchange: '🔄',
        internship: '💼',
        research: '🔬'
      };

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
            id: p.id,
            title: p.program_name,
            type: type,
            desc: p.description || 'No description available.',
            institution: p.institution_name || 'Partner Institution',
            country: p.country_name || 'Unknown',

            startDate: p.start_date,
            endDate: p.end_date,

            deadline: formatDate(p.end_date),
            duration: computeDuration(p.start_date, p.end_date),

            emoji: TYPE_EMOJI[type] || '🎓',

            featured: false,
            urgent: isUrgent(p.end_date)
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

            const desc =
                p.desc.length > 120
                    ? p.desc.substring(0, 120) + "..."
                    : p.desc;

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
                            <span class="card-deadline${p.urgent ? ' urgent' : ''}">
                                Deadline: ${p.deadline}
                            </span>
                        </div>
                    </div>

                    <div class="card-desc">${desc}</div>

                        <div class="card-tags">
                            <span class="tag type-${p.type}">
                                ${capitalize(p.type)}
                            </span>

                            <span class="tag">
                                ⏱ ${p.duration}
                            </span>
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