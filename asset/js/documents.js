// Auto-submit search with debounce
let searchTimer;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => this.form.submit(), 500);
});

// Confirm modal logic (same as before)
let pendingAction = null;
let pendingId = null;

const ACTION_CONFIG = {
    approve: {
        title: 'Approve Document', heading: 'Approve this document?',
        message: 'This will mark the document as approved and notify the uploader.',
        icon: 'ti ti-circle-check', iconColor: '#10b981',
        wrapClass: 'confirm-icon-wrap--approve', btnClass: 'btn-action--approve',
        btnIcon: 'ti ti-check', btnLabel: 'Approve',
        endpoint: './document_approve.php'
    },
    reject: {
        title: 'Reject Document', heading: 'Reject this document?',
        message: 'This will mark the document as rejected. The uploader will be informed.',
        icon: 'ti ti-circle-x', iconColor: '#ef4444',
        wrapClass: 'confirm-icon-wrap--reject', btnClass: 'btn-action--reject',
        btnIcon: 'ti ti-x', btnLabel: 'Reject',
        endpoint: './document_reject.php'
    },
    delete: {
        title: 'Delete Document', heading: 'Delete this document?',
        message: 'This action is permanent and cannot be undone. The file will be removed.',
        icon: 'ti ti-trash', iconColor: '#f59e0b',
        wrapClass: 'confirm-icon-wrap--delete', btnClass: 'btn-action--delete',
        btnIcon: 'ti ti-trash', btnLabel: 'Delete',
        endpoint: './document_delete.php'
    }
};

function confirmAction(action, id) {
    pendingAction = action;
    pendingId = id;
    const c = ACTION_CONFIG[action];

    document.getElementById('confirm-title').textContent   = c.title;
    document.getElementById('confirm-heading').textContent = c.heading;
    document.getElementById('confirm-message').textContent = c.message;

    const iconEl = document.getElementById('confirm-icon');
    iconEl.className = c.icon;
    iconEl.style.color = c.iconColor;

    document.getElementById('confirm-icon-wrap').className = 'confirm-icon-wrap ' + c.wrapClass;
    document.getElementById('confirm-btn').className = 'btn-action ' + c.btnClass;
    document.getElementById('confirm-btn-icon').className = c.btnIcon;
    document.getElementById('confirm-btn-label').textContent = c.btnLabel;

    document.getElementById('confirmModal').classList.add('open');
}

function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('open');
}

function closeConfirmOutside(e) {
    if (e.target.id === 'confirmModal') closeConfirm();
}

function executeAction() {
    const btn = document.getElementById('confirm-btn');
    btn.disabled = true;

    const formData = new FormData();
    formData.append('id', pendingId);

    fetch(ACTION_CONFIG[pendingAction].endpoint, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Server error.'))
        .finally(() => { closeConfirm(); btn.disabled = false; });
}
