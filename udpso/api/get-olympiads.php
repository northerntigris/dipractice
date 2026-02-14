<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? '';

if ($userRole !== 'organizer') {
  http_response_code(403);
  echo json_encode(['error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
  exit;
}

$stmt = $pdo->prepare("
  SELECT id, subject, datetime, window_start, window_end, grades, status, created_at
  FROM olympiads
  WHERE organizer_id = ?
    AND school_id IS NULL
    AND template_id IS NULL
  ORDER BY COALESCE(window_start, datetime, created_at) DESC
");
$stmt->execute([$userId]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
