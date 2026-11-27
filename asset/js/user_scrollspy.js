/* PSU x IZN - Applicant portal scroll-spy
   Highlights the sidebar sub-item (.nav-sublink) whose section is in view.
   - Sub-items whose target id is not on the page are hidden.
   - Does nothing when the page has no sub-items. */
(function () {
  'use strict';

  var nav = document.querySelector('.nav-subnav');
  if (!nav) return;

  var items = [];
  Array.prototype.forEach.call(nav.querySelectorAll('.nav-sublink'), function (link) {
    var el = document.getElementById(link.getAttribute('data-target'));
    if (!el) {
      if (link.parentElement) link.parentElement.hidden = true;
      return;
    }
    items.push({ link: link, el: el });
  });

  if (!items.length) { nav.hidden = true; return; }

  var topbar = document.querySelector('.topbar');
  var current = null;
  var lockUntil = 0;
  var ticking = false;

  function setCurrent(item) {
    if (current === item) return;
    if (current) {
      current.link.classList.remove('is-current');
      current.link.removeAttribute('aria-current');
    }
    current = item;
    item.link.classList.add('is-current');
    item.link.setAttribute('aria-current', 'location');
  }

  function update() {
    ticking = false;
    if (Date.now() < lockUntil) return;

    var offset = (topbar ? topbar.offsetHeight : 72) + 24;
    var chosen = items[0];
    var atBottom = window.innerHeight + window.pageYOffset >= document.documentElement.scrollHeight - 2;

    if (atBottom) {
      chosen = items[items.length - 1];
    } else {
      items.forEach(function (it) {
        if (it.el.getBoundingClientRect().top - offset <= 0) chosen = it;
      });
    }
    setCurrent(chosen);
  }

  function schedule() {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(update);
  }

  /* Clicking an item highlights it at once; ignore scroll updates while the
     smooth scroll runs, then re-sync. */
  items.forEach(function (it) {
    it.link.addEventListener('click', function () {
      setCurrent(it);
      lockUntil = Date.now() + 800;
      window.setTimeout(update, 850);
    });
  });

  window.addEventListener('scroll', schedule, { passive: true });
  window.addEventListener('resize', schedule);
  window.addEventListener('load', update);
  update();
})();
