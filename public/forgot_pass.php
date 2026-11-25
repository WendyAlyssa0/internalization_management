<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

$error   = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        try {
            $pdo = db();

            // 60-second rate limit
            $stmt = $pdo->prepare(
                'SELECT 1 FROM password_resets
                 WHERE email = ? AND created_at > (NOW() - INTERVAL 60 SECOND) LIMIT 1'
            );
            $stmt->execute([$email]);

            if ($stmt->fetchColumn()) {
                $error = 'Please wait a minute before requesting another code.';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $userId = $stmt->fetchColumn();

                // Always show the same message (no email enumeration)
                $message = 'If that email is registered, a 6-digit code has been sent.';

                if ($userId) {
                    // Invalidate previous codes
                    $pdo->prepare('UPDATE password_resets SET used_at = NOW()
                                   WHERE email = ? AND used_at IS NULL')
                        ->execute([$email]);

                    $otp  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $hash = password_hash($otp, PASSWORD_DEFAULT);

                    $pdo->prepare(
                        'INSERT INTO password_resets (user_id, email, otp_hash, expires_at)
                         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
                    )->execute([$userId, $email, $hash]);

                    // Stash email so verify step knows who we're working with
                    $_SESSION['reset_email'] = $email;

                    if (!sendPasswordResetOTP($email, $otp) && !MAIL_DEBUG) {
                        $message = 'Could not send the email right now. Please try again later.';
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('[forgot_password] ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password · PSU Internationalization</title>
    <link rel="stylesheet" href="../asset/css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
    <div class="login-card">
        <div class="login-left">
            <div class="top-design">
                <div class="wave-dark"></div>
                <div class="wave-medium"></div>
                <div class="wave-light"></div>
            </div>
            <div class="left-content">
                <div class="left-logo"><img src="../asset/img/psu_logo.png" alt="PSU Logo"></div>
                <div class="left-title">PSU Internationalization</div>
                <div class="left-sub">Password Recovery</div>
            </div>
        </div>

        <div class="login-right">
            <div class="form-header">
                <div class="form-title">Reset Password</div>
                <div class="form-subtitle">Enter your email and we'll send you a 6-digit code</div>
            </div>

            <?php if ($error): ?>
                <div class="error" style="color:#d9534f;margin-bottom:15px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="success" style="color:#5cb85c;margin-bottom:15px;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="field">
                    <label>Email Address</label>
                    <div class="field-wrap">
                        <i class="fa fa-envelope"></i>
                        <input type="email" name="email" placeholder="Enter your email" required autofocus>
                    </div>
                </div>

                <button type="submit" class="login-btn">
                    Send Code <i class="fa fa-arrow-right"></i>
                </button>

                <a href="login.php" class="back-btn">Back to Login</a>
            </form>

            <?php if ($message): ?>
                <p style="text-align:center;margin-top:14px;">
                    <a href="verify_otp.php" style="color:#0b1f4a;font-weight:600;">
                        Enter the 6-digit code →
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>