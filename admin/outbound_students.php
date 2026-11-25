<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/role_helpers.php';

if (empty($_SESSION['user_id']) || (!canManageActivities() && !isApprover() && !canManageSystem())) {
    http_response_code(empty($_SESSION['user_id']) ? 401 : 403);
    exit('Access denied.');
}

$search = trim((string)($_GET['search'] ?? ''));
$where = "WHERE LOWER(a.mobility_type) LIKE 'outbound%'";
$params = [];
if ($search !== '') {
    $where .= " AND (CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR u.email LIKE ? OR p.program_name LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
}

$stmt = db()->prepare("
    SELECT a.id, a.mobility_type, a.status, a.created_at, a.institution,
           CONCAT_WS(' ', u.first_name, u.last_name) AS applicant,
           COALESCE(NULLIF(u.account_type, ''), u.user_role) AS participant_type,
           u.program AS degree_program, c.campus_name, p.program_name
    FROM applications a
    LEFT JOIN users u ON u.id = a.applicant_id
    LEFT JOIN campuses c ON c.id = u.campus_id
    LEFT JOIN programs p ON p.id = a.program_id
    $where
    ORDER BY a.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

function outboundEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function outboundInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= strtoupper(substr($p, 0, 1));
    }
    return $out ?: '?';
}

function outboundStatusClass(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['approved', 'completed'], true)) return 'status-active';
    if (in_array($status, ['rejected', 'cancelled', 'declined'], true)) return 'status-inactive';
    return 'status-pending';
}
?>

<link rel="stylesheet" href="../asset/css/users.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <div class="page-header">
        <h2>Outbound Participants</h2>
        <p>Review submitted outbound mobility applications.</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search by name, email, or program..." value="<?= outboundEscape($search) ?>">
        </div>
        <select class="filter-select" id="typeFilter">
            <option value="">All Types</option>
            <option value="student">Student</option>
            <option value="faculty">Faculty</option>
            <option value="non-teaching">Non-teaching</option>
            <option value="applicant">Applicant</option>
        </select>
        <select class="filter-select" id="statusFilter">
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Type</th>
                    <th>Campus</th>
                    <th>Degree Program</th>
                    <th>Mobility Program</th>
                    <th>Status</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!empty($applications)): ?>
                <?php foreach ($applications as $application): ?>
                    <?php
                        $applicantName  = $application['applicant'] ?: 'Unknown';
                        $participantRaw = (string)$application['participant_type'];
                        $participantKey = strtolower(str_replace(' ', '-', $participantRaw));
                        $statusRaw      = (string)$application['status'];
                        $statusKey      = strtolower(str_replace(' ', '_', $statusRaw));
                        $mobilityLabel  = $application['program_name'] ?? $application['institution'] ?? '—';
                    ?>
                    <tr data-name="<?= outboundEscape(strtolower($applicantName)) ?>"
                        data-type="<?= outboundEscape($participantKey) ?>"
                        data-status="<?= outboundEscape($statusKey) ?>">
                        <td>
                            <div class="user-name-cell">
                                <div class="user-avatar"><?= outboundEscape(outboundInitials($applicantName)) ?></div>
                                <span style="font-weight:600; color:#0f172a;"><?= outboundEscape($applicantName) ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="badge"><?= outboundEscape(ucwords($participantRaw)) ?></span>
                        </td>
                        <td><?= outboundEscape($application['campus_name'] ?? '—') ?></td>
                        <td><?= outboundEscape($application['degree_program'] ?? '—') ?></td>
                        <td><?= outboundEscape($mobilityLabel) ?></td>
                        <td>
                            <span class="status-badge <?= outboundEscape(outboundStatusClass($statusRaw)) ?>">
                                <?= outboundEscape(ucwords(str_replace('_', ' ', $statusRaw))) ?>
                            </span>
                        </td>
                        <td><?= outboundEscape(date('M d, Y', strtotime((string)$application['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 12h20"/>
                                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                                    <circle cx="12" cy="12" r="10"/>
                                </svg>
                            </div>
                            <h3>No outbound applications found</h3>
                            <p><?= $search !== '' ? 'Try a different search term.' : 'Outbound applications will appear here once submitted.' ?></p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var searchInput  = document.getElementById('searchInput');
    var typeFilter   = document.getElementById('typeFilter');
    var statusFilter = document.getElementById('statusFilter');

    function filterTable() {
        var search = searchInput.value.toLowerCase();
        var type   = typeFilter.value.toLowerCase();
        var status = statusFilter.value.toLowerCase();
        document.querySelectorAll('#tableBody tr[data-name]').forEach(function (row) {
            var name    = row.dataset.name   || '';
            var rowType = row.dataset.type   || '';
            var rowSt   = row.dataset.status || '';
            row.style.display =
                (!search || name.includes(search)) &&
                (!type   || rowType === type) &&
                (!status || rowSt === status)
                ? '' : 'none';
        });
    }

    if (searchInput)  searchInput.addEventListener('input',  filterTable);
    if (typeFilter)   typeFilter.addEventListener('change', filterTable);
    if (statusFilter) statusFilter.addEventListener('change', filterTable);

    // Preserve the existing server-side search: submit on Enter
    if (searchInput) {
        searchInput.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var url = new URL(window.location.href);
            url.searchParams.set('page', 'outbound_students');
            if (searchInput.value.trim() === '') {
                url.searchParams.delete('search');
            } else {
                url.searchParams.set('search', searchInput.value.trim());
            }
            window.location.href = url.toString();
        });
    }
})();
</script>