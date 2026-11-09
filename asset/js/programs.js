/* =========================
   SEARCH (debounced)
========================= */
let searchTimer;
document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        const search = this.value.toLowerCase().trim();
        document.querySelectorAll('#tableBody tr').forEach(row => {
            const name = (row.dataset.name || '').toLowerCase();
            row.style.display = name.includes(search) ? '' : 'none';
        });
    }, 300);
});

/* =========================
   ADD PROGRAM MODAL
========================= */
function openProgramModal() {
    document.getElementById('programModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeProgramModal() {
    document.getElementById('programModal').classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('programForm').reset();
    document.getElementById('add_partner_id').innerHTML = '<option value="">Select Country First</option>';
}
function closeProgramModalOutside(e) {
    if (e.target.id === 'programModal') closeProgramModal();
}

/* =========================
   EDIT PROGRAM MODAL
========================= */
function openEditProgramModal(id) {
    document.getElementById('editProgramModal').classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch('get_program.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            document.getElementById('edit_id').value           = data.id;
            document.getElementById('edit_program_name').value = data.program_name;
            document.getElementById('edit_program_type').value = data.program_type;
            document.getElementById('edit_status').value       = data.status;
            document.getElementById('edit_country_id').value   = data.country_id;
            document.getElementById('edit_start').value        = data.start_date;
            document.getElementById('edit_end').value          = data.end_date;
            document.getElementById('edit_description').value  = data.description;

            loadPartners(data.country_id, data.partner_id);
        })
        .catch(() => {
            showToast('error', 'Cannot load program data');
            closeEditProgramModal();
        });
}
function closeEditProgramModal() {
    document.getElementById('editProgramModal').classList.remove('open');
    document.body.style.overflow = '';
}
function closeEditProgramOutside(e) {
    if (e.target.id === 'editProgramModal') closeEditProgramModal();
}

/* Load partners (shared for edit) */
function loadPartners(countryId, selectedPartner) {
    fetch('get_partners_by_country.php?country_id=' + countryId)
        .then(res => res.json())
        .then(partners => {
            const select = document.getElementById('edit_partner_id');
            select.innerHTML = '<option value="">Select Partner</option>';
            partners.forEach(partner => {
                const option = document.createElement('option');
                option.value = partner.id;
                option.textContent = partner.institution_name;
                if (String(partner.id) === String(selectedPartner)) option.selected = true;
                select.appendChild(option);
            });
        });
}

/* Load partners (for add) */
function loadAddPartners(countryId) {
    const select = document.getElementById('add_partner_id');
    select.innerHTML = '<option value="">Loading...</option>';
    fetch('get_partners_by_country.php?country_id=' + countryId)
        .then(res => res.json())
        .then(partners => {
            select.innerHTML = '<option value="">Select Partner</option>';
            if (!partners.length) {
                select.innerHTML = '<option value="">No partners for this country</option>';
                return;
            }
            partners.forEach(partner => {
                const option = document.createElement('option');
                option.value = partner.id;
                option.textContent = partner.institution_name;
                select.appendChild(option);
            });
        })
        .catch(() => select.innerHTML = '<option value="">Failed to load partners</option>');
}

/* =========================
   EDIT FORM SUBMIT
========================= */
document.getElementById('editProgramForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = this.querySelector('[type="submit"]');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Updating...';

    fetch('update_program.php', { method: 'POST', body: new FormData(this) })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error('Invalid JSON'); }
        })
        .then(data => {
            showToast(data.success ? 'success' : 'error', data.message);
            if (data.success) {
                closeEditProgramModal();
                setTimeout(() => location.reload(), 1000);
            }
        })
        .catch(() => showToast('error', 'Server error'))
        .finally(() => { btn.disabled = false; btn.innerHTML = original; });
});

document.getElementById('edit_country_id').addEventListener('change', function () {
    loadPartners(this.value, '');
});

/* =========================
   ADD FORM SUBMIT
========================= */
document.getElementById('programForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = this.querySelector('[type="submit"]');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    fetch('add_program_modal.php', { method: 'POST', body: new FormData(this) })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error('Invalid JSON'); }
        })
        .then(data => {
            showToast(data.success ? 'success' : 'error', data.message);
            if (data.success) {
                closeProgramModal();
                setTimeout(() => location.reload(), 1200);
            }
        })
        .catch(() => showToast('error', 'Something went wrong.'))
        .finally(() => { btn.disabled = false; btn.innerHTML = original; });
});

document.getElementById('add_country_id').addEventListener('change', function () {
    loadAddPartners(this.value);
});

/* =========================
   DELETE PROGRAM
========================= */
function deleteProgram(id, name) {
    document.getElementById('deleteProgramName').textContent = name || 'this program';
    document.getElementById('confirmDeleteBtn').setAttribute('href', 'programs.php?delete=' + id);
    document.getElementById('deleteProgramModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDeleteModal() {
    document.getElementById('deleteProgramModal').classList.remove('open');
    document.body.style.overflow = '';
}
function closeDeleteOutside(e) {
    if (e.target.id === 'deleteProgramModal') closeDeleteModal();
}

/* =========================
   ESC KEY
========================= */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeProgramModal();
        closeEditProgramModal();
        closeDeleteModal();
    }
});

/* =========================
   TOAST
========================= */
function showToast(type, message) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();

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
