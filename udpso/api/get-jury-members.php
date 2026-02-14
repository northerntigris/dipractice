<?php
require '../config.php';
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

$olympiadId = $_GET['id'] ?? null;
if (!$olympiadId || !is_numeric($olympiadId)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid olympiad ID']);
  exit;
}

try {
  $userRole = $_SESSION['user_role'] ?? '';
  $currentUserId = (int)($_SESSION['user_id'] ?? 0);

  $schoolIdStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
  $schoolIdStmt->execute([$currentUserId]);
  $currentSchoolId = (int)$schoolIdStmt->fetchColumn();

  $requestedSchoolRegId = (isset($_GET['school_reg_id']) && is_numeric($_GET['school_reg_id']))
    ? (int)$_GET['school_reg_id']
    : null;

  $filterSchoolValue = null;
  $filterSchoolAlt = null;

  if (in_array($userRole, ['school', 'school_coordinator'], true)) {
    $filterSchoolValue = $currentSchoolId > 0 ? $currentSchoolId : null;
  } elseif ($userRole === 'organizer' && $requestedSchoolRegId) {
    $filterSchoolValue = $requestedSchoolRegId;
    $filterSchoolAlt = null;
  }

  $stmt = $pdo->prepare("
    SELECT 
      o.id AS olympiad_jury_id,
      jm.id AS jury_member_id,
      u.full_name,
      u.username,
      u.snils,
      jm.organization,
      jm.passport_series,
      jm.passport_number,
      jm.passport_issued_by,
      jm.passport_issued_date,
      jm.birthdate,
      o.jury_role
    FROM olympiad_jury o
    JOIN jury_members jm ON o.jury_member_id = jm.id
    JOIN users u ON jm.user_id = u.id
    JOIN olympiads os ON os.id = o.olympiad_id
    WHERE o.olympiad_id IN (
      SELECT id FROM olympiads WHERE id = :olympiad_id OR template_id = :olympiad_id
    )
    AND (
      CAST(:school_value AS INT) IS NULL
      OR os.school_id = CAST(:school_value AS INT)
      OR (CAST(:school_alt AS INT) IS NOT NULL AND os.school_id = CAST(:school_alt AS INT))
    )
    ORDER BY u.full_name
  ");

  $stmt->execute([
    ':olympiad_id' => (int)$olympiadId,
    ':school_value' => $filterSchoolValue,
    ':school_alt' => $filterSchoolAlt
  ]);

  $experts = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($experts);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}

?>
