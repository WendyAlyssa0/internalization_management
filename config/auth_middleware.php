<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get clean role
 */
function getRole(): string
{
    return strtolower(trim($_SESSION['user_role'] ?? ''));
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
 * Require specific roles
 */
function requireRole(array $roles, string $redirect = '/public/login.php'): void
{
    requireLogin();

    $role = getRole();

    $roles = array_map('strtolower', $roles);

    if (!in_array($role, $roles, true)) {
        header("Location: $redirect");
        exit();
    }
}

/**
 * Shortcut: Admin only
 */
function requireAdmin(): void
{
    requireRole(['admin', 'ad', 'super admin', 'sa'], '/user/home.php');
}

/**
 * Shortcut: Applicant only
 */
function requireApplicant(): void
{
    requireRole(['applicant', 'ap'], '/admin/dashboard.php');
}
