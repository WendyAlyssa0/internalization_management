<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

/* =========================
   AUTHENTICATION & AUTHORIZATION
========================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

// FIXED: Changed $_SESSION['role'] to $_SESSION['user_role']
$role = strtolower(trim($_SESSION['user_role'] ?? ''));
$role = str_replace('_', ' ', $role); // Normalizes "super_admin" to "super admin"

$canManageAgreements = in_array($role, [
    'super admin',
    'admin',               // Added admin just in case they need access
    'campus coordinator',
    'ovplia staff',
], true);

if (!$canManageAgreements) {
    http_response_code(403);
    exit('Forbidden — you do not have permission to manage agreement types.');
}

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
        $name       = trim((string)($_POST['agreement_name'] ?? ''));
        $desc       = trim((string)($_POST['description']    ?? ''));
        $start_date = trim((string)($_POST['start_date']     ?? ''));
        $end_date   = trim((string)($_POST['end_date']       ?? ''));
        
        $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive'])
                    ? $_POST['status'] : 'Active';

        /* VALIDATION */
        if ($name === '') {
            echo json_encode(['ok' => false, 'error' => 'Agreement name is required.']);
            exit();
        }
        if (mb_strlen($name) > 150) {
            echo json_encode(['ok' => false, 'error' => 'Agreement name is too long.']);
            exit();
        }
        if (mb_strlen($desc) > 1000) {
            echo json_encode(['ok' => false, 'error' => 'Description is too long.']);
            exit();
        }
        
        if ($start_date === '' || $end_date === '') {
            echo json_encode(['ok' => false, 'error' => 'Start date and End date are required.']);
            exit();
        }

        $startTs = strtotime($start_date);
        $endTs   = strtotime($end_date);

        if ($startTs === false || $endTs === false) {
            echo json_encode(['ok' => false, 'error' => 'Invalid date format.']);
            exit();
        }

        if ($endTs < $startTs) {
            echo json_encode(['ok' => false, 'error' => 'End date cannot be before the Start date.']);
            exit();
        }

        /* DUPLICATE CHECK */
        if ($action === 'add') {
            $dup = $pdo->prepare("SELECT id FROM agreement_types WHERE agreement_name = ?");
            $dup->execute([$name]);
            if ($dup->fetchColumn()) {
                echo json_encode(['ok' => false, 'error' => 'This agreement type already exists.']);
                exit();
            }

            $stmt = $pdo->prepare("
                INSERT INTO agreement_types (agreement_name, description, start_date, end_date, status)
                VALUES (?, ?, ?, ?, 'Active')
            ");
            $stmt->execute([$name, $desc, $start_date, $end_date]);
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Invalid record.']);
                exit();
            }

            $dup = $pdo->prepare("SELECT id FROM agreement_types WHERE agreement_name = ? AND id <> ?");
            $dup->execute([$name, $id]);
            if ($dup->fetchColumn()) {
                echo json_encode(['ok' => false, 'error' => 'Another agreement type already uses this name.']);
                exit();
            }

            $stmt = $pdo->prepare("
                UPDATE agreement_types
                SET agreement_name = ?, description = ?, start_date = ?, end_date = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $desc, $start_date, $end_date, $status, $id]);
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

        /* SAFETY: prevent delete if referenced by partners */
        $ref = $pdo->prepare("SELECT COUNT(*) FROM partners WHERE agreement_type_id = ?");
        $ref->execute([$id]);
        if ((int)$ref->fetchColumn() > 0) {
            echo json_encode(['ok' => false, 'error' => 'Cannot delete — this agreement type is used by one or more partners.']);
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
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM agreement_types")->fetchColumn();
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
          <th>Validity Period</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="agreementsBody">

    <?php if (!empty($agreementTypes)): ?>
        <?php foreach ($agreementTypes as $row): ?>
            <?php 
                // Format dates for display (fallback if null)
                $sDate = !empty($row['start_date']) ? date('M j, Y', strtotime($row['start_date'])) : '—';
                $eDate = !empty($row['end_date']) ? date('M j, Y', strtotime($row['end_date'])) : '—';
            ?>
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

              <td class="description-cell" title="<?= htmlspecialchars($row['description']) ?>">
                <?= htmlspecialchars($row['description']) ?>
              </td>

              <!-- ADDED: Validity Period Column -->
              <td class="date-cell">
                  <span class="date-text"><?= h($sDate) ?></span>
                  <i class="fa-solid fa-arrow-right" style="font-size:10px; color:#94a3b8; margin:0 4px;"></i>
                  <span class="date-text"><?= h($eDate) ?></span>
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
                          data-start="<?= htmlspecialchars($row['start_date'] ?? '', ENT_QUOTES) ?>"
                          data-end="<?= htmlspecialchars($row['end_date'] ?? '', ENT_QUOTES) ?>"
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
          <input type="text" id="agreement_name" name="agreement_name" class="form-input" placeholder="e.g. Memorandum of Agreement" required maxlength="150">
        </div>

        <div class="form-group">
          <label class="form-label" for="description">
            <i class="fa-solid fa-align-left"></i> Description
          </label>
          <textarea id="description" name="description" class="form-input" placeholder="Briefly describe this agreement type…" maxlength="1000"></textarea>
        </div>

        <!-- ADDED: Date Fields -->
        <div style="display: flex; gap: 15px;">
            <div class="form-group" style="flex: 1;">
              <label class="form-label" for="start_date">
                <i class="fa-regular fa-calendar"></i> Start Date
              </label>
              <input type="date" id="start_date" name="start_date" class="form-input" required>
            </div>
            
            <div class="form-group" style="flex: 1;">
              <label class="form-label" for="end_date">
                <i class="fa-regular fa-calendar-check"></i> End Date
              </label>
              <input type="date" id="end_date" name="end_date" class="form-input" required>
            </div>
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

<!-- ... DELETE MODAL and TOAST remain unchanged ... -->

<script>
window.AGREEMENTS_CSRF = <?= json_encode($csrfToken) ?>;
</script>
<script src="../asset/js/agreement_types.js"></script>