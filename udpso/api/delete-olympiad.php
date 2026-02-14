<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['user_role'] ?? '';

if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
  exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$olympiadId = (int)($input['olympiad_id'] ?? 0);

if ($olympiadId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Некорректный olympiad_id'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // Разрешаем удаление:
  // - school / school_coordinator: только если олимпиада привязана к их школе в school_olympiads
  // - organizer: только если он владелец олимпиады
  // - admin/moderator: всегда
  if (in_array($role, ['admin', 'moderator'], true)) {
    // ok
  } elseif (in_array($role, ['school', 'school_coordinator'], true)) {
    $st = $pdo->prepare("SELECT school_id FROM users WHERE id = :uid");
    $st->execute([':uid' => $userId]);
    $schoolId = (int)($st->fetchColumn() ?: 0);

    if ($schoolId <= 0) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'Школа не определена'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $chk = $pdo->prepare("SELECT 1 FROM school_olympiads WHERE olympiad_id = :oid AND school_id = :sid LIMIT 1");
    $chk->execute([':oid' => $olympiadId, ':sid' => $schoolId]);
    if (!$chk->fetchColumn()) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'Нет прав на удаление этой олимпиады'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } elseif ($role === 'organizer') {
    $chk = $pdo->prepare("SELECT 1 FROM olympiads WHERE id = :oid AND organizer_id = :uid LIMIT 1");
    $chk->execute([':oid' => $olympiadId, ':uid' => $userId]);
    if (!$chk->fetchColumn()) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'Нет прав на удаление этой олимпиады'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } else {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Удаляем — каскады в БД уже удалят связи (participants, jury, files, school_olympiads и т.д.)
  $del = $pdo->prepare("DELETE FROM olympiads WHERE id = :oid");
  $del->execute([':oid' => $olympiadId]);

  if ($del->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Олимпиада не найдена'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Ошибка сервера', 'details' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>