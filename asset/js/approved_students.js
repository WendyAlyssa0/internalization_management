/* =========================
   SEARCH (debounced)
========================= */
let searchTimer;
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => document.getElementById('filterForm').submit(), 500);
    });
}

/* =========================
   EVENT DELEGATION (safe — no inline JSON)
========================= */
document.querySelector('.table-card tbody').addEventListener('click', (e) => {
    const viewBtn = e.target.closest('.view-btn');
    if (viewBtn) {
        openStudentModal(JSON.parse(viewBtn.dataset.row));
        return;
    }

    const delBtn = e.target.closest('.delete-btn');
    if (delBtn) {
        confirmDelete(delBtn.dataset.id, delBtn.dataset.name);
    }
});

/* =========================
   VIEW MODAL
========================= */
function openStudentModal(data) {
    const fmt = (v) => (v !== null && v !== undefined && v !== '') ? v : '—';
    const formatDate = (v) => v ? new Date(v).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
    const statusHtml = (s) => s ? `<span class="status-badge status-${s.toLowerCase().replace(/\s+/g, '-')}">${s.charAt(0).toUpperCase() + s.slice(1)}</span>` : '—';

    document.getElementById('m-name').textContent          = fmt(data.student_name);
    document.getElementById('m-id-number').textContent     = fmt(data.student_id_number);
    document.getElementById('m-email').textContent         = fmt(data.email);
    document.getElementById('m-program').textContent       = fmt(data.program_name);
    document.getElementById('m-mobility').textContent      = fmt(data.mobility_type);
    document.getElementById('m-institution').textContent   = fmt(data.institution);
    document.getElementById('m-country').textContent       = fmt(data.country);
    document.getElementById('m-status').innerHTML          = statusHtml(data.status);
    document.getElementById('m-semester').textContent      = fmt(data.semester);
    document.getElementById('m-school-year').textContent   = fmt(data.school_year);
    document.getElementById('m-approval-date').textContent = formatDate(data.approval_date);
    document.getElementById('m-app-id').textContent        = '#' + fmt(data.id);

    document.getElementById('studentModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeStudentModal() {
    document.getElementById('studentModal').classList.remove('open');
    document.body.style.overflow = '';
}

/* =========================
   DELETE MODAL
========================= */
let pendingDeleteId = null;

function confirmDelete(id, name) {
    pendingDeleteId = id;
    document.getElementById('delete-message').textContent =
        `Are you sure you want to delete the record for ${name}? This action cannot be undone.`;
    document.getElementById('deleteModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    document.body.style.overflow = '';
    pendingDeleteId = null;
}

function executeDelete() {
    if (!pendingDeleteId) return;

    const btn = document.getElementById('delete-confirm-btn');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    const formData = new FormData();
    formData.append('id', pendingDeleteId);

    fetch('approved_student_delete.php', { method: 'POST', body: formData })
        .then(async (res) => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error('Invalid server response'); }
        })
        .then(data => {
            closeDeleteModal();
            showToast(data.success ? 'success' : 'error', data.message || 'Unknown response');
            if (data.success) setTimeout(() => window.location.reload(), 1000);
            else { btn.disabled = false; btn.textContent = 'Yes, Delete'; }
        })
        .catch(err => {
            console.error(err);
            closeDeleteModal();
            showToast('error', 'Server error. Please try again.');
        });
}

/* =========================
   TOAST
========================= */
function showToast(type, message) {
    document.querySelector('.toast')?.remove();
    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.innerHTML = `<i class="fa fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i><span></span>`;
    toast.querySelector('span').textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('toast--show'));
    setTimeout(() => {
        toast.classList.remove('toast--show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

/* =========================
   ESC TO CLOSE MODALS
========================= */
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeStudentModal();
        closeDeleteModal();
    }
});

/* =========================
   CLICK OUTSIDE TO CLOSE
========================= */
document.getElementById('studentModal').addEventListener('click', (e) => {
    if (e.target.id === 'studentModal') closeStudentModal();
});
document.getElementById('deleteModal').addEventListener('click', (e) => {
    if (e.target.id === 'deleteModal') closeDeleteModal();
});
