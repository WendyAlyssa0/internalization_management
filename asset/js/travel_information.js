/* ============================================================
   travel_information.js
   Add / Edit / Delete modal logic for Travel Information
   ============================================================ */

(function () {
    'use strict';

var API_URL = '/Internalization_management/admin/travel_information_api.php';
    var formModal      = document.getElementById('form-modal');
    var deleteModal    = document.getElementById('delete-modal');
    var travelForm     = document.getElementById('travel-form');
    var formErrorBox   = document.getElementById('form-error-box');
    var formTitle      = document.getElementById('form-modal-title');
    var formSubtitle   = document.getElementById('form-modal-subtitle');
    var formSubmitBtn  = document.getElementById('form-submit-btn');
    var formSubmitLbl  = document.getElementById('form-submit-label');
    var travelIdField  = document.getElementById('travel-id');

    var deleteNameEl        = document.getElementById('delete-name');
    var deleteDestinationEl = document.getElementById('delete-destination');
    var deleteConfirmBtn   = document.getElementById('delete-confirm-btn');
    var pendingDeleteId    = null;

    var toastEl     = document.getElementById('toast');
    var toastMsg    = document.getElementById('toast-message');
    var toastIcon   = document.getElementById('toast-icon');

    /* ---------- helpers ---------- */
    function openModal(modal) {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function showToast(message, type) {
        toastMsg.textContent = message;
        toastEl.classList.remove('toast--success', 'toast--error');

        if (type === 'error') {
            toastEl.classList.add('toast--error');
            toastIcon.className = 'fa-solid fa-circle-exclamation';
        } else {
            toastEl.classList.add('toast--success');
            toastIcon.className = 'fa-solid fa-circle-check';
        }

        toastEl.classList.add('toast--show');

        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () {
            toastEl.classList.remove('toast--show');
        }, 3200);
    }

    function showFormErrors(errors) {
        var html = '';
        errors.forEach(function (msg) {
            html += '<div><i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(msg) + '</div>';
        });
        formErrorBox.innerHTML = html;
        formErrorBox.style.display = 'block';
    }

    function clearFormErrors() {
        formErrorBox.innerHTML = '';
        formErrorBox.style.display = 'none';
    }

    function resetForm() {
        travelForm.reset();
        travelIdField.value = '';
        clearFormErrors();
    }

    function setFormMode(mode) {
        if (mode === 'edit') {
            formTitle.textContent = 'Edit Travel Record';
            formSubtitle.textContent = 'Update the applicant\u2019s travel details below.';
            formSubmitLbl.textContent = 'Update Record';
        } else {
            formTitle.textContent = 'Add Travel Record';
            formSubtitle.textContent = 'Fill in the applicant\u2019s travel details below.';
            formSubmitLbl.textContent = 'Save Record';
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function setLoading(btn, loading, defaultLabel) {
        if (loading) {
            btn.disabled = true;
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
        } else {
            btn.disabled = false;
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
                delete btn.dataset.originalHtml;
            } else if (defaultLabel) {
                btn.innerHTML = defaultLabel;
            }
        }
    }

    /* ---------- open add modal ---------- */
    document.getElementById('add-travel-btn').addEventListener('click', function () {
        resetForm();
        setFormMode('add');
        openModal(formModal);
        setTimeout(function () {
            document.getElementById('applicant_name').focus();
        }, 100);
    });

    /* ---------- open edit modal ---------- */
    document.querySelectorAll('.js-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            resetForm();
            setFormMode('edit');
            openModal(formModal);
            formSubmitBtn.disabled = true;
            formSubmitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading...';

            fetch(API_URL + '?action=fetch&id=' + encodeURIComponent(id), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    formSubmitBtn.disabled = false;
                    formSubmitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> <span id="form-submit-label">Update Record</span>';

                    if (res.status === 200 && res.data) {
                        travelIdField.value         = res.data.id;
                        document.getElementById('user_id').value =    res.data.user_id || '';
                        document.getElementById('destination').value     = res.data.destination || '';
                        document.getElementById('departure_date').value   = res.data.departure_date || '';
                        document.getElementById('return_date').value      = res.data.return_date || '';
                    } else {
                        closeModal(formModal);
                        showToast(res.message || 'Could not load record.', 'error');
                    }
                })
                .catch(function () {
                    formSubmitBtn.disabled = false;
                    formSubmitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> <span id="form-submit-label">Update Record</span>';
                    closeModal(formModal);
                    showToast('Network error while loading record.', 'error');
                });
        });
    });

    /* ---------- submit form (create / update) ---------- */
    travelForm.addEventListener('submit', function (e) {
        e.preventDefault();
        clearFormErrors();

        var id = travelIdField.value;
        var action = id ? 'update' : 'create';

        var payload = new FormData(travelForm);
        payload.append('action', action);
        if (id) {
            payload.append('id', id);
        }

        setLoading(formSubmitBtn, true);

        fetch(API_URL, {
            method: 'POST',
            body: payload,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                setLoading(formSubmitBtn, false);

                if (res.status === 200) {
                    closeModal(formModal);
                    showToast(res.message, 'success');
                    setTimeout(function () { window.location.reload(); }, 700);
                } else if (res.status === 422 && res.data && res.data.errors) {
                    showFormErrors(res.data.errors);
                } else {
                    showToast(res.message || 'Something went wrong.', 'error');
                }
            })
            .catch(function () {
                setLoading(formSubmitBtn, false);
                showToast('Network error. Please try again.', 'error');
            });
    });

    /* ---------- open delete modal ---------- */
    document.querySelectorAll('.js-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            pendingDeleteId = btn.getAttribute('data-id');
            deleteNameEl.textContent = btn.getAttribute('data-name') || '—';
            deleteDestinationEl.textContent = btn.getAttribute('data-destination') || '—';
            openModal(deleteModal);
        });
    });

    /* ---------- confirm delete ---------- */
    deleteConfirmBtn.addEventListener('click', function () {
        if (!pendingDeleteId) return;

        setLoading(deleteConfirmBtn, true);

        var payload = new FormData();
        payload.append('action', 'delete');
        payload.append('id', pendingDeleteId);

        fetch(API_URL, {
            method: 'POST',
            body: payload,
            headers: { 'X-Requested-with': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                setLoading(deleteConfirmBtn, false);

                if (res.status === 200) {
                    closeModal(deleteModal);
                    pendingDeleteId = null;
                    showToast(res.message, 'success');
                    setTimeout(function () { window.location.reload(); }, 700);
                } else {
                    showToast(res.message || 'Could not delete record.', 'error');
                }
            })
            .catch(function () {
                setLoading(deleteConfirmBtn, false);
                showToast('Network error. Please try again.', 'error');
            });
    });

    /* ---------- close buttons ---------- */
    document.querySelectorAll('.js-close-form').forEach(function (btn) {
        btn.addEventListener('click', function () { closeModal(formModal); });
    });
    document.querySelectorAll('.js-close-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(deleteModal);
            pendingDeleteId = null;
        });
    });

    /* ---------- click backdrop to close ---------- */
    formModal.addEventListener('click', function (e) {
        if (e.target === formModal) closeModal(formModal);
    });
    deleteModal.addEventListener('click', function (e) {
        if (e.target === deleteModal) {
            closeModal(deleteModal);
            pendingDeleteId = null;
        }
    });

    /* ---------- escape to close ---------- */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (formModal.classList.contains('open'))   closeModal(formModal);
            if (deleteModal.classList.contains('open')) {
                closeModal(deleteModal);
                pendingDeleteId = null;
            }
        }
    });
})();


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
document.querySelectorAll('.js-close-form').forEach(el => {
    el.addEventListener('click', () => closeModal('form-modal'));
});
document.querySelectorAll('.js-close-delete').forEach(el => {
    el.addEventListener('click', () => closeModal('delete-modal'));
});
document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', e => {
        if (e.target === el) closeModal(el.id);
    });
});

/* =========================
   TOAST
========================= */
function showToast(type, message) {
    const toast = document.getElementById('toast');
    const icon = document.getElementById('toast-icon');
    const msg = document.getElementById('toast-message');

    icon.className = 'fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation');
    msg.textContent = message;

    toast.className = `toast toast--${type} toast--show`;
    setTimeout(() => toast.classList.remove('toast--show'), 3000);
}

/* =========================
   ADD NEW
========================= */
document.getElementById('add-travel-btn').addEventListener('click', () => {
    document.getElementById('travel-form').reset();
    document.getElementById('travel-id').value = '';
    document.getElementById('form-modal-title').textContent = 'Add Travel Record';
    document.getElementById('form-modal-subtitle').textContent = 'Fill in the applicant\'s flight details below.';
    document.getElementById('form-submit-label').textContent = 'Save Record';
    openModal('form-modal');
});

/* =========================
   EDIT — pre-fill modal
========================= */
document.querySelectorAll('.js-edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('travel-id').value            = btn.dataset.id;
        document.getElementById('user_id').value              = btn.dataset.userId;
        document.getElementById('flight_number').value        = btn.dataset.flight;
        document.getElementById('departure_date').value       = btn.dataset.depDate;
        document.getElementById('departure_time').value       = btn.dataset.depTime;
        document.getElementById('departure_airport').value    = btn.dataset.depAirport;
        document.getElementById('arrival_date').value         = btn.dataset.arrDate;
        document.getElementById('arrival_time').value         = btn.dataset.arrTime;
        document.getElementById('arrival_airport').value      = btn.dataset.arrAirport;

        document.getElementById('form-modal-title').textContent = 'Edit Travel Record';
        document.getElementById('form-modal-subtitle').textContent = 'Update the flight details below.';
        document.getElementById('form-submit-label').textContent = 'Update Record';
        openModal('form-modal');
    });
});

/* =========================
   SUBMIT FORM
========================= */
document.getElementById('travel-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = document.getElementById('form-submit-btn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    const fd = new FormData(this);

    fetch('travel_information_api.php', { method: 'POST', body: fd })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error('Invalid server response'); }
        })
        .then(data => {
            if (data.success) {
                showToast('success', data.message || 'Saved successfully.');
                closeModal('form-modal');
                setTimeout(() => window.location.reload(), 800);
            } else {
                showToast('error', data.message || 'Could not save.');
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
        document.getElementById('delete-name').textContent   = btn.dataset.name;
        document.getElementById('delete-flight').textContent = btn.dataset.flight || '—';
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

    fetch('travel_information_api.php', { method: 'POST', body: fd })
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

/* =========================
   ESC TO CLOSE
========================= */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('form-modal');
        closeModal('delete-modal');
    }
});
