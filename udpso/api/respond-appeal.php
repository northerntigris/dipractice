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

    $appealId = isset($_POST['appeal_id']) ? (int)$_POST['appeal_id'] : 0;
    $comment = trim((string)($_POST['comment'] ?? ''));
    $score = isset($_POST['score']) && $_POST['score'] !== '' ? (float)$_POST['score'] : null;

    if ($appealId <= 0) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'Некорректная апелляция'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if ($comment === '' && $score === null && empty($_FILES['files'])) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'Добавьте комментарий, файл или новые баллы'], JSON_UNESCAPED_UNICODE);
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
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested boolean");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_reason text");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS score_draft numeric");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested_at timestamptz");

    $pdo->exec("CREATE TABLE IF NOT EXISTS appeal_response_files (
      id SERIAL PRIMARY KEY,
      appeal_id INTEGER NOT NULL REFERENCES appeals(id) ON DELETE CASCADE,
      file_name TEXT,
      mime_type TEXT,
      file_data BYTEA,
      uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $checkStmt = $pdo->prepare("SELECT a.id, a.olympiad_id, a.student_id, a.status
      FROM appeals a
      WHERE a.id = :id
      LIMIT 1");
    $checkStmt->execute([':id' => $appealId]);
    $appeal = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$appeal) {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Апелляция не найдена'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (($appeal['status'] ?? 'pending') !== 'pending') {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'Апелляция уже обработана'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $juryStmt = $pdo->prepare("SELECT 1
      FROM olympiad_jury oj
      JOIN jury_members jm ON jm.id = oj.jury_member_id
      WHERE jm.user_id = :user_id
        AND oj.olympiad_id IN (
          SELECT id FROM olympiads WHERE id = :olympiad_id OR template_id = :olympiad_id
          UNION
          SELECT template_id FROM olympiads WHERE id = :olympiad_id AND template_id IS NOT NULL
        )
      LIMIT 1");
    $juryStmt->execute([
      ':user_id' => (int)$_SESSION['user_id'],
      ':olympiad_id' => (int)$appeal['olympiad_id']
    ]);
    if (!$juryStmt->fetchColumn()) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'Недостаточно прав'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $pdo->beginTransaction();

    if ($score !== null) {
      $scoreStmt = $pdo->prepare("UPDATE olympiad_participants
        SET score = :score,
            score_draft = :score,
            review_requested = false,
            review_reason = NULL,
            review_requested_at = NULL
        WHERE olympiad_id = :olympiad_id AND student_id = :student_id");
      $scoreStmt->execute([
        ':score' => $score,
        ':olympiad_id' => (int)$appeal['olympiad_id'],
        ':student_id' => (int)$appeal['student_id']
      ]);
    }

    $updAppeal = $pdo->prepare("UPDATE appeals
      SET status = 'resolved',
          response_comment = :comment,
          response_score = :score,
          responder_id = :responder_id,
          response_created_at = NOW()
      WHERE id = :id");
    $updAppeal->execute([
      ':comment' => $comment !== '' ? $comment : null,
      ':score' => $score,
      ':responder_id' => (int)$_SESSION['user_id'],
      ':id' => $appealId
    ]);

    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
      $files = $_FILES['files'];
      $insertFile = $pdo->prepare("INSERT INTO appeal_response_files (appeal_id, file_name, mime_type, file_data)
        VALUES (:appeal_id, :file_name, :mime_type, :file_data)");

      foreach ($files['name'] as $index => $name) {
        $errorCode = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
          continue;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
          throw new RuntimeException('Ошибка загрузки файла (код ' . (int)$errorCode . ')');
        }

        $tmpPath = $files['tmp_name'][$index] ?? '';
        if ($tmpPath === '') {
          continue;
        }

        $fileData = file_get_contents($tmpPath);
        if ($fileData === false) {
          throw new RuntimeException('Не удалось прочитать прикреплённый файл');
        }

        $insertFile->bindValue(':appeal_id', $appealId, PDO::PARAM_INT);
        $insertFile->bindValue(':file_name', (string)$name, PDO::PARAM_STR);
        $insertFile->bindValue(':mime_type', (string)($files['type'][$index] ?? 'application/octet-stream'), PDO::PARAM_STR);
        $insertFile->bindValue(':file_data', $fileData, PDO::PARAM_LOB);
        $insertFile->execute();
      }
    }

    $pdo->commit();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка обработки апелляции', 'details' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
