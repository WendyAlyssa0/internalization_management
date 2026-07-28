<?php
require_once __DIR__ . '/../config/db.php';

$pdo = db();

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    header('Content-Type: application/json');

    $action = $_POST['action'];

    try {

        if ($action === 'add') {
            $name = trim($_POST['category_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');

            if ($name === '') {
                echo json_encode(['ok' => false, 'error' => 'Category name is required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO program_category (category_name, description, status)
                VALUES (:name, :description, 'active')
            ");
            $stmt->execute([
                ':name'        => $name,
                ':description' => $desc,
            ]);

            echo json_encode(['ok' => true, 'message' => 'Category added.']);
            exit;
        }

        if ($action === 'edit') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');

            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Invalid category id.']);
                exit;
            }
            if ($name === '') {
                echo json_encode(['ok' => false, 'error' => 'Category name is required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE program_category
                SET category_name = :name, description = :description
                WHERE id = :id
            ");
            $stmt->execute([
                ':name'        => $name,
                ':description' => $desc,
                ':id'          => $id,
            ]);

            echo json_encode(['ok' => true, 'message' => 'Category updated.']);
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Invalid category id.']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM program_category WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode(['ok' => true, 'message' => 'Category deleted.']);
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

// Total number of categories
$totalCategories = (int)$pdo->query("
    SELECT COUNT(*) FROM program_category
")->fetchColumn();

// Total pages
$totalPages = max(1, ceil($totalCategories / $limit));

// Fetch only the current page
$stmt = $pdo->prepare("
    SELECT *
    FROM program_category
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
");

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/category.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

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

    <!-- PAGINATION -->
        <div class="pagination">
            <span>
                Showing <?= count($categories) ?> of <?= $totalCategories ?> categories
            </span>
            <div class="pages">
                <?php
                    $baseQ = array_merge($_GET, []);
                    unset($baseQ['p']);
                ?>
                <?php if ($page > 1): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page - 1]))) ?>">
                        <i class="fa fa-angle-left"></i>
                    </a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-left"></i></button>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $i]))) ?>"
                    class="<?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page + 1]))) ?>">
                        <i class="fa fa-angle-right"></i>
                    </a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-right"></i></button>
                <?php endif; ?>
            </div>
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

<script src="../asset/js/category.js"></script>