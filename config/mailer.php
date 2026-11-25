<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendPasswordResetOTP(string $email, string $otp): bool
{
    $credentials = require __DIR__ . '/mail_credentials.php';

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $credentials['email'];
        $mail->Password   = $credentials['app_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom($credentials['email'], 'PSUxIZN');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your PSU Internationalization Password Reset OTP';

        $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:500px;margin:auto;'>
                <h2 style='color:#0E1C36;'>PSU Internationalization Password Reset</h2>
                <p>Use the verification code below to reset your password:</p>

                <h1 style='color:#C9A227;letter-spacing:8px;'>
                    {$otp}
                </h1>

                <p>This OTP expires in 10 minutes.</p>
                <p>If you did not request this code, please ignore this email.</p>
            </div>
        ";

        $mail->AltBody = "Your PSU Internationalization password reset OTP is: {$otp}. It expires in 10 minutes.";

        $mail->send();

        return true;

    } catch (Exception $e) {
        error_log('[PHPMailer] ' . $mail->ErrorInfo);
        return false;
    }
}