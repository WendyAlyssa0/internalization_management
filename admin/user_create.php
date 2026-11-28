<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['username'])) {
    header('Location: ../public/login.php');
    exit();
}

$pdo = db();

// ROLE GATE
$currentRole = strtolower(trim($_SESSION['user_role'] ?? ''));

if ($currentRole === 'applicant' || !in_array($currentRole, ['admin', 'super admin'], true)) {
    $_SESSION['error'] = 'You are not allowed to create users.';
    header('Location: dashboard.php?page=users');
    exit();
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function generatePassword(): string {
    return 'PASSWORD';
}

function generateEmail(string $firstName, string $lastName, string $role): string {

    $prefix = match ($role) {
        'super admin' => 'SA',
        'admin' => 'AD',
        'applicant' => 'AP',
        default => 'US',
    };

    $first = strtolower(substr($firstName, 0, 1));
    $last = strtolower(str_replace(' ', '', $lastName));

    return $prefix . $first . $last . '@psuxizn.com';
}

$errors = [];
$success = false;

$email = '';
$password = generatePassword();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_create'])) {

    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $extensionName = trim($_POST['extension_name'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $program = trim($_POST['program'] ?? '');

    // VALIDATION
    if ($firstName === '' || $lastName === '' || $role === '') {
        $errors[] = 'Please fill in all required fields.';
    }

    if ($role === 'applicant' && $program === '') {
        $errors[] = 'Program is required for applicants.';
    }

    if (!$errors) {

        $email = generateEmail($firstName, $lastName, $role);

        // CHECK EMAIL EXISTENCE
        $check = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $check->execute([':email' => $email]);

        if ($check->fetch()) {
            $errors[] = 'Email already exists.';
        } else {

            try {

                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        first_name,
                        middle_name,
                        last_name,
                        extension_name,
                        birthdate,
                        program,
                        email,
                        user_role,
                        status,
                        password_hash
                    )
                    VALUES (
                        :first_name,
                        :middle_name,
                        :last_name,
                        :extension_name,
                        :birthdate,
                        :program,
                        :email,
                        :user_role,
                        :status,
                        :password_hash
                    )
                ");

                $result = $stmt->execute([
                    ':first_name' => $firstName,
                    ':middle_name' => $middleName ?: null,
                    ':last_name' => $lastName,
                    ':extension_name' => $extensionName ?: null,
                    ':birthdate' => $birthdate ?: null,
                    ':program' => $program ?: null,
                    ':email' => $email,
                    ':user_role' => strtolower($role),
                    ':status' => 'Active',
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);

                if ($result) {
                    $success = true;
                    header("refresh:2;url=dashboard.php?page=users");
                } else {
                    $errors[] = 'Insert failed.';
                }

            } catch (PDOException $e) {
                $errors[] = 'Database error.';
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
    <title>Create User</title>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../asset/css/user_create.css">
    <link rel="stylesheet" href="../asset/css/admin_fonts.css">
</head>
<body>

<div class="page">
    <div class="card">

        <div class="card-header">
            <div class="card-header-titles">
                <h2>Create User</h2>
                <p>Provision and configure a new system or applicant account.</p>
            </div>
            <a href="dashboard.php?page=users" class="back-btn">
                <i class="fa-solid fa-arrow-left"></i> Back to Users
            </a>
        </div>

        <div class="card-body">

            <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <?php foreach ($errors as $error): ?>
                        <div><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-box">
                    <div><strong><i class="fa-solid fa-circle-check"></i> Account created successfully!</strong></div>
                    <div style="margin-top: 0.5rem;"><strong>Email Address:</strong> <code><?= e($email) ?></code></div>
                    <div><strong>Temporary Password:</strong> <code><?= e($password) ?></code></div>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="user_create" value="1">

                <div class="form-section-title">Personal Information</div>
                
                <div class="form-grid">
                    <div class="form-group col-5">
                        <label>First Name<span class="required">*</span></label>
                        <input type="text" name="first_name" autocomplete="off" placeholder="e.g., Sherwin" required>
                    </div>

                    <div class="form-group col-2">
                        <label>M.I.</label>
                        <input type="text" name="middle_name" maxlength="5" placeholder="e.g., J">
                    </div>

                    <div class="form-group col-5">
                        <label>Last Name<span class="required">*</span></label>
                        <input type="text" name="last_name" autocomplete="off" placeholder="e.g., Dela Cruz" required>
                    </div>

                    <div class="form-group col-3">
                        <label>Extension</label>
                        <select name="extension_name">
                            <option value="">None</option>
                            <option>Jr.</option>
                            <option>Sr.</option>
                            <option>I</option>
                            <option>II</option>
                            <option>III</option>
                            <option>IV</option>
                        </select>
                    </div>

                    <div class="form-group col-4">
                        <label>Birthdate</label>
                        <input type="date" name="birthdate">
                    </div>

                    <div class="form-group col-5">
                        <label>System Role<span class="required">*</span></label>
                        <select name="role" required>
                            <option value="">Select Role</option>
                            <option value="super admin">Super Admin</option>
                            <option value="admin">Admin</option>
                            <option value="applicant">Applicant</option>
                        </select>
                    </div>

                    <div class="form-group col-12" id="programField" style="display: none;">
                        <label>Assigned Program / Course Target<span class="required">*</span></label>
                        <select type="text" name="program">
                                <option value ="BSIT">Bachelor of Science in information Technology</option>
                                <option value ="BSBA">Bachelor of Science in Business </option>
                                <option value ="BSED">Bachelor of Science in Education</option>
                    
                        </select>
                    </div>
                </div>

                <div class="form-section-title">System Credentials</div>
                
                <div class="form-grid">
                    <div class="form-group col-6">
                        <label>Generated Primary Email</label>
                        <input type="text" name="email" readonly placeholder="Generates systematically...">
                    </div>

                    <div class="form-group col-6">
                        <label>Default Core Password</label>
                        <input type="text" value="<?= e($password) ?>" readonly>
                    </div>
                    
                    <div class="form-group col-12">
                        <p class="form-instructions">Required items are marked explicitly with (<span class="required">*</span>).</p>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="dashboard.php?page=users" class="btn-cancel">Cancel</a>
                    <button class="btn-submit" type="submit">
                        <i class="fa-solid fa-user-plus"></i> Create User Account
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
function generateEmail(){
    const f = document.querySelector('[name="first_name"]').value.trim();
    const l = document.querySelector('[name="last_name"]').value.trim();
    const r = document.querySelector('[name="role"]').value;

    if(!f || !l || !r) return;

    let p = 'US';
    if(r === 'super admin') p = 'SA';
    else if(r === 'admin') p = 'AD';
    else if(r === 'applicant') p = 'AP';

    document.querySelector('[name="email"]').value =
        p + f[0].toLowerCase() + l.toLowerCase().replace(/\s/g,'') + '@psuxizn.com';
}

function toggleProgram(){
    const role = document.querySelector('[name="role"]').value;
    const box = document.getElementById('programField');
    const input = document.querySelector('[name="program"]');
    
    if(role === 'applicant') {
        box.style.display = 'flex';
        input.setAttribute('required', 'required');
    } else {
        box.style.display = 'none';
        input.removeAttribute('required');
    }
}

document.querySelector('[name="first_name"]').oninput = generateEmail;
document.querySelector('[name="last_name"]').oninput = generateEmail;
document.querySelector('[name="role"]').onchange = function(){
    generateEmail();
    toggleProgram();
};

// Standard Initialization Frame
toggleProgram();
</script>

</body>
</html>