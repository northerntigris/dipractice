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

if ($olympiadId <= 0 || $scheduledAtRaw === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Некорректные данные'], JSON_UNESCAPED_UNICODE);
  exit;
}

$scheduledAt = str_replace('T', ' ', $scheduledAtRaw);

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

$olStmt = $pdo->prepare("SELECT organization_id, window_start, window_end FROM olympiads WHERE id = ?");
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

$ws = (string)($ol['window_start'] ?? '');
$we = (string)($ol['window_end'] ?? '');

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

try {
  $stmt = $pdo->prepare("
    INSERT INTO school_olympiads (school_id, olympiad_id, scheduled_at, status, created_by_user_id, created_at)
    VALUES (?, ?, ?, 'planned', ?, NOW())
  ");
  $stmt->execute([$approvedSchoolId, $olympiadId, $scheduledAt, $userId]);

  echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  // уникальный индекс ux_school_olympiads_school_olympiad уже есть
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Эта олимпиада уже выбрана школой'], JSON_UNESCAPED_UNICODE);
}
?>