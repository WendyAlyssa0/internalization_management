<?php
if (!isset($pdo, $userId) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=my_applications');
    exit;
}

/* Safely get the page variable to prevent undefined errors */
$currentPage = $page ?? 'my_applications';

/* Schema-safe query */
$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.program_id,
        a.status,
        a.created_at,
        p.program_name,
        p.start_date,
        p.end_date,
        p.partner_id,
        p.country_id,
        partner.institution_name AS partner_institution,
        country.country_name AS country_name
    FROM applications a
    LEFT JOIN programs p ON p.id = a.program_id
    LEFT JOIN partners partner ON partner.id = p.partner_id
    LEFT JOIN countries country ON country.id = p.country_id
    WHERE a.applicant_id = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$userId]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    /* =====================================================
       APPLICATIONS LIST STYLES
       ===================================================== */
    .app-list {
        display: flex;
        flex-direction: column;
        gap: 20px;
        margin-top: 24px;
    }

    .app-card {
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: var(--radius);
        padding: 24px 32px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .app-card:hover {
        border-color: var(--psu-gold);
        box-shadow: var(--shadow-card);
        transform: translateY(-2px);
    }

    /* Top accent line on hover */
    .app-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--psu-gold);
        opacity: 0;
        transition: var(--transition);
    }
    .app-card:hover::before {
        opacity: 1;
    }

    .app-info {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .app-title {
        font-family: var(--font-heading);
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--psu-navy);
    }

    .app-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        font-size: 0.85rem;
        color: var(--text-muted);
        font-weight: 500;
    }

    .app-meta span {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--bg-main);
        padding: 6px 12px;
        border-radius: var(--radius-sm);
    }

    .app-meta i {
        color: var(--psu-navy);
        opacity: 0.7;
    }

    .app-status {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-shrink: 0;
    }

    /* Status Badges */
    .status-badge {
        padding: 8px 16px;
        border-radius: var(--radius-full);
        font-size: 0.75rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .badge-pending {
        background: #fffbeb;
        color: #92400e;
        border: 1px solid #fde68a;
    }
    
    .badge-approved {
        background: #ecfdf5;
        color: #047857;
        border: 1px solid #a7f3d0;
    }
    
    .badge-rejected {
        background: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }

    .btn-view {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        background: var(--bg-main);
        color: var(--psu-navy);
        border-radius: var(--radius-sm);
        font-family: var(--font-heading);
        font-weight: 700;
        font-size: 0.85rem;
        transition: var(--transition);
        text-decoration: none;
    }

    .btn-view:hover {
        background: var(--psu-navy);
        color: var(--white);
        box-shadow: 0 4px 12px rgba(11, 26, 48, 0.15);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 24px;
        background: var(--bg-main);
        border-radius: var(--radius-lg);
        border: 2px dashed var(--border-light);
    }

    .empty-icon {
        width: 72px;
        height: 72px;
        background: var(--white);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        box-shadow: var(--shadow-sm);
        color: var(--psu-navy);
        font-size: 1.75rem;
    }

    .empty-state h4 {
        font-family: var(--font-heading);
        font-size: 1.25rem;
        color: var(--psu-navy);
        margin-bottom: 8px;
    }

    .empty-state p {
        color: var(--text-muted);
        font-size: 0.95rem;
        max-width: 400px;
        margin: 0 auto 24px;
    }

    .btn-gold {
        display: inline-flex;
        padding: 12px 24px;
        background: var(--psu-gold);
        color: var(--psu-navy);
        border-radius: var(--radius-sm);
        font-family: var(--font-heading);
        font-weight: 700;
        transition: var(--transition);
        text-decoration: none;
    }
    .btn-gold:hover {
        background: var(--psu-gold-light);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(228, 177, 52, 0.3);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .app-card {
            flex-direction: column;
            align-items: flex-start;
            gap: 24px;
            padding: 24px;
        }
        .app-status {
            width: 100%;
            justify-content: space-between;
            padding-top: 20px;
            border-top: 1px solid var(--border-light);
        }
    }
</style>

<section class="content-card" aria-labelledby="applications-title">
    <div class="page-header">
        <div>
            <h2 id="applications-title"><?= $currentPage === 'application_status' ? 'Application Status' : 'My Applications' ?></h2>
            <p>Track the status of your mobility program applications.</p>
        </div>
    </div>

    <?php if (!$applications): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fa-regular fa-file-lines"></i>
            </div>
            <h4>No applications yet</h4>
            <p>You haven't applied for any programs yet. Browse our available programs to get started!</p>
            <a href="?page=available_programs" class="btn-gold">
                Browse Programs
            </a>
        </div>
    <?php else: ?>
        <div class="app-list">
            <?php foreach ($applications as $application):
                $status = strtolower((string)($application['status'] ?? 'submitted'));
                $badgeClass = match ($status) {
                    'approved', 'completed' => 'badge-approved',
                    'rejected' => 'badge-rejected',
                    default => 'badge-pending',
                };

                $partnerInst = $application['partner_institution'] ?? null;
                $countryName = $application['country_name'] ?? null;
            ?>
                <div class="app-card">
                    <div class="app-info">
                        <div class="app-title"><?= h($application['program_name'] ?? 'Program unavailable') ?></div>
                        
                        <div class="app-meta">
                            <?php if ($partnerInst): ?>
                                <span><i class="fa-solid fa-building-columns"></i> <?= h($partnerInst) ?></span>
                            <?php endif; ?>
                            
                            <?php if ($countryName): ?>
                                <span><i class="fa-solid fa-location-dot"></i> <?= h($countryName) ?></span>
                            <?php endif; ?>
                            
                            <?php if (!empty($application['start_date']) && !empty($application['end_date'])): ?>
                                <span>
                                    <i class="fa-regular fa-calendar"></i>
                                    <?= h(date('M j, Y', strtotime($application['start_date']))) ?> – <?= h(date('M j, Y', strtotime($application['end_date']))) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="app-status">
                        <span class="status-badge <?= $badgeClass ?>">
                            <?= h(ucwords(str_replace('_', ' ', $status))) ?>
                        </span>
                        
                        <a class="btn-view" href="?page=my_application_detail&id=<?= (int)$application['id'] ?>">
                            View Details <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem;"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>