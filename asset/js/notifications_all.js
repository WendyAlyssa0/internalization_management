(function () {
    'use strict';

    const CFG = window.NOTIFICATIONS_PAGE || {};
    const CSRF = CFG.csrf || '';
    const BULK_ENDPOINT   = CFG.bulkEndpoint   || 'mark_notifications_read.php';
    const SINGLE_ENDPOINT = CFG.singleEndpoint || 'mark_notification_read.php';

    const list     = document.getElementById('notificationList');
    const searchEl = document.getElementById('notificationSearch');
    const sortBtn  = document.getElementById('sortBtn');
    const sortLbl  = document.getElementById('sortLabel');
    const markAllBtn = document.getElementById('markAllBtn');
    const noResults  = document.getElementById('noSearchResults');
    const visibleCountEl = document.getElementById('visibleCount');
    const unreadCountEl  = document.getElementById('unreadFilterCount');

    let currentFilter = 'all';
    let currentSort   = 'latest';   // 'latest' | 'oldest'

    /* ---------- Filter ---------- */
    function applyFilters() {
        const q = (searchEl?.value || '').toLowerCase().trim();
        let visible = 0;

        list.querySelectorAll('.js-notification-item').forEach(item => {
            const type = item.dataset.type;
            const read = item.dataset.read;

            let show = true;

            if (currentFilter === 'unread' && read !== 'unread') show = false;
            else if (currentFilter !== 'all' && currentFilter !== 'unread' && type !== currentFilter) show = false;

            if (show && q !== '') {
                show = item.dataset.search.includes(q);
            }

            item.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (visibleCountEl) visibleCountEl.textContent = visible;
        if (noResults) noResults.style.display = (visible === 0 && list.children.length > 0) ? '' : 'none';
    }

    list.querySelectorAll('.js-filter').forEach(btn => {
        btn.addEventListener('click', function () {
            list.querySelectorAll('.js-filter').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            applyFilters();
        });
    });

    /* ---------- Search ---------- */
    searchEl?.addEventListener('input', applyFilters);

    /* ---------- Sort ---------- */
    sortBtn?.addEventListener('click', function () {
        currentSort = (currentSort === 'latest') ? 'oldest' : 'latest';
        sortLbl.textContent = (currentSort === 'latest') ? 'Latest first' : 'Oldest first';

        const items = Array.from(list.querySelectorAll('.js-notification-item'));
        items.sort((a, b) => {
            const ta = parseInt(a.dataset.time, 10);
            const tb = parseInt(b.dataset.time, 10);
            return currentSort === 'latest' ? tb - ta : ta - tb;
        });
        items.forEach(el => list.appendChild(el));
    });

    /* ---------- Mark single notification as read ---------- */
    list.addEventListener('click', function (e) {
        const item = e.target.closest('.js-notification-item');
        if (!item) return;
        if (item.dataset.read === 'read') return;

        const id = parseInt(item.dataset.id, 10);
        if (!id) return;

        fetch(SINGLE_ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF
            },
            body: JSON.stringify({ id })
        })
        .then(r => r.json())
        .then(json => {
            if (!json.success) return;
            item.classList.remove('unread');
            item.dataset.read = 'read';
            if (unreadCountEl) {
                const cur = parseInt(unreadCountEl.textContent, 10) || 0;
                unreadCountEl.textContent = Math.max(0, cur - 1);
            }
        })
        .catch(() => { /* silent */ });
    });

    /* ---------- Mark all as read ---------- */
    markAllBtn?.addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;

        const fd = new FormData();
        fd.append('csrf_token', CSRF);

        fetch(BULK_ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(json => {
                if (!json.success) return;
                list.querySelectorAll('.js-notification-item').forEach(item => {
                    item.classList.remove('unread');
                    item.dataset.read = 'read';
                });
                if (unreadCountEl) unreadCountEl.textContent = '0';
            })
            .finally(() => { btn.disabled = false; });
    });

    /* Initial state */
    applyFilters();
})();