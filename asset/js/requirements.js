/* ===================================================
   PSU × IZN — Document Requirements
   Add / Edit / Delete via fetch, CSRF-protected
   Search filter + toast notifications
   =================================================== */

document.addEventListener("DOMContentLoaded", () => {
  const csrfToken = window.REQUIREMENTS_CSRF;
  const endpoint = window.location.pathname + window.location.search;

  const addBtn = document.getElementById("btnAddRequirement");
  const tableBody = document.getElementById("requirementsBody");
  const searchInput = document.getElementById("searchInput");
  const paginationText = document.getElementById("paginationText");

  const reqModal = document.getElementById("requirementModal");
  const reqForm = document.getElementById("requirementForm");
  const modalTitle = document.getElementById("modalTitle");
  const modalSubtitle = document.getElementById("modalSubtitle");
  const modalIcon = document.getElementById("modalIcon");
  const formAction = document.getElementById("formAction");
  const formId = document.getElementById("formId");
  const nameInput = document.getElementById("requirement_name");
  const descInput = document.getElementById("description");
  const formSubmit = document.getElementById("formSubmit");
  const formSubmitLabel = document.getElementById("formSubmitLabel");

  const deleteModal = document.getElementById("deleteModal");
  let pendingDeleteId = null;

  const toast = document.getElementById("toast");
  const toastMessage = document.getElementById("toastMessage");
  let toastTimer = null;

  /* ---- Toast ---- */
  function showToast(message, type) {
    toastMessage.textContent = message;
    toast.classList.remove("toast--success", "toast--error");
    toast.classList.add(type === "error" ? "toast--error" : "toast--success");

    const icon = toast.querySelector("i");
    icon.className =
      type === "error"
        ? "fa-solid fa-circle-exclamation"
        : "fa-solid fa-circle-check";

    toast.classList.add("toast--show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toast.classList.remove("toast--show");
    }, 3200);
  }

  /* ---- Modal helpers ---- */
  function openModal(modal) {
    modal.classList.add("open");
  }
  function closeModal(modal) {
    modal.classList.remove("open");
  }

  function resetForm() {
    reqForm.reset();
    formId.value = "";
  }

  /* ---- Open Add modal ---- */
  addBtn.addEventListener("click", () => {
    resetForm();
    modalTitle.textContent = "Add Requirement";
    modalSubtitle.textContent = "Create a new document requirement";
    modalIcon.className = "fa-solid fa-file-circle-plus";
    formSubmitLabel.textContent = "Save Requirement";
    formAction.value = "add";
    openModal(reqModal);
    nameInput.focus();
  });

  /* ---- Open Edit modal / Delete modal (event delegation) ---- */
  tableBody.addEventListener("click", (e) => {
    const editBtn = e.target.closest(".edit-btn");
    if (editBtn) {
      resetForm();
      modalTitle.textContent = "Edit Requirement";
      modalSubtitle.textContent = "Update this document requirement";
      modalIcon.className = "fa-solid fa-pen";
      formSubmitLabel.textContent = "Update Requirement";
      formAction.value = "edit";
      formId.value = editBtn.dataset.id;
      nameInput.value = editBtn.dataset.name || "";
      descInput.value = editBtn.dataset.desc || "";
      openModal(reqModal);
      nameInput.focus();
      return;
    }

    const deleteBtn = e.target.closest(".delete-btn");
    if (deleteBtn) {
      pendingDeleteId = deleteBtn.dataset.id;
      openModal(deleteModal);
    }
  });

  /* ---- Close handlers ---- */
  document
    .getElementById("modalClose")
    .addEventListener("click", () => closeModal(reqModal));
  document
    .getElementById("modalCancel")
    .addEventListener("click", () => closeModal(reqModal));
  document
    .getElementById("deleteModalClose")
    .addEventListener("click", () => closeModal(deleteModal));
  document
    .getElementById("deleteCancel")
    .addEventListener("click", () => closeModal(deleteModal));

  [reqModal, deleteModal].forEach((modal) => {
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeModal(modal);
    });
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      closeModal(reqModal);
      closeModal(deleteModal);
    }
  });

  /* ---- Live search ---- */
  if (searchInput) {
    searchInput.addEventListener("input", () => {
      const term = searchInput.value.trim().toLowerCase();
      const rows = tableBody.querySelectorAll("tr[data-search]");
      let visibleCount = 0;

      rows.forEach((row) => {
        const matches = row.dataset.search.includes(term);
        row.style.display = matches ? "" : "none";
        if (matches) visibleCount += 1;
      });

      if (paginationText) {
        paginationText.textContent = term
          ? `Showing ${visibleCount} matching requirement${visibleCount === 1 ? "" : "s"}`
          : `Showing ${rows.length} requirement${rows.length === 1 ? "" : "s"}`;
      }
    });
  }

  /* ---- Submit Add/Edit ---- */
  reqForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    formSubmit.disabled = true;

    try {
      const isEdit = formAction.value === "edit";
      const body = new URLSearchParams(new FormData(reqForm));
      const res = await fetch(endpoint, {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        body,
      });
      const data = await res.json();

      if (!data.ok) {
        showToast(data.error || "Something went wrong.", "error");
        return;
      }

      showToast(
        isEdit ? "Requirement updated." : "Requirement added.",
        "success",
      );
      setTimeout(() => window.location.reload(), 600);
    } catch (err) {
      showToast("Network error. Please try again.", "error");
    } finally {
      formSubmit.disabled = false;
    }
  });

  /* ---- Confirm Delete ---- */
  document
    .getElementById("deleteConfirm")
    .addEventListener("click", async () => {
      if (!pendingDeleteId) return;

      const confirmBtn = document.getElementById("deleteConfirm");
      confirmBtn.disabled = true;

      try {
        const body = new URLSearchParams({
          action: "delete",
          id: pendingDeleteId,
          csrf_token: csrfToken,
        });
        const res = await fetch(endpoint, {
          method: "POST",
          headers: { "X-Requested-With": "XMLHttpRequest" },
          body,
        });
        const data = await res.json();

        if (!data.ok) {
          showToast(data.error || "Could not delete requirement.", "error");
          return;
        }

        closeModal(deleteModal);
        showToast("Requirement deleted.", "success");
        setTimeout(() => window.location.reload(), 600);
      } catch (err) {
        showToast("Network error. Please try again.", "error");
      } finally {
        confirmBtn.disabled = false;
        pendingDeleteId = null;
      }
    });
});
