<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
  if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $id = $_GET['id'] ?? null;
  if (!$id || !is_numeric($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid olympiad ID'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $columnStmt = $pdo->prepare("\n    SELECT 1\n    FROM information_schema.columns\n    WHERE table_schema = 'public'\n      AND table_name = 'olympiad_participants'\n      AND column_name = 'school_id'\n    LIMIT 1\n  ");
  $columnStmt->execute();
  $hasSchoolColumn = (bool)$columnStmt->fetchColumn();

  $userRole = $_SESSION['user_role'] ?? '';
  $currentUserId = (int)$_SESSION['user_id'];

  $juryStmt = $pdo->prepare("\n    WITH related_olympiads AS (\n      SELECT id\n      FROM olympiads\n      WHERE id = :olympiad_id\n\n      UNION\n\n      SELECT template_id AS id\n      FROM olympiads\n      WHERE id = :olympiad_id\n        AND template_id IS NOT NULL\n\n      UNION\n\n      SELECT id\n      FROM olympiads\n      WHERE template_id = :olympiad_id\n    )\n    SELECT 1\n    FROM olympiad_jury oj\n    JOIN jury_members jm ON oj.jury_member_id = jm.id\n    JOIN related_olympiads ro ON ro.id = oj.olympiad_id\n    WHERE jm.user_id = :user_id\n    LIMIT 1\n  ");
  $juryStmt->execute([
    ':olympiad_id' => (int)$id,
    ':user_id' => $currentUserId
  ]);
  $isJuryMember = (bool)$juryStmt->fetchColumn();

  $scoreDraftStmt = $pdo->prepare("\n    SELECT 1\n    FROM information_schema.columns\n    WHERE table_schema = 'public'\n      AND table_name = 'olympiad_participants'\n      AND column_name = 'score_draft'\n    LIMIT 1\n  ");
  $scoreDraftStmt->execute();
  $hasScoreDraft = (bool)$scoreDraftStmt->fetchColumn();

  $reviewStmt = $pdo->prepare("\n    SELECT 1\n    FROM information_schema.columns\n    WHERE table_schema = 'public'\n      AND table_name = 'olympiad_participants'\n      AND column_name = 'review_requested'\n    LIMIT 1\n  ");
  $reviewStmt->execute();
  $hasReviewFlag = (bool)$reviewStmt->fetchColumn();
  if (!$hasReviewFlag) {
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested boolean");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_reason text");
    $pdo->exec("ALTER TABLE olympiad_participants ADD COLUMN IF NOT EXISTS review_requested_at timestamptz");
    $pdo->exec("UPDATE olympiad_participants SET review_requested = false WHERE review_requested IS NULL");
    $pdo->exec("ALTER TABLE olympiad_participants ALTER COLUMN review_requested SET DEFAULT false");
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
  $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS status text");
  $pdo->exec("UPDATE appeals SET status = 'pending' WHERE status IS NULL");
  $pdo->exec("ALTER TABLE appeals ALTER COLUMN status SET DEFAULT 'pending'");
  $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS response_comment text");
  $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS response_score numeric");
  $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS responder_id integer");
  $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS response_created_at timestamptz");
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

  $publishColStmt = $pdo->prepare("\n    SELECT 1\n    FROM information_schema.columns\n    WHERE table_schema = 'public'\n      AND table_name = 'participant_work_files'\n      AND column_name = 'is_published'\n    LIMIT 1\n  ");
  $publishColStmt->execute();
  $hasWorkPublished = (bool)$publishColStmt->fetchColumn();

  $resultsColStmt = $pdo->prepare("\n    SELECT 1\n    FROM information_schema.columns\n    WHERE table_schema = 'public'\n      AND table_name = 'olympiads'\n      AND column_name = 'results_published'\n    LIMIT 1\n  ");
  $resultsColStmt->execute();
  $hasResultsPublished = (bool)$resultsColStmt->fetchColumn();

  $resultsPublished = true;
  if ($hasResultsPublished) {
    $resultsStmt = $pdo->prepare("SELECT results_published FROM olympiads WHERE id = :olympiad_id LIMIT 1");
    $resultsStmt->execute([':olympiad_id' => (int)$id]);
    $resultsValue = $resultsStmt->fetchColumn();
    $resultsPublished = $resultsValue === null ? true : (bool)$resultsValue;
  }

  $canSeeDraft = $isJuryMember;

  if (!$canSeeDraft && !$resultsPublished) {
    $scoreSelect = 'NULL AS score';
    $workFilesSelect = "'[]'::json AS work_files";
  } else {
    if ($hasScoreDraft && $canSeeDraft) {
      $scoreSelect = 'COALESCE(p.score_draft, p.score) AS score';
    } else {
      $scoreSelect = 'p.score AS score';
    }

    $workFilesFilter = '';
    if ($hasWorkPublished && !$canSeeDraft) {
      $workFilesFilter = ' AND f.is_published = true';
    }

    $workFilesSelect = "
      (
        SELECT COALESCE(
          json_agg(
            json_build_object(
              'id', f.id,
              'name', f.file_name
            )
            ORDER BY f.id
          ) FILTER (WHERE f.id IS NOT NULL),
          '[]'::json
        )
        FROM participant_work_files f
        WHERE f.olympiad_id = p.olympiad_id AND f.student_id = p.student_id{$workFilesFilter}
      ) AS work_files
    ";
  }

  $schoolIdStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
  $schoolIdStmt->execute([$currentUserId]);
  $currentSchoolId = (int)$schoolIdStmt->fetchColumn();

  $requestedSchoolRegId = null;
  if (isset($_GET['school_reg_id']) && is_numeric($_GET['school_reg_id'])) {
    $requestedSchoolRegId = (int)$_GET['school_reg_id'];
  }

  $filterSchoolValue = null;
  $filterSchoolAlt = null;

  if (in_array($userRole, ['school', 'school_coordinator'], true)) {
    $filterSchoolValue = $currentSchoolId > 0 ? $currentSchoolId : null;
  } elseif ($userRole === 'organizer' && $requestedSchoolRegId) {
    $filterSchoolValue = $requestedSchoolRegId;
    $filterSchoolAlt = null;
  }

  $schoolSelect = $hasSchoolColumn
    ? 'COALESCE(s.short_name, ss.short_name) AS school'
    : 's.short_name AS school';
  $schoolJoin = $hasSchoolColumn
    ? 'LEFT JOIN approved_schools ss ON (p.school_id = ss.id)'
    : '';

  $reviewSelect = "COALESCE(p.review_requested, false) AS review_requested, p.review_reason";
  $appealSelect = "
    latest_appeal.id AS appeal_id,
    latest_appeal.status AS appeal_status,
    latest_appeal.description AS appeal_description,
    latest_appeal.created_at AS appeal_created_at,
    latest_appeal.response_comment AS appeal_response_comment,
    latest_appeal.response_score AS appeal_response_score,
    latest_appeal.response_created_at AS appeal_response_created_at,
    latest_appeal_files.files AS appeal_files,
    latest_response_files.files AS appeal_response_files
  ";

  $stmt = $pdo->prepare("
    WITH related_olympiads AS (
      SELECT id
      FROM olympiads
      WHERE id = :olympiad_id

      UNION

      SELECT template_id AS id
      FROM olympiads
      WHERE id = :olympiad_id
        AND template_id IS NOT NULL

      UNION

      SELECT id
      FROM olympiads
      WHERE template_id = :olympiad_id
    )
    SELECT
      u.id,
      u.full_name,
      u.age,
      u.grade,
      u.snils,
      u.email,
      u.username,
      u.password,
      $scoreSelect,
      $workFilesSelect,
      $schoolSelect,
      $reviewSelect,
      $appealSelect
    FROM olympiad_participants p
    JOIN related_olympiads ro ON ro.id = p.olympiad_id
    JOIN users u ON p.student_id = u.id
    LEFT JOIN approved_schools s ON (u.school_id = s.id)
    $schoolJoin
    LEFT JOIN LATERAL (
      SELECT a.id, a.status, a.description, a.created_at, a.response_comment, a.response_score, a.response_created_at
      FROM appeals a
      WHERE a.olympiad_id = p.olympiad_id
        AND a.student_id = p.student_id
      ORDER BY a.created_at DESC
      LIMIT 1
    ) AS latest_appeal ON true
    LEFT JOIN LATERAL (
      SELECT COALESCE(json_agg(json_build_object('id', af.id, 'name', af.file_name) ORDER BY af.id DESC), '[]'::json) AS files
      FROM appeal_files af
      WHERE latest_appeal.id IS NOT NULL AND af.appeal_id = latest_appeal.id
    ) AS latest_appeal_files ON true
    LEFT JOIN LATERAL (
      SELECT COALESCE(json_agg(json_build_object('id', arf.id, 'name', arf.file_name) ORDER BY arf.id DESC), '[]'::json) AS files
      FROM appeal_response_files arf
      WHERE latest_appeal.id IS NOT NULL AND arf.appeal_id = latest_appeal.id
    ) AS latest_response_files ON true
    WHERE (
      :filter_school_value::bigint IS NULL
      OR EXISTS (
        SELECT 1
        FROM olympiads os
        WHERE os.id = p.olympiad_id
          AND (
            os.school_id = :filter_school_value::bigint
            OR (:filter_school_alt::bigint IS NOT NULL AND os.school_id = :filter_school_alt::bigint)
          )
      )
      OR u.school_id = :filter_school_value::bigint
      OR (:filter_school_alt::bigint IS NOT NULL AND u.school_id = :filter_school_alt::bigint)
    )
    ORDER BY u.full_name
  ");

  $stmt->execute([
    ':olympiad_id' => (int)$id,
    ':filter_school_value' => $filterSchoolValue,
    ':filter_school_alt' => $filterSchoolAlt
  ]);

  $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($participants, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error' => 'Ошибка загрузки участников',
    'exception' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine()
  ], JSON_UNESCAPED_UNICODE);
}

?>
