<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

// 2. Then import the classes you need
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    
    if (!$email) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND user_role IN ('applicant', 'ap') LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Logic: Generate token (same as your admin reset)
            if ($user) {
                $reset_token = bin2hex(random_bytes(32));
                $token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $update = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
                $update->execute([hash('sha256', $reset_token), $token_expiry, $user['id']]);
                
                // TODO: Replace with your actual email sending logic
                $message = 'A password reset link has been sent to your email.';
            } else {
                $message = 'If an account exists, a reset link will be sent.';
            }
        } catch (PDOException $e) {
            $error = 'System error. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../asset/css/user_login.css">
    <title>Reset Password | PSU Internationalization</title>
</head>
<body>
<div class="login-wrapper reversed">

    <div class="login-left">
        <div class="form-header">
            <div class="form-title">Reset Password</div>
            <div class="form-subtitle">Enter your email to recover your account</div>
        </div>

        <?php if ($error) echo "<div class='error'>$error</div>"; ?>
        <?php if ($message) echo "<div class='success' style='color:green; margin-bottom:15px;'>$message</div>"; ?>

        <form method="POST">
            <div class="field">
                <label>Email Address</label>
                <div class="field-wrap">
                    <i class="fa fa-envelope"></i>
                    <input type="email" name="email" placeholder="Enter your email" required autofocus>
                </div>
            </div>
            <button type="submit" class="login-btn">Send Reset Link <i class="fa fa-arrow-right"></i></button>
            
            <div class="options" style="margin-top: 25px; text-align: center;">
                <a href="user_login.php" class="back-btn">
                    <i class="fa fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </form>
    </div>
    
    <div class="login-right">
        <div class="top-design">
            <div class="wave-dark"></div>
            <div class="wave-medium"></div>
            <div class="wave-light"></div>
        </div>
        <div class="deco-circle c1"></div>
        <div class="deco-circle c2"></div>
        <div class="deco-circle c3"></div>

        <div class="right-content">
            <div class="welcome-label">Student Portal</div>
            <div class="welcome-title">
                Password<br><span>Recovery</span>
            </div>
            <div class="welcome-quote">
                Need help getting back in?
                <small>We'll help you reset your credentials to access your global opportunities.</small>
            </div>
            <div class="icon-row">
                <div class="icon-chip"><i class="fa fa-key"></i> Secure</div>
                <div class="icon-chip"><i class="fa fa-shield"></i> Verified</div>
            </div>
        </div>

        <div class="right-footer">
            <div class="right-footer-text">Pangasinan State University &nbsp;·&nbsp; International Programs</div>
        </div>
    </div>

</div>
</body>
</html>