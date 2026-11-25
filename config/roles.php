<?php
/**
 * Centralized Role and Permission Helper
 * PSU Internationalization Management System
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get the current user's role in a standardized format.
 */
function currentRole(): string {
    // Normalize to lowercase and replace spaces with underscores
    $role = strtolower(trim($_SESSION['role'] ?? $_SESSION['user_role'] ?? ''));
    return str_replace(' ', '_', $role);
}

/**
 * Check if the current user has one of the allowed roles.
 */
function hasRole(array $allowedRoles): bool {
    $normalizedAllowed = array_map(function($r) {
        return str_replace(' ', '_', strtolower(trim($r)));
    }, $allowedRoles);

    return in_array(currentRole(), $normalizedAllowed, true);
}

/* =========================================
   SPECIFIC PERMISSION CHECKS
   Based on the DFD and system requirements
   ========================================= */

/**
 * Can manage System Settings, User Accounts, etc.
 */
function isSuperAdmin(): bool {
    return hasRole(['super_admin', 'super admin']);
}

function isAdmin(): bool {
    return hasRole(['admin']);
}

function canManageSystem(): bool {
    return hasRole(['super_admin', 'super admin', 'admin']);
}

/**
 * Can create/edit Programs and Announcements
 */
function canManagePrograms(): bool {
    return hasRole([
        'super_admin', 'super admin',
        'admin',
        'campus_coordinator', 'campus coordinator',
        'ovplia_staff', 'ovplia staff'
    ]);
}

function canManageAnnouncements(): bool {
    return hasRole([
        'super_admin', 'super admin',
        'admin',
        'campus_coordinator', 'campus coordinator',
        'ovplia_staff', 'ovplia staff'
    ]);
}

/**
 * Can manage Partner Institutions and Agreement Types
 */
function canManagePartners(): bool {
    return hasRole([
        'super_admin', 'super admin',
        'campus_coordinator', 'campus coordinator',
        'ovplia_staff', 'ovplia staff'
    ]);
}

function canManageAgreements(): bool {
    return hasRole([
        'super_admin', 'super admin',
        'campus_coordinator', 'campus coordinator',
        'ovplia_staff', 'ovplia staff'
    ]);
}

/**
 * Can monitor and update Activity statuses
 */
function canManageActivities(): bool {
    return hasRole([
        'super_admin', 'super admin',
        'admin',
        'campus_coordinator', 'campus coordinator',
        'ovplia_staff', 'ovplia staff',
        'supervisor' // If you still use this role
    ]);
}

/**
 * Can review and approve applications/proposals
 */
function isApprover(): bool {
    return hasRole([
        'approving_official', 'approving official',
        'bor_secretary', 'bor secretary',
        'vp', 'president'
    ]);
}

/**
 * End-users (Students and Faculty)
 */
function isApplicant(): bool {
    return hasRole(['applicant', 'student', 'faculty']);
}