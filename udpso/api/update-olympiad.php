<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'organizer') {
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

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $datetime = trim((string)($input['datetime'] ?? ''));
    $windowStart = trim((string)($input['window_start'] ?? ''));
    $windowEnd = trim((string)($input['window_end'] ?? ''));
    $grades = trim((string)($input['grades'] ?? ''));

    if ($id <= 0 || $grades === '') {
      http_response_code(400);
      echo json_encode(['error' => 'Заполните обязательные поля'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if ($windowStart !== '' || $windowEnd !== '') {
        if ($windowStart === '' || $windowEnd === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Укажите обе даты проведения'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($datetime === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Укажите дату проведения'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT organizer_id FROM olympiads WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $organizerId = (int)$stmt->fetchColumn();
    if ($organizerId !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Нет доступа к олимпиаде'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM school_olympiads WHERE olympiad_id = ?");
    $stmt->execute([$id]);
    $schoolsCount = (int)$stmt->fetchColumn();
    if ($schoolsCount > 0) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Редактирование недоступно: школы уже присоединились к проведению.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($windowStart !== '' && $windowEnd !== '') {
        $windowStartValue = str_replace('T', ' ', $windowStart);
        $windowEndValue = str_replace('T', ' ', $windowEnd);
        $stmt = $pdo->prepare("
            UPDATE olympiads
            SET window_start = ?, window_end = ?, grades = ?
            WHERE id = ?
        ");
        $stmt->execute([$windowStartValue, $windowEndValue, $grades, $id]);
    } else {
        $datetimeValue = str_replace('T', ' ', $datetime);
        $stmt = $pdo->prepare("
            UPDATE olympiads
            SET datetime = ?, grades = ?
            WHERE id = ?
        ");
        $stmt->execute([$datetimeValue, $grades, $id]);
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка обновления олимпиады'], JSON_UNESCAPED_UNICODE);
}
?>
