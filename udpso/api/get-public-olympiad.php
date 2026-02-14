<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  echo json_encode(['error' => 'Invalid or missing ID'], JSON_UNESCAPED_UNICODE);
  exit;
}

$id = (int) $_GET['id'];

try {
  $stmt = $pdo->prepare("
    SELECT id, subject, datetime, grades, description, status
    FROM olympiads
    WHERE id = ?
      AND status IN ('upcoming', 'ongoing')
  ");
  $stmt->execute([$id]);
  $olympiad = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($olympiad) {
    echo json_encode($olympiad, JSON_UNESCAPED_UNICODE);
  } else {
    echo json_encode(['error' => 'Olympiad not found'], JSON_UNESCAPED_UNICODE);
  }
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database error'], JSON_UNESCAPED_UNICODE);
}
?>
