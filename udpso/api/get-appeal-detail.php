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

    $appealId = isset($_GET['appeal_id']) ? (int)$_GET['appeal_id'] : 0;
    if ($appealId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Некорректный appeal_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS appeal_files (
        id SERIAL PRIMARY KEY,
        appeal_id INTEGER NOT NULL REFERENCES appeals(id) ON DELETE CASCADE,
        file_name TEXT,
        mime_type TEXT,
        file_data BYTEA,
        uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS appeal_response_files (
        id SERIAL PRIMARY KEY,
        appeal_id INTEGER NOT NULL REFERENCES appeals(id) ON DELETE CASCADE,
        file_name TEXT,
        mime_type TEXT,
        file_data BYTEA,
        uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $userId = (int)$_SESSION['user_id'];
    $role = $_SESSION['user_role'] ?? '';

    $stmt = $pdo->prepare("SELECT
        a.id,
        a.olympiad_id,
        a.student_id,
        a.description,
        COALESCE(a.status, 'pending') AS status,
        a.response_comment,
        a.response_score,
        a.response_created_at,
        a.created_at,
        o.subject,
        p.score,
        u.full_name AS student_name,
        u.grade,
        u.age,
        u.snils,
        u.email,
        COALESCE(
          (SELECT json_agg(json_build_object('id', af.id, 'name', af.file_name) ORDER BY af.id DESC)
           FROM appeal_files af WHERE af.appeal_id = a.id),
          '[]'::json
        ) AS appeal_files,
        COALESCE(
          (SELECT json_agg(json_build_object('id', arf.id, 'name', arf.file_name) ORDER BY arf.id DESC)
           FROM appeal_response_files arf WHERE arf.appeal_id = a.id),
          '[]'::json
        ) AS response_files
      FROM appeals a
      JOIN olympiads o ON o.id = a.olympiad_id
      JOIN users u ON u.id = a.student_id
      LEFT JOIN olympiad_participants p ON p.olympiad_id = a.olympiad_id AND p.student_id = a.student_id
      WHERE a.id = :appeal_id
      LIMIT 1");
    $stmt->execute([':appeal_id' => $appealId]);
    $appeal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appeal) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Апелляция не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $accessGranted = false;

    $juryCheck = $pdo->prepare("WITH related_olympiads AS (
        SELECT id FROM olympiads WHERE id = :olympiad_id
        UNION
        SELECT template_id AS id FROM olympiads WHERE id = :olympiad_id AND template_id IS NOT NULL
        UNION
        SELECT id FROM olympiads WHERE template_id = :olympiad_id
      )
      SELECT 1
      FROM olympiad_jury oj
      JOIN jury_members jm ON jm.id = oj.jury_member_id
      JOIN related_olympiads ro ON ro.id = oj.olympiad_id
      WHERE jm.user_id = :user_id
      LIMIT 1");
    $juryCheck->execute([':user_id' => $userId, ':olympiad_id' => (int)$appeal['olympiad_id']]);
    $accessGranted = (bool)$juryCheck->fetchColumn();

    if (!$accessGranted && in_array($role, ['school', 'school_coordinator'], true)) {
        $schoolCheck = $pdo->prepare("SELECT 1
          FROM users current_u
          WHERE current_u.id = :user_id
            AND current_u.school_id = (SELECT school_id FROM users WHERE id = :student_id)
          LIMIT 1");
        $schoolCheck->execute([':user_id' => $userId, ':student_id' => (int)$appeal['student_id']]);
        $accessGranted = (bool)$schoolCheck->fetchColumn();
    }

    if (!$accessGranted) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Недостаточно прав'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($role, ['school', 'school_coordinator'], true)) {
        unset($appeal['snils'], $appeal['email'], $appeal['age']);
    }

    echo json_encode(['success' => true, 'appeal' => $appeal], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки апелляции'], JSON_UNESCAPED_UNICODE);
}
