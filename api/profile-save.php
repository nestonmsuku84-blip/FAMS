<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Student');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$current = user();
$registrationNumber = trim($_POST['registration_number'] ?? '');
$gender = $_POST['gender'] ?? '';
$institutionId = filter_var($_POST['institution_id'] ?? '', FILTER_VALIDATE_INT);
$programmeId = filter_var($_POST['programme_id'] ?? '', FILTER_VALIDATE_INT);
$levelId = filter_var($_POST['level_of_education_id'] ?? '', FILTER_VALIDATE_INT);
$nationalityId = filter_var($_POST['nationality_id'] ?? '', FILTER_VALIDATE_INT);
$dateOfBirth = trim($_POST['date_of_birth'] ?? '');

if ($registrationNumber === '' || !in_array($gender, ['male', 'female'], true) || !$institutionId || !$programmeId || !$levelId || !$nationalityId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
    header('Location: ../pages/student/profile.php?error=required');
    exit;
}

try {
    $pdo = db();
    $validProfileValues = $pdo->prepare(
        'SELECT 1
         FROM institutions i
         JOIN programmes_of_study p ON p.id = ? AND p.institution_id = i.id AND p.is_active = TRUE
         JOIN levels_of_education l ON l.id = ?
         JOIN nationalities n ON n.id = ?
         WHERE i.id = ? AND i.is_active = TRUE'
    );
    $validProfileValues->execute([$programmeId, $levelId, $nationalityId, $institutionId]);
    if (!$validProfileValues->fetchColumn()) {
        header('Location: ../pages/student/profile.php?error=required');
        exit;
    }
    $student = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = ?');
    $student->execute([$current['id']]);
    $studentId = $student->fetchColumn();

    if ($studentId) {
        $stmt = $pdo->prepare('UPDATE student_profiles SET institution_id = ?, programme_id = ?, level_of_education_id = ?, nationality_id = ?, registration_number = ?, gender = ?, date_of_birth = ? WHERE id = ?');
        $stmt->execute([$institutionId, $programmeId, $levelId, $nationalityId, $registrationNumber, $gender, $dateOfBirth, $studentId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO student_profiles (user_id, institution_id, programme_id, level_of_education_id, nationality_id, registration_number, gender, date_of_birth) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$current['id'], $institutionId, $programmeId, $levelId, $nationalityId, $registrationNumber, $gender, $dateOfBirth]);
    }

    header('Location: ../pages/student/profile.php?saved=1');
} catch (PDOException $e) {
    header('Location: ../pages/student/profile.php?error=save');
}
exit;
