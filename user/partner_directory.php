<?php
if (!isset($pdo, $userId) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=partner_directory');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.institution_name,
        c.country_name,
        COUNT(DISTINCT pr.id) AS available_program_count
    FROM partners p
    INNER JOIN users applicant ON applicant.id = ?
    LEFT JOIN countries c ON c.id = p.country_id
    LEFT JOIN programs pr
        ON pr.partner_id = p.id
        AND pr.status IN ('Active', 'Upcoming')
        AND pr.end_date >= CURDATE()
        AND pr.eligible_account_type = applicant.account_type
        AND (pr.eligible_department = 'All Departments' OR pr.eligible_department = applicant.department)
    WHERE p.status = 'Active'
    GROUP BY p.id, p.institution_name, c.country_name
    ORDER BY p.institution_name
");
$stmt->execute([$userId]);
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="content-card">
    <div class="page-header">
        <div>
            <h2>Partner Directory</h2>
            <p>Explore the institutions in our active exchange network.</p>
        </div>
    </div>

    <?php if (!$partners): ?>
        <p class="muted">No partner institutions are available right now.</p>
    <?php else: ?>
        <div class="partner-directory-grid">
            <?php foreach ($partners as $partner): ?>
                <article class="partner-directory-card">
                    <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                    <div>
                        <h3><?= h($partner['institution_name']) ?></h3>
                        <p><?= h($partner['country_name'] ?? 'Country not specified') ?></p>
                        <small>
                            <?= (int)$partner['available_program_count'] ?>
                            available <?= (int)$partner['available_program_count'] === 1 ? 'program' : 'programs' ?>
                        </small>
                    </div>
                    <a href="dashboard.php?page=available_programs" class="btn-secondary">Browse Programs</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
