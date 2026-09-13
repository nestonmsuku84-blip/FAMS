<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$currentUser = user();

$stmt = db()->prepare("
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone_number,
        u.status,
        u.profile_photo,
        sp.registration_number,
        p.name AS programme_name,
        l.name AS level_name
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN programmes_of_study p ON p.id = sp.programme_id
    LEFT JOIN levels_of_education l ON l.id = sp.level_of_education_id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->execute([$currentUser['id']]);

$profile = $stmt->fetch();

if (!$profile) {
    http_response_code(404);

    echo json_encode([
        'error' => 'User profile not found'
    ]);

    exit;
}

echo json_encode($profile, JSON_THROW_ON_ERROR);
