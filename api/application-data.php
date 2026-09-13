<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

function applicationScope(PDO $pdo, string $role, int $userId): array
{
    return match (strtolower($role)) {
        'student' => ['sp.user_id = ?', [$userId]],
        'department officer' => ['EXISTS (SELECT 1 FROM department_officer_scopes dos JOIN application_specializations scoped_as ON scoped_as.specialization_id = dos.specialization_id WHERE scoped_as.application_id = a.id AND dos.user_id = ?)', [$userId]],
        'fams officer', 'administrator' => ['1 = 1', []],
        default => ['0 = 1', []],
    };
}

try {
    $pdo = db();
    $current = user();
    $mode = $_GET['mode'] ?? 'list';

    if ($mode === 'options') {
        requireRole('Student');
        $window = $pdo->query("SELECT id, name, open_date, close_date FROM application_windows WHERE is_active = TRUE AND NOW() BETWEEN open_date AND close_date ORDER BY open_date DESC LIMIT 1")->fetch();
        $specializations = $pdo->query('SELECT id, name FROM specializations WHERE is_active = TRUE ORDER BY name')->fetchAll();
        respond(['window' => $window ?: null, 'specializations' => $specializations]);
    }

    [$scope, $params] = applicationScope($pdo, $current['role'], (int)$current['id']);
    if ($scope === '0 = 1') respond(['error' => 'You are not authorized to access applications.'], 403);

    $base = " FROM applications a
        JOIN student_profiles sp ON sp.id = a.student_id
        JOIN users u ON u.id = sp.user_id
        LEFT JOIN application_specializations aps ON aps.application_id = a.id AND aps.priority_order = 1
        LEFT JOIN specializations s ON s.id = aps.specialization_id
        LEFT JOIN application_windows aw ON aw.id = a.application_window_id
        LEFT JOIN placements pl ON pl.application_id = a.id
        WHERE $scope";

    if ($mode === 'draft') {
        requireRole('Student');
        $applicationId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$applicationId) respond(['error' => 'A valid draft id is required.'], 422);
        $draft = $pdo->prepare("SELECT a.*, aps.specialization_id $base AND a.id = ? AND a.status = 'draft'");
        $draft->execute([...$params, $applicationId]);
        $item = $draft->fetch();
        if (!$item) respond(['error' => 'Draft not found.'], 404);
        respond(['application' => $item]);
    }

    if ($mode === 'detail') {
        $applicationId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$applicationId) respond(['error' => 'A valid application id is required.'], 422);
        $stmt = $pdo->prepare("SELECT a.*, u.full_name AS student_name, u.email AS student_email, sp.registration_number, s.name AS specialization_name, aw.name AS window_name, aw.open_date AS window_open_date, aw.close_date AS window_close_date $base AND a.id = ?");
        $stmt->execute([...$params, $applicationId]);
        $application = $stmt->fetch();
        if (!$application) respond(['error' => 'Application not found.'], 404);
        $documents = $pdo->prepare('SELECT id, document_type, original_filename, file_format, file_size_bytes, validation_status, validation_comment, uploaded_at FROM application_documents WHERE application_id = ? ORDER BY id');
        $documents->execute([$applicationId]);
        $history = $pdo->prepare('SELECT h.previous_status, h.new_status, h.comment, h.changed_at, u.full_name AS changed_by_name FROM application_status_history h JOIN users u ON u.id = h.changed_by WHERE h.application_id = ? ORDER BY h.changed_at');
        $history->execute([$applicationId]);
        $reviews = $pdo->prepare('SELECT r.stage, r.decision, r.review_comment, r.completed_at, u.full_name AS reviewer_name FROM application_reviews r JOIN users u ON u.id = r.reviewer_id WHERE r.application_id = ? ORDER BY r.created_at');
        $reviews->execute([$applicationId]);
        $placement = $pdo->prepare('SELECT p.*, c.name AS company_name, cd.name AS department_name, s.full_name AS supervisor_name, s.email AS supervisor_email, s.phone_number AS supervisor_phone FROM placements p JOIN companies c ON c.id = p.company_id JOIN company_departments cd ON cd.id = p.company_department_id JOIN supervisors s ON s.id = p.supervisor_id WHERE p.application_id = ?');
        $placement->execute([$applicationId]);
        respond(['application' => $application, 'documents' => $documents->fetchAll(), 'history' => $history->fetchAll(), 'reviews' => $reviews->fetchAll(), 'placement' => $placement->fetch() ?: null]);
    }

    $stmt = $pdo->prepare("SELECT a.id, a.reference_number, a.status, a.submitted_at, a.created_at, u.full_name AS student_name, sp.registration_number, s.name AS specialization_name, pl.status AS placement_status $base ORDER BY a.created_at DESC");
    $stmt->execute($params);
    respond(['applications' => $stmt->fetchAll()]);
} catch (Throwable $error) {
    respond(['error' => 'Application data is unavailable.'], 500);
}
