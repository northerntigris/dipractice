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

if ($olympiadId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Некорректные данные'], JSON_UNESCAPED_UNICODE);
  exit;
}

// текущая школа
$schoolIdStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
$schoolIdStmt->execute([$userId]);
$approvedSchoolId = (int)$schoolIdStmt->fetchColumn();

if ($approvedSchoolId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Школа не найдена'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo->beginTransaction();

  $ownerStmt = $pdo->prepare("SELECT school_id FROM olympiads WHERE id = :olympiad_id");
  $ownerStmt->execute([':olympiad_id' => $olympiadId]);
  $ownerSchoolId = (int)$ownerStmt->fetchColumn();
  $isSchoolOwned = ($ownerSchoolId === $approvedSchoolId);

  if ($isSchoolOwned) {
    $delWork = $pdo->prepare("
      DELETE FROM participant_work_files
      WHERE olympiad_id = :olympiad_id
    ");
    $delWork->execute([':olympiad_id' => $olympiadId]);

    $delPart = $pdo->prepare("
      DELETE FROM olympiad_participants
      WHERE olympiad_id = :olympiad_id
    ");
    $delPart->execute([':olympiad_id' => $olympiadId]);

    $delJury = $pdo->prepare("
      DELETE FROM olympiad_jury
      WHERE olympiad_id = :olympiad_id
    ");
    $delJury->execute([':olympiad_id' => $olympiadId]);

    $delTasks = $pdo->prepare("
      DELETE FROM olympiad_task_files
      WHERE olympiad_id = :olympiad_id
    ");
    $delTasks->execute([':olympiad_id' => $olympiadId]);

    $delSo = $pdo->prepare("
      DELETE FROM school_olympiads
      WHERE olympiad_id = :olympiad_id
    ");
    $delSo->execute([':olympiad_id' => $olympiadId]);

    $delOlympiad = $pdo->prepare("
      DELETE FROM olympiads
      WHERE id = :olympiad_id
        AND school_id = :school_id
    ");
    $delOlympiad->execute([
      ':olympiad_id' => $olympiadId,
      ':school_id' => $approvedSchoolId
    ]);

    if ($delOlympiad->rowCount() === 0) {
      throw new RuntimeException('Олимпиада не найдена или недоступна для удаления');
    }

    $pdo->commit();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 1) Удаляем работы участников этой школы по этой олимпиаде
  // participant_work_files имеет FK (olympiad_id, student_id) -> olympiad_participants
  $delWork = $pdo->prepare("
    DELETE FROM participant_work_files
    WHERE olympiad_id = :olympiad_id
      AND student_id IN (
        SELECT id FROM users WHERE school_id = :school_id
      )
  ");
  $delWork->execute([
    ':olympiad_id' => $olympiadId,
    ':school_id' => $approvedSchoolId
  ]);

  // 2) Удаляем участника из олимпиадных участников (только ученики этой школы)
  $delPart = $pdo->prepare("
    DELETE FROM olympiad_participants
    WHERE olympiad_id = :olympiad_id
      AND student_id IN (
        SELECT id FROM users WHERE school_id = :school_id
      )
  ");
  $delPart->execute([
    ':olympiad_id' => $olympiadId,
    ':school_id' => $approvedSchoolId
  ]);

  // 3) Удаляем назначение школы на олимпиаду
  $delSo = $pdo->prepare("
    DELETE FROM school_olympiads
    WHERE school_id = :school_id AND olympiad_id = :olympiad_id
  ");
  $delSo->execute([
    ':school_id' => $approvedSchoolId,
    ':olympiad_id' => $olympiadId
  ]);

  if ($delSo->rowCount() === 0) {
    throw new RuntimeException('Олимпиада не найдена в вашей школе');
  }

  $pdo->commit();

  echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
?>
