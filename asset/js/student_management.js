/* =========================
   MODAL
========================= */
function openStudentModal(data) {
    const fmt = (val) => val ? val : '—';

    const formatDate = (val) => {
        if (!val) return '—';
        return new Date(val).toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric'
        });
    };

    const badgeHtml = (value, prefix) => {
        if (!value) return '—';
        const cls = value.toLowerCase().replace(/\s+/g, '-');
        return `<span class="status-badge ${prefix}-${cls}">${value.charAt(0).toUpperCase() + value.slice(1)}</span>`;
    };

    /* Header */
    document.getElementById('m-header-name').textContent  = fmt(data.student_name);
    document.getElementById('m-header-email').textContent = fmt(data.student_email);

    /* Student Info */
    document.getElementById('m-name').textContent       = fmt(data.student_name);
    document.getElementById('m-email').textContent      = fmt(data.student_email);
    document.getElementById('m-program').textContent    = fmt(data.program);
    document.getElementById('m-department').textContent = fmt(data.department);

    /* Mobility */
    document.getElementById('m-mobility').innerHTML    = badgeHtml(data.mobility_type, 'mobility');
    document.getElementById('m-institution').textContent = fmt(data.institution);
    document.getElementById('m-country').textContent     = fmt(data.country);
    document.getElementById('m-docs').innerHTML          = badgeHtml(data.documents_status, 'status');

    /* Timeline */
    document.getElementById('m-created').textContent  = formatDate(data.created_at);
    document.getElementById('m-reviewed').textContent = formatDate(data.reviewed_at);
    document.getElementById('m-id').textContent       = '#' + fmt(data.id);
    document.getElementById('m-status').innerHTML     = badgeHtml(data.status, 'status');

    document.getElementById('studentModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeStudentModal() {
    document.getElementById('studentModal').classList.remove('open');
    document.body.style.overflow = '';
}

function closeStudentModalOutside(e) {
    if (e.target.id === 'studentModal') closeStudentModal();
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeStudentModal();
});
