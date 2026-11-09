(function () {
    'use strict';

    /* =========================
       CONFIG (from PHP)
    ========================= */
    const CFG = window.PARTNERS || {};
    const CSRF = CFG.csrf || '';

    /* =========================
       MODAL HELPERS
    ========================= */
    function openModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.remove('open');
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
        const closer = e.target.closest('.js-close-modal');
        if (closer) {
            closeModal(closer.dataset.modal);
            return;
        }
        if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('open')) {
            closeModal(e.target.id);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
    });

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
       ADD — open + submit
    ========================= */
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.js-open-add')) return;

        const form = document.getElementById('addForm');
        if (form) form.reset();

        const manual = document.getElementById('addCountryManual');
        if (manual) {
            manual.style.display = 'none';
            manual.required = false;
        }

        openModal('addModal');
    });

    /* Country dropdown — reveal manual input on "__other__" */
    document.getElementById('addCountrySelect')?.addEventListener('change', function () {
        const manual = document.getElementById('addCountryManual');
        if (!manual) return;
        const isOther = this.value === '__other__';
        manual.style.display = isOther ? '' : 'none';
        manual.required = isOther;
        if (isOther) manual.focus();
    });

    /* Add form submit */
    document.getElementById('addForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        submitForm(this, 'add_partner.php', 'addModal');
    });

    /* =========================
       EDIT — open + submit
    ========================= */
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.js-edit-btn');
        if (!btn) return;
        const id = parseInt(btn.dataset.id, 10);
        if (!id) return;
        openEditModal(id);
    });

    function openEditModal(id) {
        const loading = document.getElementById('editLoading');
        const form    = document.getElementById('editForm');
        const subtitle = document.getElementById('editModalSubtitle');

        if (loading) loading.style.display = 'flex';
        if (form)    form.style.display    = 'none';
        if (subtitle) subtitle.textContent = 'Loading partner data...';

        openModal('editModal');

        fetch('partner_get.php?id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(json => {
            /* Cleaned endpoint returns {status, success, message, data} */
            if (!json.success || !json.data) {
                showToast('error', json.message || 'Failed to load partner.');
                closeModal('editModal');
                return;
            }

            const p = json.data;

            setVal('editId',          p.id);
            setVal('editInstitution', p.institution_name   || '');
            setVal('editContactFirst',p.contact_first_name || '');
            setVal('editContactMi',   p.contact_middle_name|| '');
            setVal('editContactLast', p.contact_last_name  || '');
            setVal('editEmail',       p.contact_email      || '');
            setVal('editExpiry',      p.expiry_date        || '');
            setVal('editNotes',       p.notes              || '');

            setSelectValue('editCountry',   p.country_id        || '');
            setSelectValue('editAgreement', p.agreement_type_id || '');
            setSelectValue('editStatus',    p.status            || 'Active');

            if (subtitle) subtitle.textContent = 'Editing: ' + (p.institution_name || '—');
            if (loading) loading.style.display = 'none';
            if (form)    form.style.display    = '';
        })
        .catch(err => {
            console.error('[partner edit]', err);
            showToast('error', 'Failed to load partner data.');
            closeModal('editModal');
        });
    }

    function setVal(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = (value == null ? '' : value);
    }

    function setSelectValue(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        const target = String(value);
        el.value = target;
        if (el.value === target) return;

        for (let i = 0; i < el.options.length; i++) {
            if (String(el.options[i].value).toLowerCase() === target.toLowerCase()) {
                el.selectedIndex = i;
                return;
            }
        }
        el.selectedIndex = 0;
    }

    document.getElementById('editForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        submitForm(this, 'update_partner.php', 'editModal');
    });

    /* =========================
       DELETE — modal + submit
    ========================= */
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.js-delete-btn');
        if (!btn) return;

        setVal('deleteId', btn.dataset.id || '');
        const nameEl = document.getElementById('deleteName');
        if (nameEl) nameEl.textContent = btn.dataset.name || 'this partner';

        openModal('deleteModal');
    });

    /* Delete form is a real POST — no AJAX needed.
       Server (partners.php) redirects to ?page=partners after delete. */

    /* =========================
       SHARED FORM SUBMIT
    ========================= */
    function submitForm(form, endpoint, modalId) {
        const btn = form.querySelector('[type="submit"]');
        if (!btn) return;

        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        /* Ensure CSRF token is present */
        const fd = new FormData(form);
        if (!fd.get('csrf_token')) fd.append('csrf_token', CSRF);

        fetch(endpoint, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error('Server returned non-JSON response'); }
        })
        .then(data => {
            /* Support both old {success,message} and new {status,success,message,data} */
            const ok = data.success === true || data.status === 200;
            showToast(ok ? 'success' : 'error', data.message || (ok ? 'Saved.' : 'Failed.'));

            if (ok) {
                closeModal(modalId);
                setTimeout(() => location.reload(), 800);
            }
        })
        .catch(err => {
            console.error('[partner submit]', err);
            showToast('error', 'Server error. Please try again.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = original;
        });
    }

})();