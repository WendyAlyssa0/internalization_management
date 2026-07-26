/* ===================================================
   PSU × IZN — Applicant Portal
   Dashboard interactions (account menu + sidebar dropdowns)
   =================================================== */

document.addEventListener("DOMContentLoaded", () => {
  // ---- Account dropdown ----
  const accountMenu = document.querySelector(".account-menu");
  const accountTrigger = document.querySelector(".account-trigger");

  if (accountMenu && accountTrigger) {
    accountTrigger.addEventListener("click", (e) => {
      e.stopPropagation();
      accountMenu.classList.toggle("active");
    });

    document.addEventListener("click", () => {
      accountMenu.classList.remove("active");
    });
  }

  // ---- Sidebar dropdowns ----
  document.querySelectorAll(".dropdown-toggle").forEach((toggle) => {
    toggle.addEventListener("click", () => {
      toggle.parentElement.classList.toggle("open");
    });
  });
});
