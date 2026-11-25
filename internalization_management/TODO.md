# TODO

- [x] Remove sidebar from dashboard page(s) and associated CSS/layout.

- [x] (If needed) Update included sidebar fragments to be disabled or not used.
- [ ] Re-test dashboard rendering in browser.

// --- PHPMailer Logic ---
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com'; 
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'your-email@gmail.com'; // Use your actual email
                    $mail->Password   = 'your-app-password';     // Use your App Password
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('no-reply@psu.edu.ph', 'PSUxIZN Support');
                    $mail->addAddress($email);
                    $mail->isHTML(true);
                    $mail->Subject = 'Password Reset Request';
                    $mail->Body    = "
                        <h3>Password Reset Requested</h3>
                        <p>Hi there,</p>
                        <p>We received a request to reset your password. Click the link below to proceed:</p>
                        <p><a href='http://yourdomain.com/public/reset_password.php?token=$reset_token'>Reset Password</a></p>
                        <p>This link will expire in 1 hour. If you did not request this, please ignore this email.</p>
                    ";

                    $mail->send();
                    $message = 'A password reset link has been sent to your email.';
                } catch (Exception $e) {
                    $error = 'Email could not be sent. Please contact support.';
                }

# TODO

- [x] Remove sidebar from dashboard page(s) and associated CSS/layout.
- [x] (If needed) Update included sidebar fragments to be disabled or not used.
- [x] Re-test dashboard rendering in browser.
- [x] Implement password reset email functionality using PHPMailer.


## Future Tasks
- [ ] Add "Password Changed Successfully" notification screen.
- [ ] Add server-side validation to ensure new passwords meet complexity requirements.
- [ ] Style the email template with CSS to match your branding.