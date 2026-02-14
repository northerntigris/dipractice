<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $olympiadId = $_GET['id'] ?? null;
    if (!$olympiadId || !is_numeric($olympiadId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid olympiad ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT oj.jury_role
        FROM olympiad_jury oj
        JOIN jury_members jm ON oj.jury_member_id = jm.id
        WHERE oj.olympiad_id = ? AND jm.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$olympiadId, (int)$_SESSION['user_id']]);
    $role = $stmt->fetchColumn();

    if (!$role) {
        http_response_code(404);
        echo json_encode(['error' => 'Роль жюри не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true, 'role' => $role], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка загрузки роли жюри'], JSON_UNESCAPED_UNICODE);
}
?>
