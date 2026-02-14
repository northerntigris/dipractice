<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $studentId = (int)$_SESSION['user_id'];
    $olympiadId = isset($_POST['olympiad_id']) ? (int)$_POST['olympiad_id'] : 0;
    $description = trim($_POST['description'] ?? '');

    if ($olympiadId <= 0 || $description === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Заполните описание апелляции'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS appeals (
        id SERIAL PRIMARY KEY,
        olympiad_id INTEGER NOT NULL REFERENCES olympiads(id) ON DELETE CASCADE,
        student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        description TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        response_comment TEXT,
        response_score numeric,
        responder_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        response_created_at TIMESTAMPTZ,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS appeal_files (
        id SERIAL PRIMARY KEY,
        appeal_id INTEGER NOT NULL REFERENCES appeals(id) ON DELETE CASCADE,
        file_name TEXT,
        mime_type TEXT,
        file_data BYTEA,
        uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS status text");
    $pdo->exec("UPDATE appeals SET status = 'pending' WHERE status IS NULL");
    $pdo->exec("ALTER TABLE appeals ALTER COLUMN status SET DEFAULT 'pending'");
    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS response_comment text");
    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS response_score numeric");
    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS responder_id integer");
    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS response_created_at timestamptz");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested boolean");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_reason text");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested_at timestamptz");

    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published boolean");
    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published_at timestamptz");

    $checkStmt = $pdo->prepare("
        SELECT
            o.results_published,
            o.results_published_at,
            CASE
                WHEN o.results_published = true
                  AND o.results_published_at IS NOT NULL
                  AND NOW() <= o.results_published_at + INTERVAL '24 hours'
                THEN true
                ELSE false
            END AS can_appeal
        FROM olympiad_participants op
        JOIN olympiads o ON o.id = op.olympiad_id
        WHERE op.student_id = :student_id AND o.id = :olympiad_id
        LIMIT 1
    ");
    $checkStmt->execute([
        ':student_id' => $studentId,
        ':olympiad_id' => $olympiadId
    ]);
    $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Нет доступа к олимпиаде'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($row['can_appeal'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Подача апелляции доступна только в течение 24 часов после публикации результатов'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    $appealStmt = $pdo->prepare("
        INSERT INTO appeals (olympiad_id, student_id, description)
        VALUES (:olympiad_id, :student_id, :description)
        RETURNING id
    ");
    $appealStmt->execute([
        ':olympiad_id' => $olympiadId,
        ':student_id' => $studentId,
        ':description' => $description
    ]);
    $appealId = (int)$appealStmt->fetchColumn();

    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
        $files = $_FILES['files'];
        $insertFile = $pdo->prepare("
            INSERT INTO appeal_files (appeal_id, file_name, mime_type, file_data)
            VALUES (:appeal_id, :file_name, :mime_type, :file_data)
        ");

        foreach ($files['name'] as $index => $name) {
            if ($files['error'][$index] !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmpPath = $files['tmp_name'][$index];
            if (!is_uploaded_file($tmpPath)) {
                continue;
            }
            $fileData = file_get_contents($tmpPath);
            $insertFile->execute([
                ':appeal_id' => $appealId,
                ':file_name' => $name,
                ':mime_type' => $files['type'][$index] ?? 'application/octet-stream',
                ':file_data' => $fileData
            ]);
        }
    }


    $reviewStmt = $pdo->prepare("
        UPDATE olympiad_participants
        SET review_requested = true,
            review_reason = :reason,
            review_requested_at = NOW()
        WHERE olympiad_id = :olympiad_id AND student_id = :student_id
    ");
    $reviewStmt->execute([
        ':reason' => $description,
        ':olympiad_id' => $olympiadId,
        ':student_id' => $studentId
    ]);

    $pdo->commit();

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка отправки апелляции'], JSON_UNESCAPED_UNICODE);
}
