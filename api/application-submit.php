<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireRole('Student');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$user = user();
$specialization = filter_var($_POST['specialization'] ?? '', FILTER_VALIDATE_INT);
$skillLevel = trim($_POST['skill_level'] ?? '');
$interest = trim($_POST['interest_statement'] ?? '');
$reason = trim($_POST['reason_for_application'] ?? '');
$objectives = trim($_POST['learning_objectives'] ?? '');
$declaration = isset($_POST['declaration']) ? 1 : 0;
if (!$specialization || !in_array($skillLevel, ['beginner','intermediate','advanced'], true) || !$interest || !$reason || !$objectives || !$declaration) {
    http_response_code(422); exit('Please complete all required application fields.');
}

$pdo = db();
$specializationCheck = $pdo->prepare('SELECT id FROM specializations WHERE id = ? AND is_active = TRUE');
$specializationCheck->execute([$specialization]);
if (!$specializationCheck->fetchColumn()) {
    http_response_code(422); exit('Please select an active specialization.');
}
$student = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = ?');
$student->execute([$user['id']]);
$studentId = $student->fetchColumn();
if (!$studentId) { http_response_code(422); exit('Complete your student profile before applying.'); }
$window = $pdo->query('SELECT id FROM application_windows WHERE is_active = TRUE AND NOW() BETWEEN open_date AND close_date ORDER BY open_date DESC LIMIT 1')->fetchColumn();
if (!$window) { http_response_code(422); exit('There is no open application window.'); }

$uploadDir = null;
$storedFiles = [];
$pdo->beginTransaction();
try {
    // The database enforces unique references, so retry the extremely unlikely
    // collision instead of failing a valid application with a server error.
    $reference = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = 'FAMS-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $exists = $pdo->prepare('SELECT 1 FROM applications WHERE reference_number = ?');
        $exists->execute([$candidate]);
        if (!$exists->fetchColumn()) {
            $reference = $candidate;
            break;
        }
    }
    if ($reference === null) throw new RuntimeException('Unable to generate an application reference. Please try again.');
    $stmt = $pdo->prepare('INSERT INTO applications (student_id, application_window_id, reference_number, skill_level, interest_statement, reason_for_application, expected_learning_objectives, declaration_accepted, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, \'submitted\', NOW())');
    $stmt->execute([$studentId, $window, $reference, $skillLevel, $interest, $reason, $objectives]);
    $applicationId = (int)$pdo->lastInsertId();
    $spec = $pdo->prepare('INSERT INTO application_specializations (application_id, specialization_id, priority_order) VALUES (?, ?, 1)');
    $spec->execute([$applicationId, $specialization]);
    $reviewers = $pdo->prepare('SELECT DISTINCT dos.user_id FROM department_officer_scopes dos WHERE dos.specialization_id = ?');
    $reviewers->execute([$specialization]);
    $notifyReviewer = $pdo->prepare('INSERT INTO notifications (user_id, application_id, type, subject, message) VALUES (?, ?, ?, ?, ?)');
    foreach ($reviewers->fetchAll(PDO::FETCH_COLUMN) as $reviewerId) {
        $notifyReviewer->execute([$reviewerId, $applicationId, 'application_submitted', 'New application awaiting review', 'A new application (' . $reference . ') is ready for department review.']);
    }
    $documentTypes = [
        'application_letter' => 'application_letter',
        'student_id' => 'student_id',
        'university_introduction_letter' => 'university_introduction_letter',
    ];
    $uploadDir = dirname(__DIR__) . '/uploads/' . $applicationId;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create upload directory.');
    }
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    foreach ($documentTypes as $field => $documentType) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK || $_FILES[$field]['size'] > 2097152) {
            throw new RuntimeException('Each document is required and must be 2MB or smaller.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Only PDF, JPG, and PNG documents are allowed.');
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . '/' . $storedName)) {
            throw new RuntimeException('Unable to store uploaded document.');
        }
        $storedFiles[] = $uploadDir . '/' . $storedName;
        $pdo->prepare('INSERT INTO application_documents (application_id, document_type, file_path, original_filename, file_format, mime_type, file_size_bytes, validation_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$applicationId, $documentType, 'uploads/' . $applicationId . '/' . $storedName, basename($_FILES[$field]['name']), $allowed[$mime], $mime, $_FILES[$field]['size'], 'pending']);
    }
    $pdo->commit();
    header('Location: ../pages/student/applications.html?submitted=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($storedFiles as $storedFile) {
        if (is_file($storedFile)) unlink($storedFile);
    }
    if ($uploadDir !== null && is_dir($uploadDir) && !(new FilesystemIterator($uploadDir))->valid()) {
        rmdir($uploadDir);
    }
    if ($e instanceof RuntimeException) {
        http_response_code(422);
        exit($e->getMessage());
    }
    error_log('FAMS application submission failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Application submission failed. Please try again or contact the system administrator.');
}
