<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$participantId = isset($_POST['participant_id']) ? (int)$_POST['participant_id'] : 0;
$olympiadId = isset($_POST['olympiad_id']) ? (int)$_POST['olympiad_id'] : 0;
$forceNewPassword = !empty($_POST['force_new_password']);

if ($participantId <= 0 || $olympiadId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Некорректные параметры'], JSON_UNESCAPED_UNICODE);
  exit;
}

function randomPassword(int $length = 10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  $max = strlen($alphabet) - 1;
  $out = '';
  for ($i = 0; $i < $length; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }
  return $out;
}

try {
  $stmt = $pdo->prepare("\n    SELECT
      u.id AS user_id,
      u.username,
      u.password,
      u.must_change_password
    FROM olympiad_participants op
    JOIN users u ON u.id = op.student_id
    WHERE op.olympiad_id = :olympiad_id
      AND op.student_id = :participant_id
    LIMIT 1
  ");
  $stmt->execute([
    ':olympiad_id' => $olympiadId,
    ':participant_id' => $participantId
  ]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Участник не найден для выбранной олимпиады'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $login = (string)($row['username'] ?? '');
  $passwordHash = (string)($row['password'] ?? '');
  $existingUser = ($login !== '' && $passwordHash !== '');
  $mustChangePassword = !empty($row['must_change_password']);
  $shouldGeneratePassword = $forceNewPassword || $mustChangePassword || !$existingUser;
  $password = '';

  if ($shouldGeneratePassword) {
    $password = randomPassword(10);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE users SET password = ?, must_change_password = ? WHERE id = ?");
    $upd->execute([$passwordHash, true, (int)$row['user_id']]);
  }

  echo json_encode([
    'success' => true,
    'login' => $login,
    'password' => ($shouldGeneratePassword ? $password : null),
    'existing_user' => $existingUser
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => 'Ошибка: ' . $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
