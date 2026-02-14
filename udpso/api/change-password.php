<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
session_start();

$role = $_SESSION['user_role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!$userId || !in_array($role, ['school', 'school_coordinator', 'organizer', 'student', 'expert', 'admin', 'moderator'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$currentPassword = (string)($input['current_password'] ?? '');
$newPassword = (string)($input['new_password'] ?? '');

if (mb_strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Новый пароль должен быть не короче 8 символов'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Пользователь не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!password_verify($currentPassword, $row['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Текущий пароль неверный'], JSON_UNESCAPED_UNICODE);
    exit;
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

$upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
$upd->execute([$newHash, $userId]);

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
