/* ===================================================
   PSU × IZN — Applicant Portal
   Dashboard interactions (account menu + sidebar mobile)
   =================================================== */

document.addEventListener("DOMContentLoaded", () => {
  // ---- Account dropdown ----
  const accountMenu = document.querySelector(".account-menu");
  const accountTrigger = document.querySelector(".account-trigger");

  if (accountMenu && accountTrigger) {
    accountTrigger.addEventListener("click", (e) => {
      e.stopPropagation();
      // FIX: Changed from 'active' to 'open' to match your CSS
      accountMenu.classList.toggle("open");
    });

    // Close dropdown when clicking outside
    document.addEventListener("click", (e) => {
      if (!accountMenu.contains(e.target)) {
        accountMenu.classList.remove("open");
      }
    });
  }

  // ---- Mobile Sidebar Toggle ----
  const mobileMenuBtn = document.querySelector(".mobile-menu");
  const sidebar = document.getElementById("sidebar");
  const body = document.body;

  if (mobileMenuBtn && sidebar) {
    mobileMenuBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      sidebar.classList.toggle("is-open");
      body.classList.toggle("sidebar-open");
      
      // Optional: Update aria-expanded for accessibility
      const isExpanded = sidebar.classList.contains("is-open");
      mobileMenuBtn.setAttribute("aria-expanded", isExpanded ? "true" : "false");
    });

    // Close sidebar when clicking the dark overlay outside of it
    body.addEventListener("click", (e) => {
      if (body.classList.contains("sidebar-open") && !sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
        sidebar.classList.remove("is-open");
        body.classList.remove("sidebar-open");
        mobileMenuBtn.setAttribute("aria-expanded", "false");
      }
    });
  }
});