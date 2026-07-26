(function () {
  "use strict";

  const CSRF = window.AGREEMENTS_CSRF;

  /* ── helpers ── */
  const $ = (id) => document.getElementById(id);
  const qs = (sel) => document.querySelector(sel);

  /* ── elements ── */
  const agreementModal = $("agreementModal");
  const deleteModal = $("deleteModal");
  const toast = $("toast");
  const toastMsg = $("toastMessage");
  const body = $("agreementsBody");
  const paginationText = $("paginationText");
  const searchInput = $("searchInput");

  /* ── toast ── */
  let toastTimer;
  function showToast(msg, type = "success") {
    toastMsg.textContent = msg;
    toast.className = "toast toast--" + type + " toast--show";
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toast.className = "toast";
    }, 3000);
  }

  /* ── modal helpers ── */
  function openModal(el) {
    el.classList.add("open");
  }
  function closeModal(el) {
    el.classList.remove("open");
  }

  /* ── ADD button ── */
  $("btnAdd").addEventListener("click", () => {
    $("modalTitle").textContent = "Add Agreement Type";
    $("modalSubtitle").textContent = "Create a new agreement type";
    $("modalIcon").className = "fa-solid fa-file-signature";
    $("formAction").value = "add";
    $("formId").value = "";
    $("formSubmitLabel").textContent = "Save Agreement Type";
    $("agreement_name").value = "";
    $("description").value = "";
    $("statusGroup").style.display = "none";
    $("agreementForm").reset();
    openModal(agreementModal);
    setTimeout(() => $("agreement_name").focus(), 120);
  });

  /* ── EDIT buttons ── */
  body.addEventListener("click", (e) => {
    const editBtn = e.target.closest(".edit-btn");
    if (editBtn) {
      $("modalTitle").textContent = "Edit Agreement Type";
      $("modalSubtitle").textContent = "Update this agreement type";
      $("modalIcon").className = "fa-solid fa-pen";
      $("formAction").value = "edit";
      $("formId").value = editBtn.dataset.id;
      $("formSubmitLabel").textContent = "Update Agreement Type";
      $("agreement_name").value = editBtn.dataset.name;
      $("description").value = editBtn.dataset.desc;
      $("status").value = editBtn.dataset.status;
      $("statusGroup").style.display = "flex";
      openModal(agreementModal);
      setTimeout(() => $("agreement_name").focus(), 120);
    }
  });

  /* ── close add/edit modal ── */
  $("modalClose").addEventListener("click", () => closeModal(agreementModal));
  $("modalCancel").addEventListener("click", () => closeModal(agreementModal));
  agreementModal.addEventListener("click", (e) => {
    if (e.target === agreementModal) closeModal(agreementModal);
  });

  /* ── SUBMIT add/edit ── */
  $("agreementForm").addEventListener("submit", async (e) => {
    e.preventDefault();

    const btn = $("formSubmit");
    btn.disabled = true;

    const fd = new FormData(e.target);
    fd.set("csrf_token", CSRF);

    try {
      const res = await fetch("", { method: "POST", body: fd });
      const data = await res.json();

      if (data.ok) {
        closeModal(agreementModal);
        showToast(
          $("formAction").value === "add"
            ? "Agreement type added successfully"
            : "Agreement type updated successfully",
        );
        setTimeout(() => location.reload(), 900);
      } else {
        showToast(data.error || "Something went wrong.", "error");
      }
    } catch {
      showToast("Network error. Please try again.", "error");
    } finally {
      btn.disabled = false;
    }
  });

  /* ── DELETE buttons ── */
  let deleteId = null;

  body.addEventListener("click", (e) => {
    const delBtn = e.target.closest(".delete-btn");
    if (delBtn) {
      deleteId = delBtn.dataset.id;
      openModal(deleteModal);
    }
  });

  $("deleteModalClose").addEventListener("click", () =>
    closeModal(deleteModal),
  );
  $("deleteCancel").addEventListener("click", () => closeModal(deleteModal));
  deleteModal.addEventListener("click", (e) => {
    if (e.target === deleteModal) closeModal(deleteModal);
  });

  $("deleteConfirm").addEventListener("click", async () => {
    if (!deleteId) return;

    const btn = $("deleteConfirm");
    btn.disabled = true;

    const fd = new FormData();
    fd.append("csrf_token", CSRF);
    fd.append("action", "delete");
    fd.append("id", deleteId);

    try {
      const res = await fetch("", { method: "POST", body: fd });
      const data = await res.json();

      if (data.ok) {
        closeModal(deleteModal);
        showToast("Agreement type deleted");

        const row = body.querySelector(`tr[data-id="${deleteId}"]`);
        if (row) row.remove();

        const remaining = body.querySelectorAll("tr:not(.empty-row)").length;
        if (remaining === 0) {
          body.innerHTML =
            '<tr class="empty-row"><td colspan="5">No agreement types found</td></tr>';
        }
        updateCount();
        deleteId = null;
      } else {
        showToast(data.error || "Delete failed.", "error");
      }
    } catch {
      showToast("Network error. Please try again.", "error");
    } finally {
      btn.disabled = false;
    }
  });

  /* ── row count ── */
  function updateCount() {
    const n = body.querySelectorAll("tr:not(.empty-row)").length;
    paginationText.textContent =
      "Showing " + n + " agreement type" + (n === 1 ? "" : "s");
  }

  /* ── search ── */
  searchInput.addEventListener("input", () => {
    const q = searchInput.value.trim().toLowerCase();
    let visible = 0;

    body.querySelectorAll("tr[data-search]").forEach((row) => {
      const match = row.dataset.search.includes(q);
      row.style.display = match ? "" : "none";
      if (match) visible++;
    });

    const emptyRow = body.querySelector(".empty-row");
    if (emptyRow) emptyRow.style.display = visible === 0 ? "" : "none";

    paginationText.textContent =
      "Showing " + visible + " agreement type" + (visible === 1 ? "" : "s");
  });
})();
