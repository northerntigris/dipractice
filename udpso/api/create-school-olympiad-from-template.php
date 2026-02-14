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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$templateId = (int)($input['template_id'] ?? 0);
$scheduledAtRaw = (string)($input['scheduled_at'] ?? '');
$grades = trim((string)($input['grades'] ?? ''));
$description = trim((string)($input['description'] ?? ''));

if ($templateId <= 0 || $scheduledAtRaw === '' || $grades === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Некорректные данные'], JSON_UNESCAPED_UNICODE);
  exit;
}

function parse_grades_to_set(string $gradesStr): array {
  $gradesStr = preg_replace('/\s+/', '', $gradesStr);
  if ($gradesStr === '') return [];
  $set = [];
  $parts = preg_split('/[;,]+/', $gradesStr);
  foreach ($parts as $part) {
    if ($part === '') continue;
    if (str_contains($part, '-')) {
      [$a, $b] = array_map('intval', explode('-', $part, 2));
      if ($a > 0 && $b > 0) {
        $from = min($a, $b);
        $to = max($a, $b);
        for ($g = $from; $g <= $to; $g++) $set[$g] = true;
      }
    } else {
      $g = (int)$part;
      if ($g > 0) $set[$g] = true;
    }
  }
  return array_keys($set);
}

// datetime-local -> timestamp
$scheduledAt = str_replace('T', ' ', $scheduledAtRaw);

// текущая школа
$schoolIdStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
$schoolIdStmt->execute([$userId]);
$approvedSchoolId = (int)$schoolIdStmt->fetchColumn();

if ($approvedSchoolId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Школа не найдена'], JSON_UNESCAPED_UNICODE);
  exit;
}

// организация школы
$orgStmt = $pdo->prepare("SELECT organization_id FROM approved_schools WHERE id = ?");
$orgStmt->execute([$approvedSchoolId]);
$schoolOrgId = (int)$orgStmt->fetchColumn();

if ($schoolOrgId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Организация школы не найдена'], JSON_UNESCAPED_UNICODE);
  exit;
}

// шаблон должен принадлежать организации школы
$tplStmt = $pdo->prepare("
  SELECT id, subject, description, window_start, window_end, organization_id, organizer_id, grades
  FROM olympiads
  WHERE id = ?
");
$tplStmt->execute([$templateId]);
$tpl = $tplStmt->fetch(PDO::FETCH_ASSOC);

if (!$tpl) {
  http_response_code(404);
  echo json_encode(['success' => false, 'error' => 'Шаблон олимпиады не найден'], JSON_UNESCAPED_UNICODE);
  exit;
}

$tplOrgId = (int)($tpl['organization_id'] ?? 0);
if ($tplOrgId <= 0 || $tplOrgId !== $schoolOrgId) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Шаблон недоступен вашей организации'], JSON_UNESCAPED_UNICODE);
  exit;
}

$ws = (string)($tpl['window_start'] ?? '');
$we = (string)($tpl['window_end'] ?? '');
if ($ws === '' || $we === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'У шаблона не задан диапазон проведения'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!($scheduledAt >= $ws && $scheduledAt <= $we)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Дата проведения должна быть внутри допустимого диапазона'], JSON_UNESCAPED_UNICODE);
  exit;
}

$newGrades = parse_grades_to_set($grades);
if (count($newGrades) === 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Некорректный формат классов'], JSON_UNESCAPED_UNICODE);
  exit;
}

$templateGrades = parse_grades_to_set((string)($tpl['grades'] ?? ''));
if (count($templateGrades) === 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'У шаблона не заданы классы'], JSON_UNESCAPED_UNICODE);
  exit;
}

foreach ($newGrades as $grade) {
  if (!in_array($grade, $templateGrades, true)) {
    http_response_code(400);
    echo json_encode([
      'success' => false,
      'error' => 'Выбранные классы должны быть в пределах классов, заданных организатором'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// запрещаем пересечение классов внутри одной школы по одному шаблону
$existsStmt = $pdo->prepare("
  SELECT COALESCE(so.grades, o.grades) AS grades
  FROM olympiads o
  LEFT JOIN school_olympiads so
    ON so.olympiad_id = o.id AND so.school_id = o.school_id
  WHERE o.school_id = :school_id AND o.template_id = :template_id
");
$existsStmt->execute([
  ':school_id' => $approvedSchoolId,
  ':template_id' => $templateId
]);
$existing = $existsStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($existing as $row) {
  $existingGrades = parse_grades_to_set((string)($row['grades'] ?? ''));
  if ($existingGrades && array_intersect($newGrades, $existingGrades)) {
    http_response_code(400);
    echo json_encode([
      'success' => false,
      'error' => 'Нельзя назначить пересекающиеся классы: эти классы уже используются в другой олимпиаде вашей школы по этому шаблону'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

try {
  $pdo->beginTransaction();

  $subject = (string)$tpl['subject'];
  $tplDesc = (string)($tpl['description'] ?? '');
  $finalDesc = ($description !== '') ? $description : $tplDesc;

  $ins = $pdo->prepare("
    INSERT INTO olympiads (
      school_id, template_id, subject, datetime, grades, description, status,
      organizer_id, window_start, window_end, organization_id, created_at
    )
    VALUES (
      :school_id, :template_id, :subject, :dt, :grades, :descr, 'upcoming',
      :organizer_id, :ws, :we, :org_id, NOW()
    )
    RETURNING id
  ");

  $ins->execute([
    ':school_id' => $approvedSchoolId,
    ':template_id' => $templateId,
    ':subject' => $subject,
    ':dt' => $scheduledAt,
    ':grades' => $grades,
    ':descr' => $finalDesc,
    ':organizer_id' => (int)($tpl['organizer_id'] ?? 0) ?: null,
    ':ws' => $ws,
    ':we' => $we,
    ':org_id' => $tplOrgId
  ]);

  $newId = (int)$ins->fetchColumn();

  $pdo->commit();

  echo json_encode(['success' => true, 'olympiad_id' => $newId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
?>
