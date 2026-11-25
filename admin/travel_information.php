<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* AUTH */
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

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* =========================================================
   AJAX HANDLER (Update Status)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    $allowedStatuses = ['pending', 'traveling', 'completed', 'rejected'];
    if ($id <= 0 || !in_array($status, $allowedStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE travel_information SET travel_status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log('travel update failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

/* =========================================================
   FETCH DATA
========================================================= */
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$sql = "
    SELECT
        t.*,
        CONCAT(u.first_name,' ',u.last_name) AS student_name
    FROM travel_information t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (CONCAT(u.first_name,' ',u.last_name) LIKE ? OR t.flight_number LIKE ? OR t.departure_airport LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like];
}

if ($statusFilter !== '') {
    $sql .= " AND t.travel_status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY t.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$travel = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/applications.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">
    <div class="page-header">
        <h2>Travel Information</h2>
        <p>Manage flight logistics and travel statuses for approved students.</p>
    </div>

    <div class="toolbar">
        <form method="GET" class="search-wrap">
            <input type="hidden" name="page" value="travel_information">
            <i class="fa fa-search"></i>
            <input type="text" name="search" placeholder="Search by student or flight..." value="<?= h($search) ?>">
        </form>
        
        <form method="GET" class="filter-bar" style="margin:0;">
            <input type="hidden" name="page" value="travel_information">
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="traveling" <?= $statusFilter === 'traveling' ? 'selected' : '' ?>>Traveling</option>
                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
        </form>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Flight No.</th>
                    <th>Departure</th>
                    <th>Arrival</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($travel)): ?>
                <?php foreach ($travel as $t): ?>
                    <?php
                        // Replaced PHP 8 'match' with switch for backward compatibility
                        $status = strtolower($t['travel_status'] ?? 'pending');
                        $badgeClass = 'status-pending';
                        if ($status === 'completed') $badgeClass = 'status-approved';
                        if ($status === 'traveling') $badgeClass = 'status-evaluation';
                        if ($status === 'rejected') $badgeClass = 'status-rejected';
                    ?>
                <tr>
                    <td><?= h($t['student_name'] ?? 'Unknown') ?></td>
                    <td><?= h($t['flight_number'] ?? '—') ?></td>
                    <td>
                        <?= h($t['departure_airport'] ?? '—') ?><br>
                        <small><?= h($t['departure_date'] ?? '') ?> <?= h($t['departure_time'] ?? '') ?></small>
                    </td>
                    <td>
                        <?= h($t['arrival_airport'] ?? '—') ?><br>
                        <small><?= h($t['arrival_date'] ?? '') ?> <?= h($t['arrival_time'] ?? '') ?></small>
                    </td>
                    <td>
                        <span class="status-badge <?= $badgeClass ?>">
                            <?= ucfirst($status) ?>
                        </span>
                    </td>
                    <td>
                        <div class="actions">
                            <button class="action-btn evaluate-btn" title="Mark Traveling" onclick="updateStatus(<?= $t['id'] ?>, 'traveling')">
                                <i class="fa fa-plane-departure"></i>
                            </button>
                            <button class="action-btn approve-btn" title="Mark Completed" onclick="updateStatus(<?= $t['id'] ?>, 'completed')">
                                <i class="fa fa-check-circle"></i>
                            </button>
                            <button class="action-btn reject-btn" title="Reject/Cancel" onclick="updateStatus(<?= $t['id'] ?>, 'rejected')">
                                <i class="fa fa-times-circle"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="empty-row">No travel records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function updateStatus(id, status) {
    if (!confirm('Are you sure you want to update the status to ' + status + '?')) return;

    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('id', id);
    formData.append('status', status);
    formData.append('csrf_token', '<?= $csrfToken ?>');

    fetch('', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Failed to update status.');
            }
        })
        .catch(() => alert('Server error.'));
}
</script>