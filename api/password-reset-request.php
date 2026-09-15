<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
if (!$email) { header('Location: ../pages/auth/forgot-password.php?error=invalid'); exit; }
try {
    $pdo = db(); $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = ? AND status = 'active'"); $stmt->execute([$email]); $account = $stmt->fetch();
    if (!$account) { header('Location: ../pages/auth/forgot-password.php?error=not_found'); exit; }
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE account_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = 'reset_password' AND used_at IS NULL")->execute([$account['id']]);
    $pdo->prepare("INSERT INTO account_tokens (user_id, token_hash, purpose, expires_at) VALUES (?, ?, 'reset_password', DATE_ADD(NOW(), INTERVAL 1 HOUR))")->execute([$account['id'], hash('sha256', $token)]);
    if (!sendAccountMail($account['email'], 'Reset your FAMS password', "Open this link within one hour to reset your password:\n" . applicationUrl('/pages/auth/reset-password.php?token=' . $token))) throw new RuntimeException('Mail delivery failed.');
    header('Location: ../pages/auth/forgot-password.php?sent=1');
} catch (Throwable $error) { header('Location: ../pages/auth/forgot-password.php?error=delivery'); }
exit;
