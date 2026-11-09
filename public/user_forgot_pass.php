<?php
require_once __DIR__ . '/../config/auth_co.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Fallback escaper in case auth_co.php does not define e(). */
if (!function_exists('e')) {
    function e(?string $v): string
    {
        return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

const MAX_ATTEMPTS    = 5;    // wrong codes allowed per request
const RESEND_COOLDOWN = 60;   // seconds between code emails
const MIN_PASS        = 8;
const MAX_PASS        = 72;   // bcrypt only uses the first 72 bytes

function mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }
    return mb_substr($parts[0], 0, 1) . str_repeat('•', max(2, mb_strlen($parts[0]) - 1)) . '@' . $parts[1];
}

$pdo = db();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* Two steps: "request" (enter email) and "verify" (enter code + new password).
 * The pending email is remembered in the session between steps. */
$forgot_error   = '';
$forgot_message = '';
$forgot_email   = $_SESSION['pw_reset_email'] ?? '';
$forgot_step    = !empty($_SESSION['pw_reset_email']) ? 'verify' : 'request';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $forgot_action = $_POST['forgot_action'] ?? 'request';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $forgot_error = 'Your session expired. Please try again.';

    /* ----- change email: drop the pending session, back to step 1 ----- */
    } elseif ($forgot_action === 'change_email') {
        unset($_SESSION['pw_reset_email'], $_SESSION['pw_reset_attempts']);
        $forgot_email = '';
        $forgot_step  = 'request';

    /* ----- send / resend the code ----- */
    } elseif ($forgot_action === 'request' || $forgot_action === 'resend') {

        $inputEmail = $forgot_action === 'resend'
            ? ($_SESSION['pw_reset_email'] ?? '')
            : strtolower(trim($_POST['email'] ?? ''));
        $email    = filter_var($inputEmail, FILTER_VALIDATE_EMAIL);
        $waitLeft = RESEND_COOLDOWN - (time() - (int)($_SESSION['pw_reset_last_sent'] ?? 0));

        if (!$email) {
            $forgot_error = 'Please enter a valid email address.';
            $forgot_step  = 'request';
        } elseif ($waitLeft > 0) {
            $forgot_error = "Please wait {$waitLeft} seconds before requesting another code.";
            $forgot_step  = $forgot_action === 'resend' ? 'verify' : 'request';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND user_role IN ('applicant', 'ap') LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Only generate + email a code when the account exists, but
                // always advance and show the same message either way so we
                // never leak which addresses are registered.
                if ($user) {
                    $otp          = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $token_expiry = date('Y-m-d H:i:s', strtotime('+' . (int)OTP_TTL_MINUTES . ' minutes'));

                    $pdo->prepare('UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?')
                        ->execute([hash('sha256', $otp), $token_expiry, $user['id']]);

                    sendPasswordResetOTP($email, $otp);
                }

                $_SESSION['pw_reset_email']     = $email;
                $_SESSION['pw_reset_attempts']  = 0;
                $_SESSION['pw_reset_last_sent'] = time();
                $forgot_email   = $email;
                $forgot_step    = 'verify';
                $forgot_message = "If an account exists for that email, we've sent a 6-digit verification code.";

            } catch (PDOException $ex) {
                error_log('Forgot password request failed: ' . $ex->getMessage());
                $forgot_error = 'System error. Please try again later.';
                $forgot_step  = 'request';
            }
        }

    /* ----- verify code + set the new password ----- */
    } elseif ($forgot_action === 'verify') {

        $email = filter_var($_SESSION['pw_reset_email'] ?? '', FILTER_VALIDATE_EMAIL);
        $otp   = preg_replace('/\D/', '', implode('', (array)($_POST['otp'] ?? [])));
        $new   = $_POST['password'] ?? '';
        $conf  = $_POST['confirm_password'] ?? '';
        $forgot_email = $email ?: '';
        $forgot_step  = 'verify';

        if (!$email) {
            $forgot_error = 'Your session expired. Please start again.';
            unset($_SESSION['pw_reset_email'], $_SESSION['pw_reset_attempts']);
            $forgot_step  = 'request';
        } elseif (($_SESSION['pw_reset_attempts'] ?? 0) >= MAX_ATTEMPTS) {
            $forgot_error = 'Too many incorrect attempts. Please request a new code.';
        } elseif (strlen($otp) !== 6) {
            $forgot_error = 'Please enter the 6-digit code from your email.';
        } elseif (strlen($new) < MIN_PASS) {
            $forgot_error = 'Your new password must be at least ' . MIN_PASS . ' characters.';
        } elseif (strlen($new) > MAX_PASS) {
            $forgot_error = 'Your new password must be at most ' . MAX_PASS . ' characters.';
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $forgot_error = 'Your new password must include at least one letter and one number.';
        } elseif ($new !== $conf) {
            $forgot_error = 'Passwords do not match.';
        } else {
            try {
                $stmt = $pdo->prepare('
                    SELECT id FROM users
                    WHERE email = ?
                      AND reset_token = ?
                      AND reset_token_expiry IS NOT NULL
                      AND reset_token_expiry > NOW()
                    LIMIT 1
                ');
                $stmt->execute([$email, hash('sha256', $otp)]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $_SESSION['pw_reset_attempts'] = ($_SESSION['pw_reset_attempts'] ?? 0) + 1;
                    $left = MAX_ATTEMPTS - $_SESSION['pw_reset_attempts'];

                    if ($left <= 0) {
                        // Burn the code so it can't be guessed any further.
                        $pdo->prepare('UPDATE users SET reset_token = NULL, reset_token_expiry = NULL WHERE email = ?')
                            ->execute([$email]);
                        $forgot_error = 'Too many incorrect attempts. Please request a new code.';
                    } else {
                        $forgot_error = "That code is invalid or has expired. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.';
                    }
                } else {
                    $pdo->prepare('
                        UPDATE users
                        SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL
                        WHERE id = ?
                    ')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);

                    unset($_SESSION['pw_reset_email'], $_SESSION['pw_reset_attempts'], $_SESSION['pw_reset_last_sent']);
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    header('Location: user_login.php?reset=1');
                    exit();
                }
            } catch (PDOException $ex) {
                error_log('Password reset verify failed: ' . $ex->getMessage());
                $forgot_error = 'System error. Please try again later.';
            }
        }
    }
}

/* Seconds left before "Resend" works again (drives the countdown). */
$cooldownLeft = max(0, RESEND_COOLDOWN - (time() - (int)($_SESSION['pw_reset_last_sent'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset Password | PSU Internalization</title>
      <link rel="stylesheet" href="../asset/css/user_forgot_pass.css">
</head>
<body>
  <main class="shell">

    <aside class="panel">
      <div class="brand"><span class="brand-mark">PSU</span> Account Recovery</div>
      <span class="shape diamond-a"></span>
      <span class="shape diamond-b"></span>
      <span class="shape tri"></span>
      <span class="shape circle"></span>

      <h2>Reset your<span>PSU Internalization</span> password</h2>
      <p>We'll email you a one-time code so you can get back into your account securely.</p>
      <a href="user_login.php" class="ghost-btn">Sign In</a>
    </aside>

    <section class="form-side">

<?php if ($forgot_step === 'request'): ?>
      <!-- STEP 1: REQUEST CODE -->
      <h1>Reset Password</h1>
      <p class="subtitle">Enter your email and we'll send a 6-digit verification code.</p>

      <?php if ($forgot_error !== ''): ?>
        <div class="alert" role="alert"><?= e($forgot_error) ?></div>
      <?php endif; ?>

      <form id="forgotForm" method="post" action="forgot_password.php">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="forgot_action" value="request">

        <div class="field">
          <label class="lbl" for="email">Email Address</label>
          <div class="wrap">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
            <input type="email" id="email" name="email" value="<?= e($forgot_email) ?>" placeholder="name@psu.edu.ph" maxlength="190" autocomplete="email" autofocus required>
          </div>
        </div>

        <button type="submit" class="submit-btn">Send Code</button>
      </form>

      <p class="back-line"><a href="user_login.php">Back to Sign In</a></p>

<?php else: ?>
      <!-- STEP 2: VERIFY CODE + NEW PASSWORD -->
      <h1>Enter Code</h1>
      <p class="subtitle">We sent a 6-digit code to <strong><?= e(mask_email($forgot_email)) ?></strong>.<br>Enter it below with your new password.</p>

      <?php if ($forgot_message !== ''): ?>
        <div class="alert success" role="status"><?= e($forgot_message) ?></div>
      <?php endif; ?>
      <?php if ($forgot_error !== ''): ?>
        <div class="alert" role="alert"><?= e($forgot_error) ?></div>
      <?php endif; ?>

      <form id="verifyForm" method="post" action="forgot_password.php">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="forgot_action" value="verify">

        <div class="field">
          <label class="lbl" for="otp0">Verification Code</label>
          <div class="otp-row" id="otpRow">
            <?php for ($i = 0; $i < 6; $i++): ?>
              <input type="text" id="otp<?= $i ?>" name="otp[]" maxlength="1" inputmode="numeric" pattern="[0-9]" aria-label="Digit <?= $i + 1 ?>"
                     <?= $i === 0 ? 'autocomplete="one-time-code" autofocus' : '' ?> required>
            <?php endfor; ?>
          </div>
          <p class="otp-hint">The code expires in <b><?= (int)OTP_TTL_MINUTES ?> minutes</b>.</p>
        </div>

        <div class="field">
          <label class="lbl" for="resetPassword">New Password</label>
          <div class="wrap has-toggle">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
            <input type="password" id="resetPassword" name="password" placeholder="Enter new password" minlength="8" maxlength="72" autocomplete="new-password" required>
            <button type="button" class="eye-btn" data-eye aria-label="Show password">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <div class="strength" id="strength" data-score="0" aria-live="polite">
            <div class="bars"><span></span><span></span><span></span><span></span></div>
            <div class="lab">Password strength: <b id="strengthLabel">—</b> · 8+ characters with a letter and a number</div>
          </div>
        </div>

        <div class="field">
          <label class="lbl" for="confirmPassword">Confirm New Password</label>
          <div class="wrap has-toggle">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
            <input type="password" id="confirmPassword" name="confirm_password" placeholder="Confirm new password" maxlength="72" autocomplete="new-password" required>
            <button type="button" class="eye-btn" data-eye aria-label="Show password">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" class="submit-btn" id="submitBtn">Reset Password</button>
      </form>

      <div class="links">
        <span>Didn't get a code?</span>
        <form method="post" action="forgot_password.php">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="forgot_action" value="resend">
          <button type="submit" class="linklike" id="resendBtn" data-wait="<?= (int)$cooldownLeft ?>" <?= $cooldownLeft > 0 ? 'disabled' : '' ?>>Resend</button>
        </form>
        <span>·</span>
        <form method="post" action="forgot_password.php">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="forgot_action" value="change_email">
          <button type="submit" class="linklike" formnovalidate>Use a different email</button>
        </form>
      </div>

      <p class="back-line"><a href="user_login.php">Back to Sign In</a></p>
<?php endif; ?>

    </section>
  </main>

  
</body>
</html>
    <script src="../asset/js/user_forgot_pass.js"></script>
