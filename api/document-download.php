<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();
$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$documentId) { http_response_code(422); exit('A valid document id is required.'); }

try {
    $current = user();
    $role = strtolower($current['role']);
    $scope = match ($role) {
        'student' => 'sp.user_id = ?',
        'department officer' => 'EXISTS (SELECT 1 FROM department_officer_scopes dos JOIN application_specializations aps ON aps.specialization_id = dos.specialization_id WHERE aps.application_id = a.id AND dos.user_id = ?)',
        'fams officer', 'administrator' => '1 = 1',
        default => '0 = 1',
    };
    $params = in_array($role, ['student', 'department officer'], true) ? [$current['id'], $documentId] : [$documentId];
    $stmt = db()->prepare("SELECT d.file_path, d.original_filename, d.mime_type FROM application_documents d JOIN applications a ON a.id = d.application_id JOIN student_profiles sp ON sp.id = a.student_id WHERE $scope AND d.id = ?");
    $stmt->execute($params);
    $document = $stmt->fetch();
    if (!$document) { http_response_code(404); exit('Document not found.'); }
    $uploadRoot = realpath(dirname(__DIR__) . '/uploads');
    $file = realpath(dirname(__DIR__) . '/' . $document['file_path']);
    if (!$uploadRoot || !$file || !str_starts_with($file, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($file)) { http_response_code(404); exit('Document file is unavailable.'); }
    header('Content-Type: ' . $document['mime_type']);
    header('Content-Length: ' . (string)filesize($file));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $document['original_filename']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
} catch (Throwable $error) {
    http_response_code(500);
    exit('Document is unavailable.');
}
