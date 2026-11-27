
<?php

if (!isset($pdo, $userId, $user) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=my_applications');
    exit;
}

$appId = (int) ($_GET['id'] ?? 0);

if ($appId <= 0) {
    header('Location: ?page=my_applications');
    exit;
}

try {

    /* =====================================================
       FETCH APPLICATION DETAILS
    ===================================================== */

    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.program_id,
            a.status,
            a.created_at,
            p.program_name,
            p.start_date,
            p.end_date,
            partner.institution_name AS partner_institution,
            country.country_name AS country_name
        FROM applications a
        LEFT JOIN programs p
            ON p.id = a.program_id
        LEFT JOIN partners partner
            ON partner.id = p.partner_id
        LEFT JOIN countries country
            ON country.id = p.country_id
        WHERE a.id = ?
          AND a.applicant_id = ?
        LIMIT 1
    ");

    $stmt->execute([$appId, $userId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    /* =====================================================
       CHECK IF APPLICATION EXISTS
    ===================================================== */

    if (!$app) {
        echo '<div class="content-card"><p>Application not found.</p></div>';
        return;
    }

    /* =====================================================
       FETCH PROGRAM REQUIREMENTS
       Requirements assigned by the Admin
    ===================================================== */

    $reqStmt = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.requirement_name,
            r.description,
            pr.is_required,
            pr.requirement_file
        FROM program_requirements pr
        INNER JOIN requirements r
            ON r.id = pr.requirement_id
        WHERE pr.program_id = ?
        ORDER BY
            pr.is_required DESC,
            r.requirement_name ASC
    ");

    $reqStmt->execute([$app['program_id']]);
    $requirements = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log('Application detail error: ' . $e->getMessage());

    echo '<div class="content-card">
            <p>Unable to load application details. Please try again later.</p>
          </div>';

    return;
}

/* =====================================================
   APPLICATION STATUS
===================================================== */

$status = strtolower((string) $app['status']);

$badgeClass = match ($status) {
    'approved', 'completed' => 'badge-approved',
    'rejected' => 'badge-rejected',
    default => 'badge-pending',
};

?>

<style>

/* =====================================================
   APPLICATION DETAIL
===================================================== */

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 32px;
}

.detail-item {
    background: var(--bg-main);
    padding: 20px 24px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-light);
    transition: var(--transition);
}

.detail-item:hover {
    border-color: var(--psu-gold);
    box-shadow: var(--shadow-sm);
}

.detail-item label {
    display: block;
    font-family: var(--font-heading);
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-light);
    margin-bottom: 8px;
}

.detail-item strong {
    display: block;
    font-size: 1rem;
    color: var(--psu-navy);
    font-weight: 600;
    word-break: break-word;
}

/* =====================================================
   STATUS BADGES
===================================================== */

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--radius-full);
    font-size: 0.8rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.badge-approved {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}

.badge-pending {
    background: #fffbeb;
    color: #92400e;
    border: 1px solid #fde68a;
}

.badge-rejected {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

/* =====================================================
   BACK LINK
===================================================== */

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: var(--text-muted);
    font-weight: 600;
    margin-bottom: 16px;
    text-decoration: none;
    transition: var(--transition);
}

.back-link:hover {
    color: var(--psu-navy);
    transform: translateX(-4px);
}

/* =====================================================
   PROGRAM REQUIREMENTS
===================================================== */

.requirements-section {
    margin-top: 24px;
    padding: 28px;
    background: #fff;
    border: 1px solid var(--border-light);
    border-radius: var(--radius-sm);
}

.requirements-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.requirements-header h3 {
    color: var(--psu-navy);
    font-size: 1.1rem;
    margin: 0 0 6px;
}

.requirements-header p {
    color: var(--text-muted);
    font-size: 0.88rem;
    margin: 0;
}

.requirements-count {
    background: #f1f5f9;
    color: var(--psu-navy);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}

/* Requirement list */

.requirements-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.requirement-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 18px 20px;
    background: var(--bg-main);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    transition: var(--transition);
}

.requirement-item:hover {
    border-color: var(--psu-gold);
}

.requirement-content {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    min-width: 0;
}

.requirement-icon {
    width: 40px;
    height: 40px;
    min-width: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eaf0f8;
    color: var(--psu-navy);
    border-radius: 9px;
    font-size: 1rem;
}

.requirement-info {
    min-width: 0;
}

.requirement-info strong {
    display: block;
    color: var(--psu-navy);
    font-size: 0.95rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.requirement-info p {
    color: var(--text-muted);
    font-size: 0.85rem;
    line-height: 1.5;
    margin: 0;
}

/* Required / Optional labels */

.requirement-type {
    flex-shrink: 0;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
}

.requirement-type.required {
    background: #fff7df;
    color: #946c00;
}

.requirement-type.optional {
    background: #eff6ff;
    color: #1d4ed8;
}

/* Empty state */

.requirements-empty {
    padding: 30px 20px;
    text-align: center;
    background: var(--bg-main);
    border: 1px dashed var(--border-light);
    border-radius: 10px;
}

.requirements-empty i {
    font-size: 1.8rem;
    color: var(--text-light);
    margin-bottom: 10px;
}

.requirements-empty p {
    color: var(--text-muted);
    font-size: 0.88rem;
    margin: 0;
}

/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 600px) {

    .detail-grid {
        grid-template-columns: 1fr;
    }

    .requirements-section {
        padding: 20px;
    }

    .requirement-item {
        align-items: flex-start;
        flex-direction: column;
    }

    .requirement-type {
        margin-left: 54px;
    }

}

</style>


<!-- =====================================================
     APPLICATION DETAILS
===================================================== -->

<section class="content-card" id="application-details" aria-labelledby="application-title">

    <div class="page-header"
         style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">

        <div>

            <a href="?page=my_applications" class="back-link">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Applications
            </a>

            <h2 id="application-title" style="margin-bottom:12px;">
                <?= h($app['program_name'] ?? 'Application details') ?>
            </h2>

            <div>
                Status:

                <span class="status-badge <?= h($badgeClass) ?>"
                      style="margin-left:8px;">

                    <?= h(ucwords(str_replace('_', ' ', $status))) ?>

                </span>
            </div>

        </div>

    </div>


    <!-- APPLICATION INFORMATION -->

    <div class="detail-grid">

        <!-- Applicant Info -->

        <div class="detail-item">

            <label>Applicant Name</label>

            <strong>
                <?= h(trim(
                    ($user['first_name'] ?? '') . ' ' .
                    ($user['last_name'] ?? '')
                )) ?>
            </strong>

        </div>


        <div class="detail-item">

            <label>Email Address</label>

            <strong>
                <?= h($user['email'] ?? '—') ?>
            </strong>

        </div>


        <!-- Program Info -->

        <div class="detail-item">

            <label>Partner Institution</label>

            <strong>
                <?= h($app['partner_institution'] ?? 'Not specified') ?>
            </strong>

        </div>


        <div class="detail-item">

            <label>Host Country</label>

            <strong>
                <?= h($app['country_name'] ?? 'Not specified') ?>
            </strong>

        </div>


        <!-- Program Dates -->

        <div class="detail-item">

            <label>Program Start</label>

            <strong>
                <?= !empty($app['start_date'])
                    ? h(date('M d, Y', strtotime($app['start_date'])))
                    : 'TBA' ?>
            </strong>

        </div>


        <div class="detail-item">

            <label>Program End</label>

            <strong>
                <?= !empty($app['end_date'])
                    ? h(date('M d, Y', strtotime($app['end_date'])))
                    : 'TBA' ?>
            </strong>

        </div>


        <div class="detail-item">

            <label>Date Applied</label>

            <strong>
                <?= !empty($app['created_at'])
                    ? h(date('M d, Y', strtotime($app['created_at'])))
                    : '—' ?>
            </strong>

        </div>

    </div>

</section>


<!-- =====================================================
     PROGRAM REQUIREMENTS
===================================================== -->

<section class="requirements-section" id="application-requirements">

    <div class="requirements-header">

        <div>

            <h3>
                <i class="fa-solid fa-clipboard-list"></i>
                Program Requirements
            </h3>

            <p>
                Review the documents and requirements assigned to this program.
            </p>

        </div>

        <span class="requirements-count">
            <?= count($requirements) ?>
            <?= count($requirements) === 1 ? 'Requirement' : 'Requirements' ?>
        </span>

    </div>


    <?php if (empty($requirements)): ?>

        <div class="requirements-empty">

            <i class="fa-regular fa-folder-open"></i>

            <p>
                No requirements have been assigned to this program yet.
            </p>

        </div>

    <?php else: ?>

        <div class="requirements-list">

            <?php foreach ($requirements as $requirement): ?>

                <div class="requirement-item">

                    <div class="requirement-content">

                        <div class="requirement-icon">
                            <i class="fa-regular fa-file-lines"></i>
                        </div>

                        <div class="requirement-info">

                            <strong>
                                <?= h($requirement['requirement_name']) ?>
                            </strong>

                            <?php if (!empty($requirement['description'])): ?>

                                <p>
                                    <?= h($requirement['description']) ?>
                                </p>

                            <?php endif; ?>

                        </div>

                    </div>


                    <span class="requirement-type <?= (int)$requirement['is_required'] === 1 ? 'required' : 'optional' ?>">

                        <?= (int)$requirement['is_required'] === 1
                            ? 'Required'
                            : 'Optional' ?>

                    </span>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>
