/* ===== OTP password reset — 3 steps, all in real time ===== */
(function () {
  const API = 'password_reset_api.php';  // path from reset_password.php
  const $ = (s, r = document) => r.querySelector(s);

  const sEmail = $('#step-email');
  const sOtp   = $('#step-otp');
  const sPass  = $('#step-pass');
  if (!sEmail) return;                   // not on reset page

  let currentEmail = '';
  let resetToken   = '';
  let resendTimer  = null;

  const show = (el) => {
    [sEmail, sOtp, sPass].forEach(s => { if (s) s.hidden = true; });
    if (el) el.hidden = false;
  };

  const note = (el, text, ok) => {
    if (!el) return;
    el.textContent = text;
    el.className   = 'msg ' + (ok === undefined ? '' : ok ? 'ok' : 'err');
  };

  const busy = (btn, on) => {
    if (!btn) return;
    btn.disabled = on;
    if (!btn.dataset.orig) btn.dataset.orig = btn.textContent;
    btn.textContent = on ? 'Please wait…' : btn.dataset.orig;
  };

  async function post(data) {
    const res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(data),
    });
    return res.json();
  }

  /* ----- STEP 1 ----- */
  $('#form-email').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn  = e.submitter || $('#form-email button');
    const noteEl = $('#email-msg');
    busy(btn, true);
    try {
      const out = await post({ action: 'request', email: $('#email').value });
      note(noteEl, out.message, out.ok);
      if (out.ok) {
        currentEmail = $('#email').value.trim();
        $('#otp-email-label').textContent = currentEmail;
        show(sOtp);
        $('#otp').value = '';
        $('#otp').focus();
        startResend(60);
      }
    } catch { note(noteEl, 'Network error. Try again.', false); }
    finally { busy(btn, false); }
  });

  /* ----- STEP 2 ----- */
  $('#otp').addEventListener('input', (e) => {
    e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
  });

  $('#form-otp').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn  = e.submitter || $('#form-otp button[type=submit]');
    const noteEl = $('#otp-msg');
    busy(btn, true);
    try {
      const out = await post({
        action: 'verify',
        email:  currentEmail,
        otp:    $('#otp').value,
      });
      note(noteEl, out.message, out.ok);
      if (out.ok) {
        resetToken = out.token;
        show(sPass);
        $('#new-pass').value = '';
        $('#confirm-pass').value = '';
        $('#new-pass').focus();
      }
    } catch { note(noteEl, 'Network error. Try again.', false); }
    finally { busy(btn, false); }
  });

  /* ----- STEP 3 ----- */
  $('#form-pass').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn  = e.submitter || $('#form-pass button');
    const noteEl = $('#pass-msg');
    const p1 = $('#new-pass').value;
    const p2 = $('#confirm-pass').value;

    if (p1 !== p2)   return note(noteEl, 'Passwords do not match.', false);
    if (p1.length < 8) return note(noteEl, 'Use at least 8 characters.', false);

    busy(btn, true);
    try {
      const out = await post({ action: 'reset', token: resetToken, password: p1 });
      note(noteEl, out.message, out.ok);
      if (out.ok) {
        setTimeout(() => { location.href = 'user_login.php'; }, 1200);
      }
    } catch { note(noteEl, 'Network error. Try again.', false); }
    finally { busy(btn, false); }
  });

  /* ----- Resend countdown ----- */
  function startResend(seconds) {
    const btn = $('#resend');
    if (!btn) return;
    clearInterval(resendTimer);
    let left = seconds;
    btn.disabled = true;
    btn.textContent = `Resend in ${left}s`;
    resendTimer = setInterval(() => {
      left--;
      if (left <= 0) {
        clearInterval(resendTimer);
        btn.disabled = false;
        btn.textContent = 'Resend code';
      } else {
        btn.textContent = `Resend in ${left}s`;
      }
    }, 1000);
  }

  $('#resend')?.addEventListener('click', async () => {
    const noteEl = $('#otp-msg');
    try {
      const out = await post({ action: 'request', email: currentEmail });
      note(noteEl, out.message, out.ok);
      if (out.ok) startResend(60);
    } catch { note(noteEl, 'Network error. Try again.', false); }
  });
})();