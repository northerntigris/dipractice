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

    $role = $_SESSION['user_role'] ?? '';
    if (!in_array($role, ['school', 'school_coordinator'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Недостаточно прав'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $olympiadId = isset($input['olympiad_id']) ? (int)$input['olympiad_id'] : 0;
    $studentId = isset($input['student_id']) ? (int)$input['student_id'] : 0;
    $reason = trim((string)($input['reason'] ?? ''));

    if ($olympiadId <= 0 || $studentId <= 0 || $reason === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Заполните обязательные поля'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published boolean");
    $pdo->exec("UPDATE olympiads SET results_published = true WHERE results_published IS NULL");
    $pdo->exec("ALTER TABLE olympiads ALTER COLUMN results_published SET DEFAULT false");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested boolean");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_reason text");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested_at timestamptz");
    $pdo->exec("UPDATE olympiad_participants SET review_requested = false WHERE review_requested IS NULL");
    $pdo->exec("ALTER TABLE olympiad_participants ALTER COLUMN review_requested SET DEFAULT false");

    $statusStmt = $pdo->prepare("SELECT status, results_published FROM olympiads WHERE id = ? LIMIT 1");
    $statusStmt->execute([$olympiadId]);
    $olympiad = $statusStmt->fetch(PDO::FETCH_ASSOC);
    if (!$olympiad) {
        http_response_code(404);
        echo json_encode(['error' => 'Олимпиада не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($olympiad['status'] ?? '') !== 'completed') {
        http_response_code(400);
        echo json_encode(['error' => 'Пересмотр доступен только после завершения олимпиады'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($olympiad['results_published'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Результаты ещё не опубликованы'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $schoolIdStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
    $schoolIdStmt->execute([(int)$_SESSION['user_id']]);
    $currentSchoolId = (int)$schoolIdStmt->fetchColumn();

    $participantStmt = $pdo->prepare("
        SELECT p.score, p.review_requested, u.school_id
        FROM olympiad_participants p
        JOIN users u ON p.student_id = u.id
        WHERE p.olympiad_id = ? AND p.student_id = ?
        LIMIT 1
    ");
    $participantStmt->execute([$olympiadId, $studentId]);
    $participant = $participantStmt->fetch(PDO::FETCH_ASSOC);
    if (!$participant) {
        http_response_code(404);
        echo json_encode(['error' => 'Участник не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $studentSchoolId = (int)($participant['school_id'] ?? 0);
    if ($currentSchoolId <= 0 || $studentSchoolId !== $currentSchoolId) {
        http_response_code(403);
        echo json_encode(['error' => 'Нет доступа к участнику'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($participant['score'] === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Баллы ещё не выставлены'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($participant['review_requested'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Пересмотр уже запрошен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $update = $pdo->prepare("
        UPDATE olympiad_participants
        SET review_requested = true,
            review_reason = :reason,
            review_requested_at = NOW()
        WHERE olympiad_id = :olympiad_id AND student_id = :student_id
    ");
    $update->execute([
        ':reason' => $reason,
        ':olympiad_id' => $olympiadId,
        ':student_id' => $studentId
    ]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка запроса пересмотра'], JSON_UNESCAPED_UNICODE);
}
?>
