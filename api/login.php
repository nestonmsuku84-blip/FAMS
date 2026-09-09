<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = (string)($_POST['password'] ?? '');
if (!$email || $password === '') { header('Location: ../pages/auth/login.html?error=invalid'); exit; }

$stmt = db()->prepare("SELECT u.id, u.full_name, u.email, u.password_hash, r.name AS role_name
    FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
    WHERE u.email = ? AND u.status = 'active' ORDER BY ur.assigned_at LIMIT 1");
$stmt->execute([$email]);
$account = $stmt->fetch();
if (!$account || !password_verify($password, $account['password_hash'])) {
    header('Location: ../pages/auth/login.html?error=invalid'); exit;
}

session_regenerate_id(true);
$_SESSION['user'] = ['id' => (int)$account['id'], 'full_name' => $account['full_name'], 'email' => $account['email'], 'role' => $account['role_name']];
db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$account['id']]);
header('Location: ' . roleDashboard($account['role_name']));
exit;
