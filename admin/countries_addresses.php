<?php
ob_start();
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
   AJAX ENDPOINTS (all POST, all CSRF-checked)
   =================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    checkCsrf();

    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $country = trim((string)($_POST['country_name'] ?? ''));
        $region    = trim((string)($_POST['region'] ?? ''));
        $province    = trim((string)($_POST['province'] ?? ''));
        $city    = trim((string)($_POST['city'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));

        if ($country === '') {
            echo json_encode(['ok' => false, 'error' => 'Country name is required.']);
            exit();
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO countries_addresses 
                (country_name, region, province, city, street_address, status)
                VALUES (?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$country, $region, $province, $city, $address]);
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Invalid record.']);
                exit();
            }
            $stmt = $pdo->prepare("
                UPDATE countries_addresses
                SET country_name = ?, 
                region = ?, 
                province = ?,
                city = ?, 
                street_address = ?
                WHERE id = ?
            ");
            $stmt->execute([$country, $region, $province, $city, $address, $id]);
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

        $stmt = $pdo->prepare("DELETE FROM countries_addresses WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['ok' => true]);
        exit();
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid record.']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT status FROM countries_addresses WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            echo json_encode(['ok' => false, 'error' => 'Record not found.']);
            exit();
        }

        $newStatus = ($current === 'Active') ? 'Inactive' : 'Active';

        $stmt = $pdo->prepare("UPDATE countries_addresses SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);

        echo json_encode(['ok' => true, 'status' => $newStatus]);
        exit();
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit();
}

/* FETCH */
$totalCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM countries_addresses
")->fetchColumn();

$totalPages = max(1, ceil($totalCount / $limit));

$stmt = $pdo->prepare("
    SELECT *
    FROM countries_addresses
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$countries = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="../asset/css/cmodal.css">

<div class="content">

  <div class="page-header">
    <h2>Country & Address Management</h2>
    <p>Manage partner countries and their associated addresses.</p>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="searchInput" placeholder="Search countries…">
    </div>
    <button type="button" class="create-btn" id="btnAddCountry">
      <i class="fa-solid fa-plus"></i>
      Add Country / Address
    </button>
  </div>

  <div class="table-card">
    <table id="countriesTable">
      <thead>
        <tr>
          <th>Country</th>
          <th>Region</th>
          <th>Province</th>
          <th>City</th>
          <th>Street Address</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
          <tbody id="countriesBody">

            <?php if (!empty($countries)): ?>
              <?php foreach ($countries as $row): ?>
                <?php
                  $status   = $row['status'] ?? 'Active';
                  $isActive = ($status === 'Active');
                ?>
            <tr data-id="<?= (int)$row['id'] ?>"
                data-search="<?= htmlspecialchars(strtolower($row['country_name'].' '.$row['region'].' '.$row['province'].' '.$row['city'].' '.$row['street_address'])) ?>">
                <!-- Country -->
                <td>
                    <div class="country-cell">
                        <div class="country-icon">
                            <i class="fa fa-flag"></i>
                        </div>
                        <div class="country-info">
                            <strong><?= htmlspecialchars($row['country_name']) ?></strong>
                        </div>
                    </div>
                </td>

                <td><?= htmlspecialchars($row['region']) ?></td>
                <td><?= htmlspecialchars($row['province']) ?></td>
                <!-- City -->
                <td><?= htmlspecialchars($row['city']) ?></td>

                <!-- Address -->
                <td title="<?= htmlspecialchars($row['street_address']) ?>">
                    <?= htmlspecialchars($row['street_address']) ?>
                </td>

                <!-- Status -->
                <td>
                    <button
                        type="button"
                        class="status-badge status-toggle <?= $isActive ? 'status-active' : 'status-inactive' ?>"
                        data-id="<?= (int)$row['id'] ?>"
                        data-status="<?= htmlspecialchars($status) ?>">
                        <?= htmlspecialchars($status) ?>
                    </button>
                </td>

                  <td>
                    <div class="actions">
                      <button type="button"
                              class="action-btn edit-btn"
                              title="Edit"
                              data-id="<?= (int)$row['id'] ?>"
                              data-country="<?= htmlspecialchars($row['country_name'], ENT_QUOTES) ?>"
                              data-region="<?= htmlspecialchars($row['region'], ENT_QUOTES) ?>"
                              data-province="<?= htmlspecialchars($row['province'], ENT_QUOTES) ?>"
                              data-city="<?= htmlspecialchars($row['city'], ENT_QUOTES) ?>"

                              data-address="<?= htmlspecialchars($row['street_address'], ENT_QUOTES) ?>">
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
              <tr>
                  <td colspan="5">
                      <div class="empty-state">
                          <div class="empty-state-icon">
                              <i class="fa-solid fa-earth-americas"></i>
                          </div>

                          <h3>No Countries Found</h3>
                          <p>No country and address records have been added yet.</p>
                      </div>
                  </td>
              </tr>
            <?php endif; ?>

          </tbody>
    </table>

  <!-- PAGINATION -->
          <div class="pagination">
              <span>
                  Showing <?= count($countries) ?> of <?= $totalCount ?> Countries Adreesses
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

</div>

<!-- ===================== ADD / EDIT MODAL ===================== -->
<div class="modal-overlay" id="countryModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-header-left">
        <div class="modal-icon"><i class="fa-solid fa-earth-americas" id="modalIcon"></i></div>
        <div>
          <h3 id="modalTitle">Add Country / Address</h3>
          <p class="modal-subtitle" id="modalSubtitle">Create a new country record</p>
        </div>
      </div>
      <button type="button" class="modal-close" id="modalClose">&times;</button>
    </div>

    <form id="countryForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" id="formAction" value="add">
      <input type="hidden" name="id" id="formId" value="">

      <div class="modal-body">
        <div class="form-row">

          <div class="form-group">
            <label class="form-label" for="country_name">
              <i class="fa-solid fa-flag"></i> Country Name <span class="required">*</span>
            </label>
            <input type="text" id="country_name" name="country_name"
                  class="form-input" placeholder="e.g. Philippines"
                  required maxlength="100">
          </div>

          <div class="form-group">
            <label class="form-label" for="region">
              <i class="fa-solid fa-flag"></i> Region <span class="required">*</span>
            </label>
            <input type="text" id="region" name="region"
                  class="form-input" placeholder="e.g. Metro Manila"
                  required maxlength="100">
          </div>

          <div class="form-group">
            <label class="form-label" for="province">
              <i class="fa-solid fa-flag"></i> Province/State <span class="required">*</span>
            </label>
            <input type="text" id="province" name="province"
                  class="form-input" placeholder="e.g. Pampanga"
                  required maxlength="100">
          </div>

          <div class="form-group">
            <label class="form-label" for="city">
              City
            </label>
            <input type="text" id="city" name="city"
                  class="form-input" placeholder="e.g. Tokyo" maxlength="100">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="address">
            <i class="fa-solid fa-location-dot"></i> Address
          </label>
          <textarea id="address" name="address" class="form-input"
                    placeholder="Full address…" maxlength="500"></textarea>
        </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="modalCancel">Cancel</button>
        <button type="submit" class="btn-confirm" id="formSubmit">
          <i class="fa-solid fa-check"></i>
          <span id="formSubmitLabel">Save</span>
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
          <h3>Delete Record</h3>
          <p class="modal-subtitle">This action cannot be undone</p>
        </div>
      </div>
      <button type="button" class="modal-close" id="deleteModalClose">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size:13.5px;color:#475569;line-height:1.6;">
        Are you sure you want to permanently delete this country/address record?
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
  window.COUNTRIES_CSRF = <?= json_encode($csrfToken) ?>;
</script>
<script src="../asset/js/countries_adderesses.js"></script>