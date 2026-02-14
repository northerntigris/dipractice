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

    $olympiadId = isset($_POST['olympiad_id']) ? (int)$_POST['olympiad_id'] : 0;
    $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

    if ($olympiadId <= 0 || $studentId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректные данные'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published boolean");
    $pdo->exec("UPDATE olympiads SET results_published = true WHERE results_published IS NULL");
    $pdo->exec("ALTER TABLE olympiads ALTER COLUMN results_published SET DEFAULT false");
    $pdo->exec("ALTER TABLE participant_work_files ADD COLUMN IF NOT EXISTS is_published boolean");
    $pdo->exec("UPDATE participant_work_files SET is_published = true WHERE is_published IS NULL");
    $pdo->exec("ALTER TABLE participant_work_files ALTER COLUMN is_published SET DEFAULT true");
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
        echo json_encode(['error' => 'Нет доступа к загрузке файлов'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $status = $row['status'] ?? null;
    $resultsPublished = !empty($row['results_published']);
    if ($status !== 'completed') {
        http_response_code(400);
        echo json_encode(['error' => 'Загрузка файлов доступна только после завершения олимпиады'], JSON_UNESCAPED_UNICODE);
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

    $uploads = [];
    if (isset($_FILES['work_files'])) {
        $files = $_FILES['work_files'];
        $count = is_array($files['name']) ? count($files['name']) : 1;
        for ($i = 0; $i < $count; $i++) {
            $uploads[] = [
                'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                'type' => is_array($files['type']) ? ($files['type'][$i] ?? null) : ($files['type'] ?? null),
                'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error' => is_array($files['error']) ? ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE)
            ];
        }
    } elseif (isset($_FILES['work_file'])) {
        $uploads[] = [
            'name' => $_FILES['work_file']['name'],
            'type' => $_FILES['work_file']['type'] ?? null,
            'tmp_name' => $_FILES['work_file']['tmp_name'],
            'error' => $_FILES['work_file']['error']
        ];
    }

    if (!$uploads) {
        http_response_code(400);
        echo json_encode(['error' => 'Файлы не загружены'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("ALTER TABLE participant_work_files DROP CONSTRAINT IF EXISTS participant_work_files_olympiad_id_student_id_key");

    $fileIds = [];
    foreach ($uploads as $upload) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Ошибка загрузки файла'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (empty($upload['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Не удалось прочитать файл'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fileStream = fopen($upload['tmp_name'], 'rb');
        if ($fileStream === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Не удалось прочитать файл'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO participant_work_files (olympiad_id, student_id, file_name, mime_type, file_data, is_published)
            VALUES (?, ?, ?, ?, ?, false)
            RETURNING id
        ");
        $stmt->bindValue(1, $olympiadId, PDO::PARAM_INT);
        $stmt->bindValue(2, $studentId, PDO::PARAM_INT);
        $stmt->bindValue(3, $upload['name'], PDO::PARAM_STR);
        if ($upload['type'] === null) {
            $stmt->bindValue(4, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(4, $upload['type'], PDO::PARAM_STR);
        }
        $stmt->bindValue(5, $fileStream, PDO::PARAM_LOB);
        $stmt->execute();
        $fileIds[] = (int)$stmt->fetchColumn();
        $stmt->closeCursor();
    }

    echo json_encode(['success' => true, 'file_ids' => $fileIds], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка загрузки файла'], JSON_UNESCAPED_UNICODE);
}
?>
