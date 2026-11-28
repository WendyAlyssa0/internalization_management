<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/participant_profile.php';

$pdo = db();

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$campuses         = participantCampuses($pdo);
$programsByCampus = participantProgramsByCampus($pdo);

/* ==================================================================
 * STATE VARIABLES
 * ================================================================== */
$reg_errors      = [];
$reg_success     = false;

$reg_first_name  = '';
$reg_middle_name = '';
$reg_last_name   = '';
$reg_suffix      = '';
$reg_sex         = '';
$reg_dob         = '';

$reg_email       = '';   // institutional (primary) email
$reg_alt_email   = '';   // optional personal email

$reg_details = [
    'account_type'   => 'student',
    'campus_id'      => 0,
    'program'        => '',
    'student_number' => '',
    'year_level'     => '',
    'position_rank'  => '',
];

$reg_step = 1;

/* ==================================================================
 * POST HANDLING
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $str = static fn(string $key): string => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';

    // Step 1 — personal
    $reg_first_name  = $str('first_name');
    $reg_middle_name = $str('middle_name');
    $reg_last_name   = $str('last_name');
    $reg_suffix      = $str('suffix');
    $reg_sex         = $str('sex');
    $reg_dob         = $str('date_of_birth');

    // Step 2 — account
    $reg_email       = strtolower($str('email'));
    $reg_alt_email   = strtolower($str('alternate_email'));

    // Step 4 — security
    $password        = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm         = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $agree_terms     = isset($_POST['agree_terms']);

    $firstErrorStep = 5;
    $fail = static function (string $message, int $atStep) use (&$reg_errors, &$firstErrorStep): void {
        $reg_errors[] = $message;
        $firstErrorStep = min($firstErrorStep, $atStep);
    };

    /* ---- Step 1 ---- */
    if ($reg_first_name === '' || $reg_last_name === '') {
        $fail('Please enter your first and last name.', 1);
    }
    if (!in_array($reg_sex, ['Male', 'Female', 'Prefer not to say'], true)) {
        $fail('Please select your sex.', 1);
    }
    $dobForDb = null;
    if ($reg_dob === '') {
        $fail('Please enter your date of birth.', 1);
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $reg_dob);
        if (!$d || $d->format('Y-m-d') !== $reg_dob) {
            $fail('Please enter a valid date of birth.', 1);
        } elseif ($d > new DateTime()) {
            $fail('Date of birth cannot be in the future.', 1);
        } else {
            $dobForDb = $reg_dob;
        }
    }

    /* ---- Step 2 ---- */
    if ($reg_email === '') {
        $fail('Please enter your PSU institutional email address.', 2);
    } elseif (!filter_var($reg_email, FILTER_VALIDATE_EMAIL)) {
        $fail('Your institutional email address is not valid.', 2);
    } elseif (!isInstitutionalEmail($reg_email)) {
        $fail('Use your PSU institutional email (' . institutionalEmailHint() . ') as your primary email.', 2);
    }
    if ($reg_alt_email !== '') {
        if (!filter_var($reg_alt_email, FILTER_VALIDATE_EMAIL)) {
            $fail('Your alternative personal email address is not valid.', 2);
        } elseif ($reg_alt_email === $reg_email) {
            $fail('Your alternative email must be different from your institutional email.', 2);
        }
    }

    /* ---- Step 3 ---- */
    [$reg_details, $detailErrors] = validateParticipantDetails($_POST, $campuses, $programsByCampus);
    foreach ($detailErrors as $message) {
        $fail($message, 3);
    }

    /* ---- Step 4 ---- */
    if ($password === '' || $confirm === '') {
        $fail('Please create a password.', 4);
    } elseif (strlen($password) < 8) {
        $fail('Password must be at least 8 characters long.', 4);
    } elseif ($password !== $confirm) {
        $fail('Passwords do not match.', 4);
    }
    if (!$agree_terms) {
        $fail('You must agree to the Terms and Conditions.', 4);
    }

    if ($reg_errors) {
        $reg_step = $firstErrorStep;
    }

    /* ---- Save ---- */
    if (!$reg_errors) {
        $check = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $check->execute([$reg_email]);

        if ($check->fetch()) {
            $reg_errors[] = 'This institutional email address is already registered.';
            $reg_step = 2;
        } else {
            $columns = [
                'first_name'      => $reg_first_name,
                'middle_name'     => $reg_middle_name !== '' ? $reg_middle_name : null,
                'last_name'       => $reg_last_name,
                'suffix'          => $reg_suffix !== '' ? $reg_suffix : null,
                'email'           => $reg_email,
                'alternate_email' => $reg_alt_email !== '' ? $reg_alt_email : null,
                'sex'             => $reg_sex,
                'birthdate'       => $dobForDb,
                'password_hash'   => password_hash($password, PASSWORD_DEFAULT),
                'user_role'       => 'applicant',
                'status'          => 'active',
            ] + participantDetailColumns($reg_details);

            try {
                $names = array_keys($columns);
                $sql = 'INSERT INTO users (' . implode(', ', $names) . ') VALUES (:' . implode(', :', $names) . ')';
                $pdo->prepare($sql)->execute($columns);

                $reg_success = true;
                header('refresh:2;url=user_login.php');
            } catch (PDOException $ex) {
                error_log('register.php insert failed: ' . $ex->getMessage());
                $reg_errors[] = 'Registration failed. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — PSU Internationalization</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ===== CSS DESIGN TOKENS ===== */
        :root {
            --psu-navy: #0b1a30;
            --psu-blue: #1c3d6e;
            --psu-gold: #e4b134;
            --psu-gold-light: #f4c856;
            
            --bg-main: #eef2f7; /* Deepened background */
            --white: #ffffff;
            --text-main: #0f172a;
            --text-muted: #475569;
            --text-light: #94a3b8;
            
            --border-light: #e2e8f0;
            
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 20px;
            --radius-full: 9999px;
            
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-card: 0 20px 40px -10px rgba(11, 26, 48, 0.1), 0 0 0 1px rgba(11, 26, 48, 0.02);
            
            --font-heading: 'Plus Jakarta Sans', sans-serif;
            --font-body: 'Inter', sans-serif;
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-body);
            background-color: var(--bg-main);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
            position: relative;
            overflow-x: hidden;
        }

        /* Background Map Pattern */
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url('data:image/svg+xml;utf8,<svg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><path d="M10 10h80v80H10z" fill="none" stroke="%23cbd5e1" stroke-width="0.5" opacity="0.3"/></svg>');
            background-size: 800px;
            background-position: center;
            opacity: 0.8;
            z-index: -1;
        }

        /* Decorative Bottom Waves */
        body::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 25vh;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%230b1a30" fill-opacity="1" d="M0,224L48,213.3C96,203,192,181,288,181.3C384,181,480,203,576,224C672,245,768,267,864,250.7C960,235,1056,181,1152,165.3C1248,149,1344,171,1392,181.3L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path><path fill="%23e4b134" fill-opacity="1" d="M0,288L48,272C96,256,192,224,288,213.3C384,203,480,213,576,229.3C672,245,768,267,864,261.3C960,256,1056,224,1152,208C1248,192,1344,192,1392,192L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>');
            background-size: cover;
            background-repeat: no-repeat;
            background-position: bottom center;
            z-index: -1;
        }

        .page {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* ===== LEFT SIDE: HERO ===== */
        .hero {
            flex: 1;
            padding: 60px 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            z-index: 1;
        }

        .hero-top {
            position: absolute;
            top: 60px;
            left: 80px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 16px;
            text-decoration: none;
            font-family: var(--font-heading);
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--psu-navy);
        }

        .brand .badge {
            background: var(--psu-navy);
            color: var(--white);
            font-size: 1rem;
            font-weight: 800;
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            letter-spacing: 0.5px;
        }

        .brand .divider-line { 
            width: 2px; 
            height: 24px; 
            background: var(--psu-gold); 
            border-radius: 2px;
        }

        .hero-body {
            max-width: 600px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: var(--white);
            border: 1px solid rgba(228, 177, 52, 0.4);
            padding: 8px 24px;
            border-radius: var(--radius-full);
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--psu-navy);
            box-shadow: var(--shadow-sm);
            margin-bottom: 32px;
        }

        .eyebrow i {
            width: 20px;
            height: 20px;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%23e4b134" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>') no-repeat center;
            display: inline-block;
        }

        .hero h2 {
            font-family: var(--font-heading);
            font-size: 4rem;
            line-height: 1.1;
            font-weight: 800;
            color: var(--psu-navy);
            letter-spacing: -1.5px;
            margin-bottom: 24px;
        }

        .hero h2 em {
            color: var(--psu-gold);
            font-style: normal;
        }

        .hero .lead {
            font-size: 1.15rem;
            color: var(--text-muted);
            line-height: 1.7;
            margin-bottom: 64px;
            max-width: 500px;
        }

        .features {
            list-style: none;
            display: flex;
            gap: 40px;
            padding-top: 40px;
            border-top: 1px solid rgba(11, 26, 48, 0.1);
        }

        .features li {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 12px;
            flex: 1;
            position: relative;
        }

        .features li:not(:last-child)::after {
            content: '';
            position: absolute;
            right: -20px;
            top: 20%;
            height: 60%;
            width: 1px;
            background: rgba(11, 26, 48, 0.1);
        }

        .features .ico {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--psu-navy);
        }

        .features strong {
            font-family: var(--font-heading);
            font-size: 1rem;
            color: var(--psu-navy);
            font-weight: 700;
        }

        .features small {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .hero-foot {
            position: absolute;
            bottom: 40px;
            left: 80px;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* ===== RIGHT SIDE: FORM CARD ===== */
        .form-side {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 80px 40px 20px;
            position: relative;
            z-index: 2;
        }

        .register-card {
            background: var(--white);
            width: 100%;
            max-width: 580px;
            border-radius: var(--radius-lg);
            padding: 48px 56px;
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(255, 255, 255, 0.5);
            position: relative;
            margin-top: 40px;
            margin-bottom: 40px;
        }

        .register-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .user-plus-icon {
            width: 56px;
            height: 56px;
            background: var(--bg-main);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            position: relative;
        }
        
        .user-plus-icon svg {
            color: var(--psu-navy);
        }

        .user-plus-icon::after {
            content: '+';
            position: absolute;
            right: -2px;
            bottom: -2px;
            background: var(--psu-gold);
            color: var(--white);
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            border: 2px solid var(--white);
        }

        .register-header h1 {
            font-family: var(--font-heading);
            font-size: 2rem;
            color: var(--psu-navy);
            font-weight: 800;
            margin-bottom: 8px;
        }

        .register-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Stepper */
        .stepper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 36px;
            padding: 0 10px;
        }

        .stepper .st {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 2;
        }

        .stepper .dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--white);
            border: 2px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-light);
            transition: var(--transition);
        }

        .stepper .lbl {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-light);
            transition: var(--transition);
        }

        .stepper .st.active .dot {
            background: var(--psu-navy);
            border-color: var(--psu-navy);
            color: var(--white);
            box-shadow: 0 4px 10px rgba(11, 26, 48, 0.2);
        }

        .stepper .st.active .lbl { color: var(--psu-navy); }

        .stepper .st.done .dot {
            background: var(--psu-gold);
            border-color: var(--psu-gold);
            color: var(--psu-navy);
        }
        
        .stepper .st.done .lbl { color: var(--psu-navy); }

        .stepper .line {
            flex: 1;
            height: 2px;
            background: var(--border-light);
            margin: 0 8px;
            margin-bottom: 24px;
            border-radius: 2px;
            position: relative;
        }

        .stepper .line i {
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 0;
            background: var(--psu-gold);
            border-radius: 2px;
            transition: var(--transition);
        }

        .stepper .line.fill i { width: 100%; }

        /* Alerts */
        .alert-error, .alert-success {
            padding: 16px 20px;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        /* Forms */
        .form-step { display: none; animation: fadeIn 0.4s ease; }
        .form-step.active { display: block; }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-title {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .step-title .ic {
            width: 44px;
            height: 44px;
            background: var(--bg-main);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--psu-navy);
        }

        .step-title h3 {
            font-family: var(--font-heading);
            font-size: 1.1rem;
            color: var(--psu-navy);
            font-weight: 700;
        }

        .step-title p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 16px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--psu-navy);
        }

        .form-label .opt {
            color: var(--text-light);
            font-weight: 400;
            font-size: 0.8rem;
        }

        .form-hint {
            font-size: 0.78rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        select.form-input:disabled {
            background-color: var(--bg-main);
            color: var(--text-light);
            cursor: not-allowed;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .lead-ic {
            position: absolute;
            left: 16px;
            width: 18px;
            height: 18px;
            color: var(--text-light);
            pointer-events: none;
            z-index: 1;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            font-family: var(--font-body);
            font-size: 0.9rem;
            color: var(--psu-navy);
            background: var(--white);
            transition: var(--transition);
            outline: none;
        }

        .form-input::placeholder { color: var(--text-light); }

        .form-input:focus {
            border-color: var(--psu-navy);
            box-shadow: 0 0 0 3px rgba(11, 26, 48, 0.08);
        }

        .input-wrap .form-input { padding-left: 44px; }
        .form-input.no-ic { padding-left: 16px; }

        select.form-input {
            appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%230b1a30" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>');
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
            cursor: pointer;
        }

        .radio-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .radio-pill {
            flex: 1;
            cursor: pointer;
            position: relative;
        }

        .radio-pill input {
            position: absolute;
            opacity: 0;
            width: 0; height: 0;
        }

        .radio-pill span {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-muted);
            transition: var(--transition);
        }

        .radio-pill input:checked + span {
            background: rgba(28, 61, 110, 0.04);
            border-color: var(--psu-navy);
            color: var(--psu-navy);
            font-weight: 600;
        }

        .toggle-pw {
            position: absolute;
            right: 16px;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-light);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .toggle-pw:hover { color: var(--psu-navy); }

        /* Password Strength */
        .strength {
            display: flex;
            gap: 4px;
            margin-top: 4px;
        }

        .strength i {
            flex: 1;
            height: 4px;
            background: var(--border-light);
            border-radius: 2px;
            transition: var(--transition);
        }

        .strength-text {
            font-size: 0.75rem;
            color: var(--text-light);
            font-weight: 600;
            margin-top: 2px;
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 20px 0 24px;
            cursor: pointer;
        }

        .checkbox-group input {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: var(--psu-navy);
            cursor: pointer;
        }

        .checkbox-group span {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .checkbox-group a {
            color: var(--psu-navy);
            font-weight: 600;
            text-decoration: underline;
        }

        /* Actions */
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 24px;
            gap: 16px;
        }

        .btn-primary, .btn-secondary {
            padding: 14px 24px;
            border-radius: var(--radius-sm);
            font-family: var(--font-body);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--psu-navy);
            color: var(--white);
            flex: 1;
        }

        .btn-primary:hover {
            background: var(--psu-blue);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text-muted);
            border: 1px solid var(--border-light);
        }

        .btn-secondary:hover {
            background: var(--bg-main);
            color: var(--psu-navy);
        }

        .login-link {
            text-align: center;
            margin-top: 32px;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .login-link a {
            color: var(--psu-gold);
            font-weight: 700;
            text-decoration: none;
            margin-left: 4px;
        }

        .login-link a:hover { text-decoration: underline; }

        /* Responsive */
        @media (max-width: 1100px) {
            .hero { padding: 60px 40px; }
            .hero h2 { font-size: 3rem; }
            .form-side { padding: 40px; }
            .register-card { padding: 36px 40px; }
        }

        @media (max-width: 900px) {
            .page { flex-direction: column; }
            .hero { padding: 100px 24px 40px; align-items: center; text-align: center; }
            .hero-top { top: 30px; left: 50%; transform: translateX(-50%); width: 100%; display: flex; justify-content: center; }
            .hero .lead { margin: 0 auto 40px; }
            .features { display: none; }
            .hero-foot { position: relative; bottom: 0; left: 0; margin-top: 40px; }
            .form-side { padding: 0 24px 40px; }
            .register-card { margin-top: 0; }
        }

        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
            .register-card { padding: 24px; }
            .stepper .lbl { display: none; }
            .hero h2 { font-size: 2.5rem; }
        }
    </style>
</head>
<body>

<div class="page">
    <!-- LEFT HERO SECTION -->
    <aside class="hero">
        <div class="hero-top">
            <a href="website.php" class="brand">
                <span class="badge">PSU</span> 
                <div class="divider-line"></div>
                PSU Internationalization
            </a>
        </div>

        <div class="hero-body">
            <div class="eyebrow"><i></i> Student, Faculty &amp; Staff Mobility Portal</div>
            <h2>Your journey to the <em>world</em> starts here.</h2>
            <p class="lead">Create your account to explore international exchange, study abroad, and global learning programs offered by Pangasinan State University.</p>

            <ul class="features">
                <li>
                    <div class="ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></div>
                    <div><strong>Explore<br>Programs</strong></div>
                </li>
                <li>
                    <div class="ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                    <div><strong>Build<br>Global Connections</strong></div>
                </li>
                <li>
                    <div class="ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg></div>
                    <div><strong>Broaden<br>Your Horizons</strong></div>
                </li>
            </ul>
        </div>

        <div class="hero-foot">&copy; <?= date('Y') ?> Pangasinan State University &middot; Internationalization Office</div>
    </aside>

    <!-- RIGHT FORM CARD -->
    <main class="form-side">
        <div class="register-card">
            <div class="register-header">
                <div class="user-plus-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                </div>
                <h1>Create Account</h1>
                <p>Join PSU Internationalization to apply for global programs</p>
            </div>

            <!-- Stepper -->
            <div class="stepper" id="stepper">
                <div class="st" data-s="1"><div class="dot"><span>1</span></div><div class="lbl">Personal</div></div>
                <div class="line"><i></i></div>
                <div class="st" data-s="2"><div class="dot"><span>2</span></div><div class="lbl">Account</div></div>
                <div class="line"><i></i></div>
                <div class="st" data-s="3"><div class="dot"><span>3</span></div><div class="lbl">PSU Details</div></div>
                <div class="line"><i></i></div>
                <div class="st" data-s="4"><div class="dot"><span>4</span></div><div class="lbl">Security</div></div>
            </div>

            <?php if (!empty($reg_errors)): ?>
                <div class="alert-error">
                    <div>
                        <?php foreach ($reg_errors as $error): ?>
                            &bull; <?= e($error) ?><br>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($reg_success): ?>
                <div class="alert-success">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    Registration successful! Redirecting...
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" id="registerForm" novalidate>

                <!-- STEP 1: PERSONAL INFO -->
                <div class="form-step <?= ($reg_step === 1) ? 'active' : '' ?>" id="step-1">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <div class="input-wrap">
                                <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <input class="form-input" type="text" name="first_name" placeholder="Enter your first name" required value="<?= e($reg_first_name) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <div class="input-wrap">
                                <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <input class="form-input" type="text" name="last_name" placeholder="Enter your last name" required value="<?= e($reg_last_name) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Middle Name <span class="opt">(Optional)</span></label>
                            <input class="form-input no-ic" type="text" name="middle_name" maxlength="100" placeholder="Enter your middle name" value="<?= e($reg_middle_name) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Suffix <span class="opt">(Optional)</span></label>
                            <input class="form-input no-ic" type="text" name="suffix" maxlength="20" placeholder="e.g. Jr., III" value="<?= e($reg_suffix) ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Sex *</label>
                            <div class="input-wrap">
                                <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="10" r="4"/><path d="M12 14v7M8 18h8M19 5l-3 3M17 3l2 2"/></svg>
                                <select class="form-input" name="sex" required>
                                    <option value="">Select sex</option>
                                    <option value="Male" <?= $reg_sex === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= $reg_sex === 'Female' ? 'selected' : '' ?>>Female</option>
                                    <option value="Prefer not to say" <?= $reg_sex === 'Prefer not to say' ? 'selected' : '' ?>>Prefer not to say</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Date of Birth *</label>
                            <div class="input-wrap">
                                <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                <input class="form-input" type="date" name="date_of_birth" required value="<?= e($reg_dob) ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-actions" style="justify-content: flex-end;">
                        <button type="button" class="btn-primary" onclick="nextStep(2, 1)">Next &rarr;</button>
                    </div>
                </div>

                <!-- STEP 2: ACCOUNT INFO -->
                <div class="form-step <?= ($reg_step === 2) ? 'active' : '' ?>" id="step-2">
                    <div class="form-group">
                        <label class="form-label" for="reg_email">PSU Institutional Email *</label>
                        <div class="input-wrap">
                            <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>
                            <input class="form-input" type="email" name="email" id="reg_email" placeholder="juan.delacruz@psu.edu.ph" required autocomplete="email" maxlength="150" value="<?= e($reg_email) ?>">
                        </div>
                        <small class="form-hint">Your primary email for signing in and receiving updates. Must end in <?= e(institutionalEmailHint()) ?>.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="reg_alt_email">Alternative Personal Email <span class="opt">(Optional)</span></label>
                        <div class="input-wrap">
                            <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>
                            <input class="form-input" type="email" name="alternate_email" id="reg_alt_email" placeholder="you@gmail.com" maxlength="150" value="<?= e($reg_alt_email) ?>">
                        </div>
                        <small class="form-hint">A backup contact if your school email becomes unavailable.</small>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="nextStep(1)">&larr; Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(3, 2)">Next &rarr;</button>
                    </div>
                </div>

                <!-- STEP 3: PSU AFFILIATION -->
                <?php
                    $acct = $reg_details['account_type'];
                    $selCampus = (int)$reg_details['campus_id'];
                    $campusProgramList = $programsByCampus[$selCampus] ?? [];
                ?>
                <div class="form-step <?= ($reg_step === 3) ? 'active' : '' ?>" id="step-3">
                    <div class="form-group">
                        <span class="form-label" id="classification-label">I am a PSU... *</span>
                        <div class="radio-group" role="radiogroup" aria-labelledby="classification-label">
                            <?php foreach (PARTICIPANT_CLASSIFICATIONS as $value => $label): ?>
                                <label class="radio-pill"><input type="radio" name="account_type" value="<?= e($value) ?>" <?= $acct === $value ? 'checked' : '' ?> required><span><?= e($label) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="campus_id">Campus *</label>
                        <div class="input-wrap">
                            <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V10l7-5 7 5v11M9 21v-6h6v6"/></svg>
                            <select class="form-input" name="campus_id" id="campus_id" required>
                                <option value="">Select your campus</option>
                                <?php foreach ($campuses as $id => $name): ?>
                                    <option value="<?= (int)$id ?>" <?= $selCampus === $id ? 'selected' : '' ?>><?= e($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="program">
                            <span id="program-label-text"><?= $acct === 'student' ? 'Degree Program' : 'Department / Program' ?></span>
                            <span id="program-req"><?= $acct === 'non-teaching' ? '<span class="opt">(Optional)</span>' : '*' ?></span>
                        </label>
                        <select class="form-input no-ic" name="program" id="program" <?= $selCampus ? '' : 'disabled' ?> <?= $acct === 'non-teaching' ? '' : 'required' ?>>
                            <?php if (!$selCampus): ?>
                                <option value="">Select a campus first</option>
                            <?php else: ?>
                                <option value=""><?= $campusProgramList ? 'Select' : 'No programs listed for this campus' ?></option>
                                <?php foreach ($campusProgramList as $prog): ?>
                                    <option value="<?= e($prog) ?>" <?= $reg_details['program'] === $prog ? 'selected' : '' ?>><?= e($prog) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- STUDENT FIELDS -->
                    <div class="type-fields" data-type="student" <?= $acct === 'student' ? '' : 'hidden' ?>>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="student_number">Student Number *</label>
                                <input class="form-input no-ic" type="text" name="student_number" id="student_number" maxlength="30" placeholder="e.g. 21-UR-0123" value="<?= e($reg_details['student_number']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="year_level">Year Level *</label>
                                <select class="form-input no-ic" name="year_level" id="year_level">
                                    <option value="">Select</option>
                                    <?php foreach (PARTICIPANT_YEAR_LEVELS as $yl): ?>
                                        <option value="<?= e($yl) ?>" <?= $reg_details['year_level'] === $yl ? 'selected' : '' ?>><?= e($yl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- FACULTY FIELDS -->
                    <div class="type-fields" data-type="faculty" <?= $acct === 'faculty' ? '' : 'hidden' ?>>
                        <div class="form-group">
                            <label class="form-label" for="faculty_rank">Academic Rank *</label>
                            <select class="form-input no-ic" name="faculty_rank" id="faculty_rank">
                                <option value="">Select</option>
                                <?php foreach (PARTICIPANT_FACULTY_RANKS as $rank): ?>
                                    <option value="<?= e($rank) ?>" <?= ($acct === 'faculty' && $reg_details['position_rank'] === $rank) ? 'selected' : '' ?>><?= e($rank) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- NON-TEACHING FIELDS -->
                    <div class="type-fields" data-type="non-teaching" <?= $acct === 'non-teaching' ? '' : 'hidden' ?>>
                        <div class="form-group">
                            <label class="form-label" for="position">Position / Designation *</label>
                            <input class="form-input no-ic" type="text" name="position" id="position" maxlength="120" placeholder="e.g. Administrative Officer II" value="<?= e($acct === 'non-teaching' ? $reg_details['position_rank'] : '') ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="nextStep(2)">&larr; Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(4, 3)">Next &rarr;</button>
                    </div>
                </div>

                <!-- STEP 4: SECURITY -->
                <div class="form-step <?= ($reg_step === 4) ? 'active' : '' ?>" id="step-4">
                    <div class="form-group">
                        <label class="form-label">Password *</label>
                        <div class="input-wrap">
                            <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input class="form-input" type="password" name="password" id="pw" placeholder="Create a password" required autocomplete="new-password" style="padding-right:46px;">
                            <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Show or hide password">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <div class="strength" id="strength"><i></i><i></i><i></i><i></i></div>
                        <div class="strength-text" id="strengthText"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirm Password *</label>
                        <div class="input-wrap">
                            <svg class="lead-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input class="form-input" type="password" name="confirm_password" id="pw2" placeholder="Confirm password" required autocomplete="new-password" style="padding-right:46px;">
                             <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Show or hide password">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <label class="checkbox-group">
                        <input type="checkbox" name="agree_terms" required>
                        <span>I agree to the <a href="#">Terms and Conditions</a> and <a href="#">Privacy Policy</a>.</span>
                    </label>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="nextStep(3)">&larr; Back</button>
                        <button type="submit" class="btn-primary" onclick="return validateFinal()">Register Account &rarr;</button>
                    </div>
                </div>

            </form>

            <div class="login-link">
                Already have an account? <a href="user_login.php">Log in</a>
            </div>

        </div>
    </main>
</div>

<script>
    const stepNodes = document.querySelectorAll('#stepper .st');
    const lineNodes = document.querySelectorAll('#stepper .line');

    function updateStepper(step) {
        stepNodes.forEach(node => {
            const s = Number(node.dataset.s);
            node.classList.toggle('active', s === step);
            node.classList.toggle('done', s < step);
        });
        lineNodes.forEach((line, i) => line.classList.toggle('fill', i + 1 < step));
    }

    function validateStep(n) {
        const inputs = document.getElementById('step-' + n).querySelectorAll('input[required], select[required]');
        for (const input of inputs) {
            if (!input.checkValidity()) { input.reportValidity(); return false; }
        }
        return true;
    }

    function nextStep(step, validateFrom = null) {
        if (validateFrom !== null && !validateStep(validateFrom)) return;
        document.querySelectorAll('.form-step').forEach(el => el.classList.remove('active'));
        document.getElementById('step-' + step).classList.add('active');
        updateStepper(step);
    }

    function validateFinal() {
        for (let n = 1; n <= 4; n++) {
            if (!validateStep(n)) { nextStep(n); validateStep(n); return false; }
        }
        return true;
    }

    function togglePw() {
        const pw = document.getElementById('pw');
        const pw2 = document.getElementById('pw2');
        const type = pw.type === 'password' ? 'text' : 'password';
        pw.type = type; pw2.type = type;
    }

    document.getElementById('pw').addEventListener('input', function () {
        const v = this.value;
        let score = 0;
        if (v.length >= 8) score++;
        if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        const colors = ['#ef4444', '#f59e0b', '#84cc16', '#10b981'];
        const labels = ['Weak', 'Fair', 'Good', 'Strong'];
        document.querySelectorAll('#strength i').forEach((bar, i) => {
            bar.style.background = (v && i < score) ? colors[score - 1] : '';
        });
        document.getElementById('strengthText').textContent = v ? labels[Math.max(score - 1, 0)] : '';
    });

    document.addEventListener('DOMContentLoaded', () => updateStepper(<?= (int)$reg_step ?>));
    
    /* ---------- PSU affiliation: classification + campus → program ---------- */
    const PROGRAMS_BY_CAMPUS = <?= json_encode((object)$programsByCampus, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const PSU_DOMAINS = <?= json_encode(PSU_EMAIL_DOMAINS) ?>;
    const TYPE_REQUIRED = {
        'student':      ['student_number', 'year_level'],
        'faculty':      ['faculty_rank'],
        'non-teaching': ['position'],
    };

    const campusSelect  = document.getElementById('campus_id');
    const programSelect = document.getElementById('program');

    function selectedType() {
        const checked = document.querySelector('input[name="account_type"]:checked');
        return checked ? checked.value : 'student';
    }

    function applyAccountType() {
        const type = selectedType();
        document.querySelectorAll('.type-fields').forEach(block => {
            const active = block.dataset.type === type;
            block.hidden = !active;
            block.querySelectorAll('input, select').forEach(el => {
                el.disabled = !active;   // inactive fields are not submitted
                el.required = active && TYPE_REQUIRED[type].includes(el.id);
            });
        });
        const optional = type === 'non-teaching';
        programSelect.required = !optional && !programSelect.disabled;
        document.getElementById('program-label-text').textContent = type === 'student' ? 'Degree Program' : 'Department / Program';
        document.getElementById('program-req').innerHTML = optional ? '<span class="opt">(Optional)</span>' : '*';
    }

    function populatePrograms() {
        const previous = programSelect.value;
        const list = PROGRAMS_BY_CAMPUS[campusSelect.value] || [];
        programSelect.innerHTML = '';
        const placeholder = new Option(
            !campusSelect.value ? 'Select a campus first' : (list.length ? 'Select' : 'No programs listed for this campus'), '');
        programSelect.add(placeholder);
        list.forEach(name => programSelect.add(new Option(name, name, false, name === previous)));
        programSelect.disabled = !campusSelect.value;
        applyAccountType();
    }

    campusSelect.addEventListener('change', populatePrograms);
    document.querySelectorAll('input[name="account_type"]').forEach(r => r.addEventListener('change', applyAccountType));
    applyAccountType();

    /* ---------- Email checks (server re-validates) ---------- */
    const emailInput = document.getElementById('reg_email');
    const altInput   = document.getElementById('reg_alt_email');

    function isInstitutional(email) {
        const domain = (email.split('@')[1] || '').toLowerCase();
        return PSU_DOMAINS.some(d => domain === d || domain.endsWith('.' + d));
    }

    function checkEmails() {
        const primary = emailInput.value.trim();
        emailInput.setCustomValidity(primary && !isInstitutional(primary)
            ? 'Use your PSU institutional email (@' + PSU_DOMAINS.join(' or @') + ').' : '');
        const alt = altInput.value.trim();
        altInput.setCustomValidity(alt && alt.toLowerCase() === primary.toLowerCase()
            ? 'Your alternative email must be different from your institutional email.' : '');
    }
    emailInput.addEventListener('input', checkEmails);
    altInput.addEventListener('input', checkEmails);
    checkEmails();

    /* ---------- Password length ---------- */
    const pwInput = document.getElementById('pw');
    pwInput.minLength = 8;
</script>

</body>
</html>