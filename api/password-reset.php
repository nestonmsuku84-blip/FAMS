<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$token = trim($_POST['token'] ?? ''); $password = (string)($_POST['password'] ?? ''); $confirm = (string)($_POST['confirm_password'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token) || strlen($password) < 8 || $password !== $confirm) { header('Location: ../pages/auth/reset-password.php?error=invalid'); exit; }
try { $pdo = db(); $stmt = $pdo->prepare("SELECT id, user_id FROM account_tokens WHERE token_hash = ? AND purpose = 'reset_password' AND used_at IS NULL AND expires_at > NOW()"); $stmt->execute([hash('sha256', $token)]); $record = $stmt->fetch(); if (!$record) { header('Location: ../pages/auth/reset-password.php?error=expired'); exit; } $pdo->beginTransaction(); $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $record['user_id']]); $pdo->prepare('UPDATE account_tokens SET used_at = NOW() WHERE id = ?')->execute([$record['id']]); $pdo->commit(); header('Location: ../pages/auth/login.html?reset=1'); } catch (Throwable $e) { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); header('Location: ../pages/auth/reset-password.php?error=save'); } exit;
