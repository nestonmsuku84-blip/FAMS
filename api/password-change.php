<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$currentPassword = (string)($_POST['current_password'] ?? '');
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');
if (strlen($newPassword) < 8 || $newPassword !== $confirmPassword) { header('Location: ../pages/student/profile.php?password_error=invalid'); exit; }
try {
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([user()['id']]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($currentPassword, $hash)) { header('Location: ../pages/student/profile.php?password_error=current'); exit; }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), user()['id']]);
    header('Location: ../pages/student/profile.php?password_saved=1');
} catch (Throwable $error) {
    header('Location: ../pages/student/profile.php?password_error=save');
}
exit;
