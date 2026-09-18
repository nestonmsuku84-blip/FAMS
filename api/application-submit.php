<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireRole('Student');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

function failApplication(string $message, int $code = 422): never { http_response_code($code); exit($message); }
function currentWindow(PDO $pdo): ?array { $row = $pdo->query('SELECT id, open_date, close_date FROM application_windows WHERE is_active = TRUE AND NOW() BETWEEN open_date AND close_date ORDER BY open_date DESC LIMIT 1')->fetch(); return $row ?: null; }
function wantsJson(): bool { return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'); }

$pdo = db(); $current = user(); $action = $_POST['submission_action'] ?? $_POST['action'] ?? '';
$student = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = ?'); $student->execute([$current['id']]); $studentId = (int)$student->fetchColumn();
if (!$studentId) failApplication('Complete your student profile before creating an application.');
$specialization = filter_var($_POST['specialization'] ?? null, FILTER_VALIDATE_INT);
$skillLevel = trim($_POST['skill_level'] ?? ''); $interest = trim($_POST['interest_statement'] ?? '');
$reason = trim($_POST['reason_for_application'] ?? ''); $objectives = trim($_POST['learning_objectives'] ?? '');
$reportingDate = trim($_POST['reporting_date'] ?? ''); $trainingEndDate = trim($_POST['training_end_date'] ?? '');

function validApplicationDate(string $date): bool { $value = DateTime::createFromFormat('Y-m-d', $date); return $value && $value->format('Y-m-d') === $date; }
function validTrainingDates(string $reportingDate, string $trainingEndDate, bool $required): bool
{
    if ($reportingDate === '' && $trainingEndDate === '') return !$required;
    return validApplicationDate($reportingDate) && validApplicationDate($trainingEndDate) && $trainingEndDate >= $reportingDate;
}
function datesAreWithinApplicationPeriod(string $reportingDate, string $trainingEndDate, array $window): bool
{
    if ($reportingDate === '' && $trainingEndDate === '') return true;
    $windowStart = substr((string)$window['open_date'], 0, 10);
    return $reportingDate >= $windowStart && $trainingEndDate >= $windowStart;
}

if ($action === 'draft') {
    $window = currentWindow($pdo); if (!$window) failApplication('There is no active application window.');
    if (!validTrainingDates($reportingDate, $trainingEndDate, false) || !datesAreWithinApplicationPeriod($reportingDate, $trainingEndDate, $window)) failApplication('Enter dates on or after the application window opens. The training end date cannot be before the reporting date.');
    $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
    if ($applicationId) {
        $draft = $pdo->prepare("SELECT id FROM applications WHERE id = ? AND student_id = ? AND status IN ('draft', 'returned_for_correction')"); $draft->execute([$applicationId, $studentId]);
        if (!$draft->fetchColumn()) failApplication('This draft is no longer available for editing.', 404);
        $pdo->prepare("UPDATE applications SET application_window_id = ?, skill_level = ?, interest_statement = ?, reason_for_application = ?, expected_learning_objectives = ?, reporting_date = ?, training_end_date = ? WHERE id = ?")
            ->execute([$window['id'], in_array($skillLevel, ['beginner','intermediate','advanced'], true) ? $skillLevel : 'beginner', $interest, $reason, $objectives, $reportingDate ?: null, $trainingEndDate ?: null, $applicationId]);
        $pdo->prepare('DELETE FROM application_specializations WHERE application_id = ?')->execute([$applicationId]);
        $id = $applicationId; $message = 'Draft updated. You can continue editing before submission.';
    } else {
        $reference = 'FAMS-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $pdo->prepare("INSERT INTO applications (student_id, application_window_id, reference_number, skill_level, interest_statement, reason_for_application, expected_learning_objectives, reporting_date, training_end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')")
            ->execute([$studentId, $window['id'], $reference, in_array($skillLevel, ['beginner','intermediate','advanced'], true) ? $skillLevel : 'beginner', $interest, $reason, $objectives, $reportingDate ?: null, $trainingEndDate ?: null]);
        $id = (int)$pdo->lastInsertId(); $message = 'Draft saved. You can continue editing before submission.';
    }
    if ($specialization) $pdo->prepare('INSERT INTO application_specializations (application_id, specialization_id, priority_order) VALUES (?, ?, 1)')->execute([$id, $specialization]);
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['id' => $id, 'message' => $message]); exit;
}

if ($action !== 'submit') failApplication('Save the application as a draft before submitting it.');
$applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
if (!$applicationId) failApplication('Save the application as a draft before submitting it.');
$window = currentWindow($pdo); if (!$window) failApplication('There is no active application window.');
if (!$specialization || !in_array($skillLevel, ['beginner','intermediate','advanced'], true) || !$interest || !$reason || !$objectives || !isset($_POST['declaration']) || !validTrainingDates($reportingDate, $trainingEndDate, true) || !datesAreWithinApplicationPeriod($reportingDate, $trainingEndDate, $window)) failApplication('Please complete all required application fields. Both training dates must be on or after the application window opens, and the end date cannot be before the reporting date.');
$check = $pdo->prepare('SELECT id FROM specializations WHERE id = ? AND is_active = TRUE'); $check->execute([$specialization]); if (!$check->fetchColumn()) failApplication('Please select an active specialization.');

$pdo->beginTransaction(); $files = []; $previousFiles = []; $uploadDir = null;
try {
    $draft = $pdo->prepare("SELECT status FROM applications WHERE id = ? AND student_id = ? AND status IN ('draft', 'returned_for_correction') FOR UPDATE"); $draft->execute([$applicationId, $studentId]);
    $previousStatus = $draft->fetchColumn();
    if (!$previousStatus) throw new RuntimeException('Only a saved draft or returned application can be submitted.');
    $submittedStatus = $previousStatus === 'returned_for_correction' ? 'resubmitted' : 'submitted';
    $pdo->prepare("UPDATE applications SET application_window_id = ?, skill_level = ?, interest_statement = ?, reason_for_application = ?, expected_learning_objectives = ?, reporting_date = ?, training_end_date = ?, declaration_accepted = 1, status = ?, submitted_at = NOW() WHERE id = ?")
        ->execute([$window['id'], $skillLevel, $interest, $reason, $objectives, $reportingDate, $trainingEndDate, $submittedStatus, $applicationId]);
    $pdo->prepare('DELETE FROM application_specializations WHERE application_id = ?')->execute([$applicationId]);
    $pdo->prepare('INSERT INTO application_specializations (application_id, specialization_id, priority_order) VALUES (?, ?, 1)')->execute([$applicationId, $specialization]);
    $existingDocuments = $pdo->prepare('SELECT file_path FROM application_documents WHERE application_id = ? FOR UPDATE');
    $existingDocuments->execute([$applicationId]);
    $previousFiles = $existingDocuments->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare('DELETE FROM application_documents WHERE application_id = ?')->execute([$applicationId]);
    $uploadDir = dirname(__DIR__) . '/uploads/' . $applicationId;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) throw new RuntimeException('Unable to create upload directory.');
    $allowed = ['application_letter' => ['application/pdf' => 'pdf'], 'student_id' => ['image/jpeg' => 'jpg', 'image/png' => 'png'], 'university_introduction_letter' => ['application/pdf' => 'pdf']];
    foreach ($allowed as $field => $types) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK || $_FILES[$field]['size'] > 3145728) throw new RuntimeException('All required documents must be included and no larger than 3 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']); if (!isset($types[$mime])) throw new RuntimeException($field === 'student_id' ? 'The student ID must be a JPG or PNG image.' : 'Letters must be PDF files.');
        $file = bin2hex(random_bytes(16)) . '.' . $types[$mime]; if (!move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . '/' . $file)) throw new RuntimeException('Unable to store an uploaded document.');
        $files[] = $uploadDir . '/' . $file;
        $pdo->prepare('INSERT INTO application_documents (application_id, document_type, file_path, original_filename, file_format, mime_type, file_size_bytes, validation_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$applicationId, $field, 'uploads/' . $applicationId . '/' . $file, basename($_FILES[$field]['name']), $types[$mime], $mime, $_FILES[$field]['size'], 'pending']);
    }
    $reference = $pdo->prepare('SELECT reference_number FROM applications WHERE id = ?'); $reference->execute([$applicationId]); $reference = (string)$reference->fetchColumn();
    $reviewers = $pdo->prepare("SELECT DISTINCT dos.user_id FROM department_officer_scopes dos JOIN users u ON u.id = dos.user_id WHERE dos.specialization_id = ? AND u.status = 'active'");
    $reviewers->execute([$specialization]);
    $reviewerIds = $reviewers->fetchAll(PDO::FETCH_COLUMN);
    $routedToFams = !$reviewerIds;
    if ($routedToFams) {
        $pdo->prepare("UPDATE applications SET status = 'under_review' WHERE id = ?")->execute([$applicationId]);
        $famsOfficers = $pdo->query("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name = 'FAMS Officer' AND u.status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        if (!$famsOfficers) throw new RuntimeException('No active department reviewer or FAMS Officer is assigned to receive this application.');
        $reviewerIds = $famsOfficers;
    }
    $notice = $pdo->prepare("INSERT INTO notifications (user_id, application_id, type, channel, subject, message, is_read) VALUES (?, ?, ?, 'in_system', ?, ?, FALSE)");
    $reviewSubject = $routedToFams ? 'New application awaiting final review' : 'New application awaiting department review';
    $reviewMessage = $routedToFams ? "A new application ($reference) has no assigned department reviewer and is ready for final review." : "A new application ($reference) is ready for department review.";
    foreach ($reviewerIds as $reviewer) $notice->execute([$reviewer, $applicationId, 'application_submitted', $reviewSubject, $reviewMessage]);
    $studentMessage = $routedToFams ? 'Your application was submitted and has been sent for final review.' : 'Your application was submitted successfully. Please wait for the result.';
    $notice->execute([$current['id'], $applicationId, 'application_submitted', 'Application submitted successfully', $studentMessage]);
    $pdo->commit();
    foreach ($previousFiles as $previousFile) {
        $path = realpath(dirname(__DIR__) . '/' . $previousFile);
        $uploadRoot = realpath(dirname(__DIR__) . '/uploads');
        if ($uploadRoot && $path && str_starts_with($path, $uploadRoot . DIRECTORY_SEPARATOR) && is_file($path)) unlink($path);
    }
    if (wantsJson()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'Application submitted successfully. Please wait for the result.', 'redirect' => '../pages/student/applications.html?submitted=1']);
        exit;
    }
    header('Location: ../pages/student/applications.html?submitted=1');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack(); foreach ($files as $file) if (is_file($file)) unlink($file);
    if ($error instanceof RuntimeException) failApplication($error->getMessage()); error_log('FAMS application submission failed: ' . $error->getMessage()); failApplication('Application submission failed. Please try again.', 500);
}
