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

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $olympiadId = isset($input['olympiad_id']) ? (int)$input['olympiad_id'] : 0;
    $studentId = isset($input['student_id']) ? (int)$input['student_id'] : 0;
    $score = $input['score'] ?? null;
    $scoreRaw = is_string($score) ? trim($score) : $score;

    if ($olympiadId <= 0 || $studentId <= 0 || $scoreRaw === null || $scoreRaw === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Заполните обязательные поля'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (is_string($scoreRaw)) {
        $scoreRaw = str_replace(',', '.', $scoreRaw);
    }
    $scoreValue = filter_var($scoreRaw, FILTER_VALIDATE_FLOAT);
    if ($scoreValue === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный формат баллов'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published boolean");
    $pdo->exec("UPDATE olympiads SET results_published = true WHERE results_published IS NULL");
    $pdo->exec("ALTER TABLE olympiads ALTER COLUMN results_published SET DEFAULT false");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS score_draft numeric");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested boolean");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_reason text");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested_at timestamptz");
    $pdo->exec("UPDATE olympiad_participants SET review_requested = false WHERE review_requested IS NULL");
    $pdo->exec("ALTER TABLE olympiad_participants ALTER COLUMN review_requested SET DEFAULT false");

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
        echo json_encode(['error' => 'Нет доступа к выставлению баллов'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = $row['status'] ?? null;
    $resultsPublished = !empty($row['results_published']);

    if ($status !== 'completed') {
        http_response_code(400);
        echo json_encode(['error' => 'Баллы можно выставлять только после завершения олимпиады'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($resultsPublished) {
        $reviewCheck = $pdo->prepare("
            SELECT review_requested
            FROM olympiad_participants
            WHERE olympiad_id = ? AND student_id = ?
            LIMIT 1
        ");
        $reviewCheck->execute([$olympiadId, $studentId]);
        $reviewRequested = (bool)$reviewCheck->fetchColumn();
        if (!$reviewRequested) {
            http_response_code(400);
            echo json_encode(['error' => 'Результаты уже опубликованы и не могут быть изменены'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($scoreValue < 0 || $scoreValue > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Баллы должны быть в диапазоне от 0 до 100'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE olympiad_participants
        SET score_draft = ?
        WHERE olympiad_id = ? AND student_id = ?
    ");
    $stmt->execute([$scoreValue, $olympiadId, $studentId]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сохранения баллов'], JSON_UNESCAPED_UNICODE);
}
?>
