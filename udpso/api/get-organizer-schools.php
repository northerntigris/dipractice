<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

session_start();

try {
  if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $userId = (int)$_SESSION['user_id'];

  $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
  $roleStmt->execute([$userId]);
  $role = $roleStmt->fetchColumn();

  if ($role !== 'organizer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $pdo->exec("\n    CREATE TABLE IF NOT EXISTS organizer_school_documents (\n      id BIGSERIAL PRIMARY KEY,\n      school_id BIGINT NOT NULL REFERENCES approved_schools(id) ON DELETE CASCADE,\n      original_name TEXT NOT NULL,\n      stored_name TEXT NOT NULL,\n      file_size BIGINT NOT NULL DEFAULT 0,\n      uploaded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()\n    )\n  ");

  $stmt = $pdo->prepare("\n    SELECT\n      s.id,\n      s.full_name,\n      s.short_name,\n      s.region,\n      s.contact_email,\n      s.contact_phone,\n      s.approved_at,\n      s.address,\n      s.inn,\n      s.ogrn,\n      s.ogrn_date,\n      s.director_fio,\n      s.director_inn,\n      s.director_position,\n      u.username AS login\n    FROM approved_schools s\n    LEFT JOIN users u\n      ON u.school_id = s.id AND u.role = 'school'\n    WHERE s.approved_by = :org_id\n ORDER BY s.approved_at DESC, s.id DESC\n  ");
  $stmt->execute([':org_id' => $userId]);

  $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!empty($schools)) {
    $docStmt = $pdo->prepare("\n      SELECT id, school_id, original_name, stored_name, file_size, uploaded_at\n      FROM organizer_school_documents\n      WHERE school_id = :school_id\n      ORDER BY uploaded_at DESC, id DESC\n    ");

    foreach ($schools as &$school) {
      $docStmt->execute([':school_id' => (int)$school['id']]);
      $school['documents'] = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($school);
  }

  echo json_encode(['success' => true, 'schools' => $schools], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Ошибка загрузки школ'], JSON_UNESCAPED_UNICODE);
}
?>
