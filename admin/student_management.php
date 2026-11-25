<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* AUTH & ROLE */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}
if (!canManageActivities() && !isAdmin()) {
    http_response_code(403);
    exit('Access denied.');
}

$pdo = db();

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* Load Departments dynamically */
$departmentOptions = require __DIR__ . '/../config/academic_departments.php';

/* =========================
   FILTERS + PAGINATION
========================= */
$search     = trim($_GET['search'] ?? '');
$department = $_GET['department'] ?? '';

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

/* =========================
   WHERE (APPROVED ONLY)
========================= */
$where  = "WHERE a.status = 'approved'";
$params = [];

if ($search !== '') {
    $where .= " AND (
        CONCAT(u.first_name,' ',u.last_name) LIKE ?
        OR p.program_name LIKE ?
        OR a.department LIKE ?
        OR a.mobility_type LIKE ?
        OR a.institution LIKE ?
        OR c.country_name LIKE ?
    )";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if ($department !== '') {
    $where .= " AND a.department = ?";
    $params[] = $department;
}

$joins = "
FROM applications a
LEFT JOIN users u ON a.applicant_id = u.id
LEFT JOIN programs p ON a.program_id = p.id
LEFT JOIN countries c ON p.country_id = c.id
";

/* COUNT */
$countStmt = $pdo->prepare("SELECT COUNT(*) $joins $where");
$countStmt->execute($params);
$totalStudents = (int)$countStmt->fetchColumn();
$totalPages    = max(1, (int)ceil($totalStudents / $limit));

/* DATA ($limit and $offset are integers, safe to inline) */
$sql = "
SELECT
    a.id,
    p.program_name AS program,
    a.department,
    a.mobility_type,
    a.institution,
    c.country_name AS country,
    a.status,
    a.reviewed_at,
    a.created_at,
    a.documents_status,
    CONCAT(u.first_name,' ',u.last_name) AS student_name,
    u.email AS student_email
$joins
$where
ORDER BY a.reviewed_at DESC
LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/applications.css">
<link rel="stylesheet" href="../asset/css/programs.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
    /* Student modal: stays inside the screen, body scrolls, button always visible */
    #studentModal.modal-overlay { padding: 16px; box-sizing: border-box; overflow-y: auto; }
    #studentModal .modal-box {
        display: flex; flex-direction: column;
        width: 100%; max-width: 720px;
        max-height: calc(100vh - 32px);
        margin: auto; overflow: hidden;
    }
    #studentModal .modal-header,
    #studentModal .modal-footer { flex-shrink: 0; }
    #studentModal .modal-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
    #studentModal .modal-footer {
        display: flex; justify-content: flex-end; gap: 12px;
        padding: 16px 24px; background: #fff; border-top: 1px solid #e2e8f0;
    }
    .toolbar-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .toolbar-filters select { min-width: 200px; }
    .student-email { display: block; font-size: 0.78rem; color: #64748b; font-weight: 400; }
</style>

<div class="content">

    <div class="page-header">
        <h2>Student Management</h2>
        <p>Manage approved mobility students and records.</p>
    </div>

    <form method="GET" class="toolbar">
        <input type="hidden" name="page" value="student_management">

        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" name="search" id="searchInput" placeholder="Search students..." value="<?= h($search) ?>">
        </div>

        <div class="toolbar-filters">
            <select name="department" class="form-input">
                <option value="">All Departments</option>
                <?php foreach ($departmentOptions as $deptKey => $deptLabel): ?>
                    <option value="<?= h($deptKey) ?>" <?= $department === $deptKey ? 'selected' : '' ?>>
                        <?= h($deptLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="create-btn">
                <i class="fa fa-filter"></i> Filter
            </button>
        </div>
    </form>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Program</th>
                    <th>Department</th>
                    <th>Mobility</th>
                    <th>Institution</th>
                    <th>Country</th>
                    <th>Approved Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!empty($students)): ?>
                <?php foreach ($students as $s): ?>
                <tr data-id="<?= (int)$s['id'] ?>">
                    <td>
                        <div class="program-name">
                            <div class="program-icon"><i class="fa fa-user-graduate"></i></div>
                            <div class="program-info">
                                <strong><?= h($s['student_name']) ?></strong>
                                <span class="student-email"><?= h($s['student_email']) ?></span>
                            </div>
                        </div>
                    </td>
                    <td><?= h($s['program']) ?></td>
                    <td><?= h($s['department']) ?></td>
                    <td>
                        <span class="mobility-badge mobility-<?= strtolower(h($s['mobility_type'])) ?>">
                            <?= h($s['mobility_type']) ?>
                        </span>
                    </td>
                    <td><?= h($s['institution']) ?></td>
                    <td><?= h($s['country'] ?? 'N/A') ?></td>
                    <td>
                        <?= !empty($s['reviewed_at']) ? date('M d, Y', strtotime($s['reviewed_at'])) : 'N/A' ?>
                    </td>
                    <td>
                        <button type="button" class="action-btn view-btn"
                                onclick="openStudentModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)"
                                title="View Details">
                            <i class="fa fa-eye"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                                    <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                                </svg>
                            </div>
                            <h3>No approved students found</h3>
                            <p>Approved mobility students will appear here.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pagination">
            <span>Showing <?= count($students) ?> of <?= $totalStudents ?> student<?= $totalStudents === 1 ? '' : 's' ?></span>
            <div class="pages">
                <?php $baseQ = array_merge($_GET, []); unset($baseQ['p']); ?>
                <?php if ($page > 1): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page - 1]))) ?>"><i class="fa fa-angle-left"></i></a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-left"></i></button>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $i]))) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page + 1]))) ?>"><i class="fa fa-angle-right"></i></a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-right"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- =========================
     VIEW STUDENT MODAL
========================= -->
<div id="studentModal" class="modal-overlay" onclick="closeStudentModalOutside(event)">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon"><i class="fa fa-user-graduate"></i></div>
                <div>
                    <h3 id="m-header-name">Student Details</h3>
                    <p class="modal-subtitle" id="m-header-email">—</p>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeStudentModal()"><i class="fa fa-times"></i></button>
        </div>

        <div class="modal-body">
            <!-- Student Info -->
            <div class="modal-section">
                <h4><i class="fa fa-user"></i> Student Information</h4>
                <div class="modal-grid">
                    <div class="modal-field"><label>Full Name</label><span id="m-name"></span></div>
                    <div class="modal-field"><label>Email</label><span id="m-email"></span></div>
                    <div class="modal-field"><label>Program</label><span id="m-program"></span></div>
                    <div class="modal-field"><label>Department</label><span id="m-department"></span></div>
                </div>
            </div>

            <!-- Mobility Info -->
            <div class="modal-section">
                <h4><i class="fa fa-plane"></i> Mobility Details</h4>
                <div class="modal-grid">
                    <div class="modal-field"><label>Mobility Type</label><span id="m-mobility"></span></div>
                    <div class="modal-field"><label>Institution</label><span id="m-institution"></span></div>
                    <div class="modal-field"><label>Country</label><span id="m-country"></span></div>
                    <div class="modal-field"><label>Documents Status</label><span id="m-docs"></span></div>
                </div>
            </div>

            <!-- Dates -->
            <div class="modal-section">
                <h4><i class="fa fa-calendar"></i> Timeline</h4>
                <div class="modal-grid">
                    <div class="modal-field"><label>Date Applied</label><span id="m-created"></span></div>
                    <div class="modal-field"><label>Date Approved</label><span id="m-reviewed"></span></div>
                    <div class="modal-field"><label>Status</label><span id="m-status"></span></div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeStudentModal()">Close</button>
        </div>
    </div>
</div>

<script>
function openStudentModal(data) {
    const fmt = (val) => val ? val : '—';
    const formatDate = (val) => {
        if (!val) return '—';
        return new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    };
    const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const badgeHtml = (value, prefix) => {
        if (!value) return '—';
        const cls = String(value).toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
        return `<span class="status-badge ${prefix}-${cls}">${esc(value.charAt(0).toUpperCase() + value.slice(1))}</span>`;
    };

    /* Header */
    document.getElementById('m-header-name').textContent  = fmt(data.student_name);
    document.getElementById('m-header-email').textContent = fmt(data.student_email);

    /* Student Info */
    document.getElementById('m-name').textContent       = fmt(data.student_name);
    document.getElementById('m-email').textContent      = fmt(data.student_email);
    document.getElementById('m-program').textContent    = fmt(data.program);
    document.getElementById('m-department').textContent = fmt(data.department);

    /* Mobility */
    document.getElementById('m-mobility').innerHTML      = badgeHtml(data.mobility_type, 'mobility');
    document.getElementById('m-institution').textContent = fmt(data.institution);
    document.getElementById('m-country').textContent     = fmt(data.country);
    document.getElementById('m-docs').innerHTML          = badgeHtml(data.documents_status, 'status');

    /* Timeline */
    document.getElementById('m-created').textContent  = formatDate(data.created_at);
    document.getElementById('m-reviewed').textContent = formatDate(data.reviewed_at);
    document.getElementById('m-status').innerHTML     = badgeHtml(data.status, 'status');

    document.getElementById('studentModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeStudentModal() {
    document.getElementById('studentModal').classList.remove('open');
    document.body.style.overflow = '';
}

function closeStudentModalOutside(e) {
    if (e.target.id === 'studentModal') closeStudentModal();
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeStudentModal();
});
</script>