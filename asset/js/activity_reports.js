/* =========================
   MODAL HELPERS
========================= */
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
document.querySelectorAll('.js-close-form').forEach(el =>
    el.addEventListener('click', () => closeModal('form-modal'))
);
document.querySelectorAll('.js-close-delete').forEach(el =>
    el.addEventListener('click', () => closeModal('delete-modal'))
);
document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', e => {
        if (e.target === el) closeModal(el.id);
    });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('form-modal');
        closeModal('delete-modal');
    }
});

/* =========================
   TOAST
========================= */
function showToast(type, message) {
    const toast = document.getElementById('toast');
    const icon  = document.getElementById('toast-icon');
    const msg   = document.getElementById('toast-message');

    icon.className = 'fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation');
    msg.textContent = message;

    toast.className = `toast toast--${type} toast--show`;
    setTimeout(() => toast.classList.remove('toast--show'), 3000);
}

/* =========================
   ADD NEW
========================= */
document.getElementById('add-activity-btn').addEventListener('click', () => {
    document.getElementById('activity-form').reset();
    document.getElementById('activity-id').value = '';
    document.getElementById('form-modal-title').textContent = 'Add Activity Record';
    document.getElementById('form-modal-subtitle').textContent = "Fill in the student's weekly activity details below.";
    document.getElementById('form-submit-label').textContent = 'Save Record';
    openModal('form-modal');
});

/* =========================
   EDIT — pre-fill with data-*
========================= */
document.querySelectorAll('.js-edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('activity-id').value          = btn.dataset.id;
        document.getElementById('student_id').value           = btn.dataset.studentId;
        document.getElementById('week_number').value          = btn.dataset.week;
        document.getElementById('progress').value             = btn.dataset.progress;
        document.getElementById('status').value               = btn.dataset.status;
        document.getElementById('report').value               = btn.dataset.report;
        document.getElementById('supervisor_feedback').value = btn.dataset.feedback;

        document.getElementById('form-modal-title').textContent = 'Edit Activity Record';
        document.getElementById('form-modal-subtitle').textContent = 'Update the activity details below.';
        document.getElementById('form-submit-label').textContent = 'Update Record';
        openModal('form-modal');
    });
});

/* =========================
   SUBMIT FORM
========================= */
document.getElementById('activity-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = document.getElementById('form-submit-btn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    const fd = new FormData(this);
    fd.append('action', document.getElementById('activity-id').value ? 'update' : 'create');

    fetch('activity_reports_api.php', { method: 'POST', body: fd })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error('Invalid server response: ' + text.slice(0, 200)); }
        })
        .then(data => {
            if (data.success) {
                showToast('success', data.message || 'Saved successfully.');
                closeModal('form-modal');
                setTimeout(() => window.location.reload(), 800);
            } else {
                // Show field-level errors if present
                if (data.data?.errors?.length) {
                    showToast('error', data.data.errors.join(' '));
                } else {
                    showToast('error', data.message || 'Could not save.');
                }
            }
        })
        .catch(err => {
            console.error(err);
            showToast('error', 'Server error. Please try again.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = original;
        });
});

/* =========================
   DELETE
========================= */
let pendingDeleteId = null;

document.querySelectorAll('.js-delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        pendingDeleteId = btn.dataset.id;
        document.getElementById('delete-name').textContent = btn.dataset.name;
        document.getElementById('delete-week').textContent = btn.dataset.week;
        openModal('delete-modal');
    });
});

document.getElementById('delete-confirm-btn').addEventListener('click', function () {
    if (!pendingDeleteId) return;
    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Deleting...';

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', pendingDeleteId);

    fetch('activity_reports_api.php', { method: 'POST', body: fd })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error('Invalid server response'); }
        })
        .then(data => {
            if (data.success) {
                showToast('success', data.message || 'Deleted.');
                closeModal('delete-modal');
                setTimeout(() => window.location.reload(), 800);
            } else {
                showToast('error', data.message || 'Could not delete.');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('error', 'Server error.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = original;
        });
});
