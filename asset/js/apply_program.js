const redirectUrl = document.querySelector('[data-redirect-url]')?.dataset.redirectUrl;
if (redirectUrl) {
    window.location.href = redirectUrl;
}

document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('programSelect');
    const partner = document.getElementById('partner');
    const country = document.getElementById('country');
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');
    const cards = document.querySelectorAll('.program-card');
    const reason = document.getElementById('reason');
    const charCount = document.getElementById('charCount');
    const form = document.getElementById('applyForm');

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr + 'T00:00:00');
        if (isNaN(d)) return dateStr;
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function updateFields() {
        const option = select.options[select.selectedIndex];
        partner.value = option?.dataset.partner || '';
        country.value = option?.dataset.country || '';
        startDate.value = formatDate(option?.dataset.start || '');
        endDate.value = formatDate(option?.dataset.end || '');

        cards.forEach(card => {
            card.classList.toggle('selected', card.dataset.programId === select.value);
        });
    }

    function updateCounter() {
        const len = reason.value.length;
        charCount.textContent = len;
        charCount.classList.remove('ok', 'warn', 'over');
        if (len >= 500) charCount.classList.add('over');
        else if (len >= 450) charCount.classList.add('warn');
        else charCount.classList.add('ok');
    }

    select.addEventListener('change', updateFields);

    if (select.value) updateFields();

    reason.addEventListener('input', updateCounter);
    updateCounter();

    cards.forEach(card => {
        const activate = () => {
            select.value = card.dataset.programId;
            updateFields();
            document.getElementById('applyForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
        card.addEventListener('click', activate);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate();
            }
        });
    });

    form.addEventListener('submit', function() {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    });
});
