<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php'; // Includes session_start()
require_once __DIR__ . '/role_helpers.php';

/* =========================
   AUTHENTICATION
========================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

/* =========================
   AUTHORIZATION
   Only Staff (Admin, Approvers, Coordinators) can view this list.
   Applicants cannot see other applicants' data.
========================= */
if (!canManageActivities() && !isApprover() && !isAdmin()) {
    http_response_code(403);
    exit('Forbidden — you do not have permission to view approved students.');
}

$pdo = db();

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Approved Students (Who have submitted complete documents)
|--------------------------------------------------------------------------
*/
$sql = "
SELECT
    a.id,
    CONCAT(
        u.first_name, ' ',
        IF(u.middle_name IS NULL OR u.middle_name='', '', CONCAT(LEFT(u.middle_name,1),'. ')),
        u.last_name
    ) AS student_name,
    p.program_name,
    c.country_name,
    a.institution AS institution_name,
    a.mobility_type,
    a.semester,
    a.school_year,
    a.reviewed_at AS approval_date,
    a.documents_status,
    a.status
FROM applications a
LEFT JOIN users u ON a.applicant_id = u.id
LEFT JOIN programs p ON a.program_id = p.id
LEFT JOIN countries c ON p.country_id = c.id
WHERE LOWER(TRIM(a.status)) = 'approved'
ORDER BY a.reviewed_at DESC
";

$students = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* Filter options come from the real data, so nothing is guessed */
$mobilityTypes = [];
$countryNames  = [];
foreach ($students as $r) {
    $m = trim((string)($r['mobility_type'] ?? ''));
    $c = trim((string)($r['country_name'] ?? ''));
    if ($m !== '') $mobilityTypes[strtolower($m)] = $m;
    if ($c !== '') $countryNames[strtolower($c)]  = $c;
}
ksort($mobilityTypes);
ksort($countryNames);
?>

<link rel="stylesheet" href="../asset/css/applications.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
    .summary-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
    .summary-card {
        min-width: 150px; padding: 14px 18px;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    }
    .summary-card .summary-num { font-size: 1.6rem; font-weight: 800; line-height: 1.1; }
    .summary-card .summary-label { font-size: 0.8rem; color: #64748b; margin-top: 2px; }
    .filter-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; align-items: center; }
    .filter-row select, .filter-row button {
        padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px;
        background: #fff; font-size: 0.9rem; min-width: 180px;
    }
    .filter-row button { min-width: 0; cursor: pointer; }
</style>

<div class="content">

    <div class="page-header">
        <h2>Approved Students</h2>
        <p>Every applicant approved in Applications Management appears here. Document status is shown for each student.</p>
    </div>

    <!-- SUMMARY (updates with the filters) -->
    <div class="summary-row">
        <div class="summary-card">
            <div class="summary-num" id="count-total"><?= count($students) ?></div>
            <div class="summary-label">Total students</div>
        </div>
        <?php foreach ($mobilityTypes as $key => $label): ?>
            <div class="summary-card">
                <div class="summary-num" data-count-mobility="<?= h($key) ?>">0</div>
                <div class="summary-label"><?= h($label) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search student by name, program, country, or partner...">
        </div>
    </div>

    <div class="filter-row">
        <select id="mobilityFilter">
            <option value="">All Mobility Types</option>
            <?php foreach ($mobilityTypes as $key => $label): ?>
                <option value="<?= h($key) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="countryFilter">
            <option value="">All Countries</option>
            <?php foreach ($countryNames as $key => $label): ?>
                <option value="<?= h($key) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="docsFilter">
            <option value="">All Documents</option>
            <option value="complete">Complete</option>
            <option value="incomplete">Not complete</option>
        </select>

        <button type="button" id="resetFilters"><i class="fa fa-rotate-left"></i> Reset</button>
    </div>

    <div class="table-section">
        <table class="table-card">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Program</th>
                    <th>Country</th>
                    <th>Partner Institution</th>
                    <th>Mobility</th>
                    <th>Semester</th>
                    <th>School Year</th>
                    <th>Approved Date</th>
                    <th>Documents</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody id="tableBody">
            <?php if ($students): ?>
                <?php foreach ($students as $row): ?>

                <?php
                    $initials = '';
                    $parts = explode(' ', trim($row['student_name'] ?? ''));
                    foreach ($parts as $part) {
                        if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
                        if (strlen($initials) >= 2) break;
                    }

                    $mobilityKey = strtolower(trim((string)($row['mobility_type'] ?? '')));
                    $countryKey  = strtolower(trim((string)($row['country_name'] ?? '')));
                    $planeIcon   = $mobilityKey === 'inbound'  ? 'fa-plane-arrival'
                                 : ($mobilityKey === 'outbound' ? 'fa-plane-departure' : 'fa-plane');
                ?>

                <tr data-search="<?= h(strtolower(($row['student_name'] ?? '') . ' ' . ($row['program_name'] ?? '') . ' ' . ($row['country_name'] ?? '') . ' ' . ($row['institution_name'] ?? ''))) ?>"
                    data-mobility="<?= h($mobilityKey) ?>"
                    data-country="<?= h($countryKey) ?>"
                    data-docs="<?= h(strtolower(trim((string)($row['documents_status'] ?? '')))) ?>">

                    <td>
                        <div class="applicant-cell">
                            <div class="applicant-avatar"><?= h($initials ?: '?') ?></div>
                            <div class="applicant-info">
                                <strong><?= h($row['student_name']) ?></strong>
                            </div>
                        </div>
                    </td>

                    <td><?= h($row['program_name']) ?></td>
                    <td><?= h($row['country_name'] ?? 'N/A') ?></td>
                    <td><?= h($row['institution_name']) ?></td>

                    <td>
                        <span class="status-badge status-submitted">
                            <i class="fa-solid <?= $planeIcon ?>"></i> <?= h($row['mobility_type']) ?>
                        </span>
                    </td>

                    <td><?= h($row['semester']) ?></td>
                    <td><?= h($row['school_year']) ?></td>

                    <td class="date-cell">
                        <?= !empty($row['approval_date']) ? date("M d, Y", strtotime($row['approval_date'])) : '—' ?>
                    </td>

                    <td>
                        <?php $docsKey = strtolower(trim((string)($row['documents_status'] ?? ''))); ?>
                        <span class="status-badge <?= $docsKey === 'complete' ? 'status-approved' : 'status-submitted' ?>">
                            <?= $docsKey !== '' ? h(ucfirst($docsKey)) : 'Not submitted' ?>
                        </span>
                    </td>

                    <td>
                        <span class="status-badge status-approved">
                            <?= h(ucfirst($row['status'])) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>

                <tr id="noMatchRow" style="display:none;">
                    <td colspan="10" style="text-align:center; padding:28px; color:#64748b;">
                        No students match your search or filters.
                    </td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="10">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fa fa-folder-open" style="font-size: 30px; color: var(--color-info);"></i>
                            </div>
                            <h3>No Approved Students</h3>
                            <p>No approved students with complete documents found at this time.</p>
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
    const searchInput    = document.getElementById('searchInput');
    const mobilityFilter = document.getElementById('mobilityFilter');
    const countryFilter  = document.getElementById('countryFilter');
    const docsFilter     = document.getElementById('docsFilter');
    const resetBtn       = document.getElementById('resetFilters');
    const rows           = Array.from(document.querySelectorAll('#tableBody tr[data-search]'));
    const noMatchRow     = document.getElementById('noMatchRow');

    function applyFilters() {
        const keyword  = searchInput.value.toLowerCase().trim();
        const mobility = mobilityFilter.value;
        const country  = countryFilter.value;
        const docs     = docsFilter.value;

        let visible = 0;
        const perMobility = {};

        rows.forEach(function (row) {
            const matchText     = (row.dataset.search || '').includes(keyword);
            const matchMobility = !mobility || row.dataset.mobility === mobility;
            const matchCountry  = !country  || row.dataset.country  === country;
            const matchDocs     = !docs
                || (docs === 'complete'   && row.dataset.docs === 'complete')
                || (docs === 'incomplete' && row.dataset.docs !== 'complete');
            const show = matchText && matchMobility && matchCountry && matchDocs;

            row.style.display = show ? '' : 'none';
            if (show) {
                visible++;
                perMobility[row.dataset.mobility] = (perMobility[row.dataset.mobility] || 0) + 1;
            }
        });

        document.getElementById('count-total').textContent = visible;
        document.querySelectorAll('[data-count-mobility]').forEach(function (el) {
            el.textContent = perMobility[el.dataset.countMobility] || 0;
        });

        if (noMatchRow) noMatchRow.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
    }

    searchInput.addEventListener('input', applyFilters);
    mobilityFilter.addEventListener('change', applyFilters);
    countryFilter.addEventListener('change', applyFilters);
    docsFilter.addEventListener('change', applyFilters);
    resetBtn.addEventListener('click', function () {
        searchInput.value = '';
        mobilityFilter.value = '';
        countryFilter.value = '';
        docsFilter.value = '';
        applyFilters();
    });

    applyFilters();
})();
</script>