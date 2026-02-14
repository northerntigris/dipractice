<?php
require_once '../config.php';
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$required = ['id', 'full_name', 'grade'];

foreach ($required as $field) {
  if (empty($data[$field])) {
    echo json_encode(['error' => "Missing field: $field"]);
    exit;
  }
}

$stmt = $pdo->prepare("
  UPDATE users SET
    full_name = ?,
    age = ?,
    grade = ?,
    snils = ?,
    email = ?
  WHERE id = ?
");

$stmt->execute([
  $data['full_name'],
  $data['age'] ?? null,
  $data['grade'],
  $data['snils'] ?? null,
  $data['email'] ?? null,
  $data['id']
]);

echo json_encode(['success' => true]);

?>