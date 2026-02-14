<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$juryId = isset($_POST['jury_id']) ? (int)$_POST['jury_id'] : 0;
$olympiadId = isset($_POST['olympiad_id']) ? (int)$_POST['olympiad_id'] : 0;
$forceNewPassword = !empty($_POST['force_new_password']);

if ($juryId <= 0 || $olympiadId <= 0) {
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

function randomLoginSuffix(int $length = 4): string {
  $digits = '0123456789';
  $max = strlen($digits) - 1;
  $out = '';
  for ($i = 0; $i < $length; $i++) {
    $out .= $digits[random_int(0, $max)];
  }
  return $out;
}

try {
  $stmt = $pdo->prepare("\n    SELECT\n      jm.id AS jury_member_id,\n      jm.user_id,\n      u.full_name AS jury_full_name,\n      u.username,\n      u.password,\n      u.must_change_password,\n      o.id AS olympiad_id,\n      o.subject AS olympiad_name,\n      COALESCE(so.scheduled_at, o.datetime) AS olympiad_date,\n      org.full_name AS organizer_name\n    FROM jury_members jm\n    JOIN users u ON u.id = jm.user_id\n    JOIN olympiad_jury oj ON oj.jury_member_id = jm.id\n    JOIN olympiads o ON o.id = oj.olympiad_id\n    LEFT JOIN users org ON org.id = o.organizer_id\n    LEFT JOIN school_olympiads so ON so.olympiad_id = o.id\n    WHERE jm.id = :jury_id AND oj.olympiad_id = :olympiad_id\n    LIMIT 1\n  ");
  $stmt->execute([
    ':jury_id' => $juryId,
    ':olympiad_id' => $olympiadId
  ]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Член жюри не найден для выбранной олимпиады'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $userId = (int)$row['user_id'];

  $login = (string)($row['username'] ?? '');
  $passwordHash = (string)($row['password'] ?? '');
  $existingUser = ($login !== '' && $passwordHash !== '');
  $password = '';
  $mustChangePassword = !empty($row['must_change_password']);
  $shouldGeneratePassword = $forceNewPassword || $mustChangePassword || !$existingUser;

  if ($login === '') {
    $loginBase = 'jury' . $userId;
    $login = $loginBase;

    $checkLogin = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
    $attempt = 0;
    do {
      $checkLogin->execute([$login, $userId]);
      $exists = (bool)$checkLogin->fetchColumn();
      if ($exists) {
        $attempt++;
        $login = $loginBase . randomLoginSuffix(4 + min($attempt, 4));
      }
    } while ($exists && $attempt < 10);

    if ($exists) {
      throw new RuntimeException('Не удалось сгенерировать уникальный логин');
    }
  }

  if ($shouldGeneratePassword) {
    $password = randomPassword(10);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $upd = $pdo->prepare("UPDATE users SET username = ?, password = ?, must_change_password = ? WHERE id = ?");
    $upd->execute([$login, $passwordHash, true, $userId]);
  } elseif ($login !== (string)($row['username'] ?? '')) {
    $upd = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
    $upd->execute([$login, $userId]);
  }

  echo json_encode([
    'success' => true,
    'login' => $login,
    'password' => ($shouldGeneratePassword ? $password : null),
    'existing_user' => $existingUser,
    'olympiad_name' => $row['olympiad_name'] ?? '',
    'olympiad_date' => $row['olympiad_date'] ?? '',
    'organizer_name' => $row['organizer_name'] ?? ''
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => 'Ошибка: ' . $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
