<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['user_role'] ?? '';

if ($userId <= 0 || !in_array($role, ['school', 'school_coordinator'], true)) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
  exit;
}

$schoolIdStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
$schoolIdStmt->execute([$userId]);
$approvedSchoolId = (int)$schoolIdStmt->fetchColumn();

if ($approvedSchoolId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Школа не найдена'], JSON_UNESCAPED_UNICODE);
  exit;
}

$orgStmt = $pdo->prepare("SELECT organization_id FROM approved_schools WHERE id = ?");
$orgStmt->execute([$approvedSchoolId]);
$organizationId = (int)$orgStmt->fetchColumn();

if ($organizationId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Организация школы не найдена'], JSON_UNESCAPED_UNICODE);
  exit;
}

$stmt = $pdo->prepare("
  SELECT
    o.id,
    o.school_id,
    o.template_id,
    o.subject,
    o.grades,
    o.datetime,
    so.grades AS school_grades,
    so.scheduled_at AS school_scheduled_at,
    o.description,
    o.status,
    o.window_start,
    o.window_end,
    o.created_at,
    CASE
      WHEN o.school_id = :school_id THEN true
      ELSE false
    END AS is_owned_by_school,
    CASE
      WHEN (o.school_id = :school_id) OR (so.id IS NOT NULL) THEN true
      ELSE false
    END AS already_chosen
  FROM olympiads o
  LEFT JOIN school_olympiads so
    ON so.olympiad_id = o.id AND so.school_id = :school_id
  WHERE o.organization_id = :org_id
  ORDER BY o.created_at DESC
");

$stmt->execute([
  ':school_id' => $approvedSchoolId,
  ':org_id' => $organizationId
]);

echo json_encode(['success' => true, 'olympiads' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
?>
