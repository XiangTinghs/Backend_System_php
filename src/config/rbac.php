<?php

return [
    'known_roles' => ['viewer', 'manager', 'security_admin'],
    'assignable_roles' => ['viewer', 'manager'],
    'revocable_roles' => ['viewer', 'manager'],
    'protected_roles' => ['security_admin'],

    // Deny all unreviewed capabilities, including role management and account/password writes.
    // These codes do not create any roles_permissions records.
    'delegable_permissions' => ['admins.read'],
];
