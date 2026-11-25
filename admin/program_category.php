<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php'; // Added to ensure permissions work
require_once __DIR__ . '/role_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* AUTH & ROLE */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}
if (!canManagePrograms()) {
    http_response_code(403);
    exit('Access denied.');
}

$pdo = db();

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

/* =========================================================
   AJAX HANDLER
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    header('Content-Type: application/json');

    // Verify CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Session expired. Please refresh the page.']);
        exit;
    }

    $action = $_POST['action'];

    try {
        if ($action === 'add') {
            $name = trim($_POST['category_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');

            if ($name === '') {
                echo json_encode(['ok' => false, 'error' => 'Category name is required.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO program_category (category_name, description, status) VALUES (:name, :description, 'active')");
            $stmt->execute([':name' => $name, ':description' => $desc]);

            echo json_encode(['ok' => true, 'message' => 'Category added.']);
            exit;
        }

        if ($action === 'edit') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');

            if ($id <= 0 || $name === '') {
                echo json_encode(['ok' => false, 'error' => 'Invalid category data.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE program_category SET category_name = :name, description = :description WHERE id = :id");
            $stmt->execute([':name' => $name, ':description' => $desc, ':id' => $id]);

            echo json_encode(['ok' => true, 'message' => 'Category updated.']);
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Invalid category id.']);
                exit;
            }

            // Safe Delete: Catch foreign key constraint errors
            try {
                $stmt = $pdo->prepare("DELETE FROM program_category WHERE id = :id");
                $stmt->execute([':id' => $id]);
                echo json_encode(['ok' => true, 'message' => 'Category deleted.']);
            } catch (PDOException $e) {
                if ($e->getCode() == '23000') {
                    echo json_encode(['ok' => false, 'error' => 'Cannot delete: this category is currently assigned to one or more programs.']);
                } else {
                    throw $e;
                }
            }
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        exit;

    } catch (Throwable $e) {
        error_log('program_category.php error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'A server error occurred. Please try again.']);
        exit;
    }
}

/* =========================================================
   FETCH DATA
========================================================= */
$totalCategories = (int)$pdo->query("SELECT COUNT(*) FROM program_category")->fetchColumn();
$totalPages = max(1, ceil($totalCategories / $limit));

$stmt = $pdo->prepare("SELECT * FROM program_category ORDER BY id DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        <button type="button" class="create-btn" id="addCategoryBtn">
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
                    <tr data-search="<?= strtolower(h($row['category_name'] . ' ' . $row['description'])) ?>">
                        <td>
                            <div class="category-cell">
                                <div class="category-icon"><i class="fa-solid fa-tags"></i></div>
                                <div class="category-info"><strong><?= h($row['category_name']) ?></strong></div>
                            </div>
                        </td>
                        <td><?= h($row['description']) ?></td>
                        <td><span class="status-badge status-<?= strtolower(h($row['status'])) ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                        <td>
                            <button type="button" class="action-btn edit-btn"
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-name="<?= h($row['category_name']) ?>"
                                    data-description="<?= h($row['description']) ?>">
                                <i class="fa fa-pen"></i>
                            </button>
                            <button type="button" class="action-btn delete-btn"
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-name="<?= h($row['category_name']) ?>">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4" class="text-center">No program categories found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- PAGINATION -->
        <div class="pagination">
            <span>Showing <?= count($categories) ?> of <?= $totalCategories ?> categories</span>
            <div class="pages">
                <?php $baseQ = array_merge($_GET, []); unset($baseQ['p']); ?>
                <?php if ($page > 1): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page - 1]))) ?>"><i class="fa fa-angle-left"></i></a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-left"></i></button>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $i]))) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page + 1]))) ?>"><i class="fa fa-angle-right"></i></a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-right"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL FORM -->
<!-- ============================================================ -->
<div class="modal-overlay" id="categoryModalOverlay">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="categoryModalTitle">
        <div class="modal-head">
            <div class="modal-head-left">
                <div class="modal-icon-badge"><i class="fa-solid fa-tags"></i></div>
                <div class="modal-title">
                    <h3 id="categoryModalTitle">Add Category</h3>
                    <p id="categoryModalSubtitle">Create a new program category</p>
                </div>
            </div>
            <button type="button" class="modal-close" id="categoryModalClose"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form id="categoryForm" novalidate>
            <!-- ADDED HIDDEN ACTION FIELD -->
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="categoryId" value="">
            
            <div class="modal-body">
                <div class="modal-field">
                    <label for="categoryName"><i class="fa-solid fa-tag"></i> Category Name <span class="required">*</span></label>
                    <input type="text" name="category_name" id="categoryName" placeholder="e.g. Student Exchange" maxlength="150" required>
                </div>
                <div class="modal-field">
                    <label for="categoryDescription"><i class="fa-solid fa-align-left"></i> Description</label>
                    <textarea name="description" id="categoryDescription" placeholder="Brief description..." maxlength="500"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="categoryModalCancel"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" id="categorySubmitBtn"><i class="fa-solid fa-floppy-disk"></i> Save Category</button>
            </div>
        </form>
    </div>
</div>

<!-- INLINE JS (Fixes the missing category.js path issues) -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalOverlay = document.getElementById('categoryModalOverlay');
    const form = document.getElementById('categoryForm');
    const formAction = document.getElementById('formAction');
    const categoryId = document.getElementById('categoryId');
    const categoryName = document.getElementById('categoryName');
    const categoryDesc = document.getElementById('categoryDescription');
    const modalTitle = document.getElementById('categoryModalTitle');
    const modalSubtitle = document.getElementById('categoryModalSubtitle');
    const submitBtn = document.getElementById('categorySubmitBtn');
    
    const csrfToken = <?= json_encode($csrf) ?>;

    // Open Modal for ADD
    document.getElementById('addCategoryBtn').addEventListener('click', () => {
        form.reset();
        formAction.value = 'add';
        categoryId.value = '';
        modalTitle.textContent = 'Add Category';
        modalSubtitle.textContent = 'Create a new program category';
        modalOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        categoryName.focus();
    });

    // Open Modal for EDIT
    document.getElementById('tableBody').addEventListener('click', (e) => {
        const editBtn = e.target.closest('.edit-btn');
        const deleteBtn = e.target.closest('.delete-btn');

        if (editBtn) {
            form.reset();
            formAction.value = 'edit';
            categoryId.value = editBtn.dataset.id;
            categoryName.value = editBtn.dataset.name;
            categoryDesc.value = editBtn.dataset.description;
            modalTitle.textContent = 'Edit Category';
            modalSubtitle.textContent = 'Update category details';
            modalOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            categoryName.focus();
        }

        if (deleteBtn) {
            const id = deleteBtn.dataset.id;
            const name = deleteBtn.dataset.name;
            if (!confirm(`Are you sure you want to delete "${name}"?`)) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            formData.append('csrf_token', csrfToken);

            fetch('', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.ok) {
                        deleteBtn.closest('tr').remove();
                    } else {
                        alert(data.error);
                    }
                })
                .catch(() => alert('Server error.'));
        }
    });

    // Close Modal
    const closeModal = () => {
        modalOverlay.classList.remove('active', 'visible', 'open');
        document.body.style.overflow = '';
    };

    document.getElementById('categoryModalClose').addEventListener('click', closeModal);
    document.getElementById('categoryModalCancel').addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modalOverlay.classList.contains('active')) closeModal();
    });
    
    // Submit Form via AJAX
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!categoryName.value.trim()) return alert('Category name is required.');

        const formData = new FormData(form);
        formData.append('csrf_token', csrfToken); // Add CSRF token to payload

        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    location.reload();
                } else {
                    alert(data.error || 'An error occurred.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(() => {
                alert('Server error.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
    });

    // Live Search
    document.getElementById('searchInput').addEventListener('input', function() {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#tableBody tr[data-search]').forEach(row => {
            row.style.display = row.dataset.search.includes(q) ? '' : 'none';
        });
    });
});
</script>