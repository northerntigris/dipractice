<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'school') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $userId = (int)($payload['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не указан пользователь'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $schoolStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
    $schoolStmt->execute([(int)$_SESSION['user_id']]);
    $schoolId = (int)$schoolStmt->fetchColumn();

    if ($schoolId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Школа не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $checkStmt = $pdo->prepare("\n      SELECT id\n      FROM users\n      WHERE id = :user_id\n        AND school_id = :school_id\n        AND role = 'school_coordinator'\n      LIMIT 1\n    ");
    $checkStmt->execute([
        ':user_id' => $userId,
        ':school_id' => $schoolId
    ]);

    if (!$checkStmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $delStmt = $pdo->prepare("DELETE FROM users WHERE id = :user_id");
    $delStmt->execute([':user_id' => $userId]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка удаления пользователя'], JSON_UNESCAPED_UNICODE);
}
