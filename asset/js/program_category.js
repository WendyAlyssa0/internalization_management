(function () {
    'use strict';
    const CFG  = window.PROGRAM_CATEGORY || {};
    const CSRF = CFG.csrf || '';

    function openModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('open'); document.body.style.overflow = 'hidden'; }
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.remove('open'); document.body.style.overflow = ''; }
    }

    document.addEventListener('click', e => {
        const closer = e.target.closest('.js-close-modal');
        if (closer) { closeModal(closer.dataset.modal); return; }
        if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('open')) {
            closeModal(e.target.id);
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
        }
    });

    /* Add */
    document.addEventListener('click', e => {
        if (!e.target.closest('.js-open-add')) return;
        document.getElementById('categoryForm').reset();
        document.getElementById('categoryAction').value = 'add';
        document.getElementById('categoryId').value = '';
        document.getElementById('categoryModalTitle').textContent = 'Add Category';
        document.getElementById('categoryModalSubtitle').textContent = 'Create a new program category';
        openModal('categoryModalOverlay');
    });

    /* Edit */
    document.addEventListener('click', e => {
        const btn = e.target.closest('.js-edit-btn');
        if (!btn) return;
        const id = parseInt(btn.dataset.id, 10);
        if (!id) return;

        fetch('program_category_get.php?id=' + id, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(json => {
                if (!json.success || !json.data) { alert(json.message || 'Failed to load.'); return; }
                const c = json.data;
                document.getElementById('categoryAction').value = 'edit';
                document.getElementById('categoryId').value     = c.id;
                document.getElementById('categoryName').value   = c.category_name;
                document.getElementById('categoryDescription').value = c.description || '';
                document.getElementById('categoryModalTitle').textContent = 'Edit Category';
                document.getElementById('categoryModalSubtitle').textContent = 'Update this program category';
                openModal('categoryModalOverlay');
            })
            .catch(() => alert('Failed to load.'));
    });

    /* Form submit */
    document.getElementById('categoryForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = this.querySelector('[type=submit]');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        const fd = new FormData(this);
        if (!fd.get('csrf_token')) fd.append('csrf_token', CSRF);

        fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(json => {
                if (json.ok) {
                    closeModal('categoryModalOverlay');
                    setTimeout(() => location.reload(), 400);
                } else {
                    alert(json.error || 'Failed.');
                }
            })
            .catch(() => alert('Server error.'))
            .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
    });

    /* Toggle status */
    document.addEventListener('click', e => {
        const toggle = e.target.closest('.js-toggle-status');
        if (!toggle) return;
        const id = parseInt(toggle.dataset.id, 10);
        if (!id) return;

        const fd = new FormData();
        fd.append('action', 'toggle_status');
        fd.append('id', id);
        fd.append('csrf_token', CSRF);

        fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(json => { if (json.ok) setTimeout(() => location.reload(), 200); })
            .catch(() => {});
    });

    /* Delete */
    let pendingId = null;

    document.addEventListener('click', e => {
        const btn = e.target.closest('.js-delete-btn');
        if (!btn) return;
        pendingId = parseInt(btn.dataset.id, 10);
        const name = btn.dataset.name || 'this category';
        const uses = parseInt(btn.dataset.uses || '0', 10);

        const q = document.getElementById('deleteQuestionText');
        if (uses > 0) {
            q.textContent = `Cannot delete "${name}" — used by ${uses} program(s). Reassign them first.`;
            document.getElementById('deleteModalConfirm').disabled = true;
        } else {
            q.textContent = `Are you sure you want to permanently delete "${name}"?`;
            document.getElementById('deleteModalConfirm').disabled = false;
        }
        openModal('deleteModalOverlay');
    });

    document.getElementById('deleteModalConfirm')?.addEventListener('click', function () {
        if (!pendingId) return;
        const btn = this;
        btn.disabled = true;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', pendingId);
        fd.append('csrf_token', CSRF);

        fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(json => {
                if (json.ok) {
                    closeModal('deleteModalOverlay');
                    setTimeout(() => location.reload(), 300);
                } else {
                    alert(json.error || 'Failed.');
                    btn.disabled = false;
                }
            })
            .catch(() => { btn.disabled = false; });
    });
})();