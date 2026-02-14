<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Не авторизован']);
        exit;
    }

    // В проекте везде используется user_role (на всякий случай поддержим и role)
    $role = $_SESSION['user_role'] ?? ($_SESSION['role'] ?? '');
    if (!in_array($role, ['school', 'school_coordinator'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Нет прав']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;

    $olympiadId = isset($data['olympiad_id']) ? (int)$data['olympiad_id'] : 0;
    $studentId = isset($data['student_id']) ? (int)$data['student_id'] : 0;

    if ($olympiadId <= 0 || $studentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
        exit;
    }

    $stmt = $pdo->prepare("
        DELETE FROM olympiad_participants
        WHERE olympiad_id = :oid AND student_id = :sid
    ");
    $stmt->execute([
        ':oid' => $olympiadId,
        ':sid' => $studentId
    ]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Связь не найдена (возможно уже удалена)']);
        exit;
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка сервера',
        'exception' => $e->getMessage()
    ]);
}
?>