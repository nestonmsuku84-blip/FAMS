<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole(['Department Officer', 'FAMS Officer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
$decision = $_POST['decision'] ?? '';
$comment = trim($_POST['comment'] ?? '');
$role = user()['role'];
$stage = strtolower($role) === 'department officer' ? 'department' : 'fams';
$allowedDecisions = ['approved', 'rejected', 'needs_correction'];
if (!$applicationId || !in_array($decision, $allowedDecisions, true)) { http_response_code(422); exit('A valid application and decision are required.'); }

try {
    $pdo = db();
    $scope = $stage === 'department'
        ? 'EXISTS (SELECT 1 FROM department_officer_scopes dos JOIN application_specializations aps ON aps.specialization_id = dos.specialization_id WHERE aps.application_id = a.id AND dos.user_id = ?)'
        : '1 = 1';
    $parameters = $stage === 'department' ? [user()['id'], $applicationId] : [$applicationId];
    $application = $pdo->prepare("SELECT a.id, a.status, sp.user_id AS student_user_id FROM applications a JOIN student_profiles sp ON sp.id = a.student_id WHERE $scope AND a.id = ? FOR UPDATE");
    $pdo->beginTransaction();
    $application->execute($parameters);
    $current = $application->fetch();
    if (!$current) throw new RuntimeException('Application not found or outside your scope.');
    if ($stage === 'department' && !in_array($current['status'], ['submitted', 'resubmitted'], true)) throw new RuntimeException('This application cannot be reviewed at the department stage.');
    if ($stage === 'fams' && $current['status'] !== 'under_review') throw new RuntimeException('The department must approve this application before final review.');

    $existingReview = $pdo->prepare('SELECT id FROM application_reviews WHERE application_id = ? AND stage = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $existingReview->execute([$applicationId, $stage]);
    $reviewId = $existingReview->fetchColumn();
    if ($reviewId) {
        $review = $pdo->prepare("UPDATE application_reviews SET reviewer_id = ?, status = 'completed', decision = ?, review_comment = ?, completed_at = NOW() WHERE id = ?");
        $review->execute([user()['id'], $decision, $comment ?: null, $reviewId]);
    } else {
        $review = $pdo->prepare("INSERT INTO application_reviews (application_id, reviewer_id, stage, status, decision, review_comment, started_at, completed_at) VALUES (?, ?, ?, 'completed', ?, ?, NOW(), NOW())");
        $review->execute([$applicationId, user()['id'], $stage, $decision, $comment ?: null]);
    }
    $newStatus = match ($decision) {
        'approved' => $stage === 'department' ? 'under_review' : 'approved',
        'rejected' => 'rejected',
        default => 'returned_for_correction',
    };
    $update = $pdo->prepare('UPDATE applications SET status = ?, decision_at = CASE WHEN ? IN (\'approved\', \'rejected\') THEN NOW() ELSE decision_at END WHERE id = ?');
    $update->execute([$newStatus, $decision, $applicationId]);
    $history = $pdo->prepare('INSERT INTO application_status_history (application_id, previous_status, new_status, changed_by, comment) VALUES (?, ?, ?, ?, ?)');
    $history->execute([$applicationId, $current['status'], $newStatus, user()['id'], $comment ?: null]);
    $notice = $pdo->prepare('INSERT INTO notifications (user_id, application_id, type, subject, message) VALUES (?, ?, ?, ?, ?)');
    $notice->execute([$current['student_user_id'], $applicationId, 'application_review', 'Application status updated', 'Your application has been ' . str_replace('_', ' ', $newStatus) . '.']);
    $pdo->commit();
    header('Location: ' . ($stage === 'department' ? '../pages/hod/pending-reviews.html?reviewed=1' : '../pages/secretary/all-applications.html?reviewed=1'));
    exit;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    exit($error instanceof RuntimeException ? $error->getMessage() : 'Unable to submit review.');
}
