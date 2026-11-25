/* ===================================================
   PSU × IZN — Admin Sidebar
   Dropdown accordion + mobile off-canvas drawer
   =================================================== */

document.addEventListener("DOMContentLoaded", () => {

  /* ---- dropdown accordion ---- */
  const dropdowns = document.querySelectorAll(".sidebar-dropdown");

  dropdowns.forEach((dropdown) => {
    const toggle = dropdown.querySelector(".dropdown-toggle");
    if (!toggle) return;

    toggle.addEventListener("click", () => {
      dropdowns.forEach((item) => {
        if (item !== dropdown) item.classList.remove("open");
      });
      dropdown.classList.toggle("open");
    });
  });

  /* ---- mobile off-canvas drawer ---- */
  const sidebar  = document.getElementById('mainSidebar');
  const overlay  = document.getElementById('sidebarOverlay');
  const openBtn  = document.getElementById('sidebarToggle'); // lives in topbar.php
  const closeBtn = document.getElementById('sidebarClose');  // lives in sidebar.php

  if (!sidebar) return;

  function toggleSidebar(open) {
    sidebar.classList.toggle('open', open);
    if (overlay) overlay.classList.toggle('visible', open);
    document.body.style.overflow = open ? 'hidden' : '';
  }

  if (openBtn) {
    openBtn.addEventListener('click', () => toggleSidebar(true));
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', () => toggleSidebar(false));
  }

  if (overlay) {
    overlay.addEventListener('click', () => toggleSidebar(false));
  }

  // close the drawer automatically if the viewport is resized
  // back up to desktop while it's open
  window.addEventListener('resize', () => {
    if (window.innerWidth > 860) {
      toggleSidebar(false);
    }
  });
});