
<?php

require_once __DIR__ . '/../config/mailer.php';

$recipient = 'latorrewendy5@gmail.com';

$testLink = 'http://localhost/Internalization_management/public/reset_password.php?test=1';

if (sendPasswordResetLink($recipient, $testLink)) {
    echo 'SUCCESS! Gmail SMTP is working. Check your inbox.';
} else {
    echo 'FAILED! Please check your SMTP configuration and PHP error log.';
}
