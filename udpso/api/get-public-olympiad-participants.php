<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $id = $_GET['id'] ?? null;
  if (!$id || !is_numeric($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid olympiad ID'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $stmt = $pdo->prepare("
    WITH related_olympiads AS (
      SELECT id
      FROM olympiads
      WHERE id = :olympiad_id

      UNION

      SELECT template_id AS id
      FROM olympiads
      WHERE id = :olympiad_id
        AND template_id IS NOT NULL

      UNION

      SELECT id
      FROM olympiads
      WHERE template_id = :olympiad_id
    )
    SELECT
      u.id,
      u.full_name,
      u.grade
    FROM olympiad_participants p
    JOIN related_olympiads ro ON ro.id = p.olympiad_id
    JOIN users u ON p.student_id = u.id
    ORDER BY u.full_name ASC
  ");

  $stmt->execute([':olympiad_id' => (int)$id]);
  $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($participants, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error' => 'Ошибка загрузки участников',
    'details' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
