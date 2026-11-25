<?php
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

if (!canManagePartners()) {
    http_response_code(403);
    exit('Forbidden — you do not have permission to manage countries and addresses.');
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
        $country  = trim((string)($_POST['country_name'] ?? ''));
        $region   = trim((string)($_POST['region'] ?? ''));
        $province = trim((string)($_POST['province'] ?? ''));
        $city     = trim((string)($_POST['city'] ?? ''));
        $postal   = trim((string)($_POST['postal_code'] ?? ''));
        $address  = trim((string)($_POST['address'] ?? ''));

        if ($country === '') {
            echo json_encode(['ok' => false, 'error' => 'Country name is required.']);
            exit();
        }
        if ($province === '') {
            echo json_encode(['ok' => false, 'error' => 'Province/State is required.']);
            exit();
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO countries_addresses
                (country_name, region, province, city, postal_code, street_address, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$country, $region, $province, $city, $postal, $address]);
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
                    postal_code = ?,
                    street_address = ?
                WHERE id = ?
            ");
            $stmt->execute([$country, $region, $province, $city, $postal, $address, $id]);
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

        $newStatus = (strtolower($current) === 'active') ? 'inactive' : 'active';

        $stmt = $pdo->prepare("UPDATE countries_addresses SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);

        echo json_encode(['ok' => true, 'status' => ucfirst($newStatus)]);
        exit();
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit();
}

/* TABLE FILTERS (cascading dropdowns) */
$fCountry  = trim((string)($_GET['f_country'] ?? ''));
$fRegion   = trim((string)($_GET['f_region'] ?? ''));
$fProvince = trim((string)($_GET['f_province'] ?? ''));
$fCity     = trim((string)($_GET['f_city'] ?? ''));

if ($fCountry === '')  { $fRegion = ''; }
if ($fRegion === '')   { $fProvince = ''; }
if ($fProvince === '') { $fCity = ''; }

function distinctValues(PDO $pdo, string $col, array $conds): array {
    $allowed = ['country_name', 'region', 'province', 'city'];
    if (!in_array($col, $allowed, true)) { return []; }
    $w = []; $p = [];
    foreach ($conds as $c => $v) {
        if (in_array($c, $allowed, true) && $v !== '') { $w[] = "$c = ?"; $p[] = $v; }
    }
    $sql = "SELECT DISTINCT $col FROM countries_addresses WHERE $col IS NOT NULL AND $col <> ''"
         . ($w ? ' AND ' . implode(' AND ', $w) : '')
         . " ORDER BY $col";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

$optCountries = distinctValues($pdo, 'country_name', []);
$optRegions   = $fCountry  !== '' ? distinctValues($pdo, 'region',   ['country_name' => $fCountry]) : [];
$optProvinces = $fRegion   !== '' ? distinctValues($pdo, 'province', ['country_name' => $fCountry, 'region' => $fRegion]) : [];
$optCities    = $fProvince !== '' ? distinctValues($pdo, 'city',     ['country_name' => $fCountry, 'region' => $fRegion, 'province' => $fProvince]) : [];

$where = []; $params = [];
foreach ([['country_name', $fCountry], ['region', $fRegion], ['province', $fProvince], ['city', $fCity]] as [$col, $val]) {
    if ($val !== '') { $where[] = "$col = ?"; $params[] = $val; }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$hasFilter = ($fCountry !== '');

/* FETCH PAGINATION */
$cnt = $pdo->prepare("SELECT COUNT(*) FROM countries_addresses $whereSql");
$cnt->execute($params);
$totalCount = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $limit));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $limit; }

$stmt = $pdo->prepare("
    SELECT *
    FROM countries_addresses
    $whereSql
    ORDER BY id DESC
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
");
$stmt->execute($params);

$countries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$keepQ = $_GET;
unset($keepQ['p'], $keepQ['f_country'], $keepQ['f_region'], $keepQ['f_province'], $keepQ['f_city']);
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
    :root {
        --color-primary: #0f2044; --color-primary-hover: #162c55; --color-accent: #2563eb;
        --color-success: #16a34a; --color-success-bg: #dcfce7; --color-success-text: #15803d;
        --color-error: #dc2626; --color-error-bg: #fee2e2; --color-error-text: #b91c1c;
        --color-bg: #f8fafc; --color-surface: #ffffff; --color-border: #e2e8f0;
        --color-text-primary: #0f172a; --color-text-secondary: #475569; --color-text-muted: #94a3b8;
        --radius-sm: 6px; --radius-md: 8px; --radius-lg: 12px; --radius-xl: 16px; --radius-full: 9999px;
        --shadow-sm: 0 1px 2px rgba(0,0,0,.05); --shadow-md: 0 4px 6px -1px rgba(0,0,0,.1);
        --shadow-modal: 0 25px 50px -12px rgba(0,0,0,.25);
        --font-sans: 'Oswald', 'Segoe UI', system-ui, sans-serif;
    }
    *, *::before, *::after { box-sizing: border-box; }
    .content { padding: 32px 36px; font-family: var(--font-sans); color: var(--color-text-primary); }
    .page-header { margin-bottom: 28px; }
    .page-header h2 { font-size: 1.75rem; font-weight: 700; margin: 0 0 6px; letter-spacing: -0.3px; }
    .page-header p { font-size: 0.9rem; color: var(--color-text-secondary); margin: 0; padding-left: 12px; border-left: 3px solid var(--color-accent); }

    .toolbar { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
    .search-wrap { flex: 1; min-width: 240px; display: flex; align-items: center; gap: 10px; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-full); padding: 0 18px; height: 44px; box-shadow: var(--shadow-sm); }
    .search-wrap:focus-within { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
    .search-wrap i { color: var(--color-text-muted); font-size: 0.9rem; }
    .search-wrap input { flex: 1; border: none; outline: none; font-size: 0.9rem; background: transparent; font-family: var(--font-sans); }
    .create-btn { display: flex; align-items: center; gap: 7px; padding: 9px 20px; background: var(--color-primary); color: white; border: none; border-radius: var(--radius-md); font-size: 0.875rem; font-weight: 600; font-family: var(--font-sans); cursor: pointer; height: 44px; box-shadow: var(--shadow-sm); transition: 0.2s; }
    .create-btn:hover { background: var(--color-primary-hover); transform: translateY(-1px); box-shadow: var(--shadow-md); }

    .filter-bar { display: flex; align-items: flex-end; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 14px 18px; box-shadow: var(--shadow-sm); }
    .filter-item { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 170px; }
    .filter-item label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; color: var(--color-text-muted); letter-spacing: .3px; }
    .filter-item select { width: 100%; padding: 9px 12px; font-size: 0.875rem; font-family: var(--font-sans); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); color: var(--color-text-primary); cursor: pointer; }
    .filter-item select:focus { outline: none; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
    .filter-item select:disabled { background: #f1f5f9; color: var(--color-text-muted); cursor: not-allowed; }
    .filter-clear { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 14px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 0.85rem; color: var(--color-text-secondary); text-decoration: none; background: var(--color-surface); }
    .filter-clear:hover { background: var(--color-error-bg); color: var(--color-error); border-color: #fecdd3; }

    .table-card { background: var(--color-surface); border-radius: var(--radius-lg); border: 1px solid var(--color-border); overflow: hidden; box-shadow: var(--shadow-md); }
    .table-card table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    .table-card thead { background: #f8fafc; border-bottom: 1px solid var(--color-border); }
    .table-card thead th { padding: 16px 20px; text-align: left; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--color-text-muted); }
    .table-card tbody tr { border-bottom: 1px solid var(--color-border); transition: background 0.2s; }
    .table-card tbody tr:hover { background: #f8fafc; }
    .table-card tbody td { padding: 16px 20px; color: var(--color-text-secondary); vertical-align: middle; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .country-cell { display: flex; align-items: center; gap: 12px; }
    .country-icon { width: 38px; height: 38px; border-radius: var(--radius-md); background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
    .country-info strong { display: block; font-size: 0.875rem; font-weight: 600; color: var(--color-text-primary); }

    .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: var(--radius-full); font-size: 0.76rem; font-weight: 600; border: none; cursor: pointer; }
    .status-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .status-active { background: var(--color-success-bg); color: var(--color-success-text); }
    .status-active::before { animation: pulse 2s infinite; }
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(22,163,74,0.4); } 70% { box-shadow: 0 0 0 4px rgba(22,163,74,0); } 100% { box-shadow: 0 0 0 0 rgba(22,163,74,0); } }
    .status-inactive { background: #f1f5f9; color: #64748b; }

    .actions { display: flex; align-items: center; gap: 6px; }
    .action-btn { width: 34px; height: 34px; border: 1px solid transparent; border-radius: var(--radius-md); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem; background: transparent; transition: 0.2s; color: var(--color-text-muted); }
    .edit-btn:hover { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
    .delete-btn:hover { background: #fff1f2; color: #dc2626; border-color: #fecdd3; }

    .pagination { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; background: var(--color-surface); border-top: 1px solid var(--color-border); flex-wrap: wrap; gap: 12px; }
    .pagination > span { font-size: 0.85rem; color: var(--color-text-muted); }
    .pages { display: flex; align-items: center; gap: 6px; }
    .pages a, .pages button { min-width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 500; text-decoration: none; border: 1px solid transparent; color: var(--color-text-secondary); background: transparent; cursor: pointer; transition: 0.2s; }
    .pages a:hover:not(.active), .pages button:hover:not(:disabled) { background: var(--color-bg); border-color: var(--color-border); }
    .pages a.active { background: var(--color-primary); color: white; border-color: var(--color-primary); }
    .pages button:disabled { opacity: 0.35; cursor: not-allowed; }
    .page-arrow { border: 1px solid var(--color-border) !important; background: var(--color-surface) !important; }

    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15,32,68,.45); backdrop-filter: blur(3px); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
    .modal-overlay.open { display: flex; }
    .modal-box { background: var(--color-surface); border-radius: var(--radius-xl); box-shadow: var(--shadow-modal); width: 100%; max-width: 640px; max-height: 90vh; display: flex; flex-direction: column; animation: slideUp 0.22s ease; }
    @keyframes slideUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--color-border); }
    .modal-header-left { display: flex; align-items: center; gap: 14px; }
    .modal-icon { width: 44px; height: 44px; border-radius: var(--radius-md); background: var(--color-primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; }
    .modal-header h3 { font-size: 1.1rem; font-weight: 700; margin: 0 0 2px; }
    .modal-subtitle { font-size: 0.8rem; color: var(--color-text-muted); margin: 0; }
    .modal-close { width: 32px; height: 32px; border: none; background: var(--color-bg); border-radius: var(--radius-sm); cursor: pointer; color: var(--color-text-secondary); }
    .modal-close:hover { background: var(--color-error-bg); color: var(--color-error); }
    .modal-body { padding: 24px; overflow-y: auto; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .form-group { margin-bottom: 18px; }
    .form-label { display: flex; align-items: center; gap: 6px; font-size: 0.8rem; font-weight: 600; color: var(--color-text-secondary); margin-bottom: 7px; }
    .form-input { width: 100%; padding: 10px 14px; font-size: 0.875rem; font-family: var(--font-sans); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); }
    .form-input:focus { outline: none; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
    .combo { position: relative; }
    .combo-list { display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px); max-height: 220px; overflow-y: auto; margin: 0; padding: 4px; list-style: none; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); z-index: 1100; }
    .combo-list.open { display: block; }
    .combo-list li { padding: 8px 10px; border-radius: var(--radius-sm); font-size: 0.875rem; cursor: pointer; color: var(--color-text-secondary); }
    .combo-list li.active, .combo-list li:hover { background: #eff6ff; color: #1d4ed8; }
    .combo-list li.combo-empty { color: var(--color-text-muted); cursor: default; background: transparent; }
    .regen-btn { border: none; background: transparent; color: var(--color-accent); font-family: var(--font-sans); font-size: 0.78rem; font-weight: 600; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 5px; }
    .regen-btn:hover { text-decoration: underline; }
    textarea.form-input { resize: vertical; min-height: 90px; }
    .modal-footer { padding: 16px 24px; border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end; gap: 10px; background: #fafafa; }
    .btn-secondary { padding: 9px 20px; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); cursor: pointer; }
    .btn-confirm { display: flex; align-items: center; gap: 7px; padding: 9px 20px; background: var(--color-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; }

    .toast { position: fixed; bottom: 28px; right: 28px; z-index: 2000; display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-radius: var(--radius-md); color: white; transform: translateY(12px); opacity: 0; transition: 0.25s; }
    .toast--show { opacity: 1; transform: translateY(0); }
    .toast--success { background: var(--color-primary); }
    .toast--error { background: var(--color-error); }
</style>

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
      <i class="fa-solid fa-plus"></i> Add Country / Address
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
              $status   = $row['status'] ?? 'active';
              $isActive = (strtolower($status) === 'active');
              $displayStatus = $isActive ? 'Active' : 'Inactive';
            ?>
        <tr data-id="<?= (int)$row['id'] ?>" data-search="<?= htmlspecialchars(strtolower($row['country_name'].' '.$row['region'].' '.$row['province'].' '.$row['city'].' '.$row['street_address'])) ?>">
            <td>
                <div class="country-cell">
                    <div class="country-icon"><i class="fa fa-flag"></i></div>
                    <div class="country-info"><strong><?= htmlspecialchars($row['country_name']) ?></strong></div>
                </div>
            </td>
            <td><?= htmlspecialchars($row['region']) ?></td>
            <td><?= htmlspecialchars($row['province']) ?></td>
            <td><?= htmlspecialchars($row['city']) ?></td>
            <td title="<?= htmlspecialchars($row['street_address']) ?>"><?= htmlspecialchars($row['street_address']) ?></td>
            <td>
                <button type="button" class="status-badge status-toggle <?= $isActive ? 'status-active' : 'status-inactive' ?>" data-id="<?= (int)$row['id'] ?>" data-status="<?= htmlspecialchars($displayStatus) ?>">
                    <?= htmlspecialchars($displayStatus) ?>
                </button>
            </td>
            <td>
                <div class="actions">
                  <button type="button" class="action-btn edit-btn" title="Edit" data-id="<?= (int)$row['id'] ?>" data-country="<?= htmlspecialchars($row['country_name'], ENT_QUOTES) ?>" data-region="<?= htmlspecialchars($row['region'], ENT_QUOTES) ?>" data-province="<?= htmlspecialchars($row['province'], ENT_QUOTES) ?>" data-city="<?= htmlspecialchars($row['city'], ENT_QUOTES) ?>" data-postal="<?= htmlspecialchars($row['postal_code'] ?? '', ENT_QUOTES) ?>" data-address="<?= htmlspecialchars($row['street_address'], ENT_QUOTES) ?>">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button type="button" class="action-btn delete-btn" title="Delete" data-id="<?= (int)$row['id'] ?>">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
            </td>
        </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
              <td colspan="7">
                  <div class="empty-state" style="text-align:center; padding:40px;">
                      <div class="empty-state-icon" style="font-size:2rem; color:#94a3b8; margin-bottom:12px;"><i class="fa-solid fa-earth-americas"></i></div>
                      <h3 style="margin-bottom:6px; color:#0f172a;">No Countries Found</h3>
                      <p style="color:#64748b; font-size:0.85rem;">No country and address records have been added yet.</p>
                  </div>
              </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="pagination">
        <span>Showing <?= count($countries) ?> of <?= $totalCount ?> Countries Addresses<?= $hasFilter ? ' (filtered)' : '' ?></span>
        <div class="pages">
            <?php
                $baseQ = array_merge($_GET, []);
                unset($baseQ['p']);
            ?>
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

<!-- MODALS -->
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
            <label class="form-label" for="country_name"><i class="fa-solid fa-flag"></i> Country Name *</label>
            <div class="combo">
              <input type="text" id="country_name" name="country_name" class="form-input" placeholder="Type a letter to search…" maxlength="100" autocomplete="off">
              <ul class="combo-list" id="country_list"></ul>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="region"><i class="fa-solid fa-map"></i> Region <span id="regionStar">*</span></label>
            <div class="combo">
              <input type="text" id="region" name="region" class="form-input" placeholder="Type a letter to search…" maxlength="100" autocomplete="off">
              <ul class="combo-list" id="region_list"></ul>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="province"><i class="fa-solid fa-location-dot"></i> Province/State *</label>
            <div class="combo">
              <input type="text" id="province" name="province" class="form-input" placeholder="Type a letter to search…" maxlength="100" autocomplete="off">
              <ul class="combo-list" id="province_list"></ul>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="city">City/Municipality</label>
            <div class="combo">
              <input type="text" id="city" name="city" class="form-input" placeholder="Type a letter to search…" maxlength="100" autocomplete="off">
              <ul class="combo-list" id="city_list"></ul>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="postal_code">Postal Code</label>
            <input type="text" id="postal_code" name="postal_code" class="form-input" placeholder="e.g. 100-0001" maxlength="20">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="street_part"><i class="fa-solid fa-road"></i> Street / Building / Barangay</label>
          <input type="text" id="street_part" class="form-input" placeholder="e.g. 123 Rizal St., Brgy. Poblacion" maxlength="200" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label" for="address" style="justify-content:space-between;">
            <span><i class="fa-solid fa-location-dot"></i> Complete Address</span>
            <button type="button" id="btnRegen" class="regen-btn"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto-generate</button>
          </label>
          <textarea id="address" name="address" class="form-input" placeholder="Generated automatically from the selections above…" maxlength="500"></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="modalCancel">Cancel</button>
        <button type="submit" class="btn-confirm" id="formSubmit"><i class="fa-solid fa-check"></i> <span id="formSubmitLabel">Save</span></button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="deleteModal">
  <div class="modal-box" style="width:420px;">
    <div class="modal-header">
      <div class="modal-header-left">
        <div class="modal-icon" style="background:#fff5f5;color:#dc2626;"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div>
          <h3>Delete Record</h3>
          <p class="modal-subtitle">This action cannot be undone</p>
        </div>
      </div>
      <button type="button" class="modal-close" id="deleteModalClose">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size:13.5px;color:#475569;line-height:1.6;">Are you sure you want to permanently delete this country/address record?</p>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-secondary" id="deleteCancel">Cancel</button>
      <button type="button" class="btn-confirm" id="deleteConfirm" style="background:#dc2626;"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<div class="toast" id="toast">
  <i class="fa-solid fa-circle-check"></i> <span id="toastMessage">Saved successfully</span>
</div>

<script>
  window.COUNTRIES_CSRF = <?= json_encode($csrfToken) ?>;
</script>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const csrfToken = window.COUNTRIES_CSRF;
  const endpoint = window.location.pathname + window.location.search;

  /* ---------- DATA ---------- */
  const COUNTRIES = "Afghanistan,Albania,Algeria,Andorra,Angola,Antigua and Barbuda,Argentina,Armenia,Australia,Austria,Azerbaijan,Bahamas,Bahrain,Bangladesh,Barbados,Belarus,Belgium,Belize,Benin,Bhutan,Bolivia,Bosnia and Herzegovina,Botswana,Brazil,Brunei,Bulgaria,Burkina Faso,Burundi,Cabo Verde,Cambodia,Cameroon,Canada,Central African Republic,Chad,Chile,China,Colombia,Comoros,Congo,Costa Rica,Croatia,Cuba,Cyprus,Czech Republic,Denmark,Djibouti,Dominica,Dominican Republic,Ecuador,Egypt,El Salvador,Equatorial Guinea,Eritrea,Estonia,Eswatini,Ethiopia,Fiji,Finland,France,Gabon,Gambia,Georgia,Germany,Ghana,Greece,Grenada,Guatemala,Guinea,Guinea-Bissau,Guyana,Haiti,Honduras,Hungary,Iceland,India,Indonesia,Iran,Iraq,Ireland,Israel,Italy,Ivory Coast,Jamaica,Japan,Jordan,Kazakhstan,Kenya,Kiribati,Kuwait,Kyrgyzstan,Laos,Latvia,Lebanon,Lesotho,Liberia,Libya,Liechtenstein,Lithuania,Luxembourg,Madagascar,Malawi,Malaysia,Maldives,Mali,Malta,Marshall Islands,Mauritania,Mauritius,Mexico,Micronesia,Moldova,Monaco,Mongolia,Montenegro,Morocco,Mozambique,Myanmar,Namibia,Nauru,Nepal,Netherlands,New Zealand,Nicaragua,Niger,Nigeria,North Korea,North Macedonia,Norway,Oman,Pakistan,Palau,Palestine,Panama,Papua New Guinea,Paraguay,Peru,Philippines,Poland,Portugal,Qatar,Romania,Russia,Rwanda,Saint Kitts and Nevis,Saint Lucia,Saint Vincent and the Grenadines,Samoa,San Marino,Sao Tome and Principe,Saudi Arabia,Senegal,Serbia,Seychelles,Sierra Leone,Singapore,Slovakia,Slovenia,Solomon Islands,Somalia,South Africa,South Korea,South Sudan,Spain,Sri Lanka,Sudan,Suriname,Sweden,Switzerland,Syria,Taiwan,Tajikistan,Tanzania,Thailand,Timor-Leste,Togo,Tonga,Trinidad and Tobago,Tunisia,Turkey,Turkmenistan,Tuvalu,Uganda,Ukraine,United Arab Emirates,United Kingdom,United States,Uruguay,Uzbekistan,Vanuatu,Vatican City,Venezuela,Vietnam,Yemen,Zambia,Zimbabwe".split(",");

  // Country -> Region -> Province -> [Cities]
  const LOCATIONS = {
    "Philippines": {
      "Metro Manila": {
        "Capital District (1st District)": ["Manila"],
        "Eastern Manila District (2nd District)": ["Mandaluyong","Marikina","Pasig","Quezon City","San Juan"],
        "Northern Manila District (3rd District)": ["Caloocan","Malabon","Navotas","Valenzuela"],
        "Southern Manila District (4th District)": ["Las Piñas","Makati","Muntinlupa","Parañaque","Pasay","Pateros","Taguig"]
      },
      "Ilocos Region": { "Ilocos Norte": ["Laoag","Batac"], "Ilocos Sur": ["Vigan","Candon"], "La Union": ["San Fernando","Bauang"], "Pangasinan": ["Agno","Aguilar","Alcala","Anda","Asingan","Balungao","Bani","Basista","Bautista","Bayambang","Binalonan","Binmaley","Bolinao","Bugallon","Burgos","Calasiao","Dasol","Infanta","Labrador","Laoac","Lingayen","Mabini","Malasiqui","Manaoag","Mangaldan","Mangatarem","Mapandan","Natividad","Pozorrubio","Rosales","San Fabian","San Jacinto","San Manuel","San Nicolas","San Quintin","Santa Barbara","Santa Maria","Santo Tomas","Sison","Sual","Umingan","Urbiztondo","Villasis"] },
      "Cagayan Valley": { "Batanes": [], "Cagayan": ["Tuguegarao"], "Isabela": ["Ilagan","Cauayan","Santiago"], "Nueva Vizcaya": ["Bayombong"], "Quirino": [] },
      "Central Luzon": { "Aurora": [], "Bataan": ["Balanga"], "Bulacan": ["Malolos","Meycauayan","San Jose del Monte"], "Nueva Ecija": ["Cabanatuan","Gapan","Palayan","San Jose"], "Pampanga": ["Angeles","San Fernando","Mabalacat"], "Tarlac": ["Tarlac City"], "Zambales": ["Olongapo"] },
      "Calabarzon": { "Batangas": ["Batangas City","Lipa","Tanauan"], "Cavite": ["Bacoor","Cavite City","Dasmariñas","Imus","Naic","Tagaytay","Trece Martires","General Trias","Carmona"], "Laguna": ["Calamba","San Pablo","Santa Rosa","Biñan","Cabuyao","San Pedro"], "Quezon": ["Lucena","Tayabas"], "Rizal": ["Antipolo"] },
      "Mimaropa": { "Marinduque": [], "Occidental Mindoro": [], "Oriental Mindoro": ["Calapan"], "Palawan": ["Puerto Princesa"], "Romblon": [] },
      "Bicol Region": { "Albay": ["Legazpi","Ligao","Tabaco"], "Camarines Norte": [], "Camarines Sur": ["Naga","Iriga"], "Catanduanes": [], "Masbate": [], "Sorsogon": ["Sorsogon City"] },
      "Western Visayas": { "Aklan": [], "Antique": [], "Capiz": ["Roxas"], "Guimaras": [], "Iloilo": ["Iloilo City","Passi"], "Negros Occidental": ["Bacolod","Bago","Kabankalan"] },
      "Central Visayas": { "Bohol": ["Tagbilaran"], "Cebu": ["Cebu City","Lapu-Lapu","Mandaue","Talisay"], "Negros Oriental": ["Dumaguete"], "Siquijor": [] },
      "Eastern Visayas": { "Biliran": [], "Eastern Samar": [], "Leyte": ["Tacloban","Ormoc"], "Northern Samar": [], "Samar": [], "Southern Leyte": [] },
      "Zamboanga Peninsula": { "Zamboanga del Norte": ["Dipolog","Dapitan"], "Zamboanga del Sur": ["Pagadian"], "Zamboanga Sibugay": [] },
      "Northern Mindanao": { "Bukidnon": ["Malaybalay","Valencia"], "Camiguin": [], "Lanao del Norte": ["Iligan"], "Misamis Occidental": ["Oroquieta","Ozamiz"], "Misamis Oriental": ["Cagayan de Oro"] },
      "Davao Region": { "Davao de Oro": [], "Davao del Norte": ["Tagum"], "Davao del Sur": ["Davao City","Digos"], "Davao Occidental": [], "Davao Oriental": ["Mati"] },
      "Soccsksargen": { "Cotabato": ["Kidapawan"], "Sarangani": [], "South Cotabato": ["General Santos","Koronadal"], "Sultan Kudarat": [] },
      "Caraga": { "Agusan del Norte": ["Butuan"], "Agusan del Sur": [], "Dinagat Islands": [], "Surigao del Norte": ["Surigao City"], "Surigao del Sur": ["Tandag","Bislig"] },
      "Cordillera Administrative Region": { "Abra": [], "Apayao": [], "Benguet": ["Baguio"], "Ifugao": [], "Kalinga": [], "Mountain Province": [] },
      "BARMM": { "Basilan": [], "Lanao del Sur": ["Marawi"], "Maguindanao del Norte": [], "Maguindanao del Sur": [], "Sulu": [], "Tawi-Tawi": [] }
    }
  };

  /* ---------- ELEMENTS ---------- */
  const addBtn = document.getElementById("btnAddCountry");
  const tableBody = document.getElementById("countriesBody");
  const searchInput = document.getElementById("searchInput");

  const form = document.getElementById("countryForm");
  const formAction = document.getElementById("formAction");
  const formId = document.getElementById("formId");
  const submitLabel = document.getElementById("formSubmitLabel");

  const postalInput = document.getElementById("postal_code");
  const addressInput = document.getElementById("address");

  const modal = document.getElementById("countryModal");
  const deleteModal = document.getElementById("deleteModal");
  const modalTitle = document.getElementById("modalTitle");
  const modalSubtitle = document.getElementById("modalSubtitle");
  const modalIcon = document.getElementById("modalIcon");

  const toast = document.getElementById("toast");
  const toastMessage = document.getElementById("toastMessage");

  let deleteId = null;
  let toastTimer = null;

  /* ---------- TYPE-AHEAD DROPDOWNS ---------- */
  const LEVELS = ["country", "region", "province", "city"];
  const IDS = { country: "country_name", region: "region", province: "province", city: "city" };
  const LABELS = { country: "Country", region: "Region", province: "Province/State", city: "City" };
  const combos = {};
  LEVELS.forEach((l) => {
    combos[l] = {
      input: document.getElementById(IDS[l]),
      list: document.getElementById(l + "_list"),
      label: LABELS[l],
      items: [],
      active: -1
    };
  });
  const countryInput = combos.country.input;
  let original = { country: "", region: "", province: "", city: "" };

  function esc(s) {
    return String(s).replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }

  /* ---------- WORLD DATA (every country) ----------
     Philippines uses the built-in LOCATIONS list (Region -> Province -> City).
     Every other country is loaded on demand from a free public API,
     with locations-full.json as offline fallback. */
  const API = "https://countriesnow.space/api/v0.1";
  const remote = { states: {}, cities: {} };   // caches
  const pending = {};                          // requests in flight
  const failed = {};                           // requests that failed

  /* ---- offline fallback (locations-full.json) ---- */
  let OFFLINE = null;
  let offlinePromise = null;
  function loadOffline() {
    if (OFFLINE) return Promise.resolve(OFFLINE);
    if (!offlinePromise) {
      offlinePromise = fetch('locations-full.json')
        .then(r => r.ok ? r.json() : {})
        .then(j => { OFFLINE = j || {}; return OFFLINE; })
        .catch(() => (OFFLINE = {}));
    }
    return offlinePromise;
  }

  const val = (l) => combos[l].input.value.trim();
  const hasBuiltIn = (c) => Object.prototype.hasOwnProperty.call(LOCATIONS, c);
  const sortAZ = (arr) => arr.filter(Boolean).sort((a, b) => a.localeCompare(b));

  async function postJSON(path, payload) {
    const res = await fetch(API + path, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const j = await res.json();
    if (j.error) throw new Error(j.msg || "API error");
    return j.data;
  }

  function refreshOpenLists() {
    LEVELS.forEach((l) => { if (combos[l].list.classList.contains("open")) renderList(l); });
  }

  function loadStates(country) {
    const key = "s|" + country;
    if (!country || !COUNTRIES.includes(country) || hasBuiltIn(country) ||
        remote.states[country] || pending[key] || failed[key]) return;
    pending[key] = true;

    postJSON("/countries/states", { country })
      .then(d => { remote.states[country] = sortAZ((d.states || []).map(x => x.name)); })
      .catch(async () => {
        const off = await loadOffline();
        const entry = off[country];
        if (entry) {
          remote.states[country] = sortAZ(Object.keys(entry));
        } else {
          failed[key] = true;
        }
      })
      .finally(() => { delete pending[key]; refreshOpenLists(); });
  }

  function stripSuffix(n) {
    return n.replace(/\s+(Prefecture|Province|State|Region|Department|County|District|Governorate)$/i, "");
  }

  function loadCities(country, state) {
    const key = "c|" + country + "|" + state;
    if (!country || !state || !COUNTRIES.includes(country) || hasBuiltIn(country) ||
        remote.cities[country + "|" + state] || pending[key] || failed[key]) return;
    pending[key] = true;

    const attempt = (st) => postJSON("/countries/state/cities", { country, state: st });
    attempt(state)
      .catch(() => {
        const alt = stripSuffix(state);
        if (alt === state) throw new Error("no cities");
        return attempt(alt);
      })
      .then(d => { remote.cities[country + "|" + state] = sortAZ(Array.isArray(d) ? d : []); })
      .catch(async () => {
        const off = await loadOffline();
        const entry = off[country] && off[country][state];
        const list = entry && entry[state] ? entry[state] : null;
        if (list) {
          remote.cities[country + "|" + state] = sortAZ(list);
        } else {
          failed[key] = true;
        }
      })
      .finally(() => { delete pending[key]; refreshOpenLists(); });
  }

  function isLoading(level) {
    const c = val("country"), p = val("province");
    if (hasBuiltIn(c)) return false;
    if (level === "province") return !!pending["s|" + c];
    if (level === "city") return !!pending["c|" + c + "|" + p];
    return false;
  }

  function optionsFor(level) {
    const c = val("country"), r = val("region"), p = val("province");
    if (level === "country") return COUNTRIES;

    if (hasBuiltIn(c)) {
      const d = LOCATIONS[c];
      if (level === "region")   return Object.keys(d);
      if (level === "province") return d[r] ? Object.keys(d[r]) : [];
      if (level === "city")     return (d[r] && d[r][p]) ? d[r][p].filter(Boolean) : [];
      return [];
    }

    if (level === "province") { loadStates(c); return remote.states[c] || []; }
    if (level === "city")     { loadCities(c, p); return remote.cities[c + "|" + p] || []; }
    return [];
  }

  function canonical(level, value) {
    const v = value.trim().toLowerCase();
    return optionsFor(level).find((o) => o.toLowerCase() === v) || null;
  }

  function suggest(level, term) {
    const all = optionsFor(level);
    const t = term.trim().toLowerCase();
    if (!t) return all;
    const starts = [], wordStarts = [];
    all.forEach((o) => {
      const low = o.toLowerCase();
      if (low.startsWith(t)) starts.push(o);
      else if (low.split(/[\s\-\/]+/).some((w) => w.startsWith(t))) wordStarts.push(o);
    });
    return starts.concat(wordStarts);
  }

  function highlight(level) {
    const c = combos[level];
    [...c.list.querySelectorAll("li[data-i]")].forEach((li, i) => {
      li.classList.toggle("active", i === c.active);
      if (i === c.active) li.scrollIntoView({ block: "nearest" });
    });
  }

  function closeList(level) {
    combos[level].list.classList.remove("open");
    combos[level].active = -1;
  }

  function renderList(level) {
    const c = combos[level];
    const term = c.input.value.trim();
    c.items = suggest(level, term);
    c.active = (term && c.items.length) ? 0 : -1;

    if (!c.items.length) {
      let msg;
      if (isLoading(level)) msg = "Loading…";
      else if (level === "country") msg = "No matching country";
      else if (level === "region" && !hasBuiltIn(val("country"))) msg = "Optional for this country — leave blank or type it";
      else if (!optionsFor(level).length) msg = "No list available — type it manually";
      else msg = "No match — you can type it manually";
      c.list.innerHTML = '<li class="combo-empty">' + msg + "</li>";
    } else {
      c.list.innerHTML = c.items.map((o, i) => '<li data-i="' + i + '">' + esc(o) + "</li>").join("");
    }
    c.list.classList.add("open");
    highlight(level);
  }

  function clearChildren(level) {
    LEVELS.slice(LEVELS.indexOf(level) + 1).forEach((l) => {
      combos[l].input.value = "";
      closeList(l);
    });
  }

  function choose(level, value) {
    const c = combos[level];
    const changed = c.input.value !== value;
    c.input.value = value;
    closeList(level);
    if (changed) clearChildren(level);
    afterChange(level);
  }

  LEVELS.forEach((level) => {
    const c = combos[level];

    c.input.addEventListener("focus", () => renderList(level));

    c.input.addEventListener("input", () => {
      clearChildren(level);
      renderList(level);
      afterChange(level);
    });

    c.input.addEventListener("keydown", (e) => {
      const open = c.list.classList.contains("open");
      if (e.key === "ArrowDown") {
        e.preventDefault();
        if (!open) { renderList(level); return; }
        if (c.items.length) { c.active = (c.active + 1) % c.items.length; highlight(level); }
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        if (open && c.items.length) { c.active = (c.active - 1 + c.items.length) % c.items.length; highlight(level); }
      } else if (e.key === "Enter") {
        if (open && c.active >= 0 && c.items[c.active]) {
          e.preventDefault();
          choose(level, c.items[c.active]);
        }
      } else if (e.key === "Escape") {
        if (open) { e.stopPropagation(); closeList(level); }
      } else if (e.key === "Tab") {
        closeList(level);
      }
    });

    c.input.addEventListener("blur", () => {
      closeList(level);
      const canon = canonical(level, c.input.value);
      if (canon) c.input.value = canon;
      afterChange(level);
    });

    c.list.addEventListener("mousedown", (e) => {
      e.preventDefault();
      const li = e.target.closest("li[data-i]");
      if (li) choose(level, c.items[Number(li.dataset.i)]);
    });
  });

  function setLocation(country, region, province, city) {
    combos.country.input.value = country || "";
    combos.region.input.value = region || "";
    combos.province.input.value = province || "";
    combos.city.input.value = city || "";
    original = { country: country || "", region: region || "", province: province || "", city: city || "" };
    LEVELS.forEach(closeList);
    loadStates(val("country"));
    loadCities(val("country"), val("province"));
    updateRegionStar();
  }

  /* ---------- COMPLETE ADDRESS GENERATOR ---------- */
  const streetInput = document.getElementById("street_part");
  const regenBtn = document.getElementById("btnRegen");
  const regionStar = document.getElementById("regionStar");
  let addrManual = false;

  function updateRegionStar() {
    const c = val("country");
    regionStar.style.display = (!c || hasBuiltIn(c)) ? "" : "none";
  }

  function buildAddress() {
    const parts = [streetInput.value.trim(), val("city"), val("province"), val("region"), postalInput.value.trim(), val("country")];
    const seen = new Set();
    return parts.filter((p) => {
      if (!p) return false;
      const k = p.toLowerCase();
      if (seen.has(k)) return false;
      seen.add(k);
      return true;
    }).join(", ");
  }

  function autoAddress() {
    if (!addrManual) addressInput.value = buildAddress();
  }

  function afterChange(level) {
    if (level === "country") loadStates(val("country"));
    if (level === "province") loadCities(val("country"), val("province"));
    updateRegionStar();
    autoAddress();
  }

  streetInput.addEventListener("input", autoAddress);
  postalInput.addEventListener("input", autoAddress);
  addressInput.addEventListener("input", () => { addrManual = addressInput.value.trim() !== ""; });
  regenBtn.addEventListener("click", () => {
    addrManual = false;
    autoAddress();
    showToast("Address generated.");
  });

  /* ---------- UI HELPERS ---------- */
  function showToast(message, type = "success") {
    toastMessage.textContent = message;
    toast.classList.remove("toast--success", "toast--error");
    toast.classList.add(type === "error" ? "toast--error" : "toast--success");
    toast.querySelector("i").className = type === "error" ? "fa-solid fa-circle-exclamation" : "fa-solid fa-circle-check";
    toast.classList.add("toast--show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove("toast--show"), 3200);
  }

  const openModal = () => modal.classList.add("open");
  const closeModal = () => modal.classList.remove("open");
  const openDeleteModal = () => deleteModal.classList.add("open");
  const closeDeleteModal = () => deleteModal.classList.remove("open");

  function resetForm() {
    form.reset();
    formId.value = "";
    formAction.value = "add";
    addrManual = false;
    setLocation("", "", "", "");
  }

  /* ---------- EVENTS ---------- */
  addBtn.addEventListener("click", () => {
    resetForm();
    modalTitle.textContent = "Add Country / Address";
    modalSubtitle.textContent = "Create a new country record";
    modalIcon.className = "fa-solid fa-earth-americas";
    submitLabel.textContent = "Save";
    openModal();
    countryInput.focus();
  });

  tableBody.addEventListener("click", (e) => {
    const editBtn = e.target.closest(".edit-btn");
    const deleteBtn = e.target.closest(".delete-btn");
    const statusBtn = e.target.closest(".status-toggle");

    if (editBtn) {
      resetForm();
      formAction.value = "edit";
      formId.value = editBtn.dataset.id;

      setLocation(
        editBtn.dataset.country || "",
        editBtn.dataset.region || "",
        editBtn.dataset.province || "",
        editBtn.dataset.city || ""
      );
      postalInput.value = editBtn.dataset.postal || "";
      addressInput.value = editBtn.dataset.address || "";
      addrManual = addressInput.value.trim() !== "";

      modalTitle.textContent = "Edit Country / Address";
      modalSubtitle.textContent = "Update existing record";
      modalIcon.className = "fa-solid fa-pen";
      submitLabel.textContent = "Update";
      openModal();
      return;
    }

    if (deleteBtn) {
      deleteId = deleteBtn.dataset.id;
      openDeleteModal();
      return;
    }

    if (statusBtn) {
      statusBtn.disabled = true;
      const body = new URLSearchParams({ action: "toggle_status", id: statusBtn.dataset.id, csrf_token: csrfToken });
      fetch(endpoint, { method: "POST", body }).then(res => res.json()).then(data => {
        if (data.ok) {
          statusBtn.textContent = data.status;
          statusBtn.classList.toggle("status-active", data.status === "Active");
          statusBtn.classList.toggle("status-inactive", data.status !== "Active");
        }
        statusBtn.disabled = false;
      }).catch(() => {
        statusBtn.disabled = false;
        showToast("Network error.", "error");
      });
    }
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    for (const lvl of LEVELS) {
      const c = combos[lvl];
      const v = c.input.value.trim();
      if (!v) {
        if (lvl === "city" || (lvl === "region" && !hasBuiltIn(val("country")))) continue;
        showToast(c.label + " is required.", "error");
        c.input.focus();
        return;
      }
      const canon = canonical(lvl, v);
      if (canon) {
        c.input.value = canon;
      } else if (optionsFor(lvl).length && v !== original[lvl]) {
        showToast("Please choose a valid " + c.label + " from the list.", "error");
        c.input.focus();
        return;
      }
    }

    const submitBtn = document.getElementById("formSubmit");
    submitBtn.disabled = true;

    try {
      const body = new URLSearchParams(new FormData(form));
      const res = await fetch(endpoint, { method: "POST", body });
      const data = await res.json();

      if (!data.ok) {
        showToast(data.error || "Something went wrong.", "error");
        return;
      }
      showToast("Saved successfully!");
      setTimeout(() => location.reload(), 700);
    } catch (err) {
      showToast("Network error.", "error");
    } finally {
      submitBtn.disabled = false;
    }
  });

  document.getElementById("deleteConfirm").addEventListener("click", async () => {
    if (!deleteId) return;
    const confirmBtn = document.getElementById("deleteConfirm");
    confirmBtn.disabled = true;

    try {
      const body = new URLSearchParams({ action: "delete", id: deleteId, csrf_token: csrfToken });
      const res = await fetch(endpoint, { method: "POST", body });
      const data = await res.json();

      if (data.ok) {
        closeDeleteModal();
        showToast("Record deleted.");
        setTimeout(() => location.reload(), 700);
      } else {
        showToast(data.error || "Could not delete.", "error");
      }
    } catch (err) {
      showToast("Network error.", "error");
    } finally {
      confirmBtn.disabled = false;
      deleteId = null;
    }
  });

  document.getElementById("modalClose").onclick = closeModal;
  document.getElementById("modalCancel").onclick = closeModal;
  document.getElementById("deleteModalClose").onclick = closeDeleteModal;
  document.getElementById("deleteCancel").onclick = closeDeleteModal;

  searchInput.addEventListener("input", () => {
    const term = searchInput.value.trim().toLowerCase();
    tableBody.querySelectorAll("tr[data-search]").forEach((row) => {
      row.style.display = row.dataset.search.includes(term) ? "" : "none";
    });
  });
});
</script>