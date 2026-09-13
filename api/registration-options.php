<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $organization = $pdo->query('SELECT id, name FROM institutions WHERE is_active = TRUE ORDER BY id LIMIT 1')->fetch();
    $programmes = [];
    if ($organization) { $stmt = $pdo->prepare('SELECT id, name FROM programmes_of_study WHERE is_active = TRUE AND institution_id = ? ORDER BY name'); $stmt->execute([$organization['id']]); $programmes = $stmt->fetchAll(); }
    echo json_encode([
        'institutions' => $organization ? [$organization] : [],
        'programmes' => $programmes,
        'levels' => $pdo->query('SELECT id, name FROM levels_of_education ORDER BY name')->fetchAll(),
        'nationalities' => $pdo->query('SELECT id, name FROM nationalities ORDER BY name')->fetchAll(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Registration options are unavailable.']);
}
