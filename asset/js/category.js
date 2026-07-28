const PAGE_SIZE = 5;

const allRows          = Array.from(document.querySelectorAll("#tableBody tr[data-search]"));
const pagerNav         = document.getElementById("pagerNav");
const paginationSummary = document.getElementById("paginationSummary");
const searchInput      = document.getElementById("searchInput");

let currentPage = 1;

function getFilteredRows() {
    const keyword = searchInput.value.toLowerCase();
    return allRows.filter(row => (row.dataset.search || "").includes(keyword));
}

function renderTable() {
    const filtered  = getFilteredRows();
    const total     = filtered.length;
    const pageCount = Math.max(1, Math.ceil(total / PAGE_SIZE));

    if (currentPage > pageCount) currentPage = pageCount;

    allRows.forEach(row => row.style.display = "none");

    const start = (currentPage - 1) * PAGE_SIZE;
    const pageRows = filtered.slice(start, start + PAGE_SIZE);
    pageRows.forEach(row => row.style.display = "");

    if (total === 0) {
        paginationSummary.textContent = "Showing 0 category(s)";
    } else {
        paginationSummary.textContent =
            `Showing ${start + 1}–${Math.min(start + PAGE_SIZE, total)} of ${total} category(s)`;
    }

    renderPager(pageCount);
}

function renderPager(pageCount) {
    pagerNav.innerHTML = "";

    pagerNav.appendChild(makePagerBtn({
        label: '<i class="fa-solid fa-chevron-left"></i>',
        disabled: currentPage === 1,
        onClick: () => goToPage(currentPage - 1),
    }));

    const pages = getPageList(currentPage, pageCount);
    pages.forEach(p => {
        if (p === "...") {
            const span = document.createElement("span");
            span.className = "pager-ellipsis";
            span.textContent = "...";
            pagerNav.appendChild(span);
        } else {
            pagerNav.appendChild(makePagerBtn({
                label: String(p),
                active: p === currentPage,
                onClick: () => goToPage(p),
            }));
        }
    });

    pagerNav.appendChild(makePagerBtn({
        label: '<i class="fa-solid fa-chevron-right"></i>',
        disabled: currentPage === pageCount,
        onClick: () => goToPage(currentPage + 1),
    }));
}

function makePagerBtn({ label, active = false, disabled = false, onClick }) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "pager-btn" + (active ? " active" : "");
    btn.innerHTML = label;
    btn.disabled = disabled;
    btn.addEventListener("click", onClick);
    return btn;
}

function getPageList(current, total) {
    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }
    const pages = [1];
    if (current > 3) pages.push("...");
    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);
    for (let i = start; i <= end; i++) pages.push(i);
    if (current < total - 2) pages.push("...");
    pages.push(total);
    return pages;
}

function goToPage(page) {
    currentPage = page;
    renderTable();
}

searchInput.addEventListener("input", function () {
    currentPage = 1;
    renderTable();
});

renderTable();

/* ============================================================
   MODAL
============================================================ */
const categoryModalOverlay = document.getElementById("categoryModalOverlay");
const categoryForm         = document.getElementById("categoryForm");
const categoryModalTitle   = document.getElementById("categoryModalTitle");
const categoryModalSub     = document.getElementById("categoryModalSubtitle");
const categorySubmitBtn    = document.getElementById("categorySubmitBtn");

function toggleCategoryModal(open) {
    categoryModalOverlay.classList.toggle("visible", open);
    document.body.style.overflow = open ? "hidden" : "";
}

function openAddModal() {
    categoryForm.reset();
    document.getElementById("categoryId").value = "";
    categoryModalTitle.textContent = "Add Category";
    categoryModalSub.textContent = "Create a new program category";
    toggleCategoryModal(true);
}

function openEditModal(id, row) {
    if (typeof row === "string") {
        try { row = JSON.parse(row); } catch { return; }
    }
    document.getElementById("categoryId").value = id;
    document.getElementById("categoryName").value = row.category_name || "";
    document.getElementById("categoryDescription").value = row.description || "";
    categoryModalTitle.textContent = "Edit Category";
    categoryModalSub.textContent = "Update this program category";
    toggleCategoryModal(true);
}

function deleteCategory(id) {

    if (!confirm("Delete this category?")) return;

    const endpoint = window.location.pathname + window.location.search;

    const body = new URLSearchParams({
        action: "delete",
        id: id
    });

    fetch(endpoint, {
        method: "POST",
        body: new URLSearchParams({
            action: "delete",
            id: id
        })
    })
    .then(async (res) => {
        const text = await res.text();

        console.log("STATUS:", res.status);
        console.log("RAW RESPONSE:");
        console.log(text);

        return JSON.parse(text);
    })
    .then(data => {
        showToast(data.ok ? "success" : "error", data.message || data.error);

        if (data.ok) {
            setTimeout(() => location.reload(), 700);
        }
    })
    .catch(() => {
        showToast("error", "Server error.");
    });
}

document.getElementById("categoryModalClose").addEventListener("click", () => toggleCategoryModal(false));
document.getElementById("categoryModalCancel").addEventListener("click", () => toggleCategoryModal(false));
categoryModalOverlay.addEventListener("click", function (e) {
    if (e.target === categoryModalOverlay) toggleCategoryModal(false);
});

/* ============================================================
   FORM SUBMIT (AJAX)
============================================================ */
categoryForm.addEventListener("submit", function (e) {
    e.preventDefault();

    const endpoint = window.location.pathname + window.location.search;

    const formData = new FormData(this);

    if (document.getElementById("categoryId").value) {
        formData.append("action", "edit");
    } else {
        formData.append("action", "add");
    }

    const original = categorySubmitBtn.innerHTML;
    categorySubmitBtn.disabled = true;
    categorySubmitBtn.innerHTML = "Saving...";

    fetch(endpoint, {
        method: "POST",
        body: new URLSearchParams({
            action: "delete",
            id: id
        })
    })
    .then(async (res) => {
        const text = await res.text();

        console.log("STATUS:", res.status);
        console.log("RAW RESPONSE:");
        console.log(text);

        return JSON.parse(text);
    })
    .then(data => {
        showToast(data.ok ? "success" : "error", data.message || data.error);

        if (data.ok) {
            toggleCategoryModal(false);
            setTimeout(() => location.reload(), 700);
        }
    })
    .catch(() => {
        showToast("error", "Server error.");
    })
    .finally(() => {
        categorySubmitBtn.disabled = false;
        categorySubmitBtn.innerHTML = original;
    });
});

/* ============================================================
   ESC KEY HANDLER
============================================================ */
document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") toggleCategoryModal(false);
});

/* ============================================================
   TOAST NOTIFICATION
============================================================ */
function showToast(type, message) {
    const existing = document.querySelector(".toast");
    if (existing) existing.remove();

    const toast = document.createElement("div");
    toast.className = `toast toast--${type}`;
    toast.innerHTML = `
        <i class="fa fa-${type === "success" ? "check-circle" : "exclamation-circle"}"></i>
        <span></span>
    `;
    toast.querySelector("span").textContent = message;

    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add("toast--show"));

    setTimeout(() => {
        toast.classList.remove("toast--show");
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}