<?php

require_once __DIR__ . '/../config/db.php';

$pdo = db();

/* ------------------------------------------------------------
   DELETE
   ------------------------------------------------------------ */
if (isset($_GET['delete'])) {

    $id = filter_var($_GET['delete'], FILTER_VALIDATE_INT);

    if ($id !== false) {
        $stmt = $pdo->prepare("
            DELETE FROM travel_information
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    header("Location: dashboard.php?page=travel_information");
    exit();
}

/* ------------------------------------------------------------
   SEARCH
   ------------------------------------------------------------ */
$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT *
        FROM travel_information
        WHERE applicant_name LIKE ?
           OR destination LIKE ?
        ORDER BY id DESC
    ");
    $like = '%' . $search . '%';
    $stmt->execute([$like, $like]);
} else {
    $stmt = $pdo->query("
        SELECT *
        FROM travel_information
        ORDER BY id DESC
    ");
}

$travels = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------------------------------------
   HELPERS
   ------------------------------------------------------------ */
function travel_status(string $departure, string $return): array
{
    $today = new DateTimeImmutable('today');
    $dep   = new DateTimeImmutable($departure);
    $ret   = new DateTimeImmutable($return);

    if ($today < $dep) {
        return ['upcoming', 'Upcoming'];
    }

    if ($today >= $dep && $today <= $ret) {
        return ['active', 'Ongoing'];
    }

    return ['completed', 'Completed'];
}

function format_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    return (new DateTimeImmutable($date))->format('M d, Y');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = array_map(fn($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2));
    return implode('', $letters) ?: '?';
}
?>

<link rel="stylesheet" href="../asset/css/travel_information.css">

<div class="content">

    <div class="page-header">
        <h2><i class="fa-solid fa-plane"></i> Travel Information</h2>
        <p>Manage applicant travel records, including destinations and departure/return schedules.</p>
    </div>

    <div class="toolbar">
        <form class="search-wrap" method="get" action="dashboard.php">
            <input type="hidden" name="page" value="travel_information">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input
                type="text"
                name="q"
                value="<?= htmlspecialchars($search) ?>"
                placeholder="Search by applicant or destination..."
            >
        </form>

        <a href="dashboard.php?page=travel_information_create" class="create-btn">
            <i class="fa-solid fa-plus"></i>
            Add Travel Record
        </a>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Destination</th>
                    <th>Departure Date</th>
                    <th>Return Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>

                <?php if (!empty($travels)): ?>

                    <?php foreach ($travels as $t): ?>
                        <?php
                        [$statusClass, $statusLabel] = travel_status(
                            $t['departure_date'],
                            $t['return_date']
                        );
                        $name = $t['applicant_name'] ?? '';
                        ?>
                        <tr>
                            <td>
                                <div class="user-name-cell">
                                    <div class="user-avatar"><?= htmlspecialchars(initials($name ?: '?')) ?></div>
                                    <span><?= htmlspecialchars($name) ?></span>
                                </div>
                            </td>

                            <td>
                                <span class="badge">
                                    <i class="fa-solid fa-location-dot"></i>
                                    <?= htmlspecialchars($t['destination']) ?>
                                </span>
                            </td>

                            <td><?= htmlspecialchars(format_date($t['departure_date'])) ?></td>
                            <td><?= htmlspecialchars(format_date($t['return_date'])) ?></td>

                            <td>
                                <span class="status-badge status-<?= htmlspecialchars($statusClass) ?>">
                                    <?= htmlspecialchars($statusLabel) ?>
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <a
                                        href="dashboard.php?page=travel_information_edit&id=<?= (int) $t['id'] ?>"
                                        class="action-btn edit-btn"
                                        title="Edit"
                                    >
                                        <i class="fa-solid fa-pen"></i>
                                    </a>

                                    <a
                                        href="dashboard.php?page=travel_information&delete=<?= (int) $t['id'] ?>"
                                        class="action-btn delete-btn"
                                        title="Delete"
                                        onclick="return confirm('Delete this travel record?')"
                                    >
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fa-solid fa-plane-circle-xmark"></i>
                                </div>
                                <h3>No travel records found</h3>
                                <p>
                                    <?= $search !== ''
                                        ? 'Try adjusting your search terms.'
                                        : 'Add a travel record to get started.' ?>
                                </p>
                            </div>
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>
        </table>
    </div>

</div>