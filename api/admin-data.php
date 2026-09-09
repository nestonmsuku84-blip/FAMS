<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Administrator');
header('Content-Type: application/json; charset=utf-8');

function adminRespond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

try {
    $pdo = db();
    $resource = $_GET['resource'] ?? $_POST['resource'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = match ($resource) {
            'specializations' => $pdo->query('SELECT id, name, description, is_active, created_at FROM specializations ORDER BY name')->fetchAll(),
            'windows' => $pdo->query('SELECT id, name, open_date, close_date, is_active, created_at FROM application_windows ORDER BY open_date DESC')->fetchAll(),
            'institutions' => $pdo->query('SELECT id, name, code, email, phone_number, address, is_active FROM institutions ORDER BY name')->fetchAll(),
            'companies' => $pdo->query('SELECT c.id, c.name, c.code, c.email, c.phone_number, c.address, c.is_active, (SELECT COUNT(*) FROM company_departments cd WHERE cd.company_id = c.id) AS department_count FROM companies c ORDER BY c.name')->fetchAll(),
            'users' => $pdo->query('SELECT u.id, u.full_name, u.email, u.status, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS roles FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id GROUP BY u.id ORDER BY u.full_name')->fetchAll(),
            'roles' => $pdo->query('SELECT id, name FROM roles ORDER BY name')->fetchAll(),
            default => throw new InvalidArgumentException('Unknown resource.'),
        };
        adminRespond(['items' => $rows]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') adminRespond(['error' => 'Method not allowed.'], 405);

    if ($resource === 'users') {
        $action = $_POST['action'] ?? 'create';
        if ($action === 'set_status') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? '';
            if (!$userId || !in_array($status, ['active', 'suspended'], true)) adminRespond(['error' => 'Choose a valid user and status.'], 422);
            if ($userId === (int)user()['id']) adminRespond(['error' => 'You cannot change your own account status.'], 422);
            $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
            $stmt->execute([$status, $userId]);
            if ($stmt->rowCount() === 0) adminRespond(['error' => 'User not found.'], 404);
            adminRespond(['message' => 'User status updated.']);
        }
        $fullName = trim($_POST['full_name'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = (string)($_POST['password'] ?? '');
        $roleId = filter_var($_POST['role_id'] ?? null, FILTER_VALIDATE_INT);
        if ($fullName === '' || !$email || strlen($password) < 8 || !$roleId) adminRespond(['error' => 'Provide a name, valid email, role, and password of at least 8 characters.'], 422);
        $role = $pdo->prepare('SELECT id FROM roles WHERE id = ?');
        $role->execute([$roleId]);
        if (!$role->fetchColumn()) adminRespond(['error' => 'Choose a valid role.'], 422);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, status) VALUES (?, ?, ?, \'active\')');
        $stmt->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $newUserId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$newUserId, $roleId]);
        $pdo->commit();
        adminRespond(['message' => 'User created.', 'id' => $newUserId], 201);
    }

    if ($resource === 'institutions') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        if ($name === '') adminRespond(['error' => 'An institution name is required.'], 422);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) adminRespond(['error' => 'Provide a valid institution email.'], 422);
        $stmt = $pdo->prepare('INSERT INTO institutions (name, code, email, phone_number, address, is_active) VALUES (?, ?, ?, ?, ?, TRUE)');
        $stmt->execute([$name, $code ?: null, $email ?: null, $phone ?: null, $address ?: null]);
        adminRespond(['message' => 'Institution created.', 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($resource === 'specializations') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($name === '') adminRespond(['error' => 'A specialization name is required.'], 422);
        $stmt = $pdo->prepare('INSERT INTO specializations (name, description, is_active) VALUES (?, ?, TRUE)');
        $stmt->execute([$name, $description ?: null]);
        adminRespond(['message' => 'Specialization created.', 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($resource === 'windows') {
        $name = trim($_POST['name'] ?? '');
        $openDate = trim($_POST['open_date'] ?? '');
        $closeDate = trim($_POST['close_date'] ?? '');
        if ($name === '' || strtotime($openDate) === false || strtotime($closeDate) === false || strtotime($closeDate) <= strtotime($openDate)) {
            adminRespond(['error' => 'Provide a name and a valid opening and closing date.'], 422);
        }
        $stmt = $pdo->prepare('INSERT INTO application_windows (name, open_date, close_date, is_active, created_by) VALUES (?, ?, ?, TRUE, ?)');
        $stmt->execute([$name, date('Y-m-d H:i:s', strtotime($openDate)), date('Y-m-d H:i:s', strtotime($closeDate)), user()['id']]);
        adminRespond(['message' => 'Application window created.', 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($resource === 'companies') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $department = trim($_POST['department_name'] ?? '');
        $supervisor = trim($_POST['supervisor_name'] ?? '');
        if ($name === '' || $department === '' || $supervisor === '') adminRespond(['error' => 'Company, department, and supervisor are required.'], 422);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) adminRespond(['error' => 'Provide a valid company email.'], 422);
        $pdo->beginTransaction();
        $company = $pdo->prepare('INSERT INTO companies (name, email, phone_number, address, is_active) VALUES (?, ?, ?, ?, TRUE)');
        $company->execute([$name, $email ?: null, $phone ?: null, $address ?: null]);
        $companyId = (int)$pdo->lastInsertId();
        $departmentStmt = $pdo->prepare('INSERT INTO company_departments (company_id, name, is_active) VALUES (?, ?, TRUE)');
        $departmentStmt->execute([$companyId, $department]);
        $departmentId = (int)$pdo->lastInsertId();
        $supervisorStmt = $pdo->prepare('INSERT INTO supervisors (company_id, company_department_id, full_name, is_active) VALUES (?, ?, ?, TRUE)');
        $supervisorStmt->execute([$companyId, $departmentId, $supervisor]);
        $pdo->commit();
        adminRespond(['message' => 'Company, department, and supervisor created.', 'id' => $companyId], 201);
    }

    adminRespond(['error' => 'Unknown resource.'], 422);
} catch (PDOException $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    adminRespond(['error' => $error->getCode() === '23000' ? 'That record already exists.' : 'Unable to save the record.'], $error->getCode() === '23000' ? 409 : 500);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    adminRespond(['error' => 'Unable to process the request.'], 500);
}
