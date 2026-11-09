link rel="stylesheet" href="../asset/css/profile.css">
<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/user_login.php');
    exit();
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* =========================
   FETCH USER DATA
========================= */
$stmt = $pdo->prepare("
    SELECT id, first_name, middle_name, last_name, suffix,
           email, student_id_number, employee_id_number, program,
           birthdate, sex, contact, user_role, status, profile_picture, created_at
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found.");
}

$fullName = trim(
    $user['first_name'] . ' ' . 
    ($user['middle_name'] ? substr($user['middle_name'], 0, 1) . '. ' : '') . 
    $user['last_name'] . 
    ($user['suffix'] ? ' ' . $user['suffix'] : '')
);

$role = strtolower($user['user_role']);
$isFaculty = ($role === 'faculty');

$initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));

/* =========================
   HANDLE AJAX UPDATE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'update_profile') {
            $firstName = trim($_POST['first_name'] ?? '');
            $middleName = trim($_POST['middle_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $suffix = trim($_POST['suffix'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $birthdate = $_POST['birthdate'] ?? null;
            $sex = $_POST['sex'] ?? null;

            if ($firstName === '' || $lastName === '') {
                echo json_encode(['success' => false, 'message' => 'First and last name are required.']);
                exit();
            }

            /* Handle Profile Picture Upload */
            $profilePicturePath = $user['profile_picture']; // Keep existing by default
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['profile_picture'];
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($ext, $allowed)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid image type. Use JPG, PNG, or WebP.']);
                    exit();
                }
                if ($file['size'] > 2 * 1024 * 1024) {
                    echo json_encode(['success' => false, 'message' => 'Image too large. Max 2MB.']);
                    exit();
                }

                $uploadDir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                // Delete old image
                if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/../' . $user['profile_picture'])) {
                    @unlink(__DIR__ . '/../' . $user['profile_picture']);
                }

                $filename = "profile_{$userId}_" . time() . "." . $ext;
                $profilePicturePath = "uploads/profiles/" . $filename;

                if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to save image.']);
                    exit();
                }
            }

            $stmt = $pdo->prepare("
                UPDATE users SET
                    first_name = ?, middle_name = ?, last_name = ?, suffix = ?,
                    contact = ?, birthdate = ?, sex = ?, profile_picture = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $firstName, $middleName ?: null, $lastName, $suffix ?: null,
                $contact ?: null, $birthdate ?: null, $sex ?: null,
                $profilePicturePath, $userId
            ]);

            $_SESSION['first_name'] = $firstName;

            echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
            exit();
        }
    } catch (PDOException $e) {
        error_log('Profile update error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
        exit();
    }
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

<div class="profile-page">

    <div class="page-header">
        <h2>My Profile</h2>
        <p>Manage your personal information and account details.</p>
    </div>

    <div class="profile-grid">

        <!-- LEFT: IDENTITY CARD -->
        <aside class="identity-card">
            <div class="avatar-wrap">
                <?php if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/../' . $user['profile_picture'])): ?>
                    <img src="../<?= h($user['profile_picture']) ?>" alt="Profile" class="avatar-image" id="avatarPreview">
                <?php else: ?>
                    <div class="avatar-initials" id="avatarPreview"><?= h($initials) ?></div>
                <?php endif; ?>
                <label for="profile_picture_input" class="avatar-upload-btn" title="Change photo">
                    <i class="ti ti-camera"></i>
                </label>
            </div>

            <h3><?= h($fullName) ?></h3>
            <p class="email"><?= h($user['email']) ?></p>

            <?php
                $roleLabel = 'Student';
                $roleClass = 'student';
                if ($isFaculty) { $roleLabel = 'Faculty'; $roleClass = 'faculty'; }
                elseif (in_array($role, ['admin', 'super admin'])) { $roleLabel = 'Administrator'; $roleClass = 'admin'; }
            ?>
            <span class="role-badge <?= $roleClass ?>">
                <i class="ti ti-shield-check"></i> <?= $roleLabel ?>
            </span>

            <div class="identity-meta">
                <?php if (!empty($user['student_id_number']) || !empty($user['employee_id_number'])): ?>
                    <div class="meta-row">
                        <span>ID Number</span>
                        <span><?= h($user['student_id_number'] ?: $user['employee_id_number']) ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($user['program'])): ?>
                    <div class="meta-row">
                        <span><?= $isFaculty ? 'Department' : 'Program' ?></span>
                        <span><?= h($user['program']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="meta-row">
                    <span>Member Since</span>
                    <span><?= date('M Y', strtotime($user['created_at'])) ?></span>
                </div>

                <div class="meta-row">
                    <span>Status</span>
                    <span style="color: #166534;">
                        <i class="ti ti-circle-check"></i> <?= h(ucfirst($user['status'])) ?>
                    </span>
                </div>
            </div>
        </aside>

        <!-- RIGHT: EDIT FORM -->
        <div class="form-card">
            <form id="profileForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">
                <!-- Hidden file input for avatar -->
                <input type="file" name="profile_picture" id="profile_picture_input" accept="image/jpeg,image/png,image/webp" style="display:none;">

                <h4 class="section-title"><i class="ti ti-user"></i> Personal Information</h4>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" value="<?= h($user['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" value="<?= h($user['last_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" value="<?= h($user['middle_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Suffix</label>
                        <input type="text" name="suffix" value="<?= h($user['suffix']) ?>" placeholder="e.g. Jr., Sr., III">
                    </div>
                    <div class="form-group">
                        <label>Birthdate</label>
                        <input type="date" name="birthdate" value="<?= h($user['birthdate']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Sex</label>
                        <select name="sex">
                            <option value="">Select...</option>
                            <option value="male" <?= $user['sex'] === 'male' ? 'selected' : '' ?>>Male</option>
                            <option value="female" <?= $user['sex'] === 'female' ? 'selected' : '' ?>>Female</option>
                            <option value="prefer_not_to_say" <?= $user['sex'] === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
                        </select>
                    </div>
                </div>

                <h4 class="section-title"><i class="ti ti-address-book"></i> Contact & Academic</h4>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Email Address (Read-only)</label>
                        <input type="email" value="<?= h($user['email']) ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact" value="<?= h($user['contact']) ?>" placeholder="09XXXXXXXXX">
                    </div>
                    <div class="form-group full">
                        <label><?= $isFaculty ? 'Department' : 'Program / Course' ?></label>
                        <input type="text" value="<?= h($user['program']) ?>" disabled>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-save" id="saveBtn">
                        <i class="ti ti-device-floppy"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
    <i class="ti ti-circle-check"></i>
    <span id="toastMessage">Saved.</span>
</div>
    <script src="../asset/js/profile.js"></script>
