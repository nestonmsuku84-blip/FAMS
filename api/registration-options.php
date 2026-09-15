<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $institutions = $pdo->query('SELECT id, name FROM institutions WHERE is_active = TRUE ORDER BY name')->fetchAll();
    $institutionId = filter_var($_GET['institution_id'] ?? null, FILTER_VALIDATE_INT) ?: (int)($institutions[0]['id'] ?? 0);
    $programmes = [];
    if ($institutionId) { $stmt = $pdo->prepare('SELECT id, name FROM programmes_of_study WHERE is_active = TRUE AND institution_id = ? ORDER BY name'); $stmt->execute([$institutionId]); $programmes = $stmt->fetchAll(); }
    echo json_encode([
        'institutions' => $institutions,
        'programmes' => $programmes,
        'levels' => $pdo->query('SELECT id, name FROM levels_of_education ORDER BY name')->fetchAll(),
        'nationalities' => $pdo->query('SELECT id, name FROM nationalities ORDER BY name')->fetchAll(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Registration options are unavailable.']);
}
