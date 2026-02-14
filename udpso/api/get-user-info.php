<?php
require_once '../config.php';

session_start();

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['error' => 'unauthorized']);
  exit;
}

$stmt = $pdo->prepare("SELECT full_name, role, school_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$displayName = $user['full_name'] ?? 'пользователь';

if (($user['role'] ?? '') === 'school' && !empty($user['school_id'])) {
  $schoolStmt = $pdo->prepare("SELECT director_fio FROM approved_schools WHERE id = ?");
  $schoolStmt->execute([$user['school_id']]);
  $school = $schoolStmt->fetch(PDO::FETCH_ASSOC);
  if (!empty($school['director_fio'])) {
    $displayName = $school['director_fio'];
  }
}

echo json_encode([
  'full_name' => $user['full_name'] ?? 'пользователь',
  'display_name' => $displayName,
]);
?>
