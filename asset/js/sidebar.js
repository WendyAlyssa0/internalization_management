/* ===================================================
   PSU × IZN — Admin Sidebar
   Dropdown accordion behavior
   =================================================== */

document.addEventListener("DOMContentLoaded", () => {
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
});
