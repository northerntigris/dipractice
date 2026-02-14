<?php
header('Content-Type: application/json');
require_once '../config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
  exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
  echo json_encode(['success' => false, 'error' => 'Некорректный JSON']);
  exit;
}

$required = ['subject', 'window_start', 'window_end', 'grades'];
foreach ($required as $field) {
  if (empty($input[$field])) {
    echo json_encode(['success' => false, 'error' => "Поле '{$field}' обязательно"]);
    exit;
  }
}

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'error' => 'Пользователь не авторизован']);
  exit;
}

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, organization_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || ($row['role'] ?? '') !== 'organizer') {
  echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
  exit;
}

$organizationId = (int)($row['organization_id'] ?? 0);
if ($organizationId <= 0) {
  echo json_encode(['success' => false, 'error' => 'У организатора не указана организация']);
  exit;
}

$windowStart = str_replace('T', ' ', $input['window_start']);
$windowEnd   = str_replace('T', ' ', $input['window_end']);

try {
  $stmt = $pdo->prepare("
    INSERT INTO olympiads (organizer_id, organization_id, subject, window_start, window_end, grades, description, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'upcoming', NOW())
  ");

  $subject = trim($input['subject']);
  $stmt->execute([
    $userId,
    $organizationId,
    $subject,
    $windowStart,
    $windowEnd,
    trim($input['grades']),
    trim($input['description'] ?? '')
  ]);

  $activityStmt = $pdo->prepare("
    INSERT INTO activities (user_id, type, title, created_at)
    VALUES (:user_id, 'olympiad_created', :title, NOW())
  " );
  $activityStmt->execute([
    ':user_id' => $userId,
    ':title' => 'Создана олимпиада: ' . $subject
  ]);

  echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => 'Ошибка при создании олимпиады: ' . $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}


?>