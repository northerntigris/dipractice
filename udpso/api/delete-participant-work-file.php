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

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $olympiadId = isset($input['olympiad_id']) ? (int)$input['olympiad_id'] : 0;
    $fileId = isset($input['file_id']) ? (int)$input['file_id'] : 0;

    if ($olympiadId <= 0 || $fileId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректные данные'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published boolean");
    $pdo->exec("UPDATE olympiads SET results_published = true WHERE results_published IS NULL");
    $pdo->exec("ALTER TABLE olympiads ALTER COLUMN results_published SET DEFAULT false");

    $stmt = $pdo->prepare("
        SELECT o.status, o.results_published
        FROM olympiad_jury oj
        JOIN jury_members jm ON oj.jury_member_id = jm.id
        JOIN olympiads o ON oj.olympiad_id = o.id
        WHERE oj.olympiad_id = ? AND jm.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$olympiadId, (int)$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(403);
        echo json_encode(['error' => 'Нет доступа к удалению файлов'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $status = $row['status'] ?? null;
    $resultsPublished = !empty($row['results_published']);
    if ($status !== 'completed') {
        http_response_code(400);
        echo json_encode(['error' => 'Удаление файлов доступно только после завершения олимпиады'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($resultsPublished) {
        http_response_code(400);
        echo json_encode(['error' => 'Результаты уже опубликованы и не могут быть изменены'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $checkStmt = $pdo->prepare("
        SELECT 1
        FROM participant_work_files
        WHERE id = ? AND olympiad_id = ?
        LIMIT 1
    ");
    $checkStmt->execute([$fileId, $olympiadId]);
    if (!$checkStmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Файл не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $deleteStmt = $pdo->prepare("
        DELETE FROM participant_work_files
        WHERE id = ? AND olympiad_id = ?
    ");
    $deleteStmt->execute([$fileId, $olympiadId]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка удаления файла'], JSON_UNESCAPED_UNICODE);
}
?>
