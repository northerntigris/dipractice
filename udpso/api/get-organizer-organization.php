<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $schoolId = (int)$stmt->fetchColumn();

    if ($schoolId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не определена школа организатора'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT short_name FROM approved_schools WHERE id = ?");
    $stmt->execute([$schoolId]);
    $shortName = $stmt->fetchColumn();

    if (!$shortName) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Организация не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true, 'organization' => $shortName], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки организации'], JSON_UNESCAPED_UNICODE);
}
?>
