<?php
// api/get-student-olympiads.php
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

    // имя ученика
    $nameStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
    $nameStmt->execute([$studentId]);
    $studentName = $nameStmt->fetchColumn() ?: '';


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

    $hasWorkFiles = (bool)$pdo->query("
        SELECT 1 FROM information_schema.tables
        WHERE table_schema='public' AND table_name='participant_work_files'
        LIMIT 1
    ")->fetchColumn();

    $hasWorkPublished = (bool)$pdo->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_schema='public' AND table_name='participant_work_files' AND column_name='is_published'
        LIMIT 1
    ")->fetchColumn();

    $hasResultsPublished = (bool)$pdo->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_schema='public' AND table_name='olympiads' AND column_name='results_published'
        LIMIT 1
    ")->fetchColumn();

    $scoreExpr = $hasResultsPublished
        ? "CASE WHEN o.results_published THEN op.score ELSE NULL END"
        : "op.score";

    $workFilesSelect = $hasWorkFiles
        ? ($hasWorkPublished
            ? "(SELECT COALESCE(json_agg(json_build_object('id', f.id, 'name', f.file_name) ORDER BY f.id DESC), '[]'::json)
                FROM participant_work_files f
               WHERE f.olympiad_id = o.id
                 AND f.student_id = op.student_id
                 AND f.is_published = true) AS work_files"
            : "(SELECT COALESCE(json_agg(json_build_object('id', f.id, 'name', f.file_name) ORDER BY f.id DESC), '[]'::json)
                FROM participant_work_files f
               WHERE f.olympiad_id = o.id
                 AND f.student_id = op.student_id) AS work_files")
        : "'[]'::json AS work_files";

    $sql = "
        SELECT
            o.id,
            o.subject,
            o.datetime,
            o.status,
            $scoreExpr AS score,
            COALESCE(s.short_name, org.full_name) AS organization_name,

            CASE
              WHEN $scoreExpr IS NULL THEN NULL
              ELSE RANK() OVER (PARTITION BY op.olympiad_id ORDER BY $scoreExpr DESC NULLS LAST)
            END AS place,

            $workFilesSelect,

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
            SELECT a.id, COALESCE(a.status, 'pending') AS status, a.response_comment, a.response_score, a.response_created_at
            FROM appeals a
            WHERE a.olympiad_id = o.id AND a.student_id = op.student_id
            ORDER BY a.created_at DESC
            LIMIT 1
        ) latest_appeal ON true
        WHERE op.student_id = :student_id
        ORDER BY o.datetime DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':student_id' => $studentId]);
    $olympiads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($olympiads as &$olympiad) {
        if (!isset($olympiad['work_files'])) {
            $olympiad['work_files'] = [];
        }
        if (is_string($olympiad['work_files'])) {
            $decoded = json_decode($olympiad['work_files'], true);
            $olympiad['work_files'] = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($olympiad['work_files'])) {
            $olympiad['work_files'] = [];
        }

        if (!isset($olympiad['appeal_response_files'])) {
            $olympiad['appeal_response_files'] = [];
        } elseif (is_string($olympiad['appeal_response_files'])) {
            $decodedResponse = json_decode($olympiad['appeal_response_files'], true);
            $olympiad['appeal_response_files'] = is_array($decodedResponse) ? $decodedResponse : [];
        } elseif (!is_array($olympiad['appeal_response_files'])) {
            $olympiad['appeal_response_files'] = [];
        }
    }
    unset($olympiad);

    echo json_encode([
        'success' => true,
        'name' => $studentName,
        'olympiads' => $olympiads
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка загрузки олимпиад ученика',
        // 'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
