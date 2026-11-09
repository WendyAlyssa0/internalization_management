
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


if (!defined('MAIL_HOST'))      define('MAIL_HOST', 'smtp.example.com');
if (!defined('MAIL_USERNAME'))  define('MAIL_USERNAME', 'no-reply@example.com');
if (!defined('MAIL_PASSWORD'))  define('MAIL_PASSWORD', '');
if (!defined('MAIL_PORT'))      define('MAIL_PORT', 587);
if (!defined('MAIL_FROM_ADDR')) define('MAIL_FROM_ADDR', 'no-reply@psuinternalization.edu');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'PSU Internalization');

/* How long a one-time passcode stays valid (minutes). */
if (!defined('OTP_TTL_MINUTES')) define('OTP_TTL_MINUTES', 10);

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sendPasswordResetOTP(string $toEmail, string $otp): bool {
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
        $mail->Subject = 'Your PSU Internalization password reset code';
        $mail->Body    =
            '<div style="font-family:Arial,Helvetica,sans-serif;color:#1e2740;line-height:1.6">'
          . '<p>Use the code below to reset your PSU Internalization password:</p>'
          . '<p style="font-size:30px;font-weight:bold;letter-spacing:8px;color:#0b1f4a;margin:16px 0">'
          . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>'
          . '<p>This code expires in ' . (int)OTP_TTL_MINUTES . ' minutes. '
          . 'If you didn\'t request this, you can safely ignore this email.</p>'
          . '</div>';
        $mail->AltBody = "Your PSU Internalization password reset code is: {$otp}\n"
                       . "It expires in " . (int)OTP_TTL_MINUTES . " minutes.";

        $mail->send();
        return true;
    } catch (Exception $ex) {
        error_log('Password reset OTP email failed: ' . $mail->ErrorInfo);
        return false;
    }
}

/* Inline SVG icons used across the forms. */
function icon(string $name): string {
    $a = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
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
    $sw = $name === 'arrow' ? ' stroke-width="2"' : '';
    return '<svg ' . str_replace(' stroke-width="1.8"', $sw ?: ' stroke-width="1.8"', $a) . '>' . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Opens the page: <head>, card, left form panel and mobile brand.
 */
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
            <div><strong>PSU <b>Internalization</b></strong><small>Gateway to Global Opportunities</small></div>
          </div>
<?php }

/**
 * Closes the form panel, renders the welcome panel and the scripts.
 */
function auth_close(string $label, string $titleHtml, string $text): void { ?>
          <div class="auth-footer">&copy; 2026 Pangasinan State University · PSU Internalization</div>
        </div>

        <div class="auth-welcome-side">
          <div class="welcome-brand">
            <div class="brand-mark"><img src="../asset/img/psu_logo.png" alt="PSU logo" /></div>
            <div class="brand-text"><strong>PSU</strong><span>INTERNALIZATION</span></div>
          </div>

          <div class="welcome-label"><?= e($label) ?></div>
          <h2 class="welcome-title"><?= $titleHtml /* trusted static markup */ ?></h2>
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