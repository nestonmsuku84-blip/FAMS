<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function loggedIn(): bool { return isset($_SESSION['user']); }
function user(): ?array { return $_SESSION['user'] ?? null; }
function requireLogin(): void
{
    if (!loggedIn()) {
        header('Location: ../pages/auth/login.html');
        exit;
    }
}
function hasRole(string|array $roles): bool
{
    $roles = (array)$roles;
    $currentRole = strtolower((string)(user()['role'] ?? ''));
    foreach ($roles as $role) {
        if ($currentRole === strtolower($role)) return true;
    }
    return false;
}
function requireRole(string|array $roles): void
{
    requireLogin();
    if (!hasRole($roles)) {
        http_response_code(403);
        exit('You are not authorized to perform this action.');
    }
}
function roleDashboard(string $role): string
{
    return match (strtolower($role)) {
        'administrator' => '../pages/admin/dashboard.html',
        'department officer' => '../pages/hod/dashboard.html',
        'fams officer' => '../pages/secretary/dashboard.html',
        default => '../pages/student/dashboard.html',
    };
}
