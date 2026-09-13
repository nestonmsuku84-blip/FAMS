<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = (string)($_POST['password'] ?? '');
if ($firstName === '' || $lastName === '' || !$email || strlen($password) < 8) {
    http_response_code(422); exit('Please provide valid registration details.');
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, requires_email_verification) VALUES (?, ?, ?, TRUE)');
    $stmt->execute([$firstName . ' ' . $lastName, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int)$pdo->lastInsertId();
    $role = $pdo->prepare("SELECT id FROM roles WHERE name = 'Student' LIMIT 1");
    $role->execute();
    $roleId = $role->fetchColumn();
    if (!$roleId) throw new RuntimeException('Student role is missing from the database.');
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO account_tokens (user_id, token_hash, purpose, expires_at) VALUES (?, ?, 'verify_email', DATE_ADD(NOW(), INTERVAL 24 HOUR))")->execute([$userId, hash('sha256', $token)]);
    $pdo->commit();
    sendAccountMail($email, 'Verify your FAMS account', "Welcome to FAMS. Verify your email before signing in:\n" . applicationUrl('/api/verify-email.php?token=' . $token));
    header('Location: ../pages/auth/login.html?registered=1&verify=1');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $duplicate = $e instanceof PDOException && $e->getCode() === '23000';
    http_response_code($duplicate ? 409 : 500);
    exit($duplicate ? 'An account with that email already exists.' : 'Registration failed.');
}
