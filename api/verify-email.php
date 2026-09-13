<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

$token = trim($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) exit('This verification link is invalid.');
try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, user_id FROM account_tokens WHERE token_hash = ? AND purpose = 'verify_email' AND used_at IS NULL AND expires_at > NOW()");
    $stmt->execute([hash('sha256', $token)]);
    $record = $stmt->fetch();
    if (!$record) exit('This verification link has expired or was already used.');
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET email_verified_at = NOW(), requires_email_verification = FALSE WHERE id = ?')->execute([$record['user_id']]);
    $pdo->prepare('UPDATE account_tokens SET used_at = NOW() WHERE id = ?')->execute([$record['id']]);
    $pdo->commit();
    header('Location: ../pages/auth/login.html?verified=1');
} catch (Throwable $error) { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); http_response_code(500); exit('Unable to verify this email.'); }
exit;
