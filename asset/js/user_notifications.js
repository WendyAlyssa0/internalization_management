function filterNotifications(type, btn)
{
    document.querySelectorAll('.notification-filters button')
        .forEach(b => b.classList.remove('active'));

    btn.classList.add('active');

    const items =
        document.querySelectorAll('.notification-item');

    let visible = 0;

    items.forEach(item => {

        const unread =
            item.getAttribute('data-read') === '0';

        const show =
            type === 'all' ||
            (type === 'unread' && unread) ||
            type === item.dataset.type;

        item.style.display = show ? 'flex' : 'none';

        if (show) visible++;
    });

    document.getElementById('notification-empty')
        .style.display = visible === 0 ? 'block' : 'none';

    document.getElementById('notification-count')
        .textContent =
            `Showing ${visible} Application Update${visible !== 1 ? 's' : ''}`;
}

function markRead(el)
{
    if (el.dataset.read === '1') return;

    fetch('../api/markread.php', {
        method:'POST',
        headers:{
            'Content-Type':'application/json'
        },
        body:JSON.stringify({
            id:parseInt(el.dataset.id)
        })
    })
    .then(r => r.json())
    .then(data => {

        if(data.success){

            el.classList.remove('unread');
            el.dataset.read = '1';

            const dot =
                el.querySelector('.unread-dot');

            if(dot){
                dot.style.opacity = '0';
            }

            updateBadge();
        }

    })
    .catch(() => {
        showToast('Network error.', 'error');
    });
}

function markAllRead()
{
    const btn =
        document.querySelector('.mark-read-btn');

    btn.disabled = true;
    btn.innerHTML =
        '<i class="ti ti-loader"></i> Marking...';

    fetch('../api/mark_all_read.php',{
        method:'POST'
    })
    .then(r => r.json())
    .then(data => {

        if(data.success){

            document
                .querySelectorAll('.notification-item.unread')
                .forEach(el => {

                    el.classList.remove('unread');
                    el.dataset.read = '1';

                    const dot =
                        el.querySelector('.unread-dot');

                    if(dot){
                        dot.style.opacity = '0';
                    }
                });

            updateBadge();

            showToast(
                `${data.updated} notification(s) marked as read.`
            );
        }

    })
    .finally(() => {

        btn.disabled = false;

        btn.innerHTML =
            '<i class="ti ti-check"></i> Mark All as Read';
    });
}

function updateBadge()
{
    const unread =
        document.querySelectorAll(
            '.notification-item[data-read="0"]'
        ).length;

    const badge =
        document.getElementById('unread-badge');

    if(badge){

        badge.textContent =
            unread + ' Unread';

        badge.style.display =
            unread > 0 ? '' : 'none';
    }
}

function showToast(message, type='info')
{
    const container =
        document.getElementById('toast-container');

    const toast =
        document.createElement('div');

    toast.className = `toast ${type}`;
    toast.textContent = message;

    container.appendChild(toast);

    setTimeout(() => {

        toast.style.opacity = '0';

        setTimeout(() => {
            toast.remove();
        }, 300);

    }, 2500);
}
