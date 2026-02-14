<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid or missing ID'], JSON_UNESCAPED_UNICODE);
  exit;
}

$id = (int)$_GET['id'];
$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? '';

try {
  $columnStmt = $pdo->prepare("\n    SELECT 1\n    FROM information_schema.columns\n    WHERE table_schema = 'public'\n      AND table_name = 'olympiads'\n      AND column_name = 'results_published'\n    LIMIT 1\n  ");
  $columnStmt->execute();
  $hasResultsPublished = (bool)$columnStmt->fetchColumn();

  $resultsSelect = $hasResultsPublished ? ', o.results_published' : '';

  if ($hasResultsPublished) {
    $pdo->exec("UPDATE olympiads SET results_published = true WHERE results_published IS NULL");
    $pdo->exec("ALTER TABLE olympiads ALTER COLUMN results_published SET DEFAULT false");
  }

  // Получаем олимпиаду
  $stmt = $pdo->prepare("
    SELECT
      o.id, o.school_id, o.template_id, o.organizer_id, o.organization_id, o.subject, o.datetime, o.window_start, o.window_end,
      o.grades, o.description, o.status, o.created_at{$resultsSelect},
      so.scheduled_at AS school_scheduled_at,
      so.grades AS school_grades,
      so.status AS school_status,
      so.description AS school_description
    FROM olympiads o
    LEFT JOIN school_olympiads so
      ON so.olympiad_id = o.id
    AND so.school_id = (
      SELECT school_id FROM users WHERE id = :uid
    )
    WHERE o.id = :oid
    LIMIT 1
  ");
  $stmt->execute([':oid' => $id, ':uid' => $userId]);
  $olympiad = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$olympiad) {
    http_response_code(404);
    echo json_encode(['error' => 'Olympiad not found'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Ограничение доступа:
  if ($userRole === 'organizer') {
    if ((int)$olympiad['organizer_id'] !== $userId) {
      http_response_code(403);
      echo json_encode(['error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } elseif ($userRole === 'school' || $userRole === 'school_coordinator') {
    // школа видит только олимпиады своей организации
    $schoolIdStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
    $schoolIdStmt->execute([$userId]);
    $approvedSchoolId = (int)$schoolIdStmt->fetchColumn();

    $orgStmt = $pdo->prepare("SELECT organization_id FROM approved_schools WHERE id = ?");
    $orgStmt->execute([$approvedSchoolId]);
    $schoolOrgId = (int)$orgStmt->fetchColumn();

    if ($schoolOrgId <= 0 || (int)$olympiad['organization_id'] !== $schoolOrgId) {
      http_response_code(403);
      echo json_encode(['error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
  // jury/прочие пока не ограничиваем дополнительно

  echo json_encode($olympiad, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database error'], JSON_UNESCAPED_UNICODE);
}

?>
