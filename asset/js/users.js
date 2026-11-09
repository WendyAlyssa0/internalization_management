(function () {

    /* ── Table filter ── */
    var searchInput = document.getElementById('searchInput');
    var roleFilter  = document.getElementById('roleFilter');

    function filterTable() {
        var search = searchInput.value.toLowerCase();
        var role   = roleFilter.value.toLowerCase();
        document.querySelectorAll('#tableBody tr[data-name]').forEach(function (row) {
            var name     = row.dataset.name || '';
            var userRole = row.dataset.role || '';
            row.style.display =
                (!search || name.includes(search)) &&
                (!role   || userRole === role)
                ? '' : 'none';
        });
    }

    searchInput.addEventListener('input',  filterTable);
    roleFilter.addEventListener('change',  filterTable);

    /* ── Modal helpers ── */
    function openModal(modal)  { modal.classList.add('open');    document.body.style.overflow = 'hidden'; }
    function closeModal(modal) { modal.classList.remove('open'); document.body.style.overflow = ''; }

    function bindModal(modalId, openBtnId, closeBtnId, cancelBtnId) {
        var modal     = document.getElementById(modalId);
        var openBtn   = openBtnId   ? document.getElementById(openBtnId)   : null;
        var closeBtn  = closeBtnId  ? document.getElementById(closeBtnId)  : null;
        var cancelBtn = cancelBtnId ? document.getElementById(cancelBtnId) : null;

        if (openBtn)   openBtn.addEventListener('click',  function() { openModal(modal); });
        if (closeBtn)  closeBtn.addEventListener('click', function() { closeModal(modal); });
        if (cancelBtn) cancelBtn.addEventListener('click',function() { closeModal(modal); });

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal(modal);
        });
    }

    bindModal('createModal', 'openModalBtn', 'closeCreateModalBtn', 'cancelCreateModalBtn');
    bindModal('editModal', null, 'closeEditModalBtn', 'cancelEditModalBtn');
    bindModal('deleteModal', null, 'closeDeleteModalBtn', 'cancelDeleteModalBtn');

    var reopenCreate = <?= $reopenCreateModal ? 'true' : 'false' ?>;
    if (reopenCreate) openModal(document.getElementById('createModal'));

    var reopenEdit = <?= $reopenEditModal ? 'true' : 'false' ?>;
    if (reopenEdit) openModal(document.getElementById('editModal'));

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        ['createModal','editModal','deleteModal'].forEach(function(id) {
            var m = document.getElementById(id);
            if (m.classList.contains('open')) closeModal(m);
        });
    });

    /* ── Delete modal ── */
    var deleteModal       = document.getElementById('deleteModal');
    var deleteUserNameEl  = document.getElementById('deleteUserName');
    var confirmDeleteBtn  = document.getElementById('confirmDeleteBtn');
    var pendingDeleteForm = null;

    document.querySelectorAll('.delete-form').forEach(function (form) {
        var btn = form.querySelector('.delete-btn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            pendingDeleteForm = form;
            deleteUserNameEl.textContent = form.dataset.name || 'this user';
            openModal(deleteModal);
        });
    });

    confirmDeleteBtn.addEventListener('click', function () {
        if (!pendingDeleteForm) return;
        confirmDeleteBtn.disabled  = true;
        confirmDeleteBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Deleting...';
        pendingDeleteForm.submit();
    });

    /* ── Email preview (create form) ── */
    function generateEmailPreview() {
        var fEl = document.querySelector('#createUserForm [name="first_name"]');
        var lEl = document.querySelector('#createUserForm [name="last_name"]');
        var rEl = document.getElementById('roleSelect');
        var preview = document.getElementById('emailPreview');
        if (!fEl || !lEl || !rEl || !preview) return;

        var f = fEl.value.trim();
        var l = lEl.value.trim();
        var r = rEl.value;
        if (!f || !l || !r) { preview.value = ''; return; }
        var prefix = { 'super admin': 'SA', 'admin': 'AD', 'applicant': 'AP' }[r] || 'US';
        preview.value = prefix + f[0].toLowerCase() + l.toLowerCase().replace(/\s/g, '') + '@psuxizn.com';
    }

    function toggleProgram() {
        var roleEl = document.getElementById('roleSelect');
        var field  = document.getElementById('programField');
        var select = document.getElementById('programSelect');
        if (!roleEl || !field || !select) return;

        var show = roleEl.value === 'applicant';
        field.style.display = show ? 'flex' : 'none';
        show ? select.setAttribute('required','required') : select.removeAttribute('required');
    }

    var createFirstName = document.querySelector('#createUserForm [name="first_name"]');
    var createLastName  = document.querySelector('#createUserForm [name="last_name"]');
    var roleSelectEl    = document.getElementById('roleSelect');

    if (createFirstName) createFirstName.addEventListener('input', generateEmailPreview);
    if (createLastName)  createLastName.addEventListener('input',  generateEmailPreview);
    if (roleSelectEl) {
        roleSelectEl.addEventListener('change', function() {
            generateEmailPreview();
            toggleProgram();
        });
    }

    generateEmailPreview();
    toggleProgram();

})();