/* Shared behaviour for login.php, register.php and forgot_password.php.
 * Pages are now separate, so there is no client-side view switching. */
(function () {
  'use strict';

  var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-6.5 0-10-7-10-7a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>';

  /* ---- show / hide password ---- */
  document.querySelectorAll('[data-eye]').forEach(function (btn) {
    var input = btn.parentElement.querySelector('input');
    btn.innerHTML = EYE;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? EYE_OFF : EYE;
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  /* ---- password strength meter ---- */
  var LABELS = ['—', 'Weak', 'Fair', 'Good', 'Strong'];
  function score(pw) {
    if (!pw) return 0;
    var s = 0;
    if (pw.length >= 8) s++;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
    if (/\d/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw) || pw.length >= 12) s++;
    return Math.max(s, 1);
  }
  document.querySelectorAll('[data-strength]').forEach(function (input) {
    var meter = document.querySelector('[data-strength-meter="' + input.id + '"]');
    if (!meter) return;
    var label = meter.querySelector('.strength-label b');
    input.addEventListener('input', function () {
      var s = score(input.value);
      meter.setAttribute('data-score', s);
      if (label) label.textContent = LABELS[s];
    });
  });

  /* ---- one-time code boxes ---- */
  var row = document.getElementById('otpRow');
  if (row) {
    var boxes = Array.prototype.slice.call(row.querySelectorAll('input'));

    boxes.forEach(function (box, i) {
      box.addEventListener('input', function () {
        box.value = box.value.replace(/\D/g, '').slice(-1);
        if (box.value && boxes[i + 1]) boxes[i + 1].focus();
      });
      box.addEventListener('keydown', function (ev) {
        if (ev.key === 'Backspace' && !box.value && boxes[i - 1]) {
          boxes[i - 1].focus();
        } else if (ev.key === 'ArrowLeft' && boxes[i - 1]) {
          boxes[i - 1].focus();
        } else if (ev.key === 'ArrowRight' && boxes[i + 1]) {
          boxes[i + 1].focus();
        }
      });
      box.addEventListener('paste', function (ev) {
        var text = (ev.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
        if (!text) return;
        ev.preventDefault();
        boxes.forEach(function (b, j) { b.value = text[j] || ''; });
        boxes[Math.min(text.length, boxes.length) - 1].focus();
      });
    });
  }
})();