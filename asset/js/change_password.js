// Toggle password visibility
function toggleVis(id, btn) {
    const inp  = document.getElementById(id);
    const icon = btn.querySelector('i');
    const show = inp.type === 'password';
    
    inp.type       = show ? 'text' : 'password';
    icon.className = show ? 'ti ti-eye-off' : 'ti ti-eye';
    btn.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + id.replace(/_/g, ' '));
}

// Live Password Strength Check
function checkStrength(val) {
    const bar  = document.getElementById('sbar');
    const hint = document.getElementById('shint');
    let score  = 0;
    
    if (val.length >= 8)                              score++;
    if (val.length >= 12)                             score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val))       score++;
    if (/\d/.test(val))                               score++;
    if (/[^A-Za-z0-9]/.test(val))                     score++;

    const levels = [
        { w: '0%',   bg: '#e2e8f0', text: 'Enter a new password', color: '#64748b' },
        { w: '20%',  bg: '#ef4444', text: 'Very weak', color: '#991b1b' },
        { w: '40%',  bg: '#f59e0b', text: 'Weak', color: '#b45309' },
        { w: '60%',  bg: '#eab308', text: 'Fair', color: '#a16207' },
        { w: '80%',  bg: '#10b981', text: 'Strong', color: '#047857' },
        { w: '100%', bg: '#059669', text: 'Very strong', color: '#064e3b' },
    ];
    
    const l = val.length === 0 ? levels[0] : levels[Math.min(score, 5)];
    
    bar.style.width      = l.w;
    bar.style.background = l.bg;
    hint.textContent     = val.length === 0 ? 'Enter a new password' : l.text;
    hint.style.color     = l.color;
    
    checkMatch();
}

// Live Password Match Check
function checkMatch() {
    const np   = document.getElementById('new_password').value;
    const cp   = document.getElementById('confirm_password').value;
    const hint = document.getElementById('mhint');
    
    if (!cp) { 
        hint.style.display = 'none'; 
        return; 
    }
    
    hint.style.display = 'block';
    const match = np === cp;
    hint.textContent = match ? '✓ Passwords match' : '✗ Passwords do not match';
    hint.className   = 'input-hint ' + (match ? 'success' : 'danger');
}

// Prevent double submission
document.getElementById('passwordForm').addEventListener('submit', function(e) {
    const np = document.getElementById('new_password').value;
    const cp = document.getElementById('confirm_password').value;
    
    // Client-side guard before hitting server
    if (np !== cp || np.length < 8) {
        e.preventDefault();
        return; // The hints will already show what's wrong
    }
    
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader" style="animation: spin 1s linear infinite;"></i> Updating...';
});
