const form = document.getElementById('forgotForm');
const submitBtn = document.getElementById('submitBtn');
const errorBox = document.getElementById('loginError');
const successBox = document.getElementById('loginSuccess');

form.addEventListener('submit', async function (e) {
    e.preventDefault();

    errorBox.style.display = 'none';
    successBox.style.display = 'none';

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="btn-label">Sending...</span>';

    try {
        const response = await fetch('forgot_password_process.php', {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const text = await response.text();
        let data;
        try { data = JSON.parse(text); }
        catch { throw new Error('Invalid server response.'); }

        if (data.success) {
            successBox.textContent = data.message || 'If an account exists with that email, a reset link has been sent.';
            successBox.style.display = 'block';
            form.reset();
        } else {
            errorBox.textContent = data.error || 'Unable to process your request.';
            errorBox.style.display = 'block';
        }
    } catch (err) {
        errorBox.textContent = err.message || 'Something went wrong. Please try again.';
        errorBox.style.display = 'block';
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<span class="btn-label">Send Reset Link</span><svg class="icon-svg btn-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/></svg>';
    }
});
