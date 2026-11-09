const form        = document.getElementById('resetForm');
const submitBtn   = document.getElementById('submitBtn');
const errorBox    = document.getElementById('loginError');
const successBox  = document.getElementById('loginSuccess');
const pw          = document.getElementById('passwordInput');
const confirm     = document.getElementById('confirmInput');
const strength    = document.getElementById('strength');
const strengthLbl = document.getElementById('strengthLabel');
const matchLbl    = document.getElementById('matchLabel');

const STRENGTH_NAMES = ['—', 'Weak', 'Fair', 'Good', 'Strong'];

/* Password toggle */
document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', function () {
        const input = this.parentElement.querySelector('input');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        this.classList.toggle('is-visible', show);
    });
});

/* Strength meter */
pw.addEventListener('input', function () {
    const v = pw.value;
    let s = 0;
    if (v.length >= 8)      s++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
    if (/\d/.test(v))       s++;
    if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) s++;
    if (!v) s = 0;
    strength.dataset.score = s;
    strengthLbl.textContent = 'Password strength: ' + STRENGTH_NAMES[s];
    checkMatch();
});

/* Match check */
function checkMatch() {
    if (!confirm.value) { matchLbl.textContent = ''; return; }
    const match = pw.value === confirm.value;
    matchLbl.textContent = match ? '✓ Passwords match' : '✗ Passwords do not match';
    matchLbl.style.color = match ? '#16a34a' : '#dc2626';
}
confirm.addEventListener('input', checkMatch);

/* Submit */
form.addEventListener('submit', async function (e) {
    e.preventDefault();

    errorBox.style.display = 'none';
    successBox.style.display = 'none';

    if (pw.value !== confirm.value) {
        errorBox.textContent = 'Passwords do not match.';
        errorBox.style.display = 'block';
        return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="btn-label">Resetting...</span>';

    try {
        const response = await fetch('reset_password_process.php', {
            method: 'POST',
            body: new FormData(form),
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });

        const text = await response.text();
        let data;
        try { data = JSON.parse(text); }
        catch { throw new Error('Invalid server response.'); }

        if (data.success) {
            successBox.textContent = data.message || 'Password reset successfully!';
            successBox.style.display = 'block';
            form.reset();
            setTimeout(() => window.location.href = 'login.php', 2500);
        } else {
            errorBox.textContent = data.error || 'Unable to reset password.';
            errorBox.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<span class="btn-label">Reset Password</span><svg class="icon-svg btn-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/></svg>';
        }
    } catch (err) {
        errorBox.textContent = err.message || 'Something went wrong.';
        errorBox.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<span class="btn-label">Reset Password</span>';
    }
});
