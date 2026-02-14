<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['user_role'] ?? '';

if ($userId <= 0 || $role !== 'organizer') {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
  exit;
}

$olympiadId = (int)($_GET['id'] ?? 0);
if ($olympiadId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Некорректный ID олимпиады'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // проверка: олимпиада должна принадлежать текущему организатору
  $chk = $pdo->prepare("SELECT organizer_id, grades FROM olympiads WHERE id = ? LIMIT 1");
  $chk->execute([$olympiadId]);
  $o = $chk->fetch(PDO::FETCH_ASSOC);

  if (!$o || (int)$o['organizer_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // список школ, которые назначили проведение (school_olympiads + школьные олимпиады по шаблону)
  $stmt = $pdo->prepare("
    SELECT
      so.id AS school_olympiad_id,
      so.scheduled_at,
      so.status,
      so.grades AS school_grades,
      so.description AS school_description,
      s.id AS approved_school_id,
      s.id AS school_reg_id,
      COALESCE(s.short_name, s.full_name) AS school_name
    FROM school_olympiads so
    JOIN approved_schools s ON s.id = so.school_id
    WHERE so.olympiad_id = :template_id

    UNION ALL

    SELECT
      NULL AS school_olympiad_id,
      o.datetime AS scheduled_at,
      o.status,
      o.grades AS school_grades,
      o.description AS school_description,
      s.id AS approved_school_id,
      s.id AS school_reg_id,
      COALESCE(s.short_name, s.full_name) AS school_name
    FROM olympiads o
    JOIN approved_schools s ON s.id = o.school_id
    WHERE o.template_id = :template_id

    ORDER BY scheduled_at ASC
  ");
  $stmt->execute([':template_id' => $olympiadId]);

  echo json_encode([
    'success' => true,
    'grades' => $o['grades'],
    'schools' => $stmt->fetchAll(PDO::FETCH_ASSOC)
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
?>
