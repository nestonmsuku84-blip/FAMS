<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

function notificationResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

try {
    $pdo = db();
    $userId = (int)user()['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $items = $pdo->prepare('SELECT n.id, n.application_id, n.type, n.subject, n.message, n.is_read, n.read_at, n.created_at, a.reference_number FROM notifications n LEFT JOIN applications a ON a.id = n.application_id WHERE n.user_id = ? AND n.channel = \'in_system\' ORDER BY n.created_at DESC LIMIT 100');
        $items->execute([$userId]);
        $unread = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND channel = \'in_system\' AND is_read = FALSE');
        $unread->execute([$userId]);
        notificationResponse(['notifications' => $items->fetchAll(), 'unread_count' => (int)$unread->fetchColumn()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') notificationResponse(['error' => 'Method not allowed.'], 405);

    $action = $_POST['action'] ?? '';
    if ($action === 'read') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) notificationResponse(['error' => 'A valid notification is required.'], 422);
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = TRUE, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
    } elseif ($action === 'read_all') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = TRUE, read_at = COALESCE(read_at, NOW()) WHERE user_id = ? AND channel = \'in_system\' AND is_read = FALSE');
        $stmt->execute([$userId]);
    } else {
        notificationResponse(['error' => 'Unknown notification action.'], 422);
    }
    notificationResponse(['message' => 'Notifications updated.']);
} catch (Throwable $error) {
    notificationResponse(['error' => 'Notifications are unavailable.'], 500);
}
