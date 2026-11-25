<?php
declare(strict_types=1);

if (!defined('OTP_MAX_ATTEMPTS')) define('OTP_MAX_ATTEMPTS', 5);
if (!defined('OTP_TTL_MINUTES'))  define('OTP_TTL_MINUTES', 10);
if (!defined('RESET_SESSION_TTL')) define('RESET_SESSION_TTL', 600); // 10 min to set new pw

function generateOTP(int $len = 6): string {
    return str_pad((string)random_int(0, 10 ** $len - 1), $len, '0', STR_PAD_LEFT);
}

/**
 * STEP 1 — Request code.
 * Always returns the same generic message (no email enumeration).
 */
function requestPasswordReset(PDO $pdo, string $email): array {
    $email = strtolower(trim($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please enter a valid email address.'];
    }

    // 60s rate limit per email
    $stmt = $pdo->prepare(
        'SELECT 1 FROM password_resets
         WHERE email = ? AND created_at > (NOW() - INTERVAL 60 SECOND) LIMIT 1'
    );
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        return ['ok' => false, 'message' => 'Please wait a minute before requesting another code.'];
    }

    $generic = ['ok' => true, 'message' => 'If that email is registered, a code was sent.'];

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();
    if (!$userId) return $generic;

    // Invalidate any prior unused codes
    $pdo->prepare('UPDATE password_resets SET used_at = NOW()
                   WHERE email = ? AND used_at IS NULL')->execute([$email]);

    $otp  = generateOTP(6);
    $hash = password_hash($otp, PASSWORD_DEFAULT);

    $pdo->prepare(
        'INSERT INTO password_resets (user_id, email, otp_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
    )->execute([$userId, $email, $hash, OTP_TTL_MINUTES]);

    $sent = sendPasswordResetOTP($email, $otp);   // from your php.txt
    if (!$sent && !MAIL_DEBUG) {
        return ['ok' => false, 'message' => 'Could not send the code. Try again later.'];
    }

    return $generic;
}

/**
 * STEP 2 — Verify OTP.
 * On success, marks the row used and sets a session token that authorizes the password change.
 */
function verifyResetOTP(PDO $pdo, string $email, string $otp): array {
    $email = strtolower(trim($email));
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

    // Success → consume OTP and open a short window to change the password
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);

    $token = bin2hex(random_bytes(32));
    $_SESSION['reset_token']   = $token;
    $_SESSION['reset_email']   = $email;
    $_SESSION['reset_expires'] = time() + RESET_SESSION_TTL;

    return [
        'ok'      => true,
        'message' => 'Code verified.',
        'token'   => $token,   // sent back to JS to include in step 3
    ];
}

/**
 * STEP 3 — Change the password.
 */
function resetPassword(PDO $pdo, string $token, string $newPassword): array {
    if (empty($_SESSION['reset_token']) || !hash_equals($_SESSION['reset_token'], $token)) {
        return ['ok' => false, 'message' => 'Reset session invalid. Please start over.'];
    }
    if (($_SESSION['reset_expires'] ?? 0) < time()) {
        return ['ok' => false, 'message' => 'Session expired. Please start over.'];
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

    return ['ok' => true, 'message' => 'Password updated. Redirecting to login…'];
}