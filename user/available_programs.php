<?php
require_once __DIR__ . '/../config/program_eligibility.php';

if (!isset($pdo, $userId, $user) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=available_programs');
    exit;
}

$message = '';
$msgType = '';

/* Fetch available programs for the grid */
try {
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.program_name,
            p.description,
            p.start_date,
            p.end_date,
            country.country_name,
            EXISTS(
                SELECT 1
                FROM applications a
                WHERE a.applicant_id = ? AND a.program_id = p.id
            ) AS already_applied
        FROM programs p
        INNER JOIN users applicant ON applicant.id = ?
        LEFT JOIN degree_programs degree
            ON degree.campus_id = applicant.campus_id
           AND degree.program_name = applicant.program
        LEFT JOIN countries country ON country.id = p.country_id
        WHERE p.status IN ('Active', 'Upcoming')
        AND " . programApplicationWindowSql() . "
        AND " . programEligibilitySql() . "
        ORDER BY p.start_date, p.program_name
    ");

    $stmt->execute([$userId, $userId]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Error loading programs: ' . $e->getMessage());
    $programs = [];
    $message = 'Unable to load programs right now. Please try again later.';
    $msgType = 'error';
}
?>

<style>
    /* =====================================================
       AVAILABLE PROGRAMS SPECIFIC STYLES
       ===================================================== */
    .available-programs-page {
        padding: 32px;
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
    }

    .available-programs-page .page-header {
        margin-bottom: 32px;
        padding-bottom: 24px;
        border-bottom: 1px solid var(--border-light);
    }

    .available-programs-page .page-header h2 {
        margin: 0 0 8px;
        font-family: var(--font-heading);
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--psu-navy);
    }

    .available-programs-page .page-header p {
        color: var(--text-muted);
        font-size: 0.95rem;
    }

    /* Grid Layout */
    .program-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
        gap: 24px;
    }

    /* Program Card */
    .program-card {
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: var(--radius);
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 0;
        min-width: 0;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .program-card:hover {
        border-color: var(--psu-gold);
        box-shadow: var(--shadow-card);
        transform: translateY(-4px);
    }

    .program-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: var(--psu-gold);
        opacity: 0;
        transition: var(--transition);
    }
    .program-card:hover::before { opacity: 1; }

    .program-card-head { margin-bottom: 12px; }

    .program-card-head h3 {
        font-family: var(--font-heading);
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--psu-navy);
        margin: 0 0 8px;
        line-height: 1.4;
    }

    .country-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        background: var(--bg-main);
        border-radius: var(--radius-full);
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-muted);
    }
    .country-chip i { color: var(--psu-gold); font-size: 0.7rem; }

    .program-desc {
        flex-grow: 1;
        margin: 0 0 20px;
        color: var(--text-muted);
        font-size: 0.9rem;
        line-height: 1.6;
    }

    .program-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 20px;
        padding: 12px;
        background: var(--bg-main);
        border-radius: var(--radius-sm);
        font-size: 0.85rem;
        font-weight: 500;
        color: var(--psu-navy);
        line-height: 1.5;
    }
    .program-meta i { color: var(--psu-gold); flex-shrink: 0; }

    .program-card-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: auto;
    }

    /* Buttons */
    .btn-primary {
        width: 100%;
        justify-content: center;
        padding: 12px 16px;
        background: var(--psu-navy);
        color: var(--white);
        border-radius: var(--radius-sm);
        font-family: var(--font-heading);
        font-weight: 700;
        font-size: 0.9rem;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        border: 1px solid var(--psu-navy);
        cursor: pointer;
    }
    .btn-primary:hover {
        background: var(--psu-blue);
        border-color: var(--psu-blue);
        color: var(--white);
        box-shadow: 0 4px 12px rgba(11, 26, 48, 0.15);
    }

    .btn-secondary {
        padding: 10px 16px;
        background: var(--white);
        color: var(--psu-navy);
        border-radius: var(--radius-sm);
        border: 1px solid var(--border-light);
        font-weight: 600;
        font-size: 0.85rem;
        white-space: nowrap;
        transition: var(--transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .btn-secondary:hover {
        background: var(--bg-main);
        border-color: var(--psu-navy);
    }

    .badge-success {
        display: inline-flex;
        align-items: center;
        padding: 10px 14px;
        background: #ecfdf5;
        color: #047857;
        font-weight: 600;
        font-size: 0.85rem;
        border-radius: var(--radius-sm);
        border: 1px solid #a7f3d0;
        white-space: nowrap;
    }

    /* Alerts */
    .program-alert {
        margin-bottom: 24px;
        padding: 16px 20px;
        border: 1px solid;
        border-radius: var(--radius-sm);
        font-size: 0.9rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .program-alert.success { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
    .program-alert.warning { color: #92400e; background: #fffbeb; border-color: #fde68a; }
    .program-alert.error { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }

    /* Empty State */
    .program-empty {
        padding: 60px 20px;
        border: 2px dashed var(--border-light);
        border-radius: var(--radius-lg);
        text-align: center;
        background: var(--bg-main);
    }
    .program-empty h3 {
        margin: 0 0 8px;
        font-family: var(--font-heading);
        font-size: 1.1rem;
        color: var(--psu-navy);
    }
    .program-empty p { color: var(--text-muted); font-size: 0.95rem; }

    /* =====================================================
       MODAL STYLES
       ===================================================== */
    .modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(11, 26, 48, 0.6);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        opacity: 0;
        visibility: hidden;
        transition: var(--transition);
    }
    .modal-overlay.active { opacity: 1; visibility: visible; }
    
    .modal-container {
        width: 100%;
        max-width: 640px;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        background: var(--white);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        transform: translateY(20px);
        transition: var(--transition);
        overflow: hidden;
    }
    .modal-overlay.active .modal-container { transform: translateY(0); }
    
    .modal-header {
        padding: 24px 32px;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        background: var(--white);
    }
    .modal-header h3 {
        font-family: var(--font-heading);
        font-size: 1.35rem;
        font-weight: 800;
        color: var(--psu-navy);
        margin-bottom: 6px;
    }
    .modal-header p {
        color: var(--text-muted);
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 500;
    }
    .modal-header p i { color: var(--psu-gold); }

    .modal-close {
        flex: 0 0 36px;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-main);
        border: 1px solid var(--border-light);
        border-radius: 50%;
        color: var(--text-muted);
        cursor: pointer;
        transition: var(--transition);
    }
    .modal-close:hover {
        background: var(--border-light);
        color: var(--psu-navy);
        transform: rotate(90deg);
    }

    .modal-body {
        padding: 32px;
        overflow-y: auto;
        flex: 1;
        background: var(--white);
    }
    .modal-section-title {
        margin-bottom: 16px;
        color: var(--psu-navy);
        font-size: 0.8rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .modal-desc {
        margin-bottom: 32px;
        color: var(--text-muted);
        font-size: 0.95rem;
        line-height: 1.7;
    }
    
    .modal-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 32px;
    }
    .modal-info-item {
        padding: 16px;
        background: var(--bg-main);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-sm);
    }
    .modal-info-item small {
        display: block;
        margin-bottom: 6px;
        color: var(--text-light);
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .modal-info-item strong {
        display: block;
        color: var(--psu-navy);
        font-size: 0.95rem;
        font-family: var(--font-heading);
    }

    /* ===== REVAMPED REQUIREMENTS CHECKLIST ===== */
    .modal-req-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .modal-req-item {
        display: flex;
        align-items: flex-start;
        gap: 16px;
        padding: 16px 20px;
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-sm);
        transition: var(--transition);
    }
    
    .modal-req-item:hover {
        border-color: var(--psu-gold);
        background: #fcfdfd;
        box-shadow: var(--shadow-sm);
    }
    
    .req-checkbox {
        flex: 0 0 24px;
        width: 24px;
        height: 24px;
        background: var(--bg-main);
        border: 1px solid var(--border-light);
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-light);
        transition: var(--transition);
    }
    .modal-req-item:hover .req-checkbox {
        background: rgba(228, 177, 52, 0.1);
        border-color: var(--psu-gold);
        color: var(--psu-navy);
    }
    
    .req-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .req-content strong {
        font-family: var(--font-heading);
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--psu-navy);
    }
    
    .req-content small {
        font-size: 0.85rem;
        color: var(--text-muted);
        line-height: 1.5;
    }

    .modal-footer {
        padding: 20px 32px;
        border-top: 1px solid var(--border-light);
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 12px;
        background: var(--bg-main);
    }
    
    .btn-cancel {
        padding: 12px 20px;
        border-radius: var(--radius-sm);
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--text-muted);
        background: var(--white);
        border: 1px solid var(--border-light);
        cursor: pointer;
        transition: var(--transition);
    }
    .btn-cancel:hover { 
        background: var(--border-light); 
        color: var(--psu-navy);
    }
    
    .btn-apply-modal {
        padding: 12px 24px;
        border-radius: var(--radius-sm);
        font-weight: 700;
        font-size: 0.9rem;
        color: var(--white);
        background: var(--psu-navy);
        border: 1px solid var(--psu-navy);
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    .btn-apply-modal:hover {
        background: var(--psu-blue);
        border-color: var(--psu-blue);
        box-shadow: 0 4px 12px rgba(11, 26, 48, 0.15);
    }

    .modal-loader {
        display: flex;
        justify-content: center;
        padding: 60px 40px;
    }
    .spinner {
        width: 32px;
        height: 32px;
        border: 3px solid var(--border-light);
        border-top-color: var(--psu-gold);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    @media (max-width: 640px) {
        .available-programs-page { padding: 24px; }
        .program-grid { grid-template-columns: 1fr; }
        .modal-info-grid { grid-template-columns: 1fr; }
        .modal-header, .modal-body { padding: 24px; }
        .modal-footer { 
            padding: 16px 24px; 
            flex-direction: column-reverse;
        }
        .modal-footer button, .modal-footer a { width: 100%; justify-content: center; }
    }
</style>

<div class="content-card available-programs-page">
    <div class="page-header">
        <div>
            <h2>Available Programs</h2>
            <p>Browse programs you can apply to. Click a program to see requirements and apply.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="program-alert <?= h($msgType) ?>" role="<?= $msgType === 'success' ? 'status' : 'alert' ?>">
            <i class="fa-solid <?= $msgType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($programs)): ?>
        <div class="program-empty">
            <h3>No programs available</h3>
            <p>No programs are open for application right now. Please check back later.</p>
        </div>
    <?php else: ?>
    <div class="program-grid">
        <?php foreach ($programs as $p): ?>
        <div class="program-card">
            <div class="program-card-head">
                <h3><?= h($p['program_name']) ?></h3>
                <?php if (!empty($p['country_name'])): ?>
                    <span class="country-chip"><i class="fa-solid fa-location-dot"></i> <?= h($p['country_name']) ?></span>
                <?php endif; ?>
            </div>
            
            <p class="program-desc"><?= h(mb_strimwidth((string)$p['description'], 0, 140, '…')) ?></p>
            
            <div class="program-meta">
                <i class="fa-regular fa-calendar"></i>
                <?php if (!empty($p['start_date']) && !empty($p['end_date'])): ?>
                    <span>
                        <?= h(date('M d, Y', strtotime($p['start_date']))) ?>
                        – <?= h(date('M d, Y', strtotime($p['end_date']))) ?>
                    </span>
                <?php else: ?>
                    <span>Dates TBA</span>
                <?php endif; ?>
            </div>
            
            <div class="program-card-actions">
                <?php if ($p['already_applied']): ?>
                    <span class="badge-success" style="flex: 1;"><i class="fa-solid fa-check" style="margin-right: 6px;"></i> Already Applied</span>
                    <a class="btn-secondary" href="?page=my_applications">View Status</a>
                <?php else: ?>
                    <button type="button" class="btn-primary" onclick="openProgramModal(<?= (int)$p['id'] ?>)">
                        View Details & Apply
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- The Modal -->
<div class="modal-overlay" id="programModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-container">
        <div class="modal-header">
            <div>
                <h3 id="modalTitle">Program Title</h3>
                <p id="modalLocation"><i class="fa-solid fa-location-dot"></i> Loading...</p>
            </div>
            <button type="button" class="modal-close" aria-label="Close program details" onclick="closeProgramModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <div class="modal-body" id="modalBody">
            <!-- Dynamic Content Loaded Here -->
        </div>
        
        <div class="modal-footer" id="modalFooter" style="display: none;">
            <button type="button" class="btn-cancel" onclick="closeProgramModal()">Cancel</button>
            <a class="btn-apply-modal" id="applyLink" href="#">
                Apply Now <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<script>
    const modal = document.getElementById('programModal');
    const modalBody = document.getElementById('modalBody');
    const modalFooter = document.getElementById('modalFooter');
    const modalTitle = document.getElementById('modalTitle');
    const modalLocation = document.getElementById('modalLocation');
    const applyLink = document.getElementById('applyLink');

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[character]);
    }

    async function openProgramModal(id) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden'; 
        
        modalTitle.textContent = "Loading...";
        modalLocation.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Please wait';
        modalBody.innerHTML = '<div class="modal-loader"><div class="spinner"></div></div>';
        modalFooter.style.display = 'none';

        try {
            const response = await fetch(`get_program_ajax.php?id=${id}`);
            if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
            const result = await response.json();

            if (result.success) {
                const data = result.data;
                applyLink.href = `?page=apply&program_id=${encodeURIComponent(data.id)}`;
                
                modalTitle.textContent = data.program_name;
                modalLocation.innerHTML = `<i class="fa-solid fa-location-dot"></i> ${escapeHtml(data.country_name || 'Location TBA')}`;
                
                const start = data.start_date ? new Date(data.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'TBA';
                const end = data.end_date ? new Date(data.end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'TBA';

                let reqHtml = '';
                if (data.requirements && data.requirements.length > 0) {
                    reqHtml = data.requirements.map(r => `
                        <li class="modal-req-item">
                            <div class="req-checkbox"><i class="fa-solid fa-check"></i></div>
                            <div class="req-content">
                                <strong>${escapeHtml(r.requirement_name)}</strong>
                                <small>${escapeHtml(r.description || 'No additional details provided.')}</small>
                            </div>
                        </li>
                    `).join('');
                } else {
                    reqHtml = '<li class="modal-req-item" style="justify-content: center; color: var(--text-light); font-style: italic;">No specific requirements listed.</li>';
                }

                modalBody.innerHTML = `
                    <div class="modal-section-title">Program Overview</div>
                    <p class="modal-desc">${escapeHtml(data.description || 'No description provided.')}</p>
                    
                    <div class="modal-info-grid">
                        <div class="modal-info-item">
                            <small>Start Date</small>
                            <strong>${start}</strong>
                        </div>
                        <div class="modal-info-item">
                            <small>End Date</small>
                            <strong>${end}</strong>
                        </div>
                        <div class="modal-info-item" style="grid-column: 1 / -1;">
                            <small>Partner Institution</small>
                            <strong>${escapeHtml(data.partner_name || 'Not specified')}</strong>
                        </div>
                    </div>

                    <div class="modal-section-title" style="margin-top: 32px;">Requirements Checklist</div>
                    <ul class="modal-req-list">
                        ${reqHtml}
                    </ul>
                `;

                modalFooter.style.display = 'flex';

            } else {
                modalBody.innerHTML = `<p style="color: #dc2626; text-align: center; font-weight: 600; padding: 32px;">${escapeHtml(result.message)}</p>`;
            }
        } catch (error) {
            console.error("Fetch Error:", error);
            modalBody.innerHTML = `<p style="color: #dc2626; text-align: center; font-weight: 600; padding: 32px;">${escapeHtml(error.message)}</p>`;
        }
    }

    function closeProgramModal() {
        modal.classList.remove('active');
        document.body.style.overflow = ''; 
    }

    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeProgramModal();
        }
    });
</script>