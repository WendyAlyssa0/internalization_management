<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = db();

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function checkCsrf(): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit();
    }
}

/* ===================================================
   AJAX ENDPOINTS (all POST, all CSRF-checked)
   =================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    checkCsrf();

    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $name = trim((string)($_POST['requirement_name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));

        if ($name === '') {
            echo json_encode(['ok' => false, 'error' => 'Requirement name is required.']);
            exit();
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO requirements (requirement_name, description, created_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$name, $desc]);
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Invalid requirement.']);
                exit();
            }
            $stmt = $pdo->prepare("
                UPDATE requirements
                SET requirement_name = ?, description = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $desc, $id]);
        }

        echo json_encode(['ok' => true]);
        exit();
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid requirement.']);
            exit();
        }

        $stmt = $pdo->prepare("
            DELETE FROM requirements
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        echo json_encode(['ok' => true]);
        exit();
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit();
}

/* FETCH */
$totalCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM requirements
")->fetchColumn();

$totalPages = max(1, ceil($totalCount / $limit));

$stmt = $pdo->prepare("
    SELECT *
    FROM requirements
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="../asset/css/requirements.css">

<div class="content">

  <div class="page-header">
    <h2>Document Requirements</h2>
    <p>Manage the requirements applicants must submit for their program.</p>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="searchInput" placeholder="Search requirements…">
    </div>

    <button type="button" class="create-btn" id="btnAddRequirement">
      <i class="fa-solid fa-plus"></i>
      Add Requirement
    </button>
  </div>

  <div class="table-card">

    <table id="requirementsTable">

      <thead>
        <tr>
          <th>Requirement Name</th>
          <th>Description</th>
          <th>Date Added</th>
          <th>Action</th>
        </tr>
      </thead>

      <tbody id="requirementsBody">

        <?php if (!empty($requirements)): ?>

          <?php foreach ($requirements as $r): ?>

            <?php
              $dateAdded = '—';
              if (!empty($r['created_at'])) {
                  $dateAdded = date('M j, Y', strtotime($r['created_at']));
              }
            ?>

            <tr data-id="<?= (int)$r['id'] ?>"
                data-search="<?= htmlspecialchars(strtolower($r['requirement_name'] . ' ' . $r['description'])) ?>">
              <td class="name-cell"><?= htmlspecialchars($r['requirement_name']) ?></td>
              <td class="description-cell" title="<?= htmlspecialchars($r['description']) ?>">
                <?= htmlspecialchars($r['description']) ?>
              </td>
              <td class="date-cell"><?= htmlspecialchars($dateAdded) ?></td>
              <td>
                <div class="actions">
                  <button type="button"
                          class="action-btn edit-btn"
                          title="Edit"
                          data-id="<?= (int)$r['id'] ?>"
                          data-name="<?= htmlspecialchars($r['requirement_name'], ENT_QUOTES) ?>"
                          data-desc="<?= htmlspecialchars($r['description'], ENT_QUOTES) ?>">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button type="button"
                          class="action-btn delete-btn"
                          title="Delete"
                          data-id="<?= (int)$r['id'] ?>">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>

          <?php endforeach; ?>

        <?php else: ?>

          <tr class="empty-row">
            <td colspan="5">No requirements found</td>
          </tr>

        <?php endif; ?>

      </tbody>

    </table>

<!-- PAGINATION -->
        <div class="pagination">
            <span>
                Showing <?= count($requirements) ?> of <?= $totalCount ?> requirement<?= $totalCount === 1 ? '' : 's' ?>
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

<!-- ===================== ADD / EDIT MODAL ===================== -->
<div class="modal-overlay" id="requirementModal">
  <div class="modal-box">

    <div class="modal-header">
      <div class="modal-header-left">
        <div class="modal-icon"><i class="fa-solid fa-file-circle-plus" id="modalIcon"></i></div>
        <div>
          <h3 id="modalTitle">Add Requirement</h3>
          <p class="modal-subtitle" id="modalSubtitle">Create a new document requirement</p>
        </div>
      </div>
      <button type="button" class="modal-close" id="modalClose">&times;</button>
    </div>

    <form id="requirementForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" id="formAction" value="add">
      <input type="hidden" name="id" id="formId" value="">

      <div class="modal-body">

        <div class="form-group">
          <label class="form-label" for="requirement_name">
            <i class="fa-solid fa-tag"></i> Requirement Name
          </label>
          <input type="text" id="requirement_name" name="requirement_name"
                 class="form-input" placeholder="e.g. Valid Passport Copy"
                 required maxlength="150">
        </div>

        <div class="form-group">
          <label class="form-label" for="description">
            <i class="fa-solid fa-align-left"></i> Description
          </label>
          <textarea id="description" name="description" class="form-input"
                    placeholder="Briefly describe what's required…" maxlength="1000"></textarea>
        </div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="modalCancel">Cancel</button>
        <button type="submit" class="btn-confirm" id="formSubmit">
          <i class="fa-solid fa-check"></i>
          <span id="formSubmitLabel">Save Requirement</span>
        </button>
      </div>
    </form>

  </div>
</div>

<!-- ===================== DELETE CONFIRM MODAL ===================== -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box" style="width:420px;">

    <div class="modal-header">
      <div class="modal-header-left">
        <div class="modal-icon" style="background:#fff5f5;color:#dc2626;">
          <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div>
          <h3>Delete Requirement</h3>
          <p class="modal-subtitle">This action cannot be undone</p>
        </div>
      </div>
      <button type="button" class="modal-close" id="deleteModalClose">&times;</button>
    </div>

    <div class="modal-body">
      <p style="font-size:13.5px;color:#475569;line-height:1.6;">
        Are you sure you want to permanently delete this requirement?
      </p>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn-secondary" id="deleteCancel">Cancel</button>
      <button type="button" class="btn-confirm" id="deleteConfirm" style="background:#dc2626;">
        <i class="fa-solid fa-trash"></i> Delete
      </button>
    </div>

  </div>
</div>

<!-- ===================== TOAST ===================== -->
<div class="toast" id="toast">
  <i class="fa-solid fa-circle-check"></i>
  <span id="toastMessage">Saved successfully</span>
</div>

<script>
  window.REQUIREMENTS_CSRF = <?= json_encode($csrfToken) ?>;
</script>
<script src="../asset/js/requirements.js"></script>