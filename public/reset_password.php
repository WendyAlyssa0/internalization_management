<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';
$success = false;

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$email = $_GET['email'] ?? $_POST['email'] ?? '';

// Validate token on load
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (empty($token) || empty($email)) {
        die("Invalid reset link.");
    }

    $tokenHash = hash('sha256', $token);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE email = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$email, $tokenHash]);

    if (!$stmt->fetch()) {
        die("This reset link is invalid or has expired.");
    }
}

// Handle password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $tokenHash = hash('sha256', $token);

            $stmt = $pdo->prepare(
                'SELECT id FROM password_resets
                 WHERE email = ?
                 AND token_hash = ?
                 AND used_at IS NULL
                 AND expires_at > NOW()
                 LIMIT 1
                 FOR UPDATE'
            );

            $stmt->execute([$email, $tokenHash]);
            $resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$resetRow) {
                $pdo->rollBack();
                $error = 'This reset link is invalid or has expired.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    'UPDATE users SET password_hash = ? WHERE email = ?'
                );
                $stmt->execute([$passwordHash, $email]);

                if ($stmt->rowCount() === 0) {
                    // Check that the account exists even if the hash was unchanged.
                    $check = $pdo->prepare(
                        'SELECT id FROM users WHERE email = ? LIMIT 1'
                    );
                    $check->execute([$email]);

                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('User account not found.');
                    }
                }

                $stmt = $pdo->prepare(
                    'UPDATE password_resets SET used_at = NOW() WHERE id = ?'
                );
                $stmt->execute([$resetRow['id']]);

                $pdo->commit();
                $success = true;
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[reset_password] ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password — PSU Internationalization</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Copy the same CSS from forgot_password.php above */
        /* (Omitted here for brevity, but simply copy the <style> block from Step 3) */
        <?php include 'forgot_password_styles.css'; /* Or just paste the CSS inline */ ?>
    </style>
</head>
<body>
    <!-- Use the exact same layout structure as forgot_password.php -->
    <!-- Just change the form content inside the card -->
    
    <div class="page">
        <aside class="hero">
             <!-- ... Copy Hero Section from Step 3 ... -->
        </aside>
        <main class="form-side">
            <div class="register-card">
                <div class="register-header">
                    <div class="user-plus-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <h1>Create New Password</h1>
                    <p>Please enter your new secure password below.</p>
                </div>

                <?php if ($success): ?>
                    <div class="alert-success">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <div>Password successfully reset! You can now <a href="login.php" style="color:#047857;font-weight:bold;text-decoration:underline;">Log In</a>.</div>
                    </div>
                <?php else: ?>
                    <?php if ($error): ?>
                        <div class="alert-error">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">

                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <div class="input-wrap">
                                <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <input class="form-input" type="password" name="password" placeholder="Enter new password" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <div class="input-wrap">
                                <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <input class="form-input" type="password" name="confirm_password" placeholder="Confirm new password" required>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary">
                            Save New Password
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>