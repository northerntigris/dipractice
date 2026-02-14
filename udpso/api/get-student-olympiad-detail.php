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

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Некорректный идентификатор'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $studentId = (int)$_SESSION['user_id'];
    $olympiadId = (int)$_GET['id'];

    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published boolean");
    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published_at timestamptz");
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
    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS status text");
    $pdo->exec("UPDATE appeals SET status = 'pending' WHERE status IS NULL");
    $pdo->exec("ALTER TABLE appeals ALTER COLUMN status SET DEFAULT 'pending'");
    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS response_comment text");
    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS response_score numeric");
    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS response_created_at timestamptz");
    $pdo->exec("CREATE TABLE IF NOT EXISTS appeal_response_files (
        id SERIAL PRIMARY KEY,
        appeal_id INTEGER NOT NULL REFERENCES appeals(id) ON DELETE CASCADE,
        file_name TEXT,
        mime_type TEXT,
        file_data BYTEA,
        uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $stmt = $pdo->prepare("
        SELECT
            o.id,
            o.subject,
            o.datetime,
            o.grades,
            o.description,
            o.results_published,
            o.results_published_at,
            COALESCE(s.full_name, org.full_name) AS organizer_name,
            CASE
                WHEN o.results_published = true
                  AND o.results_published_at IS NOT NULL
                  AND NOW() <= o.results_published_at + INTERVAL '24 hours'
                THEN true
                ELSE false
            END AS can_appeal,
            CASE
                WHEN o.results_published_at IS NOT NULL
                THEN o.results_published_at + INTERVAL '24 hours'
                ELSE NULL
            END AS appeal_deadline,
            latest_appeal.id AS appeal_id,
            latest_appeal.description AS appeal_description,
            latest_appeal.status AS appeal_status,
            latest_appeal.response_comment AS appeal_response_comment,
            latest_appeal.response_score AS appeal_response_score,
            latest_appeal.response_created_at AS appeal_response_created_at,
            COALESCE(
              (SELECT json_agg(json_build_object('id', arf.id, 'name', arf.file_name) ORDER BY arf.id DESC)
               FROM appeal_response_files arf
               WHERE latest_appeal.id IS NOT NULL AND arf.appeal_id = latest_appeal.id),
              '[]'::json
            ) AS appeal_response_files
        FROM olympiad_participants op
        JOIN olympiads o ON o.id = op.olympiad_id
        LEFT JOIN approved_schools s ON o.school_id = s.id
        LEFT JOIN approved_organizations org ON o.organization_id = org.id
        LEFT JOIN LATERAL (
            SELECT a.id, a.description, COALESCE(a.status, 'pending') AS status, a.response_comment, a.response_score, a.response_created_at
            FROM appeals a
            WHERE a.olympiad_id = o.id AND a.student_id = op.student_id
            ORDER BY a.created_at DESC
            LIMIT 1
        ) latest_appeal ON true
        WHERE op.student_id = :student_id AND o.id = :olympiad_id
        LIMIT 1
    ");
    $stmt->execute([
        ':student_id' => $studentId,
        ':olympiad_id' => $olympiadId
    ]);

    $olympiad = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$olympiad) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Олимпиада не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (isset($olympiad['appeal_response_files']) && is_string($olympiad['appeal_response_files'])) {
        $decodedResponse = json_decode($olympiad['appeal_response_files'], true);
        $olympiad['appeal_response_files'] = is_array($decodedResponse) ? $decodedResponse : [];
    } elseif (!isset($olympiad['appeal_response_files']) || !is_array($olympiad['appeal_response_files'])) {
        $olympiad['appeal_response_files'] = [];
    }

    echo json_encode(['success' => true, 'olympiad' => $olympiad], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки данных'], JSON_UNESCAPED_UNICODE);
}
