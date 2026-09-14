<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('FAMS Officer');
header('Content-Type: application/json; charset=utf-8');

function placementResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

try {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $mode = $_GET['mode'] ?? 'list';
        if ($mode === 'options') {
            $applications = $pdo->query("SELECT a.id, a.reference_number, u.full_name AS student_name, s.name AS specialization_name FROM applications a JOIN student_profiles sp ON sp.id = a.student_id JOIN users u ON u.id = sp.user_id LEFT JOIN application_specializations aps ON aps.application_id = a.id AND aps.priority_order = 1 LEFT JOIN specializations s ON s.id = aps.specialization_id LEFT JOIN placements p ON p.application_id = a.id WHERE a.status = 'approved' AND p.id IS NULL ORDER BY a.decision_at DESC")->fetchAll();
            // FAMS is configured for one host company. The company is selected
            // by the system, never by the secretary.
            $company = $pdo->query('SELECT id, name FROM companies WHERE is_active = TRUE ORDER BY id LIMIT 1')->fetch();
            if (!$company) placementResponse(['error' => 'No active host company is configured. Ask the administrator to configure the company first.'], 422);
            $departments = $pdo->prepare('SELECT id, company_id, name FROM company_departments WHERE is_active = TRUE AND company_id = ? ORDER BY name');
            $departments->execute([$company['id']]);
            $supervisors = $pdo->prepare('SELECT id, company_id, company_department_id, full_name, email, phone_number FROM supervisors WHERE is_active = TRUE AND company_id = ? ORDER BY full_name');
            $supervisors->execute([$company['id']]);
            placementResponse(['applications' => $applications, 'company_name' => $company['name'], 'departments' => $departments->fetchAll(), 'supervisors' => $supervisors->fetchAll()]);
        }
        $placements = $pdo->query('SELECT p.id, p.status, p.placement_start_date, p.placement_end_date, p.notes, a.reference_number, u.full_name AS student_name, c.name AS company_name, cd.name AS department_name, s.full_name AS supervisor_name FROM placements p JOIN applications a ON a.id = p.application_id JOIN student_profiles sp ON sp.id = a.student_id JOIN users u ON u.id = sp.user_id JOIN companies c ON c.id = p.company_id JOIN company_departments cd ON cd.id = p.company_department_id JOIN supervisors s ON s.id = p.supervisor_id ORDER BY p.assigned_at DESC')->fetchAll();
        placementResponse(['placements' => $placements]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') placementResponse(['error' => 'Method not allowed.'], 405);
    if (($_POST['action'] ?? '') === 'create_supervisor') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        if ($fullName === '' || !$departmentId) placementResponse(['error' => 'Supervisor name and department are required.'], 422);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) placementResponse(['error' => 'Enter a valid supervisor email address.'], 422);
        $pdo->beginTransaction();
        $company = $pdo->query('SELECT id FROM companies WHERE is_active = TRUE ORDER BY id LIMIT 1 FOR UPDATE')->fetch();
        if (!$company) throw new RuntimeException('No active host company is configured.');
        $department = $pdo->prepare('SELECT id FROM company_departments WHERE id = ? AND company_id = ? AND is_active = TRUE');
        $department->execute([$departmentId, $company['id']]);
        if (!$department->fetchColumn()) throw new RuntimeException('Choose a department from the host company.');
        $insert = $pdo->prepare('INSERT INTO supervisors (company_id, company_department_id, full_name, email, phone_number, position, is_active) VALUES (?, ?, ?, ?, ?, ?, TRUE)');
        $insert->execute([$company['id'], $departmentId, $fullName, $email ?: null, $phone ?: null, $position ?: null]);
        $pdo->commit();
        placementResponse(['message' => 'Supervisor added. You can now assign a placement.'], 201);
    }
    $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
    $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
    $supervisorId = filter_var($_POST['supervisor_id'] ?? null, FILTER_VALIDATE_INT);
    $start = trim($_POST['placement_start_date'] ?? '');
    $end = trim($_POST['placement_end_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if (!$applicationId || !$departmentId || !$supervisorId) placementResponse(['error' => 'Choose an application, department, and supervisor.'], 422);
    if (($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) || ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) || ($start !== '' && $end !== '' && $end < $start)) placementResponse(['error' => 'Provide valid placement dates.'], 422);

    $pdo->beginTransaction();
    $company = $pdo->query('SELECT id FROM companies WHERE is_active = TRUE ORDER BY id LIMIT 1 FOR UPDATE')->fetch();
    if (!$company) throw new RuntimeException('No active host company is configured.');
    $companyId = (int)$company['id'];
    $application = $pdo->prepare("SELECT a.id, sp.user_id AS student_user_id FROM applications a JOIN student_profiles sp ON sp.id = a.student_id LEFT JOIN placements p ON p.application_id = a.id WHERE a.id = ? AND a.status = 'approved' AND p.id IS NULL FOR UPDATE");
    $application->execute([$applicationId]);
    $student = $application->fetch();
    if (!$student) throw new RuntimeException('This application is not available for placement.');
    $validAssignment = $pdo->prepare('SELECT 1 FROM company_departments cd JOIN supervisors s ON s.company_id = cd.company_id AND s.company_department_id = cd.id WHERE cd.id = ? AND cd.company_id = ? AND cd.is_active = TRUE AND s.id = ? AND s.is_active = TRUE');
    $validAssignment->execute([$departmentId, $companyId, $supervisorId]);
    if (!$validAssignment->fetchColumn()) throw new RuntimeException('The selected company, department, and supervisor do not match.');
    $insert = $pdo->prepare("INSERT INTO placements (application_id, company_id, company_department_id, supervisor_id, placement_start_date, placement_end_date, status, assigned_by, notes) VALUES (?, ?, ?, ?, ?, ?, 'confirmed', ?, ?)");
    $insert->execute([$applicationId, $companyId, $departmentId, $supervisorId, $start ?: null, $end ?: null, user()['id'], $notes ?: null]);
    $notice = $pdo->prepare('INSERT INTO notifications (user_id, application_id, type, subject, message) VALUES (?, ?, ?, ?, ?)');
    $notice->execute([$student['student_user_id'], $applicationId, 'placement_assigned', 'Placement assigned', 'Your placement has been assigned. Open My Placement to view the details.']);
    $pdo->commit();
    placementResponse(['message' => 'Placement assigned and the student has been notified.'], 201);
} catch (RuntimeException $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    placementResponse(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    placementResponse(['error' => 'Unable to save placement.'], 500);
}
