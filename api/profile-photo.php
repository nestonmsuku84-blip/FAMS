<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK || $_FILES['profile_photo']['size'] > 2097152) {
    header('Location: ../pages/profile.php?photo_error=1'); exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['profile_photo']['tmp_name']);
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($extensions[$mime])) { header('Location: ../pages/profile.php?photo_error=1'); exit; }
$directory = dirname(__DIR__) . '/uploads/profiles';
if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) { http_response_code(500); exit('Unable to store profile photo.'); }
$filename = (int)user()['id'] . '-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $directory . '/' . $filename)) { http_response_code(500); exit('Unable to store profile photo.'); }
$old = db()->prepare('SELECT profile_photo FROM users WHERE id = ?');
$old->execute([user()['id']]);
$oldPhoto = $old->fetchColumn();
db()->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')->execute(['uploads/profiles/' . $filename, user()['id']]);
if ($oldPhoto && str_starts_with($oldPhoto, 'uploads/profiles/') && is_file(dirname(__DIR__) . '/' . $oldPhoto)) unlink(dirname(__DIR__) . '/' . $oldPhoto);
header('Location: ../pages/profile.php?photo_saved=1');
