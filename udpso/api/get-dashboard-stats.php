<?php
header('Content-Type: application/json');
require_once '../config.php';

session_start();

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

$userId = $_SESSION['user_id'];

// Роль нужна, чтобы корректно формировать статистику
$stmt = $pdo->prepare("SELECT role, school_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userRow = $stmt->fetch(PDO::FETCH_ASSOC);

$userRole = $userRow['role'] ?? null;
$schoolId = $userRow['school_id'] ?? null;

// Счётчики олимпиад (если school_id не задан — будет 0)
$activeCount = 0;
$completedCount = 0;

if ($userRole === 'organizer') {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM olympiads WHERE organizer_id = ? AND school_id IS NOT NULL AND template_id IS NOT NULL AND status IN ('upcoming', 'ongoing')");
  $stmt->execute([$userId]);
  $activeCount = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM olympiads WHERE organizer_id = ? AND school_id IS NOT NULL AND template_id IS NOT NULL AND status = 'completed'");
  $stmt->execute([$userId]);
  $completedCount = (int)$stmt->fetchColumn();
}

// Новая статистика по школам (для организатора)
$mySchoolsCount = 0;
if ($userRole === 'organizer') {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM approved_schools WHERE approved_by = ?");
  $stmt->execute([$userId]);
  $mySchoolsCount = (int)$stmt->fetchColumn();
}

$stmt = $pdo->query("SELECT COUNT(*) FROM approved_schools");
$totalSchoolsCount = (int)$stmt->fetchColumn();

echo json_encode([
  'active' => $activeCount,
  'completed' => $completedCount,
  'my_schools' => $mySchoolsCount,
  'total_schools' => $totalSchoolsCount
]);
?>