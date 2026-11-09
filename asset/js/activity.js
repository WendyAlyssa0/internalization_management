/* ============================================================
   activity_monitoring.js
   Add / Edit / Delete modal logic for Activity Monitoring
   ============================================================ */

(function () {
    'use strict';

var API_URL = '/internalization_management/admin/activity_reports_api.php';

    var formModal      = document.getElementById('form-modal');
    var deleteModal    = document.getElementById('delete-modal');
    var activityForm   = document.getElementById('activity-form');
    var formErrorBox   = document.getElementById('form-error-box');
    var formTitle      = document.getElementById('form-modal-title');
    var formSubtitle   = document.getElementById('form-modal-subtitle');
    var formSubmitBtn  = document.getElementById('form-submit-btn');
    var formSubmitLbl  = document.getElementById('form-submit-label');
    var activityIdField = document.getElementById('activity-id');

    var deleteNameEl      = document.getElementById('delete-name');
    var deleteWeekEl      = document.getElementById('delete-week');
    var deleteConfirmBtn  = document.getElementById('delete-confirm-btn');
    var pendingDeleteId   = null;

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
        activityForm.reset();
        activityIdField.value = '';
        clearFormErrors();
    }

    function setFormMode(mode) {
        if (mode === 'edit') {
            formTitle.textContent = 'Edit Activity Record';
            formSubtitle.textContent = 'Update the student\u2019s weekly activity details below.';
            formSubmitLbl.textContent = 'Update Record';
        } else {
            formTitle.textContent = 'Add Activity Record';
            formSubtitle.textContent = 'Fill in the student\u2019s weekly activity details below.';
            formSubmitLbl.textContent = 'Save Record';
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function setLoading(btn, loading) {
        if (loading) {
            btn.disabled = true;
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
        } else {
            btn.disabled = false;
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
                delete btn.dataset.originalHtml;
            }
        }
    }

    /* ---------- open add modal ---------- */
    document.getElementById('add-activity-btn').addEventListener('click', function () {
        resetForm();
        setFormMode('add');
        openModal(formModal);
        setTimeout(function () {
            document.getElementById('student_name').focus();
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
                        activityIdField.value = res.data.id;
                        document.getElementById('student_id').value = res.data.student_id;
                        document.getElementById('week_number').value  = res.data.week_number || '';
                        document.getElementById('report').value      = res.data.report || '';
                        document.getElementById('progress').value    = res.data.progress || '';
                        document.getElementById('status').value      = res.data.status || 'ongoing';
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
    activityForm.addEventListener('submit', function (e) {
        e.preventDefault();
        clearFormErrors();

        var id = activityIdField.value;
        var action = id ? 'update' : 'create';

        var payload = new FormData(activityForm);
        payload.append('action', action);
        if (id) {
            payload.append('id', id);
        }

        setLoading(formSubmitBtn, true);

        fetch(API_URL, {
            method: 'POST',
            body: payload,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
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
            deleteNameEl.textContent = btn.getAttribute('data-name') || '\u2014';
            deleteWeekEl.textContent = btn.getAttribute('data-week') || '\u2014';
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
