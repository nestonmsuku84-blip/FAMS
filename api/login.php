<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = (string)($_POST['password'] ?? '');
if (!$email || $password === '') { header('Location: ../pages/auth/login.html?error=invalid'); exit; }

$pdo = db();
$stmt = $pdo->prepare("SELECT u.id, u.full_name, u.email, u.password_hash, u.requires_email_verification, u.email_verified_at, u.locked_until, r.name AS role_name
    FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
    WHERE u.email = ? AND u.status = 'active' ORDER BY ur.assigned_at LIMIT 1");
$stmt->execute([$email]);
$account = $stmt->fetch();
if (!$account) {
    header('Location: ../pages/auth/login.html?error=invalid'); exit;
}
if ($account['locked_until'] && strtotime($account['locked_until']) > time()) { header('Location: ../pages/auth/login.html?error=invalid'); exit; }
if (!password_verify($password, $account['password_hash'])) {
    $pdo->prepare('UPDATE users SET failed_login_attempts = failed_login_attempts + 1, locked_until = CASE WHEN failed_login_attempts + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE) ELSE locked_until END WHERE id = ?')->execute([$account['id']]);
    header('Location: ../pages/auth/login.html?error=invalid'); exit;
}
if ($account['role_name'] === 'Student' && (int)$account['requires_email_verification'] === 1 && !$account['email_verified_at']) {
    header('Location: ../pages/auth/login.html?error=verify_email'); exit;
}

session_regenerate_id(true);
$_SESSION['user'] = ['id' => (int)$account['id'], 'full_name' => $account['full_name'], 'email' => $account['email'], 'role' => $account['role_name']];
$pdo->prepare('UPDATE users SET last_login_at = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$account['id']]);
header('Location: ' . roleDashboard($account['role_name']));
exit;
