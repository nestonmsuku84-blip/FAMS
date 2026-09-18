<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

function dashboardResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

try {
    $pdo = db();
    $current = user();
    $role = strtolower((string)$current['role']);
    $scope = match ($role) {
        'student' => ['sp.user_id = ?', [(int)$current['id']]],
        'department officer' => ['EXISTS (SELECT 1 FROM department_officer_scopes dos JOIN application_specializations scoped_as ON scoped_as.specialization_id = dos.specialization_id WHERE scoped_as.application_id = a.id AND dos.user_id = ?)', [(int)$current['id']]],
        'fams officer', 'administrator' => ['1 = 1', []],
        default => ['0 = 1', []],
    };
    if ($scope[0] === '0 = 1') dashboardResponse(['error' => 'You are not authorized to view dashboard data.'], 403);

    [$where, $params] = $scope;
    $from = ' FROM applications a JOIN student_profiles sp ON sp.id = a.student_id JOIN users u ON u.id = sp.user_id LEFT JOIN application_specializations aps ON aps.application_id = a.id AND aps.priority_order = 1 LEFT JOIN specializations s ON s.id = aps.specialization_id WHERE ' . $where;
    $counts = $pdo->prepare('SELECT a.status, COUNT(*) AS total ' . $from . ' GROUP BY a.status');
    $counts->execute($params);
    $statuses = array_fill_keys(['draft', 'submitted', 'under_review', 'returned_for_correction', 'resubmitted', 'approved', 'rejected', 'cancelled'], 0);
    foreach ($counts->fetchAll() as $row) $statuses[$row['status']] = (int)$row['total'];

    $recent = $pdo->prepare('SELECT a.id, a.reference_number, a.status, a.submitted_at, a.created_at, u.full_name AS student_name, s.name AS specialization_name ' . $from . ' ORDER BY a.created_at DESC LIMIT 8');
    $recent->execute($params);
    $result = ['statuses' => $statuses, 'recent_applications' => $recent->fetchAll()];

    if ($role === 'department officer' && ($_GET['mode'] ?? '') === 'history') {
        $history = $pdo->prepare('SELECT a.reference_number, u.full_name AS student_name, r.decision, r.status, r.completed_at FROM application_reviews r JOIN applications a ON a.id = r.application_id JOIN student_profiles sp ON sp.id = a.student_id JOIN users u ON u.id = sp.user_id WHERE r.reviewer_id = ? AND r.stage = \'department\' ORDER BY r.completed_at DESC');
        $history->execute([(int)$current['id']]);
        $result['review_history'] = $history->fetchAll();
    }
    if ($role === 'student') {
        $placement = $pdo->prepare('SELECT p.id, p.status, c.name AS company_name FROM placements p JOIN applications a ON a.id = p.application_id JOIN student_profiles sp ON sp.id = a.student_id JOIN companies c ON c.id = p.company_id WHERE sp.user_id = ? ORDER BY p.assigned_at DESC LIMIT 1');
        $placement->execute([(int)$current['id']]);
        $result['placement'] = $placement->fetch() ?: null;
    }
    if (in_array($role, ['fams officer', 'administrator'], true)) $result['placements_confirmed'] = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE status = 'confirmed'")->fetchColumn();
    dashboardResponse($result);
} catch (Throwable $error) {
    dashboardResponse(['error' => 'Dashboard data is unavailable.'], 500);
}
