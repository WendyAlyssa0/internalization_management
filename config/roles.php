<?php
/**
 * config/roles.php
 * Single source of truth for roles that may access the admin portal.
 * Stored values in `users.user_role` must match the keys below.
 */

// Roles allowed into the admin portal (canonical stored values).
const ADMIN_PORTAL_ROLES = [
    'super admin',        // OVP LIA Director
    'campus coordinator', // one per campus (S1)
    'admin',              // legacy alias
    'ovplia staff',
    'approving official',
    'bor secretary',
];

function is_admin_portal_role(?string $role): bool
{
    $role = strtolower(trim($role ?? ''));
    return in_array($role, ADMIN_PORTAL_ROLES, true);
}