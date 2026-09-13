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
            'users' => $pdo->query('SELECT u.id, u.full_name, u.email, u.status, GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ", ") AS roles, MAX(ds.id) AS department_id, GROUP_CONCAT(DISTINCT ds.name ORDER BY ds.name SEPARATOR ", ") AS department_name FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id LEFT JOIN department_officer_scopes dos ON dos.user_id = u.id LEFT JOIN specializations ds ON ds.id = dos.specialization_id WHERE u.status = \'active\' GROUP BY u.id ORDER BY u.full_name')->fetchAll(),
            'departments' => $pdo->query('SELECT id, name FROM specializations WHERE is_active = TRUE ORDER BY name')->fetchAll(),
            'company_departments' => $pdo->query('SELECT id, company_id, name FROM company_departments WHERE is_active = TRUE ORDER BY name')->fetchAll(),
            'roles' => $pdo->query('SELECT id, name FROM roles ORDER BY name')->fetchAll(),
            'notification_recipients' => $pdo->query("SELECT id, full_name, email FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll(),
            'admin_notifications' => $pdo->query("SELECT n.id, n.subject, n.message, n.type, n.created_at, u.full_name AS recipient_name FROM notifications n JOIN users u ON u.id = n.user_id ORDER BY n.created_at DESC LIMIT 100")->fetchAll(),
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
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($fullName === '' || !$email || !$roleId || ($action === 'create' && strlen($password) < 8) || ($action === 'update' && $password !== '' && strlen($password) < 8)) adminRespond(['error' => 'Provide a name, valid email, role, and a password of at least 8 characters when setting one.'], 422);
        $role = $pdo->prepare('SELECT id, name FROM roles WHERE id = ?');
        $role->execute([$roleId]);
        $roleRow = $role->fetch();
        if (!$roleRow) adminRespond(['error' => 'Choose a valid role.'], 422);
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $companyDepartmentId = filter_var($_POST['company_department_id'] ?? null, FILTER_VALIDATE_INT);
        if ($roleRow['name'] === 'Department Officer' && !$departmentId) adminRespond(['error' => 'Assign a department to the HOD.'], 422);
        if (in_array($roleRow['name'], ['Organization Supervisor', 'Organizational Supervisor'], true) && !$companyDepartmentId) adminRespond(['error' => 'Assign an organization department to the supervisor.'], 422);

        if ($roleRow['name'] === 'Department Officer') {
            $assignedHod = $pdo->prepare('SELECT dos.user_id FROM department_officer_scopes dos JOIN users u ON u.id = dos.user_id WHERE dos.specialization_id = ? AND u.status = \'active\' AND dos.user_id <> ? LIMIT 1');
            $assignedHod->execute([$departmentId, $userId ?: 0]);
            if ($assignedHod->fetchColumn()) adminRespond(['error' => 'That department already has an assigned HOD. Edit that HOD or choose another department.'], 422);
        }

        if ($action === 'update') {
            if (!$userId) adminRespond(['error' => 'Choose a valid user to edit.'], 422);
            if ($userId === (int)user()['id'] && $roleRow['name'] !== (string)$pdo->query('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ' . (int)user()['id'] . ' LIMIT 1')->fetchColumn()) adminRespond(['error' => 'You cannot change your own role.'], 422);
            $exists = $pdo->prepare('SELECT id FROM users WHERE id = ? AND status = \'active\''); $exists->execute([$userId]);
            if (!$exists->fetchColumn()) adminRespond(['error' => 'Active user not found.'], 404);
            $pdo->beginTransaction();
            $update = 'UPDATE users SET full_name = ?, email = ?' . ($password !== '' ? ', password_hash = ?' : '') . ' WHERE id = ?';
            $params = $password !== '' ? [$fullName, $email, password_hash($password, PASSWORD_DEFAULT), $userId] : [$fullName, $email, $userId];
            $pdo->prepare($update)->execute($params);
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
            $pdo->prepare('DELETE FROM department_officer_scopes WHERE user_id = ?')->execute([$userId]);
            if ($roleRow['name'] === 'Department Officer') $pdo->prepare('INSERT INTO department_officer_scopes (user_id, specialization_id) VALUES (?, ?)')->execute([$userId, $departmentId]);
            $pdo->commit();
            adminRespond(['message' => 'User updated.']);
        }
        if ($action !== 'create') adminRespond(['error' => 'Unknown user action.'], 422);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, status) VALUES (?, ?, ?, \'active\')');
        $stmt->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $newUserId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$newUserId, $roleId]);
        if ($roleRow['name'] === 'Department Officer') $pdo->prepare('INSERT INTO department_officer_scopes (user_id, specialization_id) VALUES (?, ?)')->execute([$newUserId, $departmentId]);
        if (in_array($roleRow['name'], ['Organization Supervisor', 'Organizational Supervisor'], true)) {
            $companyDepartment = $pdo->prepare('SELECT company_id FROM company_departments WHERE id = ? AND is_active = TRUE'); $companyDepartment->execute([$companyDepartmentId]); $companyId = $companyDepartment->fetchColumn();
            if (!$companyId) throw new InvalidArgumentException('Invalid organization department.');
            $pdo->prepare('INSERT INTO supervisors (company_id, company_department_id, user_id, full_name, email, is_active) VALUES (?, ?, ?, ?, ?, TRUE)')->execute([$companyId, $companyDepartmentId, $newUserId, $fullName, $email]);
        }
        $pdo->commit();
        adminRespond(['message' => 'User created.', 'id' => $newUserId], 201);
    }

    if ($resource === 'institutions') {
        $action = $_POST['action'] ?? 'create';
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        if ($name === '') adminRespond(['error' => 'An institution name is required.'], 422);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) adminRespond(['error' => 'Provide a valid institution email.'], 422);
        if ($action === 'update') {
            $institutionId = filter_var($_POST['institution_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$institutionId) adminRespond(['error' => 'Choose a valid institution to edit.'], 422);
            $exists = $pdo->prepare('SELECT id FROM institutions WHERE id = ?');
            $exists->execute([$institutionId]);
            if (!$exists->fetchColumn()) adminRespond(['error' => 'Institution not found.'], 404);
            $stmt = $pdo->prepare('UPDATE institutions SET name = ?, code = ?, email = ?, phone_number = ?, address = ? WHERE id = ?');
            $stmt->execute([$name, $code ?: null, $email ?: null, $phone ?: null, $address ?: null, $institutionId]);
            adminRespond(['message' => 'Institution details updated.']);
        }
        if ($pdo->query('SELECT COUNT(*) FROM institutions WHERE is_active = TRUE')->fetchColumn()) adminRespond(['error' => 'This FAMS installation is configured for one organization. Update the existing organization instead of adding another one.'], 422);
        $stmt = $pdo->prepare('INSERT INTO institutions (name, code, email, phone_number, address, is_active) VALUES (?, ?, ?, ?, ?, TRUE)');
        $stmt->execute([$name, $code ?: null, $email ?: null, $phone ?: null, $address ?: null]);
        adminRespond(['message' => 'Institution created.', 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($resource === 'notifications') {
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $recipientId = $_POST['recipient_id'] ?? 'all';
        if ($subject === '' || $message === '') adminRespond(['error' => 'A subject and message are required.'], 422);
        $recipients = $recipientId === 'all'
            ? $pdo->query("SELECT id FROM users WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN)
            : [filter_var($recipientId, FILTER_VALIDATE_INT)];
        $recipients = array_filter($recipients);
        if ($recipientId !== 'all' && $recipients) {
            $activeRecipient = $pdo->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
            $activeRecipient->execute([$recipients[0]]);
            if (!$activeRecipient->fetchColumn()) $recipients = [];
        }
        if (!$recipients) adminRespond(['error' => 'Choose at least one active recipient.'], 422);
        $pdo->beginTransaction();
        $notice = $pdo->prepare("INSERT INTO notifications (user_id, type, subject, message, channel, is_read) VALUES (?, 'admin_announcement', ?, ?, 'in_system', FALSE)");
        foreach ($recipients as $id) $notice->execute([$id, $subject, $message]);
        $pdo->commit();
        adminRespond(['message' => 'Notification sent to ' . count($recipients) . ' active user(s).']);
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
