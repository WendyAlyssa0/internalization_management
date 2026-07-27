<?php
require_once __DIR__ . '/../config/db.php';

$pdo = db();

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$categories = $pdo->query("
    SELECT *
    FROM program_category
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/category.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <div class="page-header">
        <h2>Program Category Management</h2>
        <p>Manage program categories used in mobility and internationalization programs.</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search category...">
        </div>
        <button class="create-btn" onclick="openAddModal()">
            <i class="fa fa-plus"></i> Add Category
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Category Name</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th width="150">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if ($categories): ?>
                <?php foreach ($categories as $row): ?>
                    <?php
                        $iconClass = 'fa-tags';
                        $statusKey = strtolower(h($row['status']));
                    ?>
                    <tr data-search="<?= strtolower(h($row['category_name'] . ' ' . $row['description'])) ?>">
                        <td>
                            <div class="category-cell">
                                <div class="category-icon">
                                    <i class="fa-solid <?= $iconClass ?>"></i>
                                </div>
                                <div class="category-info">
                                    <strong><?= h($row['category_name']) ?></strong>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="description-cell"><?= h($row['description']) ?></div>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $statusKey ?>">
                                <?= h(ucfirst($row['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <button class="action-btn edit-btn"
                                    onclick='openEditModal(<?= (int)$row["id"] ?>, <?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)'>
                                <i class="fa fa-pen"></i>
                            </button>
                            <button class="action-btn delete-btn"
                                    onclick="deleteCategory(<?= (int)$row['id'] ?>)">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                                    <line x1="7" y1="7" x2="7.01" y2="7"/>
                                </svg>
                            </div>
                            <h3>No program categories found</h3>
                            <p>Click "Add Category" to create your first one.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pagination">
            <span id="paginationSummary">Showing <?= count($categories) ?> category(s)</span>
            <div class="pager" id="pagerNav"></div>
        </div>
    </div>

</div>

<!-- ============================================================
     ADD / EDIT CATEGORY MODAL
============================================================ -->
<div class="modal-overlay" id="categoryModalOverlay">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="categoryModalTitle">

        <div class="modal-head">
            <div class="modal-head-left">
                <div class="modal-icon-badge">
                    <i class="fa-solid fa-tags"></i>
                </div>
                <div class="modal-title">
                    <h3 id="categoryModalTitle">Add Category</h3>
                    <p id="categoryModalSubtitle">Create a new program category</p>
                </div>
            </div>
            <button class="modal-close" id="categoryModalClose" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-divider">
            <span></span>
            <span class="dot"></span>
            <span></span>
        </div>

        <form id="categoryForm">
            <div class="modal-body">

                <input type="hidden" name="id" id="categoryId" value="">

                <div class="modal-field">
                    <label for="categoryName">
                        <i class="fa-solid fa-tag"></i>
                        Category Name <span class="required">*</span>
                    </label>
                    <input type="text" name="category_name" id="categoryName" placeholder="e.g. Student Exchange" required>
                </div>

                <div class="modal-field">
                    <label for="categoryDescription">
                        <i class="fa-solid fa-align-left"></i>
                        Description
                    </label>
                    <textarea name="description" id="categoryDescription" placeholder="Brief description of this category..."></textarea>
                </div>

                <div class="modal-tip">
                    <span class="tip-icon"><i class="fa-solid fa-info"></i></span>
                    <span><strong>Tip:</strong> Use clear, consistent category names so they're easy to filter across programs.</span>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="categoryModalCancel">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="categorySubmitBtn">
                    <i class="fa-solid fa-floppy-disk"></i> Save Category
                </button>
            </div>
        </form>

    </div>
</div>

<script>
/* ============================================================
   SEARCH + PAGINATION
============================================================ */
const PAGE_SIZE = 6;

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
    if (confirm("Delete this category?")) {
        window.location = "delete_program_category.php?id=" + id;
    }
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

    const isEdit = document.getElementById("categoryId").value !== "";
    const endpoint = isEdit ? "update_program_category.php" : "save_program_category.php";

    const original = categorySubmitBtn.innerHTML;
    categorySubmitBtn.disabled = true;
    categorySubmitBtn.textContent = "Saving...";

    fetch(endpoint, {
        method: "POST",
        body: new FormData(this)
    })
    .then(async res => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch {
            throw new Error("Server did not return JSON");
        }
    })
    .then(data => {
        showToast(data.success ? "success" : "error", data.message || "Unknown response");
        if (data.success) {
            toggleCategoryModal(false);
            setTimeout(() => location.reload(), 1000);
        }
    })
    .catch(() => {
        showToast("error", "Server error. Please try again.");
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
</script>