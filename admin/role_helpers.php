<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('adminCurrentRole')) {
    function adminCurrentRole(): string
    {
        $role = strtolower(trim((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? '')));
        return str_replace('_', ' ', $role);
    }
}

if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin(): bool
    {
        return in_array(adminCurrentRole(), ['super admin', 'sa'], true);
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin(): bool
    {
        return in_array(adminCurrentRole(), ['admin', 'ad'], true);
    }
}

if (!function_exists('isApprover')) {
    function isApprover(): bool
    {
        return in_array(adminCurrentRole(), ['approving official', 'bor secretary'], true);
    }
}

if (!function_exists('canManageSystem')) {
    function canManageSystem(): bool
    {
        return isAdmin() || isSuperAdmin();
    }
}

if (!function_exists('canManagePrograms')) {
    function canManagePrograms(): bool
    {
        return canManageSystem() || in_array(adminCurrentRole(), ['campus coordinator', 'ovplia staff'], true);
    }
}

if (!function_exists('canManagePartners')) {
    function canManagePartners(): bool
    {
        return canManagePrograms();
    }
}

if (!function_exists('canManageActivities')) {
    function canManageActivities(): bool
    {
        return canManagePrograms();
    }
}

if (!function_exists('canManageAnnouncements')) {
    function canManageAnnouncements(): bool
    {
        return canManagePrograms();
    }
}
