let sortAscending = false;

/* ── Mark single as read ── */
function markRead(el) {
    if (el.dataset.read === 'read') return;

    // FIXED PATH: removed ../admin/
    fetch('./markread.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: el.dataset.id })
    })
    .then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); }
        catch { throw new Error('Server did not return JSON'); }
    })
    .then(res => {
        if (res.success) {
            el.classList.remove('unread');
            el.dataset.read = 'read';
            updateCounts();
        } else {
            showToast('error', res.message || 'Could not mark notification.');
        }
    })
    .catch(() => showToast('error', 'Could not reach the server.'));
}

/* ── Mark all as read ── */
function markAllRead() {
    // FIXED PATH: removed ../admin/
    fetch('./markallread.php', { method: 'POST' })
    .then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); }
        catch { throw new Error('Server did not return JSON'); }
    })
    .then(res => {
        if (res.success) {
            document.querySelectorAll('.notification-item').forEach(el => {
                el.classList.remove('unread');
                el.dataset.read = 'read';
            });
            updateCounts();
            showToast('success', 'All notifications marked as read.');
        } else {
            showToast('error', res.message || 'Could not mark all notifications.');
        }
    })
    .catch(() => showToast('error', 'Could not reach the server.'));
}

/* ── Update filter counts + mark-all button state ── */
function updateCounts() {
    const items = document.querySelectorAll('.notification-item');
    const counts = { all: items.length, unread: 0, system_alert: 0, approved: 0, rejected: 0, under_review: 0 };

    items.forEach(el => {
        if (el.dataset.read === 'unread') counts.unread++;
        if (counts[el.dataset.type] !== undefined) counts[el.dataset.type]++;
    });

    Object.keys(counts).forEach(key => {
        const btn = document.querySelector(`.notification-filters button[data-filter="${key}"] .filter-count`);
        if (btn) btn.textContent = counts[key];
    });

    const markAllBtn = document.getElementById('markAllBtn');
    if (markAllBtn) markAllBtn.disabled = counts.unread === 0;
}

/* ── Filter ── */
function filter(type, btn) {
    document.querySelectorAll('.notification-item').forEach(el => {
        const matchType = (type === 'all' || type === 'unread') ? true : el.dataset.type === type;
        const matchRead = type !== 'unread' ? true : el.dataset.read === 'unread';
        el.style.display = (matchType && matchRead) ? '' : 'none';
    });

    document.querySelectorAll('.notification-filters button')
        .forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

/* ── Sort ── */
function toggleSort() {
    sortAscending = !sortAscending;
    document.getElementById('sortLabel').textContent = sortAscending ? 'Oldest first' : 'Latest first';

    const list = document.getElementById('notificationList');
    const items = Array.from(list.querySelectorAll('.notification-item'));

    items.sort((a, b) => {
        const diff = a.dataset.time - b.dataset.time;
        return sortAscending ? diff : -diff;
    });

    items.forEach(item => list.appendChild(item));
}

/* ── Toast ── */
function showToast(type, message) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.innerHTML = `
        <i class="fa fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        <span></span>
    `;
    toast.querySelector('span').textContent = message;

    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('toast--show'));

    setTimeout(() => {
        toast.classList.remove('toast--show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
