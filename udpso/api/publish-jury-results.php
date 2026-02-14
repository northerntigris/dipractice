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
    if ($olympiadId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректные данные'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published boolean");
    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published_at timestamptz");
    $pdo->exec("UPDATE olympiads SET results_published = true WHERE results_published IS NULL");
    $pdo->exec("ALTER TABLE olympiads ALTER COLUMN results_published SET DEFAULT false");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS score_draft numeric");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested boolean");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_reason text");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested_at timestamptz");
    $pdo->exec("UPDATE olympiad_participants SET review_requested = false WHERE review_requested IS NULL");
    $pdo->exec("ALTER TABLE olympiad_participants ALTER COLUMN review_requested SET DEFAULT false");
    $pdo->exec("ALTER TABLE participant_work_files ADD COLUMN IF NOT EXISTS is_published boolean");
    $pdo->exec("UPDATE participant_work_files SET is_published = true WHERE is_published IS NULL");
    $pdo->exec("ALTER TABLE participant_work_files ALTER COLUMN is_published SET DEFAULT true");

    $stmt = $pdo->prepare("
        SELECT o.status, o.results_published, oj.jury_role
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
        echo json_encode(['error' => 'Нет доступа к публикации результатов'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($row['status'] ?? null) !== 'completed') {
        http_response_code(400);
        echo json_encode(['error' => 'Публикация доступна только после завершения олимпиады'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($row['jury_role']) || $row['jury_role'] !== 'председатель жюри') {
        http_response_code(403);
        echo json_encode(['error' => 'Публиковать результаты может только председатель жюри'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($row['results_published'])) {
        $reviewCheck = $pdo->prepare("
            SELECT 1
            FROM olympiad_participants
            WHERE olympiad_id IN (
              SELECT id FROM olympiads WHERE id = :olympiad_id OR template_id = :olympiad_id
            )
              AND review_requested = true
            LIMIT 1
        ");
        $reviewCheck->execute([':olympiad_id' => $olympiadId]);
        $hasReviewRequests = (bool)$reviewCheck->fetchColumn();
        if (!$hasReviewRequests) {
            http_response_code(400);
            echo json_encode(['error' => 'Результаты уже опубликованы'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $pdo->beginTransaction();

    $olympiadIdsStmt = $pdo->prepare("
        SELECT id
        FROM olympiads
        WHERE id = :olympiad_id OR template_id = :olympiad_id
    ");
    $olympiadIdsStmt->execute([':olympiad_id' => $olympiadId]);
    $olympiadIds = $olympiadIdsStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$olympiadIds) {
        throw new RuntimeException('Олимпиада не найдена');
    }

    $placeholders = implode(',', array_fill(0, count($olympiadIds), '?'));

    $updateScores = $pdo->prepare("
        UPDATE olympiad_participants
        SET score = score_draft
        WHERE olympiad_id IN ($placeholders) AND score_draft IS NOT NULL
    ");
    $updateScores->execute($olympiadIds);

    $clearReview = $pdo->prepare("
        UPDATE olympiad_participants
        SET review_requested = false,
            review_reason = NULL,
            review_requested_at = NULL
        WHERE olympiad_id IN ($placeholders)
          AND review_requested = true
    ");
    $clearReview->execute($olympiadIds);

    $updateFiles = $pdo->prepare("
        UPDATE participant_work_files
        SET is_published = true
        WHERE olympiad_id IN ($placeholders)
    ");
    $updateFiles->execute($olympiadIds);

    $publishStmt = $pdo->prepare("
        UPDATE olympiads
        SET results_published = true,
            results_published_at = NOW()
        WHERE id IN ($placeholders)
    ");
    $publishStmt->execute($olympiadIds);

    $pdo->commit();

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка публикации результатов'], JSON_UNESCAPED_UNICODE);
}
?>
