<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.full_name,
      u.email,
      u.username,
      u.role,
      u.created_at,
      COALESCE(s.short_name, o.full_name) AS organization_name,
      s.region
    FROM users u
    LEFT JOIN approved_schools s ON u.school_id = s.id
    LEFT JOIN approved_organizations o ON u.organization_id = o.id
    ORDER BY u.created_at DESC
  ");
  $stmt->execute();
  $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['success' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
