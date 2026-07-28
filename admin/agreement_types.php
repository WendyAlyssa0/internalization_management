<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


require_once __DIR__ . '/../config/db.php';

$pdo = db();

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

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

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* ===================================================
   AJAX ENDPOINTS
   =================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    checkCsrf();

    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $name   = trim((string)($_POST['agreement_name'] ?? ''));
        $desc   = trim((string)($_POST['description']    ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive'])
                    ? $_POST['status'] : 'Active';

        if ($name === '') {
            echo json_encode(['ok' => false, 'error' => 'Agreement name is required.']);
            exit();
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO agreement_types (agreement_name, description, status)
                VALUES (?, ?, 'Active')
            ");
            $stmt->execute([$name, $desc]);
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Invalid record.']);
                exit();
            }
            $stmt = $pdo->prepare("
                UPDATE agreement_types
                SET agreement_name = ?, description = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $desc, $status, $id]);
        }

        echo json_encode(['ok' => true]);
        exit();
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid record.']);
            exit();
        }
        $stmt = $pdo->prepare("DELETE FROM agreement_types WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        exit();
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit();
}

/* Total records */
$totalCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM agreement_types
")->fetchColumn();

$totalPages = max(1, ceil($totalCount / $limit));

/* Fetch current page only */
$stmt = $pdo->prepare("
    SELECT *
    FROM agreement_types
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
");

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$agreementTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="../asset/css/agreement_types.css">

<div class="content">

  <div class="page-header">
    <h2>Agreement Type Management</h2>
    <p>Define and manage agreement types used across applicant contracts.</p>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="searchInput" placeholder="Search agreement types…">
    </div>
    <button type="button" class="create-btn" id="btnAdd">
      <i class="fa-solid fa-plus"></i>
      Add Agreement Type
    </button>
  </div>

  <div class="table-card">

    <table id="agreementsTable">
      <thead>
        <tr>
          <th>Agreement Type</th>
          <th>Description</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="agreementsBody">

    <?php if (!empty($agreementTypes)): ?>
        <?php foreach ($agreementTypes as $row): ?>

            <tr data-id="<?= (int)$row['id'] ?>"
                data-search="<?= htmlspecialchars(strtolower($row['agreement_name'] . ' ' . $row['description'])) ?>">

              <td>
                  <div class="agreement-cell">
                      <div class="agreement-icon">
                          <i class="fa-solid fa-handshake"></i>
                      </div>
                      <div class="agreement-info">
                          <strong><?= htmlspecialchars($row['agreement_name']) ?></strong>
                      </div>
                  </div>
              </td>

              <td class="description-cell"
                  title="<?= htmlspecialchars($row['description']) ?>">
                <?= htmlspecialchars($row['description']) ?>
              </td>

              <td>
                <?php $isActive = $row['status'] === 'Active'; ?>
                <span class="badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>">
                  <?= htmlspecialchars($row['status']) ?>
                </span>
              </td>

              <td>
                <div class="actions">
                  <button type="button"
                          class="action-btn edit-btn"
                          title="Edit"
                          data-id="<?= (int)$row['id'] ?>"
                          data-name="<?= htmlspecialchars($row['agreement_name'], ENT_QUOTES) ?>"
                          data-desc="<?= htmlspecialchars($row['description'],    ENT_QUOTES) ?>"
                          data-status="<?= htmlspecialchars($row['status'],       ENT_QUOTES) ?>">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button type="button"
                          class="action-btn delete-btn"
                          title="Delete"
                          data-id="<?= (int)$row['id'] ?>">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </td>

            </tr>

          <?php endforeach; ?>
        <?php else: ?>
          <tr class="empty-row">
            <td colspan="5">No agreement types found</td>
          </tr>
        <?php endif; ?>

      </tbody>
    </table>


<!-- PAGINATION -->
        <div class="pagination">
            <span>
                Showing <?= count($agreementTypes) ?> of <?= $totalCount ?> Agreement Types
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
</div><!-- /.content -->


<!-- ===================== ADD / EDIT MODAL ===================== -->
<div class="modal-overlay" id="agreementModal">
  <div class="modal-box">

    <div class="modal-header">
      <div class="modal-header-left">
        <div class="modal-icon">
          <i class="fa-solid fa-file-signature" id="modalIcon"></i>
        </div>
        <div>
          <h3 id="modalTitle">Add Agreement Type</h3>
          <p class="modal-subtitle" id="modalSubtitle">Create a new agreement type</p>
        </div>
      </div>
      <button type="button" class="modal-close" id="modalClose">&times;</button>
    </div>

    <form id="agreementForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action"     id="formAction" value="add">
      <input type="hidden" name="id"         id="formId"     value="">

      <div class="modal-body">

        <div class="form-group">
          <label class="form-label" for="agreement_name">
            <i class="fa-solid fa-tag"></i> Agreement Type Name
          </label>
          <input type="text"
                id="agreement_name"
                name="agreement_name"
                class="form-input"
                placeholder="e.g. Memorandum of Agreement"
                required
                maxlength="150">
        </div>

        <div class="form-group">
          <label class="form-label" for="description">
            <i class="fa-solid fa-align-left"></i> Description
          </label>
          <textarea id="description"
                    name="description"
                    class="form-input"
                    placeholder="Briefly describe this agreement type…"
                    maxlength="1000"></textarea>
        </div>

        <div class="form-group" id="statusGroup" style="display:none;">
          <label class="form-label" for="status">
            <i class="fa-solid fa-circle-dot"></i> Status
          </label>
          <select id="status" name="status" class="form-input">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="modalCancel">Cancel</button>
        <button type="submit" class="btn-confirm"   id="formSubmit">
          <i class="fa-solid fa-check"></i>
          <span id="formSubmitLabel">Save Agreement Type</span>
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
          <h3>Delete Agreement Type</h3>
          <p class="modal-subtitle">This action cannot be undone</p>
        </div>
      </div>
      <button type="button" class="modal-close" id="deleteModalClose">&times;</button>
    </div>

    <div class="modal-body">
      <p style="font-size:13.5px;color:#475569;line-height:1.6;">
        Are you sure you want to permanently delete this agreement type?
      </p>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn-secondary" id="deleteCancel">Cancel</button>
      <button type="button" class="btn-confirm"   id="deleteConfirm" style="background:#dc2626;">
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
window.AGREEMENTS_CSRF = <?= json_encode($csrfToken) ?>;
</script>
<script src="../asset/js/agreement_types.js"></script>