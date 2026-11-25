<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/* =========================
   MAIL CONFIGURATION
   
   Configure PSU_SMTP_USERNAME, PSU_SMTP_PASSWORD, and APP_BASE_URL
   in the web server environment. Credentials are not stored in source.
========================= */
if (!defined('MAIL_HOST'))      define('MAIL_HOST',     getenv('PSU_SMTP_HOST') ?: 'smtp.gmail.com');
if (!defined('MAIL_PORT'))      define('MAIL_PORT',     (int)(getenv('PSU_SMTP_PORT') ?: 587));
if (!defined('MAIL_USERNAME'))  define('MAIL_USERNAME', getenv('PSU_SMTP_USERNAME') ?: '');
if (!defined('MAIL_PASSWORD'))  define('MAIL_PASSWORD', getenv('PSU_SMTP_PASSWORD') ?: '');
if (!defined('MAIL_FROM_ADDR')) define('MAIL_FROM_ADDR', getenv('PSU_SMTP_FROM') ?: MAIL_USERNAME);
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME','PSU Internationalization');

if (!defined('MAIL_DEBUG'))     define('MAIL_DEBUG',    false);

/* How long a one-time passcode stays valid (minutes). */
if (!defined('OTP_TTL_MINUTES')) define('OTP_TTL_MINUTES', 10);

/* =========================
   HELPERS
========================= */
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* =========================
   PASSWORD RESET — CORE
========================= */
if (!defined('OTP_MAX_ATTEMPTS')) define('OTP_MAX_ATTEMPTS', 5);

function generateOTP(int $length = 6): string {
    return str_pad((string)random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
}

/**
 * Create + email a reset OTP. Returns generic message always (no user enumeration).
 */
function requestPasswordReset(PDO $pdo, string $email): array {
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please enter a valid email address.'];
    }

    // Rate limit: max 1 request per 60s per email
    $stmt = $pdo->prepare(
        'SELECT created_at FROM password_resets
         WHERE email = ? AND created_at > (NOW() - INTERVAL 60 SECOND)
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'message' => 'Please wait a minute before requesting another code.'];
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always say the same thing (don't leak whether email exists)
    $generic = ['ok' => true, 'message' => 'If that email is registered, a reset code has been sent.'];

    if (!$user) return $generic;

    $otp = generateOTP(6);
    $hash = password_hash($otp, PASSWORD_DEFAULT);

    // Invalidate older unused codes for this email
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE email = ? AND used_at IS NULL')
        ->execute([$email]);

    $pdo->prepare(
        'INSERT INTO password_resets (user_id, email, otp_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
    )->execute([$user['id'], $email, $hash, OTP_TTL_MINUTES]);

    $sent = sendPasswordResetOTP($email, $otp);
    if (!$sent && !MAIL_DEBUG) {
        return ['ok' => false, 'message' => 'Could not send email. Please try again later.'];
    }
    return $generic;
}

/**
 * Verify OTP in real time. Returns ['ok'=>bool, 'message'=>string, 'token'=>?string]
 * On success issues a short-lived reset token stored in session.
 */
function verifyResetOTP(PDO $pdo, string $email, string $otp): array {
    $email = trim(strtolower($email));
    $otp   = preg_replace('/\D/', '', $otp);

    if (strlen($otp) !== 6) {
        return ['ok' => false, 'message' => 'Enter the 6-digit code.'];
    }

    $stmt = $pdo->prepare(
        'SELECT id, otp_hash, attempts FROM password_resets
         WHERE email = ? AND used_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => 'Code expired or invalid. Request a new one.'];
    }
    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return ['ok' => false, 'message' => 'Too many attempts. Request a new code.'];
    }

    if (!password_verify($otp, $row['otp_hash'])) {
        $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?')
            ->execute([$row['id']]);
        return ['ok' => false, 'message' => 'Incorrect code. Please try again.'];
    }

    // Mark used + create session token for the reset step
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    $token = bin2hex(random_bytes(32));
    $_SESSION['reset_token']   = $token;
    $_SESSION['reset_email']   = $email;
    $_SESSION['reset_expires'] = time() + 300; // 5 min to set new password

    return ['ok' => true, 'message' => 'Code verified.', 'token' => $token];
}

function resetPassword(PDO $pdo, string $token, string $newPassword): array {
    if (empty($_SESSION['reset_token']) || !hash_equals($_SESSION['reset_token'], $token)) {
        return ['ok' => false, 'message' => 'Invalid reset session. Please start over.'];
    }
    if (($_SESSION['reset_expires'] ?? 0) < time()) {
        return ['ok' => false, 'message' => 'Reset session expired. Please start over.'];
    }
    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'Password must be at least 8 characters.'];
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password = ? WHERE email = ?')
        ->execute([$hash, $_SESSION['reset_email']]);

    // Cleanup
    $pdo->prepare('DELETE FROM password_resets WHERE email = ?')
        ->execute([$_SESSION['reset_email']]);
    unset($_SESSION['reset_token'], $_SESSION['reset_email'], $_SESSION['reset_expires']);

    return ['ok' => true, 'message' => 'Password updated. You can now log in.'];
}

/**
 * Sends a password reset OTP via SMTP (PHPMailer).
 * Returns true on success, false on any failure.
 * Never throws.
 */
function sendPasswordResetOTP(string $toEmail, string $otp): bool {

    /* ---- Dev mode: log instead of send ---- */
    if (MAIL_DEBUG) {
        error_log("[MAIL_DEBUG] OTP for {$toEmail}: {$otp}");
        return true;
    }

    /* ---- Validate config before trying ---- */
    if (MAIL_USERNAME === '' || MAIL_PASSWORD === '') {
        error_log('[sendPasswordResetOTP] SMTP credentials are not configured.');
        return false;
    }
    $baseUrl = getenv('APP_BASE_URL');
    if (!is_string($baseUrl) || trim($baseUrl) === '') {
        error_log('[sendPasswordResetOTP] APP_BASE_URL is not configured.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {

        //$mail->SMTPDebug  = 2;
        //$mail->Debugoutput = 'error_log';

        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->Timeout    = 15;   // seconds — prevents hanging on network issues

        /* Use a valid From address matching the SMTP username — 
           many providers reject mismatched From (especially Gmail). */
        $mail->setFrom(MAIL_FROM_ADDR, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_FROM_ADDR, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your PSU Internationalization password reset code';

        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $ttl     = (int)OTP_TTL_MINUTES;
        
        // Create the magic link
        $link = rtrim($baseUrl, '/') . '/public/reset_password.php?email=' . urlencode($toEmail) . '&otp=' . urlencode($otp);

        $mail->Body =
            '<div style="font-family:Arial,Helvetica,sans-serif;color:#1e2740;line-height:1.6;max-width:520px">'
          . '<h2 style="color:#0b1f4a;margin:0 0 12px;font-size:20px">Password Reset Request</h2>'
          . '<p>Use the code below to reset your PSU Internationalization password:</p>'
          . '<p style="font-size:30px;font-weight:bold;letter-spacing:8px;color:#0b1f4a;margin:16px 0;'
          . 'padding:12px 20px;background:#f0f4ff;border-radius:8px;text-align:center;display:inline-block">'
          . $safeOtp . '</p>'
          . '<p style="margin: 24px 0;">'
          . '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="background:#0b1f4a;color:white;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;">Click here to reset your password</a>'
          . '</p>'
          . '<p style="color:#64748b;font-size:13px">This link and code expire in ' . $ttl . ' minutes. '
          . 'If you didn\'t request this, you can safely ignore this email.</p>'
          . '</div>';

        $mail->AltBody =
            "PSU Internationalization password reset\n"
          . "----------------------------------\n"
          . "Your verification code is: {$otp}\n"
          . "It expires in {$ttl} minutes.\n"
          . "If you didn't request this, ignore this email.\n";

        $mail->send();
        return true;

    } catch (PHPMailerException $ex) {
        error_log('[sendPasswordResetOTP] PHPMailer: ' . $mail->ErrorInfo);
        return false;

    } catch (\Throwable $ex) {
        error_log('[sendPasswordResetOTP] Unexpected: ' . $ex->getMessage());
        return false;
    }
}

/* =========================
   ICON HELPER
========================= */
function icon(string $name): string {
    $paths = [
        'user'  => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'lock'  => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'mail'  => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
        'edit'  => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'plus'  => '<circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/>',
        'cal'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'id'    => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M7 8h4M7 12h4M15 8h2M15 12h2M7 16h10"/>',
        'grad'  => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c0 1 2.7 2 6 2s6-1 6-2v-5"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 4 5.7 4 9s-1.5 6.5-4 9c-2.5-2.5-4-5.7-4-9s1.5-6.5 4-9Z"/>',
        'plane' => '<path d="M17.8 19.2 16 11l3.5-3.5a2.12 2.12 0 0 0-3-3L13 8 4.8 6.2a1 1 0 0 0-.9 1.7l4.6 3.3-2.3 2.3H3.5a1 1 0 0 0-.8 1.6l2.6 3.4L8.7 22a1 1 0 0 0 1.6-.8v-2.7l2.3-2.3 3.3 4.6a1 1 0 0 0 1.7-.9Z"/>',
        'medal' => '<circle cx="12" cy="8" r="6"/><path d="M8.2 13.9 7 22l5-3 5 3-1.2-8.1"/>',
        'arrow' => '<line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/>',
    ];
    $strokeWidth = ($name === 'arrow') ? '2' : '1.8';
    return sprintf(
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round">%s</svg>',
        $strokeWidth,
        $paths[$name] ?? ''
    );
}

/* =========================
   AUTH PAGE SHELL (for login/register pages)
========================= */
function auth_open(string $title): void { ?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= e($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../asset/css/user_login.css" />
  </head>
  <body>
    <div class="auth-page">
      <div class="auth-card" id="authCard">
        <div class="auth-form-side">
          <div class="auth-mobile-brand">
            <div class="brand-mark"><img src="../asset/img/psu_logo.png" alt="PSU logo" /></div>
            <div><strong>PSU <b>Internationalization</b></strong><small>Gateway to Global Opportunities</small></div>
          </div>
<?php }

function auth_close(string $label, string $titleHtml, string $text): void { ?>
          <div class="auth-footer">&copy; 2026 Pangasinan State University · PSU Internationalization</div>
        </div>

        <div class="auth-welcome-side">
          <div class="welcome-brand">
            <div class="brand-mark"><img src="../asset/img/psu_logo.png" alt="PSU logo" /></div>
            <div class="brand-text"><strong>PSU</strong><span>INTERNATIONALIZATION</span></div>
          </div>

          <div class="welcome-label"><?= e($label) ?></div>
          <h2 class="welcome-title"><?= $titleHtml ?></h2>
          <p class="welcome-text"><?= e($text) ?></p>

          <div class="welcome-features">
            <div class="welcome-feature"><span class="feature-icon"><?= icon('globe') ?></span><span class="feature-text"><strong>Partner</strong><span>Universities</span></span></div>
            <div class="welcome-feature"><span class="feature-icon"><?= icon('grad') ?></span><span class="feature-text"><strong>Exchange</strong><span>Programs</span></span></div>
            <div class="welcome-feature"><span class="feature-icon"><?= icon('plane') ?></span><span class="feature-text"><strong>Student</strong><span>Mobility</span></span></div>
            <div class="welcome-feature"><span class="feature-icon"><?= icon('medal') ?></span><span class="feature-text"><strong>Research</strong><span>Opportunities</span></span></div>
          </div>
        </div>
      </div>
    </div>
    <script src="../asset/js/auth.js"></script>
  </body>
</html>
<?php }