<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$pdo = db();

/* =========================
   AUTH CHECK
========================= */
if (!isset($_SESSION['username'])) {
    header('Location: ../public/login.php');
    exit();
}

$currentRole = strtolower(trim($_SESSION['user_role'] ?? ''));
$allowedRoles = ['super admin', 'admin'];

if (!in_array($currentRole, $allowedRoles)) {
    $_SESSION['error'] = 'You are not allowed to access User Management.';
    header('Location: dashboard.php?page=home');
    exit();
}

/* =========================
   CSRF TOKEN
========================= */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!function_exists('h')) {
    function h($str): string {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}

/* Helper: initials from a full name */
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= strtoupper(substr($p, 0, 1));
    }
    return $out ?: '?';
}

/* =========================
   DELETE USER (POST)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        die("Invalid CSRF token");
    }
    $deleteId = (int) $_POST['delete_id'];
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$deleteId]);
    header("Location: dashboard.php?page=users");
    exit();
}

/* =========================
   CREATE USER (POST)
========================= */
$createErrors  = [];
$createSuccess = false;
$createdEmail  = '';
$createdPass   = '';

function generateEmail(string $firstName, string $lastName, string $role): string {
    $prefix = match (strtolower($role)) {
        'super admin' => 'SA',
        'admin'       => 'AD',
        'applicant'   => 'AP',
        default       => 'US',
    };
    $first = strtolower(substr($firstName, 0, 1));
    $last  = strtolower(str_replace(' ', '', $lastName));
    return $prefix . $first . $last . '@psuxizn.com';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_create'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        die("Invalid CSRF token");
    }

    $firstName     = trim($_POST['first_name']    ?? '');
    $middleName    = trim($_POST['middle_name']    ?? '');
    $lastName      = trim($_POST['last_name']      ?? '');
    $extensionName = trim($_POST['extension_name'] ?? '');
    $birthdate     = trim($_POST['birthdate']      ?? '');
    $role          = strtolower(trim($_POST['role'] ?? ''));
    $program       = trim($_POST['program']        ?? '');
    $contact       = trim($_POST['contact']        ?? '');

    if ($firstName === '' || $lastName === '' || $role === '') {
        $createErrors[] = 'Please fill in all required fields.';
    }
    if (!in_array($role, ['super admin', 'admin', 'applicant'], true)) {
        $createErrors[] = 'Invalid role.';
    }
    if ($role === 'applicant' && $program === '') {
        $createErrors[] = 'Program is required for applicants.';
    }

    if (!$createErrors) {
        $createdEmail = generateEmail($firstName, $lastName, $role);
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->execute([$createdEmail]);
        if ($check->fetch()) {
            $createErrors[] = 'Generated email already exists. Try a different name.';
        } else {
            try {
                $createdPass = 'PSU@' . random_int(10000, 99999);

                $pdo->prepare("
                    INSERT INTO users
                        (first_name, middle_name, last_name, extension_name,
                         birthdate, program, email, contact, user_role, status, password_hash, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $firstName,
                    $middleName    ?: null,
                    $lastName,
                    $extensionName ?: null,
                    $birthdate     ?: null,
                    $program       ?: null,
                    $createdEmail,
                    $contact       ?: null,
                    $role,
                    'active',
                    password_hash($createdPass, PASSWORD_DEFAULT),
                    date('Y-m-d H:i:s'),
                ]);
                $createSuccess = true;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
            } catch (PDOException $e) {
                $createErrors[] = 'Database error. Please try again or contact the administrator.';
                error_log('users.php create error: ' . $e->getMessage());
            }
        }
    }
}

/* =========================
   EDIT USER (POST)
========================= */
$editErrors  = [];
$editSuccess = false;
$editUser    = null;
$editId      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_edit'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        die("Invalid CSRF token");
    }

    $editId        = (int) ($_POST['edit_id'] ?? 0);
    $firstName     = trim($_POST['edit_first_name']     ?? '');
    $middleName    = trim($_POST['edit_middle_name']     ?? '');
    $lastName      = trim($_POST['edit_last_name']       ?? '');
    $extensionName = trim($_POST['edit_extension_name']  ?? '');
    $email         = trim($_POST['edit_email']           ?? '');
    $role          = trim($_POST['edit_role']            ?? '');
    $status        = strtolower(trim($_POST['edit_status'] ?? ''));
    $password      = $_POST['edit_password'] ?? '';

    if ($firstName === '') $editErrors[] = 'First name is required.';
    if ($lastName  === '') $editErrors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $editErrors[] = 'Valid email is required.';
    if (!in_array($role, ['super admin', 'admin', 'applicant'], true)) $editErrors[] = 'Invalid role.';
    if (!in_array($status, ['active', 'inactive'], true)) $editErrors[] = 'Invalid status.';
    if ($password !== '' && strlen($password) < 6) $editErrors[] = 'Password must be at least 6 characters.';

    if (empty($editErrors)) {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $check->execute([$email, $editId]);
        if ($check->fetch()) {
            $editErrors[] = 'Email already exists for another account.';
        }
    }

    if (empty($editErrors)) {
        $params = [
            ':first_name'     => $firstName,
            ':last_name'      => $lastName,
            ':extension_name' => $extensionName ?: null,
            ':email'          => $email,
            ':user_role'      => $role,
            ':status'         => $status,
            ':id'             => $editId,
        ];

        if ($password !== '') {
            $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET first_name=:first_name, last_name=:last_name,
                        extension_name=:extension_name, email=:email, user_role=:user_role,
                        status=:status, password_hash=:password_hash WHERE id=:id";
        } else {
            $sql = "UPDATE users SET first_name=:first_name, last_name=:last_name,
                        extension_name=:extension_name, email=:email, user_role=:user_role,
                        status=:status WHERE id=:id";
        }

        $pdo->prepare($sql)->execute($params);
        $editSuccess = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);

} elseif (isset($_GET['edit_id'])) {
    $editId = (int) $_GET['edit_id'];
    $stmt   = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

$reopenCreateModal = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_create']));
$reopenEditModal   = ($editUser !== null);

/* =========================
   PAGINATION
========================= */
$page       = max(1, (int) ($_GET['p'] ?? 1));
$limit      = 10;
$offset     = ($page - 1) * $limit;
$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalPages = max(1, (int)ceil($totalUsers / $limit));

$stmt = $pdo->prepare("SELECT id, first_name, last_name, extension_name, user_role, status, created_at FROM users ORDER BY id DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<link rel="stylesheet" href="../asset/css/users.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <div class="page-header">
        <h2>User Management</h2>
        <p>Manage system accounts and roles</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search users...">
        </div>
        <select class="filter-select" id="roleFilter">
            <option value="">All Roles</option>
            <option value="admin">Admin</option>
            <option value="super admin">Super Admin</option>
            <option value="applicant">Applicant</option>
        </select>
        <button class="create-btn" id="openModalBtn">
            <i class="fa fa-plus"></i> Add Account
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!empty($users)): ?>
                <?php foreach ($users as $u): ?>
                    <?php
                        $fullname = trim(
                            $u['first_name'] . ' ' . $u['last_name'] .
                            (!empty($u['extension_name']) ? ' ' . $u['extension_name'] : '')
                        );
                        $role   = strtolower($u['user_role']);
                        $status = strtolower($u['status']);
                    ?>
                    <tr data-name="<?= h(strtolower($fullname)) ?>"
                        data-role="<?= h($role) ?>">

                        <td>
                            <div class="user-name-cell">
                                <div class="user-avatar"><?= h(initials($fullname)) ?></div>
                                <span><?= h($fullname) ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="badge"><?= h(ucwords($u['user_role'])) ?></span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= h($status) ?>">
                                <?= h(ucfirst($u['status'])) ?>
                            </span>
                        </td>
                        <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                        <td>
                            <div class="actions">
                                <a href="?page=users&edit_id=<?= (int)$u['id'] ?>"
                                   class="action-btn edit-btn"
                                   title="Edit user">
                                    <i class="fa fa-pen"></i>
                                </a>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Delete this user?')">
                                    <input type="hidden" name="delete_id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="csrf"      value="<?= h($_SESSION['csrf']) ?>">
                                    <button type="submit" class="action-btn delete-btn" title="Delete user">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                </svg>
                            </div>
                            <h3>No users found</h3>
                            <p>Click "Add Account" to create your first user.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pagination">
            <span>Showing <?= count($users) ?> of <?= $totalUsers ?> user<?= $totalUsers === 1 ? '' : 's' ?></span>
            <div class="pages">
                <?php
                    $baseQ = $_GET;
                    unset($baseQ['p'], $baseQ['edit_id']);
                ?>
                <?php if ($page > 1): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page - 1]))) ?>">
                        <i class="fa fa-angle-left"></i>
                    </a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-left"></i></button>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $i]))) ?>"
                       class="<?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page + 1]))) ?>">
                        <i class="fa fa-angle-right"></i>
                    </a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-right"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<!-- =====================================================================
     CREATE USER MODAL
====================================================================== -->
<div id="createModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
    <div class="modal-box">

        <div class="modal-header">
            <div>
                <h3 id="createModalTitle">Add Account</h3>
                <p>Create a new admin, super admin, or applicant account.</p>
            </div>
            <button class="modal-close" id="closeCreateModalBtn" aria-label="Close modal">
                <i class="fa fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">

            <?php if (!empty($createErrors)): ?>
                <div class="error-box">
                    <?php foreach ($createErrors as $err): ?>
                        <div><i class="fa-solid fa-circle-exclamation"></i> <?= h($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($createSuccess): ?>
                <div class="success-box">
                    <div><strong><i class="fa-solid fa-circle-check"></i> Account created successfully!</strong></div>
                    <div class="success-detail">
                        Email: <strong><?= h($createdEmail) ?></strong>
                        &nbsp;·&nbsp;
                        Temporary password: <strong><?= h($createdPass) ?></strong>
                    </div>
                    <div class="success-note">
                        Share this password with the user securely — it will not be shown again.
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" id="createUserForm">
                <input type="hidden" name="user_create" value="1">
                <input type="hidden" name="csrf"         value="<?= h($_SESSION['csrf']) ?>">

                <div class="form-section-title">Personal Details</div>
                <div class="form-grid">

                    <div class="form-group col-5">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" required
                               value="<?= h($_POST['first_name'] ?? '') ?>">
                    </div>

                    <div class="form-group col-2">
                        <label>M.I.</label>
                        <input type="text" name="middle_name" maxlength="5" placeholder="-"
                               value="<?= h($_POST['middle_name'] ?? '') ?>">
                    </div>

                    <div class="form-group col-5">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" required
                               value="<?= h($_POST['last_name'] ?? '') ?>">
                    </div>

                    <div class="form-group col-4">
                        <label>Extension</label>
                        <select name="extension_name">
                            <?php
                            $extensions     = ['', 'Jr.', 'Sr.', 'I', 'II', 'III', 'IV'];
                            $selectedCExt   = $_POST['extension_name'] ?? '';
                            foreach ($extensions as $ext):
                            ?>
                                <option value="<?= h($ext) ?>" <?= $selectedCExt === $ext ? 'selected' : '' ?>>
                                    <?= $ext === '' ? 'None' : h($ext) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-4">
                        <label>Birthdate</label>
                        <input type="date" name="birthdate"
                               value="<?= h($_POST['birthdate'] ?? '') ?>">
                    </div>

                    <div class="form-group col-4">
                        <label>System Role <span class="required">*</span></label>
                        <select name="role" id="roleSelect" required>
                            <?php
                            $roles        = ['super admin' => 'Super Admin', 'admin' => 'Admin', 'applicant' => 'Applicant'];
                            $selectedCRole = $_POST['role'] ?? '';
                            foreach ($roles as $val => $label):
                            ?>
                                <option value="<?= h($val) ?>" <?= $selectedCRole === $val ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-6" id="programField">
                        <label>Program <span class="required">*</span></label>
                        <select name="program" id="programSelect">
                            <option value="">Select Program</option>
                            <?php
                            $programs        = ['BSIT' => 'BSIT', 'BSBA' => 'BSBA'];
                            $selectedProgram = $_POST['program'] ?? '';
                            foreach ($programs as $val => $label):
                            ?>
                                <option value="<?= h($val) ?>" <?= $selectedProgram === $val ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-instructions">Required for applicant accounts.</p>
                    </div>

                    <div class="form-group col-6">
                        <label>Contact Number</label>
                        <input type="text" name="contact"
                               value="<?= h($_POST['contact'] ?? '') ?>">
                    </div>

                </div>

                <div class="form-section-title">Account Email</div>
                <div class="form-grid">

                    <div class="form-group col-12">
                        <label>Generated Email Preview</label>
                        <input type="text" id="emailPreview" readonly placeholder="Fill in name and role to preview">
                        <p class="form-instructions">
                            The email is generated automatically from the role and name, and cannot be edited.
                            A temporary password will be created and shown once after the account is added.
                        </p>
                    </div>

                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" id="cancelCreateModalBtn">Cancel</button>
                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-user-plus"></i> Create Account
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>


<!-- =====================================================================
     EDIT USER MODAL
====================================================================== -->
<div id="editModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <div class="modal-box">

        <div class="modal-header">
            <div>
                <h3 id="editModalTitle">Edit User</h3>
                <p>
                    <?php if ($editUser): ?>
                        Modifying account #<?= h($editUser['id']) ?> — <?= h($editUser['first_name'] . ' ' . $editUser['last_name']) ?>
                    <?php else: ?>
                        Update account details and credentials.
                    <?php endif; ?>
                </p>
            </div>
            <button class="modal-close" id="closeEditModalBtn" aria-label="Close modal">
                <i class="fa fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">

            <?php if (!empty($editErrors)): ?>
                <div class="error-box">
                    <?php foreach ($editErrors as $err): ?>
                        <div><i class="fa-solid fa-circle-exclamation"></i> <?= h($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($editSuccess): ?>
                <div class="success-box">
                    <div><strong><i class="fa-solid fa-circle-check"></i> Changes saved successfully!</strong></div>
                    <div class="success-detail">The user record has been updated.</div>
                </div>
            <?php endif; ?>

            <?php
            $ev = function($postKey, $userKey) use ($editUser) {
                return h($_POST[$postKey] ?? $editUser[$userKey] ?? '');
            };
            ?>

            <form method="POST" id="editUserForm">
                <input type="hidden" name="user_edit" value="1">
                <input type="hidden" name="edit_id"   value="<?= h($editId ?? '') ?>">
                <input type="hidden" name="csrf"       value="<?= h($_SESSION['csrf']) ?>">

                <div class="form-section-title">Personal Details</div>
                <div class="form-grid">

                    <div class="form-group col-5">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="edit_first_name" required
                               value="<?= $ev('edit_first_name', 'first_name') ?>">
                    </div>

                    <div class="form-group col-2">
                        <label>M.I.</label>
                        <input type="text" name="edit_middle_name" maxlength="5" placeholder="-"
                               value="<?= $ev('edit_middle_name', 'middle_name') ?>">
                    </div>

                    <div class="form-group col-5">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="edit_last_name" required
                               value="<?= $ev('edit_last_name', 'last_name') ?>">
                    </div>

                    <div class="form-group col-4">
                        <label>Extension</label>
                        <select name="edit_extension_name">
                            <?php
                            $extensions  = ['', 'Jr.', 'Sr.', 'I', 'II', 'III', 'IV'];
                            $selectedExt = $_POST['edit_extension_name'] ?? ($editUser['extension_name'] ?? '');
                            foreach ($extensions as $ext):
                            ?>
                                <option value="<?= h($ext) ?>" <?= $selectedExt === $ext ? 'selected' : '' ?>>
                                    <?= $ext === '' ? 'None' : h($ext) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-4">
                        <label>System Role <span class="required">*</span></label>
                        <select name="edit_role" required>
                            <?php
                            $roles        = ['super admin' => 'Super Admin', 'admin' => 'Admin', 'applicant' => 'Applicant'];
                            $selectedRole = $_POST['edit_role'] ?? ($editUser['user_role'] ?? '');
                            foreach ($roles as $val => $label):
                            ?>
                                <option value="<?= h($val) ?>" <?= $selectedRole === $val ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-4">
                        <label>Account Status <span class="required">*</span></label>
                        <select name="edit_status" required>
                            <?php
                            $statuses       = ['active' => 'Active', 'inactive' => 'Inactive'];
                            $selectedStatus = strtolower($_POST['edit_status'] ?? ($editUser['status'] ?? 'active'));
                            foreach ($statuses as $val => $label):
                            ?>
                                <option value="<?= h($val) ?>" <?= $selectedStatus === $val ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <div class="form-section-title">Security & Credentials</div>
                <div class="form-grid">

                    <div class="form-group col-6">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="edit_email" required
                               value="<?= $ev('edit_email', 'email') ?>">
                    </div>

                    <div class="form-group col-6">
                        <label>New Password</label>
                        <input type="password" name="edit_password"
                               placeholder="Leave blank to keep existing">
                    </div>

                    <div class="form-group col-12">
                        <p class="form-instructions">
                            Required fields are marked with <span class="required">*</span>.
                            <?php if ($editUser && !empty($editUser['created_at'])): ?>
                                &nbsp;·&nbsp;
                                <i class="fa-regular fa-clock"></i>
                                Account created: <strong><?= date('M d, Y', strtotime($editUser['created_at'])) ?></strong>
                            <?php endif; ?>
                        </p>
                    </div>

                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" id="cancelEditModalBtn">Cancel</button>
                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-user-check"></i> Save Changes
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>


<script>
(function () {

    /* ── Table filter ── */
    var searchInput = document.getElementById('searchInput');
    var roleFilter  = document.getElementById('roleFilter');

    function filterTable() {
        var search = searchInput.value.toLowerCase();
        var role   = roleFilter.value.toLowerCase();
        document.querySelectorAll('#tableBody tr[data-name]').forEach(function (row) {
            var name     = row.dataset.name || '';
            var userRole = row.dataset.role || '';
            row.style.display =
                (!search || name.includes(search)) &&
                (!role   || userRole === role)
                ? '' : 'none';
        });
    }

    searchInput.addEventListener('input',  filterTable);
    roleFilter.addEventListener('change',  filterTable);

    /* ── Modal helpers ── */
    function openModal(modal)  { modal.classList.add('open');    document.body.style.overflow = 'hidden'; }
    function closeModal(modal) { modal.classList.remove('open'); document.body.style.overflow = ''; }

    function bindModal(modalId, openBtnId, closeBtnId, cancelBtnId) {
        var modal     = document.getElementById(modalId);
        var openBtn   = openBtnId   ? document.getElementById(openBtnId)   : null;
        var closeBtn  = closeBtnId  ? document.getElementById(closeBtnId)  : null;
        var cancelBtn = cancelBtnId ? document.getElementById(cancelBtnId) : null;

        if (openBtn)   openBtn.addEventListener('click',  function() { openModal(modal); });
        if (closeBtn)  closeBtn.addEventListener('click', function() { closeModal(modal); });
        if (cancelBtn) cancelBtn.addEventListener('click',function() { closeModal(modal); });

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal(modal);
        });
    }

    bindModal('createModal', 'openModalBtn', 'closeCreateModalBtn', 'cancelCreateModalBtn');
    bindModal('editModal', null, 'closeEditModalBtn', 'cancelEditModalBtn');

    var reopenCreate = <?= $reopenCreateModal ? 'true' : 'false' ?>;
    if (reopenCreate) openModal(document.getElementById('createModal'));

    var reopenEdit = <?= $reopenEditModal ? 'true' : 'false' ?>;
    if (reopenEdit) openModal(document.getElementById('editModal'));

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        ['createModal','editModal'].forEach(function(id) {
            var m = document.getElementById(id);
            if (m.classList.contains('open')) closeModal(m);
        });
    });

    /* ── Email preview (create form) ── */
    function generateEmailPreview() {
        var fEl = document.querySelector('#createUserForm [name="first_name"]');
        var lEl = document.querySelector('#createUserForm [name="last_name"]');
        var rEl = document.getElementById('roleSelect');
        var preview = document.getElementById('emailPreview');
        if (!fEl || !lEl || !rEl || !preview) return;

        var f = fEl.value.trim();
        var l = lEl.value.trim();
        var r = rEl.value;
        if (!f || !l || !r) { preview.value = ''; return; }
        var prefix = { 'super admin': 'SA', 'admin': 'AD', 'applicant': 'AP' }[r] || 'US';
        preview.value = prefix + f[0].toLowerCase() + l.toLowerCase().replace(/\s/g, '') + '@psuxizn.com';
    }

    function toggleProgram() {
        var roleEl = document.getElementById('roleSelect');
        var field  = document.getElementById('programField');
        var select = document.getElementById('programSelect');
        if (!roleEl || !field || !select) return;

        var show = roleEl.value === 'applicant';
        field.style.display = show ? 'flex' : 'none';
        show ? select.setAttribute('required','required') : select.removeAttribute('required');
    }

    var createFirstName = document.querySelector('#createUserForm [name="first_name"]');
    var createLastName  = document.querySelector('#createUserForm [name="last_name"]');
    var roleSelectEl    = document.getElementById('roleSelect');

    if (createFirstName) createFirstName.addEventListener('input', generateEmailPreview);
    if (createLastName)  createLastName.addEventListener('input',  generateEmailPreview);
    if (roleSelectEl) {
        roleSelectEl.addEventListener('change', function() {
            generateEmailPreview();
            toggleProgram();
        });
    }

    generateEmailPreview();
    toggleProgram();

})();
</script>
