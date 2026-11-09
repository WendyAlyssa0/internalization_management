(function () {
      /* Show/hide password */
      document.querySelectorAll('[data-eye]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var input = btn.parentElement.querySelector('input');
          var show = input.type === 'password';
          input.type = show ? 'text' : 'password';
          btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
      });

      /* Double-submit guard on the main forms */
      ['forgotForm', 'verifyForm'].forEach(function (id) {
        var f = document.getElementById(id);
        if (!f) return;
        f.addEventListener('submit', function () {
          if (!f.checkValidity()) return;
          var b = f.querySelector('.submit-btn');
          b.disabled = true; b.textContent = 'Please wait…';
        });
      });

      /* ----- Verify step only ----- */
      var row = document.getElementById('otpRow');
      if (!row) return;

      /* Code boxes: auto-advance, backspace, arrows, paste */
      var boxes = Array.prototype.slice.call(row.querySelectorAll('input'));
      function mark(b) { b.classList.toggle('filled', b.value !== ''); }
      boxes.forEach(function (box, i) {
        box.addEventListener('input', function () {
          box.value = box.value.replace(/\D/g, '').slice(-1);
          mark(box);
          if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
        });
        box.addEventListener('keydown', function (ev) {
          if (ev.key === 'Backspace' && !box.value && i > 0) { boxes[i - 1].focus(); boxes[i - 1].value = ''; mark(boxes[i - 1]); }
          if (ev.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
          if (ev.key === 'ArrowRight' && i < boxes.length - 1) boxes[i + 1].focus();
        });
        box.addEventListener('focus', function () { box.select(); });
        box.addEventListener('paste', function (ev) {
          var text = (ev.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, boxes.length);
          if (!text) return;
          ev.preventDefault();
          boxes.forEach(function (b, k) { b.value = text[k] || ''; mark(b); });
          boxes[Math.min(text.length, boxes.length - 1)].focus();
        });
      });

      /* Password strength + confirm match */
      var pw = document.getElementById('resetPassword');
      var conf = document.getElementById('confirmPassword');
      var meter = document.getElementById('strength');
      var label = document.getElementById('strengthLabel');
      var names = ['—', 'Weak', 'Fair', 'Good', 'Strong'];
      pw.addEventListener('input', function () {
        var v = pw.value, s = 0;
        if (v.length >= 8) s++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
        if (/\d/.test(v)) s++;
        if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) s++;
        if (!v) s = 0;
        meter.dataset.score = s;
        label.textContent = names[s];
        check();
      });
      function check() { conf.setCustomValidity(conf.value && conf.value !== pw.value ? 'Passwords do not match.' : ''); }
      conf.addEventListener('input', check);

      /* Resend cooldown countdown */
      var rb = document.getElementById('resendBtn');
      var wait = parseInt(rb.dataset.wait, 10) || 0;
      if (wait > 0) {
        var timer = setInterval(function () {
          wait--;
          if (wait <= 0) { clearInterval(timer); rb.disabled = false; rb.textContent = 'Resend'; }
          else rb.textContent = 'Resend in ' + wait + 's';
        }, 1000);
        rb.textContent = 'Resend in ' + wait + 's';
      }
    })();
