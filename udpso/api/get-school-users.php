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
    $schoolStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
    $schoolStmt->execute([$_SESSION['user_id']]);
    $schoolId = $schoolStmt->fetchColumn();

    if (!$schoolId) {
        echo json_encode(['success' => true, 'users' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, full_name, email, role, created_at
        FROM users
        WHERE school_id = ? AND role = 'school_coordinator'
        ORDER BY created_at DESC
    ");
    $stmt->execute([$schoolId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки пользователей'], JSON_UNESCAPED_UNICODE);
}
