<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/role_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id']) || !canManagePartners()) {
    http_response_code(empty($_SESSION['user_id']) ? 401 : 403);
    exit('Access denied.');
}

$pdo = db();
$csrf = $_SESSION['csrf_token'] ?? '';
if ($csrf === '') {
    $csrf = $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ADD COUNTRY */
if (isset($_POST['add_country'])) {

    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        exit('Your session expired. Refresh the page and try again.');
    }

    $country_name = trim((string)($_POST['country_name'] ?? ''));
    $country_code = strtoupper(trim((string)($_POST['country_code'] ?? '')));
    $status = (string)($_POST['status'] ?? 'Active');
    if ($country_name === '' || !in_array($status, ['Active', 'Inactive'], true)) {
        http_response_code(422);
        exit('Enter a country name and valid status.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO countries
        (country_name,country_code,status)
        VALUES (?,?,?)
    ");

    $stmt->execute([
        $country_name,
        $country_code,
        $status
    ]);

    header("Location: dashboard.php?page=countries");
    exit;
}

/* UPDATE COUNTRY */
if (isset($_POST['update_country'])) {

    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        exit('Your session expired. Refresh the page and try again.');
    }

    $id = (int)($_POST['id'] ?? 0);
    $countryName = trim((string)($_POST['country_name'] ?? ''));
    $countryCode = strtoupper(trim((string)($_POST['country_code'] ?? '')));
    $status = (string)($_POST['status'] ?? 'Active');
    if ($id <= 0 || $countryName === '' || !in_array($status, ['Active', 'Inactive'], true)) {
        http_response_code(422);
        exit('Enter a country name and valid status.');
    }

    $stmt = $pdo->prepare("
        UPDATE countries
        SET
            country_name=?,
            country_code=?,
            status=?
        WHERE id=?
    ");

    $stmt->execute([
        $countryName,
        $countryCode,
        $status,
        $id
    ]);

    header("Location: dashboard.php?page=countries");
    exit;
}

/* DELETE */
if (isset($_POST['delete_country'])) {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        exit('Your session expired. Refresh the page and try again.');
    }

    $stmt = $pdo->prepare("
        DELETE FROM countries
        WHERE id=?
    ");

    $stmt->execute([(int)($_POST['id'] ?? 0)]);

    header("Location: dashboard.php?page=countries");
    exit;
}

/* SEARCH */
$search = $_GET['search'] ?? '';

$stmt = $pdo->prepare("
    SELECT *
    FROM countries
    WHERE country_name LIKE ?
       OR country_code LIKE ?
    ORDER BY country_name ASC
");

$stmt->execute([
    "%$search%",
    "%$search%"
]);

$countries = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/countries.css">

<div class="card">

    <div class="card-header d-flex justify-content-between align-items-center">

        <h3>
            <i class="fa-solid fa-earth-asia"></i>
            Country Management
        </h3>

        <button
            class="btn btn-primary"
            data-bs-toggle="modal"
            data-bs-target="#addCountryModal">

            <i class="fa-solid fa-plus"></i>
            Add Country

        </button>

    </div>

    <br>

    <form method="GET">

        <input type="hidden"
               name="page"
               value="countries">

        <div class="row">

            <div class="col-md-4">

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Search country..."
                    value="<?= htmlspecialchars($search) ?>">

            </div>

            <div class="col-md-2">

                <button class="btn btn-primary">

                    Search

                </button>

            </div>

        </div>

    </form>

    <br>

    <table class="table table-bordered table-hover">

        <thead>

        <tr>

            <th>ID</th>
            <th>Country Code</th>
            <th>Country Name</th>
            <th>Status</th>
            <th width="120">Actions</th>

        </tr>

        </thead>

        <tbody>

        <?php if(count($countries)): ?>

            <?php foreach($countries as $country): ?>

                <tr>

                    <td><?= $country['id'] ?></td>

                    <td><?= htmlspecialchars($country['country_code']) ?></td>

                    <td><?= htmlspecialchars($country['country_name']) ?></td>

                    <td>

                        <?php if($country['status']=='Active'): ?>

                            <span class="badge bg-success">

                                Active

                            </span>

                        <?php else: ?>

                            <span class="badge bg-danger">

                                Inactive

                            </span>

                        <?php endif; ?>

                    </td>

                    <td>

                        <button
                            class="btn btn-warning btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#edit<?= $country['id'] ?>">

                            <i class="fa-solid fa-pen"></i>

                        </button>

                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete country?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="id" value="<?= (int)$country['id'] ?>">
                            <button type="submit" name="delete_country" class="btn btn-danger btn-sm">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>

                    </td>

                </tr>

                <!-- EDIT MODAL -->

                <div class="modal fade"
                     id="edit<?= $country['id'] ?>">

                    <div class="modal-dialog">

                        <div class="modal-content">

                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                                <div class="modal-header">

                                    <h5>Edit Country</h5>

                                </div>

                                <div class="modal-body">

                                    <input type="hidden"
                                           name="id"
                                           value="<?= $country['id'] ?>">

                                    <div class="mb-3">

                                        <label>
                                            Country Name
                                        </label>

                                        <input
                                            type="text"
                                            name="country_name"
                                            class="form-control"
                                            value="<?= htmlspecialchars($country['country_name']) ?>"
                                            required>

                                    </div>

                                    <div class="mb-3">

                                        <label>
                                            Country Code
                                        </label>

                                        <input
                                            type="text"
                                            name="country_code"
                                            class="form-control"
                                            value="<?= htmlspecialchars($country['country_code']) ?>"
                                            required>

                                    </div>

                                    <div class="mb-3">

                                        <label>Status</label>

                                        <select
                                            name="status"
                                            class="form-control">

                                            <option
                                                value="Active"
                                                <?= $country['status']=='Active' ? 'selected' : '' ?>>
                                                Active
                                            </option>

                                            <option
                                                value="Inactive"
                                                <?= $country['status']=='Inactive' ? 'selected' : '' ?>>
                                                Inactive
                                            </option>

                                        </select>

                                    </div>

                                </div>

                                <div class="modal-footer">

                                    <button
                                        type="submit"
                                        name="update_country"
                                        class="btn btn-primary">

                                        Save Changes

                                    </button>

                                </div>

                            </form>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <tr>

                <td colspan="5" class="text-center">

                    No countries found

                </td>

            </tr>

        <?php endif; ?>

        </tbody>

    </table>

</div>

<!-- ADD COUNTRY MODAL -->

<div class="modal fade"
     id="addCountryModal">

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                <div class="modal-header">

                    <h5>Add Country</h5>

                </div>

                <div class="modal-body">

                    <div class="mb-3">

                        <label>Country Name</label>

                        <input
                            type="text"
                            name="country_name"
                            class="form-control"
                            required>

                    </div>

                    <div class="mb-3">

                        <label>Country Code</label>

                        <input
                            type="text"
                            name="country_code"
                            class="form-control"
                            required>

                    </div>

                    <div class="mb-3">

                        <label>Status</label>

                        <select
                            name="status"
                            class="form-control">

                            <option value="Active">
                                Active
                            </option>

                            <option value="Inactive">
                                Inactive
                            </option>

                        </select>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="submit"
                        name="add_country"
                        class="btn btn-success">

                        Save Country

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>