<?php
if (!isset($pdo, $userId) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=mobility_schedule');
    exit;
}

/* Schema-safe query */
$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.status,
        p.program_name,
        p.start_date,
        p.end_date,
        partner.institution_name AS partner_institution,
        c.country_name
    FROM applications a
    LEFT JOIN programs p ON p.id = a.program_id
    LEFT JOIN partners partner ON partner.id = p.partner_id
    LEFT JOIN countries c ON c.id = p.country_id
    WHERE a.applicant_id = ?
    ORDER BY p.start_date ASC, a.created_at DESC
");
$stmt->execute([$userId]);
$scheduleItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    /* =====================================================
       MOBILITY SCHEDULE (TIMELINE) STYLES
       ===================================================== */
    .timeline {
        position: relative;
        padding: 20px 0;
        margin-top: 32px;
    }

    /* The vertical line */
    .timeline::before {
        content: '';
        position: absolute;
        top: 0;
        bottom: 0;
        left: 20px;
        width: 2px;
        background: var(--border-light);
        border-radius: 2px;
    }

    .timeline-item {
        position: relative;
        display: flex;
        gap: 24px;
        margin-bottom: 32px;
        padding-left: 60px; /* Space for the dot */
    }

    .timeline-item:last-child {
        margin-bottom: 0;
    }

    /* The dot on the timeline */
    .timeline-dot {
        position: absolute;
        left: 10px; /* Centers the 22px dot on the 2px line at left:20px */
        top: 4px;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: var(--white);
        border: 4px solid var(--border-light);
        z-index: 2;
        transition: var(--transition);
    }

    /* Colors for different statuses */
    .timeline-item.approved .timeline-dot { 
        border-color: #047857; 
        background: #ecfdf5; 
        box-shadow: 0 0 0 4px rgba(4, 120, 87, 0.1);
    }
    .timeline-item.pending .timeline-dot { 
        border-color: var(--psu-gold); 
        background: #fffbeb; 
        box-shadow: 0 0 0 4px rgba(228, 177, 52, 0.1);
    }
    .timeline-item.rejected .timeline-dot { 
        border-color: #b91c1c; 
        background: #fef2f2; 
        box-shadow: 0 0 0 4px rgba(185, 28, 28, 0.1);
    }

    /* The Card */
    .timeline-card {
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-lg);
        padding: 24px 32px;
        flex: 1;
        transition: var(--transition);
        box-shadow: var(--shadow-sm);
    }

    .timeline-card:hover {
        border-color: var(--psu-gold);
        box-shadow: var(--shadow-card);
        transform: translateY(-2px);
    }

    /* Card Header */
    .timeline-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
    }

    .timeline-title {
        font-family: var(--font-heading);
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--psu-navy);
        margin-bottom: 6px;
    }

    .timeline-location {
        font-size: 0.9rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 500;
    }
    
    .timeline-location i {
        color: var(--psu-gold);
    }

    /* Date Badge */
    .date-badge {
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        background: var(--bg-main);
        padding: 12px 20px;
        border-radius: var(--radius);
        border: 1px solid var(--border-light);
        text-align: center;
        min-width: 140px;
    }

    .date-badge strong {
        font-family: var(--font-heading);
        font-size: 1rem;
        color: var(--psu-navy);
        font-weight: 700;
    }
    
    .date-badge span {
        font-size: 0.8rem;
        color: var(--text-light);
        font-weight: 600;
        margin-top: 4px;
    }

    /* Status Badge */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: var(--radius-full);
        font-size: 0.75rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .status-approved { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .status-pending { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
    .status-rejected { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

    .btn-view-details {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--psu-navy);
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: var(--transition);
    }
    .btn-view-details:hover {
        color: var(--psu-gold);
        transform: translateX(4px);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 24px;
        background: var(--bg-main);
        border-radius: var(--radius-lg);
        border: 2px dashed var(--border-light);
        margin-top: 24px;
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

    /* Responsive */
    @media (max-width: 768px) {
        .timeline-header {
            flex-direction: column;
            gap: 16px;
        }
        .date-badge {
            width: 100%;
            flex-direction: row;
            justify-content: center;
            gap: 8px;
        }
        .timeline-card {
            padding: 20px;
        }
    }
</style>

<section class="content-card">
    <div class="page-header">
        <div>
            <h2>Mobility Schedule</h2>
            <p>Review the dates, locations, and progress of your international programs.</p>
        </div>
    </div>

    <?php if (!$scheduleItems): ?>
        <!-- Improved Empty State -->
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fa-regular fa-calendar-xmark"></i>
            </div>
            <h4 style="font-size: 1.25rem; font-family: var(--font-heading); color: var(--psu-navy); margin-bottom: 8px;">No schedule items yet</h4>
            <p style="color: var(--text-muted); font-size: 0.95rem; max-width: 400px; margin: 0 auto 24px;">
                Your mobility program dates will automatically appear here once you have applied and been assigned to a program.
            </p>
            <a href="dashboard.php?page=available_programs" class="btn-gold" style="display: inline-flex; padding: 12px 24px; background: var(--psu-gold); color: var(--psu-navy); border-radius: var(--radius-sm); font-family: var(--font-heading); font-weight: 700; text-decoration: none; transition: var(--transition);">
                <i class="fa-solid fa-globe" style="margin-right: 8px;"></i> Browse Programs
            </a>
        </div>
    <?php else: ?>
        <div class="timeline">
            <?php foreach ($scheduleItems as $item): 
                $status = strtolower((string)($item['status'] ?? 'pending'));
                $statusClass = match($status) {
                    'approved' => 'status-approved',
                    'rejected' => 'status-rejected',
                    default => 'status-pending'
                };
            ?>
                <div class="timeline-item <?= $status ?>">
                    <div class="timeline-dot"></div>
                    
                    <div class="timeline-card">
                        <div class="timeline-header">
                            <div>
                                <div class="timeline-title"><?= h($item['program_name'] ?? 'Program unavailable') ?></div>
                                <div class="timeline-location">
                                    <i class="fa-solid fa-location-dot"></i>
                                    <?= h($item['partner_institution'] ?? 'Partner not specified') ?>
                                    <?php if (!empty($item['country_name'])): ?>
                                        &bull; <?= h($item['country_name']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="date-badge">
                                <?php if (!empty($item['start_date'])): ?>
                                    <strong><?= h(date('M j, Y', strtotime($item['start_date']))) ?></strong>
                                    <?php if (!empty($item['end_date'])): ?>
                                        <span>to <?= h(date('M j, Y', strtotime($item['end_date']))) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <strong>Dates Pending</strong>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-light); padding-top: 20px;">
                            <span class="status-badge <?= $statusClass ?>">
                                <i class="fa-solid <?= $status === 'approved' ? 'fa-check-circle' : ($status === 'rejected' ? 'fa-times-circle' : 'fa-clock') ?>"></i>
                                <?= h(ucwords(str_replace('_', ' ', $status))) ?>
                            </span>
                            
                            <a href="?page=my_application_detail&id=<?= (int)$item['id'] ?>" class="btn-view-details">
                                View Details <i class="fa-solid fa-arrow-right" style="font-size: 0.8rem;"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>