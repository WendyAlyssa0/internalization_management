<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

$pdo = db();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================
   FILTERS
========================= */
$search     = trim($_GET['search'] ?? '');
$department = $_GET['department'] ?? '';

/* =========================
   BASE QUERY (APPROVED ONLY)
========================= */
$sql = "
SELECT
    a.id,
    p.program_name AS program,
    a.department,
    a.mobility_type,
    a.institution,
    a.country,
    a.status,
    a.reviewed_at,
    a.created_at,
    a.documents_status,

    CONCAT(u.first_name,' ',u.last_name) AS student_name,
    u.email AS student_email

FROM applications a

LEFT JOIN users u
    ON a.applicant_id = u.id

LEFT JOIN programs p
    ON a.program_id = p.id

WHERE a.status = 'approved'
";

$params = [];

if ($search !== '') {

    $sql .= " AND (
        CONCAT(u.first_name,' ',u.last_name) LIKE ?
        OR p.program_name LIKE ?
        OR a.department LIKE ?
        OR a.mobility_type LIKE ?
        OR a.institution LIKE ?
        OR a.country LIKE ?
    )";

    $like = "%{$search}%";

    $params = [
        $like,
        $like,
        $like,
        $like,
        $like,
        $like
    ];
}

if ($department !== '') {
    $sql .= " AND a.department = ?";
    $params[] = $department;
}

$sql .= " ORDER BY a.reviewed_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/applications.css">

<div class="content">

    <div class="page-header">
        <h2>Student Management</h2>
        <p>Manage approved mobility students and records.</p>
    </div>

    <div class="toolbar">
        <form method="GET" class="search-wrap">
            <input type="hidden" name="page" value="student_management">
            <i class="fa fa-search"></i>
            <input type="text"
                   name="search"
                   placeholder="Search students..."
                   value="<?= h($search) ?>">
        </form>
    </div>

    <form method="GET" class="filter-bar">
        <input type="hidden" name="page" value="student_management">
        <select name="department" class="filter-select">
            <option value="">All Departments</option>
            <option value="IT"          <?= $department === 'IT'          ? 'selected' : '' ?>>IT</option>
            <option value="Engineering" <?= $department === 'Engineering' ? 'selected' : '' ?>>Engineering</option>
            <option value="Business"    <?= $department === 'Business'    ? 'selected' : '' ?>>Business</option>
        </select>
        <button type="submit" class="btn-filter">Filter</button>
    </form>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Program</th>
                    <th>Department</th>
                    <th>Mobility</th>
                    <th>Institution</th>
                    <th>Country</th>
                    <th>Approved Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($students)): ?>
                <?php foreach ($students as $s): ?>
                <tr>
                    <td>#<?= h($s['id']) ?></td>
                    <td><?= h($s['student_name']) ?></td>
                    <td><?= h($s['program']) ?></td>
                    <td><?= h($s['department']) ?></td>
                    <td>
                        <span class="mobility-badge mobility-<?= strtolower(h($s['mobility_type'])) ?>">
                            <?= h($s['mobility_type']) ?>
                        </span>
                    </td>
                    <td><?= h($s['institution']) ?></td>
                    <td><?= h($s['country']) ?></td>
                    <td>
                        <?= !empty($s['reviewed_at'])
                            ? date('M d, Y', strtotime($s['reviewed_at']))
                            : 'N/A' ?>
                    </td>
                    <td>
                        <div class="actions">
                            <button class="action-btn view-btn"
                                    onclick="openStudentModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)"
                                    title="View Details">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="empty-row">No students found</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pagination">
            Showing <?= count($students) ?> student(s)
        </div>
    </div>

</div>

<!-- =========================
     VIEW STUDENT MODAL
========================= -->
<div id="studentModal" class="modal-overlay" onclick="closeStudentModalOutside(event)">
    <div class="modal-box">

        <!-- HEADER -->
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon">
                    <i class="fa fa-user-graduate"></i>
                </div>
                <div>
                    <h3 id="m-header-name">Student Details</h3>
                    <p class="modal-subtitle" id="m-header-email">—</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeStudentModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <!-- BODY -->
        <div class="modal-body">

            <!-- Student Info -->
            <div class="modal-section">
                <h4><i class="fa fa-user"></i> Student Information</h4>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Full Name</label>
                        <span id="m-name"></span>
                    </div>
                    <div class="modal-field">
                        <label>Email</label>
                        <span id="m-email"></span>
                    </div>
                    <div class="modal-field">
                        <label>Program</label>
                        <span id="m-program"></span>
                    </div>
                    <div class="modal-field">
                        <label>Department</label>
                        <span id="m-department"></span>
                    </div>
                </div>
            </div>

            <!-- Mobility Info -->
            <div class="modal-section">
                <h4><i class="fa fa-plane"></i> Mobility Details</h4>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Mobility Type</label>
                        <span id="m-mobility"></span>
                    </div>
                    <div class="modal-field">
                        <label>Institution</label>
                        <span id="m-institution"></span>
                    </div>
                    <div class="modal-field">
                        <label>Country</label>
                        <span id="m-country"></span>
                    </div>
                    <div class="modal-field">
                        <label>Documents Status</label>
                        <span id="m-docs"></span>
                    </div>
                </div>
            </div>

            <!-- Dates -->
            <div class="modal-section">
                <h4><i class="fa fa-calendar"></i> Timeline</h4>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Date Applied</label>
                        <span id="m-created"></span>
                    </div>
                    <div class="modal-field">
                        <label>Date Approved</label>
                        <span id="m-reviewed"></span>
                    </div>
                    <div class="modal-field">
                        <label>Application ID</label>
                        <span id="m-id"></span>
                    </div>
                    <div class="modal-field">
                        <label>Status</label>
                        <span id="m-status"></span>
                    </div>
                </div>
            </div>

        </div>

        <!-- FOOTER -->
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeStudentModal()">Close</button>
        </div>

    </div>
</div>

<script>
/* =========================
   MODAL
========================= */
function openStudentModal(data) {
    const fmt = (val) => val ? val : '—';

    const formatDate = (val) => {
        if (!val) return '—';
        return new Date(val).toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric'
        });
    };

    const badgeHtml = (value, prefix) => {
        if (!value) return '—';
        const cls = value.toLowerCase().replace(/\s+/g, '-');
        return `<span class="status-badge ${prefix}-${cls}">${value.charAt(0).toUpperCase() + value.slice(1)}</span>`;
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
    document.getElementById('m-mobility').innerHTML    = badgeHtml(data.mobility_type, 'mobility');
    document.getElementById('m-institution').textContent = fmt(data.institution);
    document.getElementById('m-country').textContent     = fmt(data.country);
    document.getElementById('m-docs').innerHTML          = badgeHtml(data.documents_status, 'status');

    /* Timeline */
    document.getElementById('m-created').textContent  = formatDate(data.created_at);
    document.getElementById('m-reviewed').textContent = formatDate(data.reviewed_at);
    document.getElementById('m-id').textContent       = '#' + fmt(data.id);
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