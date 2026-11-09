/* SEARCH (DEBOUNCED) */
let searchTimer;
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => document.getElementById('filterForm').submit(), 400);
    });
}

/* VIEW MODAL */
function openModal(data) {
    try {
        if (!data) return;
        data = (typeof data === "string") ? JSON.parse(data) : data;

        const fmt = (val) => (val !== null && val !== undefined && val !== '') ? val : '—';
        const formatDate = (val) => {
            if (!val) return '—';
            return new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        };
        const statusHtml = (status) => {
            if (!status) return '—';
            const cls = status.toLowerCase().replace(/\s+/g, '-');
            const label = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            return `<span class="status-badge status-${cls}">${label}</span>`;
        };

        document.getElementById('m-id').textContent          = '#' + fmt(data.id);
        document.getElementById('m-name').textContent        = fmt(data.applicant_name);
        document.getElementById('m-email').textContent       = fmt(data.applicant_email);
        document.getElementById('m-program').textContent     = fmt(data.program);
        document.getElementById('m-department').textContent  = fmt(data.program_type);
        document.getElementById('m-mobility').textContent    = fmt(data.mobility_type);
        document.getElementById('m-institution').textContent = fmt(data.institution);
        document.getElementById('m-country').textContent     = fmt(data.country);
        document.getElementById('m-status').innerHTML        = statusHtml(data.status);
        document.getElementById('m-docs-status').innerHTML   = statusHtml(data.documents_status);
        document.getElementById('m-created').textContent     = formatDate(data.created_at);
        document.getElementById('m-reviewed').textContent    = formatDate(data.reviewed_at);

        document.getElementById('appModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    } catch (err) {
        console.error('Modal error:', err);
    }
}
function closeModal() {
    document.getElementById('appModal').classList.remove('open');
    document.body.style.overflow = '';
}
function closeModalOutside(e) {
    if (e.target.id === 'appModal') closeModal();
}

/* CONFIRM ACTION */
let pendingAction = null;
let pendingId = null;
const actionConfig = {
    approve: {
        title: "Approve Application",
        message: "Are you sure you want to approve this application? The applicant will be notified.",
        icon: '<i class="fa fa-check-circle" style="color:#16a34a;font-size:2.2rem;"></i>',
        btnClass: "btn-confirm--approve",
        btnLabel: "Yes, Approve"
    },
    reject: {
        title: "Reject Application",
        message: "Are you sure you want to reject this application? The applicant will be notified.",
        icon: '<i class="fa fa-times-circle" style="color:#dc2626;font-size:2.2rem;"></i>',
        btnClass: "btn-confirm--reject",
        btnLabel: "Yes, Reject"
    },
    delete: {
        title: "Delete Application",
        message: "This action cannot be undone. The application will be permanently removed.",
        icon: '<i class="fa fa-trash" style="color:#d97706;font-size:2.2rem;"></i>',
        btnClass: "btn-confirm--delete",
        btnLabel: "Yes, Delete"
    }
};

function confirmAction(action, id) {
    const cfg = actionConfig[action];
    if (!cfg) return;
    pendingAction = action;
    pendingId = id;
    document.getElementById('confirm-title').textContent = cfg.title;
    document.getElementById('confirm-message').textContent = cfg.message;
    document.getElementById('confirm-icon').innerHTML = cfg.icon;
    const btn = document.getElementById('confirm-btn');
    btn.textContent = cfg.btnLabel;
    btn.className = 'btn-confirm ' + cfg.btnClass;
    document.getElementById('confirmModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('open');
    document.body.style.overflow = '';
    pendingAction = null;
    pendingId = null;
}
function closeConfirmOutside(e) {
    if (e.target.id === 'confirmModal') closeConfirm();
}

/* EXECUTE */
function executeAction() {
    if (!pendingAction || !pendingId) return;
    const btn = document.getElementById('confirm-btn');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    const routeMap = {
        approve: 'application_approve.php',
        reject:  'application_reject.php',
        delete:  'application_delete.php'
    };
    const formData = new FormData();
    formData.append('id', pendingId);

    fetch(routeMap[pendingAction], { method: 'POST', body: formData })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error("Invalid server response"); }
        })
        .then(data => {
            closeConfirm();
            showToast(data.success ? 'success' : 'error', data.message || 'Unknown response');
            if (data.success) setTimeout(() => window.location.reload(), 1000);
            else { btn.disabled = false; btn.textContent = actionConfig[pendingAction]?.btnLabel || 'Confirm'; }
        })
        .catch(err => {
            console.error(err);
            closeConfirm();
            showToast('error', 'Server error. Please try again.');
        });
}

/* TOAST */
function showToast(type, message) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.innerHTML = `<i class="fa fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i><span></span>`;
    toast.querySelector('span').textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('toast--show'));
    setTimeout(() => { toast.classList.remove('toast--show'); setTimeout(() => toast.remove(), 300); }, 3000);
}

/* ESC */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeModal(); closeConfirm(); }
});
