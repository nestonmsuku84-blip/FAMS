<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$stmt = db()->prepare('SELECT u.full_name, sp.registration_number, sp.gender, sp.date_of_birth, sp.institution_id, sp.programme_id, sp.level_of_education_id, sp.nationality_id, i.name AS institution_name, p.name AS programme_name, l.name AS level_name, n.name AS nationality_name FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN institutions i ON i.id = sp.institution_id LEFT JOIN programmes_of_study p ON p.id = sp.programme_id LEFT JOIN levels_of_education l ON l.id = sp.level_of_education_id LEFT JOIN nationalities n ON n.id = sp.nationality_id WHERE u.id = ?');
$stmt->execute([user()['id']]);
$profile = $stmt->fetch();

echo json_encode($profile ?: ['full_name' => user()['full_name']], JSON_THROW_ON_ERROR);
