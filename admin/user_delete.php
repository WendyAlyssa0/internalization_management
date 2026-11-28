<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$pdo = db();

/* ROLE GATE */
$currentRole = strtolower(trim($_SESSION['user_role'] ?? ''));

if ($currentRole === 'applicant' || !in_array($currentRole, ['admin', 'super admin'], true)) {
    $_SESSION['error'] = 'You are not allowed to delete users.';
    header('Location: dashboard.php?page=users');
    exit();
}

/* GET ID */
$id = $_GET['id'] ?? null;

if (!$id || !is_numeric($id)) {
    die("Invalid user ID.");
}

/* FETCH USER */
$stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = :id");
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found.");
}

/* DELETE ON CONFIRM */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {

    $del = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $del->execute([':id' => $id]);

    header("Location: dashboard.php?page=users&deleted=success");
    exit();
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delete User</title>
</head>
<body>

<div style="padding:20px; font-family:Oswald,sans-serif;">

    <h2>Delete User</h2>

    <p style="color:red;">
        Are you sure you want to delete:
        <b>#<?= h($user['id']) ?> - <?= h($user['first_name'] . ' ' . $user['last_name']) ?></b>?
    </p>

    <form method="POST">
        <button type="submit" name="confirm_delete"
                style="background:red;color:white;padding:8px 15px;border:none;">
            Yes, Delete
        </button>

        <a href="dashboard.php?page=users" style="margin-left:10px;">
            Cancel
        </a>
    </form>

</div>

</body>
</html>