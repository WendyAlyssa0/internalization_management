<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* LOAD CONFIGURATION */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

/* INITIALIZE VARIABLES*/

$error = '';
$message = '';

/* PROCESS FORGOT PASSWORD REQUEST*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    // Validate email
    if ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Invalid email format.';
    } else {
        try {
            $pdo = db();

            /* CHECK RATE LIMIT */

            $stmt = $pdo->prepare("
                SELECT 1
                FROM password_resets
                WHERE email = ?
                  AND created_at > (NOW() - INTERVAL 60 SECOND)
                LIMIT 1
            ");

            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                $error = 'Please wait a minute before requesting another reset link.';
            } else {

                /* | FIND REGISTERED USER */

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ?
                    LIMIT 1
                ");

                $stmt->execute([$email]);
                $userId = $stmt->fetchColumn();

                /* GENERIC RESPONSE This prevents revealing whether an email is registered.*/

                $message = 'If that email is registered, a password reset link has been sent.';

                if ($userId) {

                    /* INVALIDATE PREVIOUS RESET TOKENS */

                    $stmt = $pdo->prepare("
                        UPDATE password_resets
                        SET used_at = NOW()
                        WHERE email = ?
                          AND used_at IS NULL
                    ");

                    $stmt->execute([$email]);

                    /* GENERATE SECURE TOKEN */
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    /* SAVE TOKEN IN DATABASE The reset link expires after 15 minutes. */

                    $stmt = $pdo->prepare("
                        INSERT INTO password_resets
                        (
                            user_id,
                            email,
                            token_hash,
                            expires_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                        )
                    ");

                    $stmt->execute([
                        $userId,
                        $email,
                        $tokenHash
                    ]);

                    /* BUILD RESET LINK Assumes reset_password.php is inside public/. */

                    $protocol = (
                        !empty($_SERVER['HTTPS']) &&
                        $_SERVER['HTTPS'] !== 'off'
                    ) ? 'https://' : 'http://';

                    $domain = $_SERVER['HTTP_HOST'];

                    $basePath = str_replace(
                        '\\',
                        '/',
                        dirname($_SERVER['SCRIPT_NAME'])
                    );

                    $basePath = rtrim($basePath, '/');

                    $resetLink = $protocol . $domain . $basePath .
                        '/reset_password.php?token=' . urlencode($token) .
                        '&email=' . urlencode($email);

                    /* SEND EMAIL USING GMAIL SMTP */
                            $otp = generateOTP();
                            $sent = sendPasswordResetOTP($email, $otp);
                    if (!$sent) {

                        // Keep the generic response to prevent email enumeration.
                        error_log(
                            '[forgot_password] Failed to send reset email to: ' .
                            $email
                        );
                    }
                }
            }
        } catch (Throwable $e) {

            error_log(
                '[forgot_password] ' .
                $e->getMessage()
            );

            // Never display database or SMTP technical errors to visitors.
            $error = 'Something went wrong. Please try again.';
            $message = '';

        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — PSU Internationalization</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ===== CSS DESIGN TOKENS ===== */
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

        body {
            font-family: var(--font-body);
            background-color: var(--bg-main);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
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
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--psu-navy);
        }

        .brand .badge {
            background: var(--psu-navy);
            color: var(--white);
            font-size: 1rem;
            font-weight: 800;
            padding: 10px 14px;
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
            font-size: 4rem;
            line-height: 1.1;
            font-weight: 800;
            color: var(--psu-navy);
            letter-spacing: -1.5px;
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
            margin-bottom: 64px;
            max-width: 500px;
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

        .register-card {
            background: var(--white);
            width: 100%;
            max-width: 480px; /* Adjusted for narrower form */
            border-radius: var(--radius-lg);
            padding: 48px;
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(255, 255, 255, 0.5);
            position: relative;
            margin-top: 40px;
            margin-bottom: 40px;
        }

        .register-header {
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
            content: '?';
            position: absolute;
            right: -2px;
            bottom: -2px;
            background: var(--psu-gold);
            color: var(--white);
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 800;
            border: 2px solid var(--white);
        }

        .register-header h1 {
            font-family: var(--font-heading);
            font-size: 2rem;
            color: var(--psu-navy);
            font-weight: 800;
            margin-bottom: 8px;
        }

        .register-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        /* Alerts */
        .alert-error, .alert-success {
            padding: 16px 20px;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            line-height: 1.4;
        }

        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .alert-success svg, .alert-error svg {
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* Forms */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 24px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--psu-navy);
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .lead-ic {
            position: absolute;
            left: 16px;
            width: 20px;
            height: 20px;
            color: var(--text-light);
            pointer-events: none;
            z-index: 1;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            font-family: var(--font-body);
            font-size: 0.95rem;
            color: var(--psu-navy);
            background: var(--white);
            transition: var(--transition);
            outline: none;
        }

        .form-input::placeholder { color: var(--text-light); }

        .form-input:focus {
            border-color: var(--psu-navy);
            box-shadow: 0 0 0 3px rgba(11, 26, 48, 0.08);
        }

        /* Actions */
        .btn-primary {
            width: 100%;
            padding: 16px;
            border-radius: var(--radius-sm);
            font-family: var(--font-body);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--psu-navy);
            color: var(--white);
            margin-bottom: 16px;
        }

        .btn-primary:hover {
            background: var(--psu-blue);
            transform: translateY(-1px);
        }

        .btn-secondary {
            display: block;
            width: 100%;
            padding: 14px;
            border-radius: var(--radius-sm);
            font-family: var(--font-body);
            font-size: 0.95rem;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            color: var(--text-muted);
            background: var(--white);
            border: 1px solid var(--border-light);
            transition: var(--transition);
        }

        .btn-secondary:hover {
            background: var(--bg-main);
            color: var(--psu-navy);
            border-color: var(--psu-navy);
        }

        .login-link {
            text-align: center;
            margin-top: 32px;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .login-link a {
            color: var(--psu-gold);
            font-weight: 700;
            text-decoration: none;
            margin-left: 4px;
        }

        .login-link a:hover { text-decoration: underline; }

        /* Responsive */
        @media (max-width: 900px) {
            .page { flex-direction: column; }
            .hero { padding: 100px 24px 40px; align-items: center; text-align: center; }
            .hero-top { top: 30px; left: 50%; transform: translateX(-50%); width: 100%; display: flex; justify-content: center; }
            .hero .lead { margin: 0 auto 40px; }
            .hero-foot { position: relative; bottom: 0; left: 0; margin-top: 40px; }
            .form-side { padding: 0 24px 40px; }
            .register-card { margin-top: 0; padding: 32px 24px; }
            .hero h2 { font-size: 2.5rem; }
        }
    </style>
</head>
<body>

<div class="page">
    <!-- LEFT HERO SECTION -->
    <aside class="hero">
        <div class="hero-top">
            <a href="website.php" class="brand">
                <span class="badge">PSU</span> 
                <div class="divider-line"></div>
                PSU Internationalization
            </a>
        </div>

        <div class="hero-body">
            <div class="eyebrow"><i></i> Account Recovery</div>
            <h2>We've got you <em>covered</em>.</h2>
            <p class="lead">Don't worry if you've forgotten your password. Enter your registered email address and we'll send you a secure link to reset it.</p>
        </div>

        <div class="hero-foot">&copy; <?= date('Y') ?> Pangasinan State University &middot; Internationalization Office</div>
    </aside>

    <!-- RIGHT FORM CARD -->
    <main class="form-side">
        <div class="register-card">
            <div class="register-header">
                <div class="user-plus-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <h1>Reset Password</h1>
                <p>Enter your email and we'll send you a link to reset your password.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-error">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert-success">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <div><?= htmlspecialchars($message) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <div class="input-wrap">
                        <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>
                        <input class="form-input" type="email" name="email" id="email" placeholder="e.g. juan.delacruz@psu.edu.ph" required autofocus>
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    Send Reset Link
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </button>

                <a href="user_login.php" class="btn-secondary">
                    Back to Login
                </a>
            </form>

            <div class="login-link">
                Don't have an account? <a href="register.php">Create one</a>
            </div>
        </div>
    </main>
</div>

</body>
</html>