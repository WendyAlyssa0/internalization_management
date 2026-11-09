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

      /* Strength meter */
      var pw = document.getElementById('regPassword');
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
      });

      /* Confirm-password check + double-submit guard */
      var confirmEl = document.getElementById('confirmPassword');
      function checkMatch() {
        confirmEl.setCustomValidity(confirmEl.value && confirmEl.value !== pw.value ? 'Passwords do not match.' : '');
      }
      pw.addEventListener('input', checkMatch);
      confirmEl.addEventListener('input', checkMatch);

      var form = document.getElementById('registerForm');

      /* Role toggle: Switch labels between Student/Faculty */
      const roleRadios = document.querySelectorAll('input[name="user_role"]');
      const idLabel = document.getElementById('id-label-text');
      const idInput = document.getElementById('id_number');

      function updateRoleUI() {
          const selected = document.querySelector('input[name="user_role"]:checked').value;
          if (selected === 'faculty') {
              idLabel.textContent = 'Employee ID Number';
              idInput.placeholder = 'e.g. EMP-2026-001';
          } else {
              idLabel.textContent = 'Student ID Number';
              idInput.placeholder = 'e.g. 21-00123';
          }
      }

      roleRadios.forEach(radio => radio.addEventListener('change', updateRoleUI));
      updateRoleUI(); // Run on page load

      /* Two-step flow */
      var steps = form.querySelectorAll('.step');
      var line = document.getElementById('stepLine');
      function goTo(n, focus) {
        steps.forEach(function (s) { s.classList.toggle('active', +s.dataset.step === n); });
        document.querySelectorAll('[data-dot]').forEach(function (d) {
          var i = +d.dataset.dot;
          d.classList.toggle('active', i === n);
          d.classList.toggle('done', i < n);
          d.textContent = i < n ? '✓' : i;
        });
        document.querySelectorAll('[data-name]').forEach(function (t) {
          t.classList.toggle('active', +t.dataset.name === n);
        });
        line.classList.toggle('done', n > 1);
        if (focus) {
          var first = form.querySelector('.step.active input:not([type=hidden]), .step.active select');
          if (first) first.focus({ preventScroll: true });
          form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
      function step1Valid() {
        var fields = form.querySelectorAll('.step[data-step="1"] input, .step[data-step="1"] select');
        for (var i = 0; i < fields.length; i++) {
          if (!fields[i].checkValidity()) { fields[i].reportValidity(); return false; }
        }
        return true;
      }
      document.getElementById('btnNext').addEventListener('click', function () {
        if (step1Valid()) goTo(2, true);
      });
      document.getElementById('btnBack').addEventListener('click', function () { goTo(1, true); });
      form.addEventListener('keydown', function (ev) {
        /* Enter on step 1 moves forward instead of submitting a half-filled form. */
        if (ev.key === 'Enter' && form.querySelector('.step.active').dataset.step === '1' && ev.target.tagName !== 'BUTTON') {
          ev.preventDefault();
          if (step1Valid()) goTo(2, true);
        }
      });
      goTo(+form.dataset.startStep || 1, false);

      form.addEventListener('submit', function () {
        if (!form.checkValidity()) return;
        var b = document.getElementById('submitBtn');
        b.disabled = true; b.textContent = 'Creating account…';
      });
    })();
