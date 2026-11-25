<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get clean, normalized role (lowercase, underscores → spaces)
 */
function getRole(): string
{
    $role = strtolower(trim($_SESSION['user_role'] ?? ''));
    return str_replace('_', ' ', $role);
}

/**
 * Require login
 */
function requireLogin(): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: /public/login.php');
        exit();
    }
}

/**
 * Require one of the given roles
 */
function requireRole(array $roles, string $redirect = '/public/login.php'): void
{
    requireLogin();

    $role = getRole();
    $roles = array_map(static function ($r) {
        return str_replace('_', ' ', strtolower(trim($r)));
    }, $roles);

    if (!in_array($role, $roles, true)) {
        header("Location: $redirect");
        exit();
    }
}

/**
 * All staff roles (admin, coordinator, OVPLIA, approvers)
 */
function requireStaff(): void
{
    requireRole([
        'admin', 'ad',
        'super admin', 'sa',
        'campus coordinator',
        'ovplia staff',
        'approving official',
        'bor secretary',
    ], '/public/login.php');
}

/**
 * Strict admin only
 */
function requireAdminOnly(): void
{
    requireRole(['admin','ad','super admin','sa'], '/public/login.php');
}

/**
 * Applicant only
 */
function requireApplicant(): void
{
    requireRole(['applicant','ap'], '/admin/dashboard.php');
}

/**
 * Backward-compat alias — behaves like the old requireAdmin()
 */
function requireAdmin(): void
{
    requireStaff();
}