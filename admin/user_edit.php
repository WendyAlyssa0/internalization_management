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
$username = $_SESSION['username'];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$currentRole = strtolower(trim($_SESSION['user_role'] ?? ''));

// Hard deny for applicants (no defaulting).
if ($currentRole === 'applicant' || !in_array($currentRole, ['admin', 'super admin'], true)) {
    $_SESSION['error'] = 'You are not allowed to edit users.';
    header('Location: sidebar.php?page=applications');
    exit();
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    echo 'Invalid user ID.';
    exit();
}

$errors = [];
$success = false;

$userStmt = $pdo->prepare("
    SELECT id, first_name, last_name, extension_name, email, user_role, status, created_at
    FROM users
    WHERE id = :id
    LIMIT 1
");
$userStmt->execute([':id' => $id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo 'User not found.';
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $extensionName = trim($_POST['extension_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $status = strtolower(trim($_POST['status'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '') $errors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (!in_array($role, ['super admin', 'admin', 'applicant'], true)) $errors[] = 'Invalid role.';
    if (!in_array($status, ['active', 'inactive'], true)) $errors[] = 'Invalid status.';
    if ($password !== '' && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    if (empty($errors)) {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
        $check->execute([':email' => $email, ':id' => $id]);

        if ($check->fetch()) {
            $errors[] = 'Email already exists.';
        }
    }

    if (empty($errors)) {
        $params = [
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':extension_name' => $extensionName,
            ':email' => $email,
            ':user_role' => $role,
            ':status' => $status,
            ':id' => $id
        ];

        if ($password !== '') {
            $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);

            $sql = "
                UPDATE users
                SET first_name = :first_name,
                    last_name = :last_name,
                    extension_name = :extension_name,
                    email = :email,
                    user_role = :user_role,
                    status = :status,
                    password_hash = :password_hash
                WHERE id = :id
            ";
        } else {
            $sql = "
                UPDATE users
                SET first_name = :first_name,
                    last_name = :last_name,
                    extension_name = :extension_name,
                    email = :email,
                    user_role = :user_role,
                    status = :status
                WHERE id = :id
            ";
        }

        $update = $pdo->prepare($sql);
        $update->execute($params);

        $success = true;

        $userStmt->execute([':id' => $id]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User Profile | PSU Internationalization</title>
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
                <h2>Edit User Details</h2>
                <p>Modifying directory parameters for Account Identifier Record #<?= e($id) ?>.</p>
            </div>
            <a href="dashboard.php?page=users" class="back-btn">
                <i class="fa-solid fa-arrow-left"></i> Back to Users
            </a>
        </div>

        <div class="card-body">

            <?php if ($success): ?>
                <div class="success-box">
                    <div><strong><i class="fa-solid fa-circle-check"></i> Changes synchronized successfully!</strong></div>
                    <div style="font-size: 0.825rem; opacity: 0.9;">The records across database pipelines have updated cleanly.</div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <?php foreach ($errors as $error): ?>
                        <div><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                
                <div class="form-section-title">Personal Details</div>
                
                <div class="form-grid">
                    <div class="form-group col-5">
                        <label>First Name<span class="required">*</span></label>
                        <input type="text" name="first_name" required value="<?= e($_POST['first_name'] ?? $user['first_name']) ?>">
                    </div>

                    <div class="form-group col-2">
                        <label>M.I.</label>
                        <input type="text" name="middle_name" placeholder="-" value="<?= e($_POST['middle_name'] ?? '') ?>">
                    </div>

                    <div class="form-group col-5">
                        <label>Last Name<span class="required">*</span></label>
                        <input type="text" name="last_name" required value="<?= e($_POST['last_name'] ?? $user['last_name']) ?>">
                    </div>

                    <div class="form-group col-4">
                        <label>Extension</label>
                        <select name="extension_name">
                            <?php 
                            $extensions = ['', 'Jr.', 'Sr.', 'I', 'II', 'III', 'IV'];
                            $selectedExt = $_POST['extension_name'] ?? $user['extension_name'];
                            foreach($extensions as $ext): 
                            ?>
                                <option value="<?= e($ext) ?>" <?= $selectedExt === $ext ? 'selected' : '' ?>>
                                    <?= $ext === '' ? 'None' : e($ext) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-4">
                        <label>System Role<span class="required">*</span></label>
                        <select name="role" required>
                            <?php
                            $roles = ['super admin', 'admin', 'applicant'];
                            $selectedRole = $_POST['role'] ?? $user['user_role'];

                            foreach ($roles as $roleOption):
                            ?>
                                <option value="<?= e($roleOption) ?>" <?= $selectedRole === $roleOption ? 'selected' : '' ?>>
                                    <?= e(ucwords($roleOption)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-4">
                        <label>Account Status<span class="required">*</span></label>
                        <select name="status" required>
                            <?php
                            $statuses = ['active', 'inactive'];
                            $selectedStatus = strtolower($_POST['status'] ?? $user['status']);

                            foreach ($statuses as $statusOption):
                            ?>
                                <option value="<?= e($statusOption) ?>" <?= $selectedStatus === $statusOption ? 'selected' : '' ?>>
                                    <?= e(ucfirst($statusOption)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-section-title">Security & Credentials</div>
                
                <div class="form-grid">
                    <div class="form-group col-6">
                        <label>Primary Email Address<span class="required">*</span></label>
                        <input type="email" name="email" required value="<?= e($_POST['email'] ?? $user['email']) ?>">
                    </div>

                    <div class="form-group col-6">
                        <label>Update Password</label>
                        <input type="password" name="password" placeholder="Leave completely blank to retain existing">
                    </div>
                    
                    <div class="form-group col-12">
                        <p class="form-instructions">Required parameters are enforced with an explicit red identifier (<span class="required">*</span>).</p>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="dashboard.php?page=users" class="btn-cancel">Cancel changes</a>
                    <button class="btn-submit" type="submit" name="update_user">
                        <i class="fa-solid fa-user-check"></i> Save Sync Matrix
                    </button>
                </div>
            </form>

            <div class="form-instructions" style="margin-top: 1.5rem; text-align: right; font-size: 0.775rem;">
                <i class="fa-regular fa-clock"></i> Node Registration Date: 
                <strong><?= e(!empty($user['created_at']) ? date('F d, Y', strtotime($user['created_at'])) : 'N/A') ?></strong>
            </div>

        </div>
    </div>
</div>

</body>
</html>