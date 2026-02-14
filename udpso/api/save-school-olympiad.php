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
$olympiadId = (int)($input['olympiad_id'] ?? 0);
$scheduledAtRaw = (string)($input['scheduled_at'] ?? '');
$grades = trim((string)($input['grades'] ?? ''));
$description = trim((string)($input['description'] ?? ''));
$dryRun = (bool)($input['dry_run'] ?? false);

if ($olympiadId <= 0 || $scheduledAtRaw === '' || $grades === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Некорректные данные'], JSON_UNESCAPED_UNICODE);
  exit;
}

function parse_grades_to_set(string $gradesStr): array {
  $gradesStr = preg_replace('/\s+/', '', $gradesStr);
  if ($gradesStr === '') {
    return [];
  }

  $set = [];
  $parts = preg_split('/[;,]+/', $gradesStr);
  foreach ($parts as $part) {
    if ($part === '') {
      continue;
    }
    if (str_contains($part, '-')) {
      [$a, $b] = array_map('intval', explode('-', $part, 2));
      if ($a > 0 && $b > 0) {
        $from = min($a, $b);
        $to = max($a, $b);
        for ($g = $from; $g <= $to; $g++) {
          $set[$g] = true;
        }
      }
    } else {
      $g = (int)$part;
      if ($g > 0) {
        $set[$g] = true;
      }
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

$orgStmt = $pdo->prepare("SELECT organization_id FROM approved_schools WHERE id = ?");
$orgStmt->execute([$approvedSchoolId]);
$schoolOrgId = (int)$orgStmt->fetchColumn();

if ($schoolOrgId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Организация школы не найдена'], JSON_UNESCAPED_UNICODE);
  exit;
}

// проверяем олимпиаду + диапазон
$olStmt = $pdo->prepare("SELECT organization_id, window_start, window_end, subject, grades, school_id, template_id FROM olympiads WHERE id = ?");
$olStmt->execute([$olympiadId]);
$ol = $olStmt->fetch(PDO::FETCH_ASSOC);

if (!$ol) {
  http_response_code(404);
  echo json_encode(['success' => false, 'error' => 'Олимпиада не найдена'], JSON_UNESCAPED_UNICODE);
  exit;
}

$olOrgId = (int)($ol['organization_id'] ?? 0);
if ($olOrgId <= 0 || $olOrgId !== $schoolOrgId) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Олимпиада недоступна для вашей организации'], JSON_UNESCAPED_UNICODE);
  exit;
}

$isSchoolOwned = ((int)($ol['school_id'] ?? 0) === $approvedSchoolId);

$ws = (string)($ol['window_start'] ?? '');
$we = (string)($ol['window_end'] ?? '');
$subject = (string)($ol['subject'] ?? '');

if ($ws === '' || $we === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'У олимпиады не задан диапазон проведения'], JSON_UNESCAPED_UNICODE);
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

$templateGrades = [];
if ($isSchoolOwned && !empty($ol['template_id'])) {
  $tplGradesStmt = $pdo->prepare("SELECT grades FROM olympiads WHERE id = ?");
  $tplGradesStmt->execute([(int)$ol['template_id']]);
  $templateGrades = parse_grades_to_set((string)($tplGradesStmt->fetchColumn() ?? ''));
} else {
  $templateGrades = parse_grades_to_set((string)($ol['grades'] ?? ''));
}
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

try {
  if ($isSchoolOwned) {
    $existingStmt = $pdo->prepare("
      SELECT grades
      FROM olympiads
      WHERE school_id = :school_id
        AND template_id = :template_id
        AND id <> :olympiad_id
    ");
    $existingStmt->execute([
      ':school_id' => $approvedSchoolId,
      ':template_id' => (int)($ol['template_id'] ?? 0),
      ':olympiad_id' => $olympiadId
    ]);
  } else {
    $existingStmt = $pdo->prepare("
      SELECT o.subject, so.grades
      FROM school_olympiads so
      JOIN olympiads o ON o.id = so.olympiad_id
      WHERE so.school_id = :school_id
        AND so.olympiad_id <> :olympiad_id
    ");
    $existingStmt->execute([
      ':school_id' => $approvedSchoolId,
      ':olympiad_id' => $olympiadId
    ]);
  }

  $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($existingRows as $row) {
    $existingGrades = parse_grades_to_set((string)($row['grades'] ?? ''));
    if (!$existingGrades) {
      continue;
    }
    if (array_intersect($newGrades, $existingGrades)) {
      http_response_code(400);
      echo json_encode([
        'success' => false,
        'error' => 'Нельзя назначить пересекающиеся классы: эти классы уже используются в другой олимпиаде вашей школы'
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Ошибка проверки классов'], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($dryRun) {
  echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  if ($isSchoolOwned) {
    $stmt = $pdo->prepare("
      UPDATE olympiads
      SET datetime = :scheduled_at,
          grades = :grades,
          description = :description
      WHERE id = :olympiad_id
        AND school_id = :school_id
    ");

    $stmt->execute([
      ':scheduled_at' => $scheduledAt,
      ':grades' => $grades,
      ':description' => ($description === '' ? '' : $description),
      ':olympiad_id' => $olympiadId,
      ':school_id' => $approvedSchoolId
    ]);

    $cleanup = $pdo->prepare("
      DELETE FROM school_olympiads
      WHERE olympiad_id = :olympiad_id
        AND school_id = :school_id
    ");
    $cleanup->execute([
      ':olympiad_id' => $olympiadId,
      ':school_id' => $approvedSchoolId
    ]);
  } else {
    // ux_school_olympiads_school_olympiad: (school_id, olympiad_id)
    $stmt = $pdo->prepare("
      INSERT INTO school_olympiads (
        school_id, olympiad_id, scheduled_at, grades, description, status, created_by_user_id, created_at
      )
      VALUES (
        :school_id, :olympiad_id, :scheduled_at, :grades, :description, 'planned', :user_id, NOW()
      )
      ON CONFLICT (school_id, olympiad_id)
      DO UPDATE SET
        scheduled_at = EXCLUDED.scheduled_at,
        grades = EXCLUDED.grades,
        description = EXCLUDED.description,
        status = 'planned'
    ");

    $stmt->execute([
      ':school_id' => $approvedSchoolId,
      ':olympiad_id' => $olympiadId,
      ':scheduled_at' => $scheduledAt,
      ':grades' => $grades,
      ':description' => ($description === '' ? null : $description),
      ':user_id' => $userId
    ]);
  }

  echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
?>
